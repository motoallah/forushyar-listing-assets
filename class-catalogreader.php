<?php
/**
 * خواندن زندهٔ کاتالوگ ووکامرس برای تغذیهٔ دستیار فروش.
 *
 * اصل ضدتوهم: ربات فقط از همین داده‌ها جواب می‌دهد؛ هیچ قیمتی از جای دیگر نمی‌آید.
 *
 * @package ForushYar
 */

namespace ForushYar;

// جلوگیری از دسترسی مستقیم.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس CatalogReader
 */
class CatalogReader {

	/**
	 * جست‌وجو در محصولات منتشرشده و قابل خرید.
	 *
	 * عملکرد: نتیجهٔ جست‌وجو ۶۰ ثانیه در transient کش می‌شود تا فروشگاه‌های
	 * پرمحصول زیر فشار نروند. موجودی «لحظه‌ای» کش نمی‌شود — اعتبارسنجی نهایی
	 * موجودی همیشه موقع افزودن به سبد (SalesTools) با دادهٔ زنده انجام می‌شود.
	 *
	 * @param string $query عبارت جست‌وجو (نام/توضیح).
	 * @param array  $args  اختیاری: category (slug)، max_price، min_price، limit.
	 * @return array لیست خلاصهٔ محصولات.
	 */
	public function search( $query = '', $args = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$query = trim( (string) $query );
		// نرمال‌سازی فارسی/عربی برای سرچ بهتر: ي→ی، ك→ک.
		$query = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $query );
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$cache_key = 'forushyar_ai_search_' . md5( $query . '|' . wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query_args = array(
			'status' => 'publish',
			'limit'  => isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 8,
			'order'  => 'DESC',
		);

		if ( '' !== $query ) {
			$query_args['s']       = $query;
			$query_args['orderby'] = 'relevance';
		} else {
			$query_args['orderby'] = 'menu_order';
		}
		if ( ! empty( $args['category'] ) ) {
			$query_args['category'] = array( sanitize_title( $args['category'] ) );
		}
		// نگاشت خودکار دسته (نگار): اگر عبارت جست‌وجو نام یک دسته را داشت
		// (مثل «لوازم آشپزخانه» → «خانه و آشپزخانه»)، فیلتر دسته اضافه کن —
		// چون سرچ متنی نام دسته‌ها را نمی‌بیند.
		$auto_cat = '';
		if ( '' !== $query && empty( $args['category'] ) ) {
			$auto_cat = $this->match_category( $query );
			if ( '' !== $auto_cat ) {
				$query_args['category'] = array( $auto_cat );
			}
		}
		if ( isset( $args['min_price'] ) && is_numeric( $args['min_price'] ) ) {
			$query_args['min_price'] = (float) $args['min_price'];
		}
		if ( isset( $args['max_price'] ) && is_numeric( $args['max_price'] ) ) {
			$query_args['max_price'] = (float) $args['max_price'];
		}

		$products = wc_get_products( $query_args );
		$result   = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
				continue;
			}
			$result[] = $this->summarize( $product );
		}

		// Fallback هوشمند: سرچ وردپرس با منطق AND همهٔ کلمات را می‌خواهد؛ اگر
		// هیچ نتیجه‌ای نبود (مثلاً «هدفون لانکر» در حالی که فقط «هدفون بلوتوثی»
		// داریم)، کلمه‌به‌کلمه با منطق OR سرچ می‌کنیم و بر اساس تعداد کلمات
		// مشترک رتبه‌بندی می‌کنیم تا نزدیک‌ترین محصول‌ها برگردند.
		if ( empty( $result ) && '' !== $query ) {
			$result = $this->search_or_fallback( $query, $query_args );
		}

		// آخرین تلاش: فقط محصولات همان دسته (بدون فیلتر متنی سخت‌گیرانه).
		if ( empty( $result ) && '' !== $auto_cat ) {
			$cat_only = array(
				'status'   => 'publish',
				'limit'    => isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 8,
				'category' => array( $auto_cat ),
				'orderby'  => 'menu_order',
				'order'    => 'DESC',
			);
			foreach ( wc_get_products( $cat_only ) as $product ) {
				if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
					continue;
				}
				$result[] = $this->summarize( $product );
			}
		}

		set_transient( $cache_key, $result, 60 );

		return $result;
	}

	/**
	 * نگاشت عبارت جست‌وجو به دستهٔ محصول فروشگاه.
	 * نام دسته‌ها فروشگاه‌به‌فروشگاه فرق می‌کند، پس از خودِ دسته‌های ووکامرس
	 * خوانده می‌شود: اگر واژه‌های شاخص نام دسته در عبارت کاربر بود، اسلاگ برمی‌گردد.
	 *
	 * @param string $query عبارت نرمال‌نشدهٔ جست‌وجو.
	 * @return string اسلاگ دسته یا رشتهٔ خالی.
	 */
	private function match_category( $query ) {
		$qnorm  = $this->normalize_text( $query );
		$qwords = array_values(
			array_filter(
				preg_split( '/\s+/u', $qnorm ),
				function ( $w ) {
					return mb_strlen( $w ) > 2;
				}
			)
		);
		if ( empty( $qwords ) ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		$best       = '';
		$best_score = 0;
		foreach ( $terms as $t ) {
			$cwords = array_values(
				array_filter(
					preg_split( '/\s+/u', $this->normalize_text( $t->name ) ),
					function ( $w ) {
						return mb_strlen( $w ) > 2;
					}
				)
			);
			if ( empty( $cwords ) ) {
				continue;
			}
			$hit = 0;
			foreach ( $cwords as $cw ) {
				foreach ( $qwords as $qw ) {
					if ( $cw === $qw || mb_strpos( $qw, $cw ) !== false ) {
						$hit++;
						break;
					}
				}
			}
			$score = $hit / count( $cwords );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $t->slug;
			}
		}
		return ( $best_score >= 0.5 ) ? $best : '';
	}

	/**
	 * سرچ جایگزین هوشمند با منطق OR + امتیازدهی وزنی + مترادف‌ها + تحمل تایپی.
	 *
	 * مراحل:
	 * ۱. حذف کلمات توقف از عبارت.
	 * ۲. گسترش با مترادف‌های فارسی.
	 * ۳. جست‌وجوی هر عبارت گسترش‌یافته.
	 * ۴. امتیازدهی وزنی همهٔ نتایج و مرتب‌سازی نهایی.
	 *
	 * @param string $query      عبارت نرمال‌شدهٔ جست‌وجو.
	 * @param array  $query_args آرگومان‌های پایهٔ wc_get_products.
	 * @return array لیست خلاصهٔ محصولات مرتب‌شده.
	 */
	private function search_or_fallback( $query, $query_args ) {
		$qnorm = $this->normalize_text( $query );
		$qnorm = $this->remove_stop_words( $qnorm );

		if ( '' === $qnorm ) {
			return array();
		}

		$words = array_values(
			array_filter(
				array_unique( preg_split( '/\s+/u', $qnorm ) ),
				function ( $w ) {
					return mb_strlen( $w ) > 1 && ! is_numeric( $w );
				}
			)
		);

		if ( empty( $words ) ) {
			return array();
		}

		// گسترش با مترادف‌ها.
		$search_queries = $this->expand_with_synonyms( $qnorm );
		// کلمات تکی هم اضافه شود.
		foreach ( $words as $word ) {
			if ( ! in_array( $word, $search_queries, true ) ) {
				$search_queries[] = $word;
			}
		}

		$merged = array();

		foreach ( $search_queries as $sq ) {
			$wq       = $query_args;
			$wq['s']  = $sq;
			$products = wc_get_products( $wq );

			foreach ( $products as $product ) {
				if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
					continue;
				}
				$id = $product->get_id();
				if ( ! isset( $merged[ $id ] ) ) {
					$merged[ $id ] = $this->summarize( $product );
				}
			}
		}

		if ( empty( $merged ) ) {
			return array();
		}

		// امتیازدهی وزنی همهٔ نتایج.
		$ranked = array();
		foreach ( $merged as $id => $product ) {
			$ranked[ $id ] = $this->score_product( $product, $qnorm, $words );
		}
		arsort( $ranked );

		// حذف نتایج با امتیاز صفر (بی‌ربط).
		$ranked = array_filter(
			$ranked,
			function ( $s ) {
				return $s > 0;
			}
		);

		$result = array();
		foreach ( $ranked as $id => $score ) {
			$result[] = $merged[ $id ];
			if ( count( $result ) >= $query_args['limit'] ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * نرمال‌سازی متن فارسی برای مقایسه: یکدست‌سازی ي/ك، حذف نیم‌فاصله و
	 * علائم، تبدیل به حروف کوچک لاتین.
	 *
	 * @param string $text متن ورودی.
	 * @return string متن نرمال‌شده.
	 */
	private function normalize_text( $text ) {
		$text = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $text );
		$text = str_replace( "\u{200c}", ' ', $text ); // نیم‌فاصله → فاصله
		$text = mb_strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * مترادف‌های فارسی برای گسترش جست‌وجو.
	 * کلید: کلمه‌ای که کاربر ممکن است بگوید → مقادیر: معادل‌های رایج در نام محصولات.
	 *
	 * @return array
	 */
	private function get_synonyms() {
		return array(
			'گوشی'     => array( 'موبایل', 'تلفن همراه', 'اسمارت فون' ),
			'موبایل'    => array( 'گوشی', 'تلفن همراه' ),
			'هدفون'    => array( 'هندزفری', 'ایربادز', 'ایرپادز' ),
			'هندزفری'  => array( 'هدفون', 'ایربادز', 'ایرپادز' ),
			'لپتاپ'    => array( 'لپ تاپ', 'نوت بوک', 'رایانه همراه' ),
			'کیف'      => array( 'کیف دستی', 'ساک' ),
			'کفش'      => array( 'کتونی', 'کتانی', 'اسنیکرز' ),
			'ساعت'     => array( 'ساعت مچی', 'اسمارت واچ' ),
			'تلویزیون' => array( 'تی وی', 'تلوزیون' ),
			'یخچال'    => array( 'فریزر' ),
			'پاوربانک' => array( 'پاور بانک', 'شارژر همراه' ),
			'شارژر'    => array( 'آداپتور' ),
			'ماوس'     => array( 'موس' ),
			'کیبورد'   => array( 'صفحه کلید' ),
			'مانیتور'  => array( 'نمایشگر' ),
			'دوربین'   => array( 'کمیرا' ),
			'عینک'     => array( 'عینک آفتابی' ),
			'لباس'     => array( 'پوشاک', 'تیشرت', 'پیراهن' ),
			'شلوار'    => array( 'جین' ),
		);
	}

	/**
	 * کلمات توقف فارسی — در امتیازدهی نادیده گرفته می‌شوند.
	 *
	 * @return array
	 */
	private function get_stop_words() {
		return array(
			'از', 'به', 'در', 'با', 'برای', 'که', 'این', 'آن', 'یک', 'یه',
			'را', 'و', 'یا', 'هم', 'نیز', 'تا', 'روی', 'زیر', 'بین',
			'میخوام', 'می‌خوام', 'میخواهم', 'می‌خواهم', 'دارید', 'داره',
			'هست', 'هستش', 'باشه', 'چیه', 'چیه؟', 'کدوم', 'چند',
			'دونه', 'مدل', 'نوع', 'مارک', 'برند',
		);
	}

	/**
	 * گروه مترادف‌های دومسیرهٔ یک کلمه: خود کلمه + همهٔ معادل‌هایش.
	 * ایندکس معکوس روی get_synonyms می‌سازد تا مثلاً «کتونی» هم به «کفش» برسد.
	 *
	 * @param string $word کلمه.
	 * @return array معادل‌ها (بدون خود کلمه).
	 */
	private function synonym_group( $word ) {
		$synonyms = $this->get_synonyms();
		$group    = array();
		foreach ( $synonyms as $key => $vals ) {
			$members = array_merge( array( $key ), $vals );
			if ( in_array( $word, $members, true ) ) {
				foreach ( $members as $m ) {
					if ( $m !== $word && ! in_array( $m, $group, true ) ) {
						$group[] = $m;
					}
				}
			}
		}
		return $group;
	}

	/**
	 * گسترش عبارت جست‌وجو با مترادف‌ها.
	 *
	 * @param string $query عبارت نرمال‌شده.
	 * @return array لیست عبارات برای جست‌وجو (اصلی + مترادف‌ها).
	 */
	private function expand_with_synonyms( $query ) {
		$queries   = array( $query );
		$words     = preg_split( '/\s+/u', $query );

		foreach ( $words as $word ) {
			foreach ( $this->synonym_group( $word ) as $syn ) {
				$expanded = str_replace( $word, $syn, $query );
				if ( ! in_array( $expanded, $queries, true ) ) {
					$queries[] = $expanded;
				}
			}
		}

		return $queries;
	}

	/**
	 * حذف کلمات توقف از عبارت.
	 *
	 * @param string $query عبارت نرمال‌شده.
	 * @return string عبارت بدون کلمات توقف.
	 */
	private function remove_stop_words( $query ) {
		$stop  = $this->get_stop_words();
		$words = preg_split( '/\s+/u', $query );
		$words = array_filter(
			$words,
			function ( $w ) use ( $stop ) {
				return ! in_array( $w, $stop, true ) && mb_strlen( $w ) > 1;
			}
		);
		return implode( ' ', $words );
	}

	/**
	 * محاسبه امتیاز تطابق محصول با عبارت جست‌وجو.
	 * دروازهٔ کلمهٔ اول: اگر کلمهٔ اول (یا مترادف دومسیره‌اش) در عنوان نباشد، امتیاز صفر.
	 * وزن‌ها: تطابق دقیق عنوان (۱۰۰) > شروع عنوان با کل عبارت (۸۰) > عبارت در عنوان (۶۰) >
	 * کلمه در ابتدای عنوان (۲۵) > کلمه در عنوان (۱۵) > کلمه در دسته‌بندی (۱۰) > شباهت تایپی (۸).
	 *
	 * @param array  $product خلاصه محصول.
	 * @param string $query   عبارت نرمال‌شده.
	 * @param array  $words   کلمات عبارت (بدون توقف).
	 * @return float امتیاز.
	 */
	private function score_product( $product, $query, $words ) {
		$score = 0;
		$name  = $this->normalize_text( $product['name'] );
		$cats  = $this->normalize_text( isset( $product['categories'] ) ? $product['categories'] : '' );

		// کلمه اول (نوع محصول) باید تو عنوان باشه، وگرنه بی‌ربطه.
		// مثلاً برای «کفش مردانه»، «کمربند چرم مردانه» نباید بیاد چون «کفش» توش نیست.
		$first_word = isset( $words[0] ) ? $words[0] : '';
		if ( '' !== $first_word && false === mb_strpos( $name, $first_word ) ) {
			// بررسی مترادف‌های کلمه اول (دومسیره؛ مثلاً «کتونی» → «کفش»).
			$found_syn = false;
			foreach ( $this->synonym_group( $first_word ) as $syn ) {
				if ( false !== mb_strpos( $name, $this->normalize_text( $syn ) ) ) {
					$found_syn = true;
					break;
				}
			}
			if ( ! $found_syn ) {
				return 0;
			}
		}

		// تطابق دقیق کل عبارت با عنوان.
		if ( $name === $query ) {
			$score += 100;
		} elseif ( 0 === mb_strpos( $name, $query ) ) {
			// عبارت در ابتدای عنوان.
			$score += 80;
		} elseif ( false !== mb_strpos( $name, $query ) ) {
			// عبارت جایی در عنوان.
			$score += 60;
		}

		// امتیاز هر کلمه.
		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			if ( 0 === mb_strpos( $name, $word ) ) {
				$score += 25; // کلمه در ابتدای عنوان.
			} elseif ( false !== mb_strpos( $name, $word ) ) {
				$score += 15; // کلمه جایی در عنوان.
			} elseif ( false !== mb_strpos( $cats, $word ) ) {
				$score += 10; // کلمه در دسته‌بندی.
			} else {
				// تحمل تایپی با Levenshtein روی کلمات عنوان.
				$name_words = preg_split( '/\s+/u', $name );
				foreach ( $name_words as $nw ) {
					$dist = $this->levenshtein_utf8( $word, $nw );
					$len  = max( mb_strlen( $word ), mb_strlen( $nw ) );
					if ( $len > 0 && $dist <= 2 && $dist / $len < 0.35 ) {
						$score += 8; // شباهت تایپی.
						break;
					}
				}
			}
		}

		return $score;
	}

	/**
	 * فاصله Levenshtein برای رشته‌های UTF-8 (فارسی).
	 *
	 * @param string $s1 رشته اول.
	 * @param string $s2 رشته دوم.
	 * @return int فاصله.
	 */
	private function levenshtein_utf8( $s1, $s2 ) {
		$a = preg_split( '//u', $s1, -1, PREG_SPLIT_NO_EMPTY );
		$b = preg_split( '//u', $s2, -1, PREG_SPLIT_NO_EMPTY );
		$la = count( $a );
		$lb = count( $b );
		if ( 0 === $la ) {
			return $lb;
		}
		if ( 0 === $lb ) {
			return $la;
		}
		// بهینه‌سازی حافظه: فقط دو ردیف نگه می‌داریم.
		$prev = range( 0, $lb );
		for ( $i = 1; $i <= $la; $i++ ) {
			$curr    = array( $i );
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost    = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$curr[ $j ] = min(
					$prev[ $j ] + 1,      // حذف
					$curr[ $j - 1 ] + 1,  // درج
					$prev[ $j - 1 ] + $cost // جایگزینی
				);
			}
			$prev = $curr;
		}
		return $prev[ $lb ];
	}

	/**
	 * جزئیات کامل یک محصول.
	 *
	 * @param int $product_id شناسهٔ محصول.
	 * @return array|null null اگر محصول معتبر/قابل خرید نباشد.
	 */
	public function get_details( $product_id ) {
		if ( ! $this->is_available() ) {
			return null;
		}
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		if ( 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
			return null;
		}

		$details = $this->summarize( $product );
		$details['description'] = wp_strip_all_tags( $product->get_short_description() );

		if ( $product->is_type( 'variable' ) ) {
			$details['variations'] = $this->variation_summary( $product );
		}

		return $details;
	}

	/**
	 * اسنپ‌شات فشردهٔ کاتالوگ برای تزریق به کانتکست مدل.
	 * عمداً کوتاه است تا توکن هدر نرود.
	 *
	 * @param int $limit حداکثر تعداد محصول.
	 * @return string متن فشردهٔ کاتالوگ.
	 */
	public function snapshot( $limit = 12 ) {
		$products = $this->search( '', array( 'limit' => max( 1, min( 20, (int) $limit ) ) ) );
		if ( empty( $products ) ) {
			return 'کاتالوگ فروشگاه در حال حاضر محصولی ندارد.';
		}

		$lines   = array();
		$lines[] = 'دادهٔ زندهٔ کاتالوگ فروشگاه (' . count( $products ) . ' محصول):';
		foreach ( $products as $p ) {
			$lines[] = sprintf(
				'#%d | %s | %s | %s | %s',
				$p['id'],
				$p['name'],
				$p['price_text'],
				$p['in_stock'] ? 'موجود' : 'ناموجود',
				$p['categories']
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * خلاصهٔ یک محصول برای مصرف مدل.
	 *
	 * @param \WC_Product $product محصول.
	 * @return array
	 */
	private function summarize( $product ) {
		$price = $product->get_price();
		$cats  = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$short = wp_strip_all_tags( $product->get_short_description() );

		// کراس‌سل: محصولات مکمل تعریف‌شده در ووکامرس.
		$cross_sell_ids = $product->get_cross_sell_ids();
		$cross_sell     = array();
		if ( ! empty( $cross_sell_ids ) && is_array( $cross_sell_ids ) ) {
			foreach ( array_slice( $cross_sell_ids, 0, 3 ) as $cs_id ) {
				$cs_product = wc_get_product( $cs_id );
				if ( $cs_product && $cs_product->is_purchasable() && $cs_product->is_in_stock() ) {
					$cross_sell[] = array(
						'id'         => $cs_product->get_id(),
						'name'       => $cs_product->get_name(),
						'price_text' => $this->format_price( $cs_product->get_price() ),
					);
				}
			}
		}

		// پرفروش: بر اساس تعداد فروش ووکامرس.
		$total_sales = (int) $product->get_total_sales();

		// امتیاز و تعداد نظر برای سوشال پروف (قانون ۳۶).
		$rating       = (float) $product->get_average_rating();
		$rating_count = (int) $product->get_rating_count();

		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'type'          => $product->get_type(),
			'price'         => '' === $price ? null : (float) $price,
			'price_text'    => $this->format_price( $price ),
			'regular_price' => $this->format_price( $product->get_regular_price() ),
			'sale_price'    => $product->is_on_sale() ? $this->format_price( $product->get_sale_price() ) : null,
			'on_sale'       => $product->is_on_sale(),
			'in_stock'      => $product->is_in_stock(),
			'stock_qty'     => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'url'           => $product->get_permalink(),
			'image'         => $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
			'categories'    => is_array( $cats ) ? implode( '، ', $cats ) : '',
			'short_desc'    => mb_substr( $short, 0, 200 ),
			'is_bestseller' => $total_sales >= 10,
			'cross_sell'    => $cross_sell,
			'rating'        => $rating,
			'rating_count'  => $rating_count,
		);
	}

	/**
	 * خلاصهٔ تنوع‌های یک محصول متغیر (حداکثر ۱۰ تنوع).
	 *
	 * @param \WC_Product_Variable $product محصول متغیر.
	 * @return array
	 */
	private function variation_summary( $product ) {
		$out        = array();
		$variations = $product->get_available_variations();
		$count      = 0;
		foreach ( $variations as $v ) {
			if ( $count >= 10 ) {
				break;
			}
			$attrs = array();
			if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
				foreach ( $v['attributes'] as $name => $value ) {
					$attrs[] = str_replace( 'attribute_', '', $name ) . ': ' . $value;
				}
			}
			$out[] = array(
				'variation_id' => isset( $v['variation_id'] ) ? (int) $v['variation_id'] : 0,
				'attributes'   => implode( '، ', $attrs ),
				'price_text'   => $this->format_price( isset( $v['display_price'] ) ? $v['display_price'] : '' ),
				'in_stock'     => ! empty( $v['is_in_stock'] ),
			);
			$count++;
		}
		return $out;
	}

	/**
	 * قالب‌بندی قیمت به تومان/واحد فروشگاه.
	 *
	 * @param mixed $price قیمت خام.
	 * @return string
	 */
	private function format_price( $price ) {
		if ( '' === $price || null === $price ) {
			return '—';
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		if ( 'IRT' === $currency ) {
			$unit = 'تومان';
		} elseif ( 'IRR' === $currency ) {
			$unit = 'ریال';
		} else {
			$unit = $currency;
		}
		// خروجی فقط قالب‌بندی/نمایش است؛ محاسبات داخلی لاتین می‌مانند.
		$formatted = number_format_i18n( (float) $price );
		return strtr( $formatted, array(
			'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
			'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
		) ) . ' ' . $unit;
	}

	/**
	 * آیا ووکامرس و توابع کاتالوگ در دسترس‌اند؟
	 *
	 * @return bool
	 */
	private function is_available() {
		return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
	}
}
