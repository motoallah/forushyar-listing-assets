<?php
/**
 * REST API امن چت — forushyar/v1/chat
 *
 * امنیت: دو مسیر مجاز — (۱) مدیریت/تست: کاربر لاگین‌کرده با capability و nonce استاندارد؛
 * (۲) ویجت فرانت‌اند: مهمان‌ها با nonce مخصوص ویجت + rate limit جداگانهٔ هر سشن.
 *
 * @package ForushYar
 */

namespace ForushYar;

// جلوگیری از دسترسی مستقیم.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس REST
 */
class REST {

	/**
	 * نام آپشن لاگ گفتگوها.
	 */
	const LOG_OPTION = 'forushyar_ai_chat_logs';

	/**
	 * سقف لاگ نگه‌داری‌شده.
	 */
	const LOG_LIMIT = 100;

	/**
	 * سقف پیام در دقیقه برای هر سشن.
	 */
	const RATE_LIMIT = 20;

	/**
	 * سقف نگه‌داری لیدها (D-B1): آرایهٔ لیدها در آپشن forushyar_ai_leads
	 * روی هر افزودن هرس می‌شود تا آپشن بی‌نهایت رشد نکند و خواندن/نوشتنش
	 * به‌مرور سنگین نشود. فقط تازه‌ترین‌ها می‌مانند (افزودن در انتهای
	 * آرایه است و نمایش داشبورد هم از انتها برش می‌زند، پس ترتیب معنایی
	 * عوض نمی‌شود). سقف روی «افزودن» اعمال می‌شود، نه روی toggle/export.
	 */
	const LEADS_LIMIT = 200;

	/**
	 * ثبت هوک.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// وقتی کاربر لاگین است ولی هدر X-WP-Nonce نفرستاده، احراز هویت کوکیِ
		// هستهٔ وردپرس درخواست را قبل از permission_callback رد می‌کند.
		// برای مسیر چت ما، تصمیم دسترسی با permission_callback خودمان است.
		add_filter( 'rest_authentication_errors', array( $this, 'bypass_cookie_auth_for_chat' ), 20 );
	}

	/**
	 * عبور از خطای احراز هویت کوکی برای مسیر چت.
	 *
	 * @param mixed $result نتیجهٔ احراز هویت.
	 * @return mixed
	 */
	public function bypass_cookie_auth_for_chat( $result ) {
		if ( true === $result || is_wp_error( $result ) ) {
			// فقط وقتی خطا از نوع کوکی/نانس است و مسیر مال ماست دخالت می‌کنیم.
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$code = $result->get_error_code();
			if ( ! in_array( $code, array( 'rest_forbidden', 'rest_cookie_invalid_nonce' ), true ) ) {
				return $result;
			}
		}
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) ) {
			$route = (string) $_GET['rest_route'];
		}
		if ( 0 === strpos( $route, '/forushyar/v1/chat' ) ) {
			return true;
		}
		return $result;
	}

	/**
	 * ثبت مسیرها.
	 */
	public function register_routes() {
		register_rest_route(
			'forushyar/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
		register_rest_route(
			'forushyar/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_feedback' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * تشخیص اینکه آیا پیام کاربر درباره سبد خرید است.
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_recommendation_query( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		$msg = preg_replace( '/\s+/', ' ', $msg );
		// ارجاع صریح به خریدهای قبلی.
		foreach ( array( 'خریدهای قبلی', 'خرید قبلی', 'خریدهای من', 'قبلا خریدم', 'قبلاً خریدم' ) as $p ) {
			if ( false !== mb_strpos( $msg, $p ) ) {
				return true;
			}
		}
		// درخواست پیشنهاد «خالی»: بعد از حذف واژه‌های پیشنهاد، چیزی نماند.
		// («چی پیشنهاد می‌دی؟» → بله؛ «گوشی پیشنهاد بده» → نه، چون «گوشی» می‌ماند)
		$bare = preg_replace( '/پیشنهاد|پیشنهادت|بده|بدید|بدم|می‌دی|میدی|چی|چه|چیز|بخرم|کن|کنید|لطفا|لطفاً|\?|؟/u', ' ', $msg );
		$bare = trim( preg_replace( '/\s+/', ' ', $bare ) );
		if ( mb_strlen( $bare ) < 2 && false !== mb_strpos( $msg, 'پیشنهاد' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * تشخیص «سفارش‌های من / سفارش‌هایم».
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_my_orders_query( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		foreach ( array( 'سفارش‌های من', 'سفارشهای من', 'سفارشات من', 'سفارش‌هایم', 'سفارشهایم', 'سفارشاتم' ) as $p ) {
			if ( false !== mb_strpos( $msg, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * فهرست سفارش‌های مشتری لاگین‌کرده — deterministic.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response
	 */
	private function handle_my_orders_query( $message, $session_key ) {
		$tools  = array( array( 'name' => 'my_orders', 'ok' => false ) );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$reply = 'برای دیدن سفارش‌ها لطفاً اول وارد حساب کاربری‌ات شو 🙂';
			$this->log_chat( $session_key, $message, $reply, $tools );
			return rest_ensure_response( array( 'reply' => $reply, 'products' => array(), 'tools' => $tools ) );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			$reply = 'متأسفانه در حال حاضر نمی‌تونم سفارش‌ها رو ببینم.';
			$this->log_chat( $session_key, $message, $reply, $tools );
			return rest_ensure_response( array( 'reply' => $reply, 'products' => array(), 'tools' => $tools ) );
		}
		$orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( empty( $orders ) ) {
			$reply = 'هنوز سفارشی ثبت نکردی 🙂 هر وقت خریدی انجام بدی، این‌جا می‌تونی پیگیریش کنی.';
			$this->log_chat( $session_key, $message, $reply, $tools );
			return rest_ensure_response( array( 'reply' => $reply, 'products' => array(), 'tools' => $tools ) );
		}
		$lines = array();
		foreach ( $orders as $o ) {
			$lines[] = '🧾 سفارش ' . $this->fa_digits( $o->get_order_number() )
				. ' — ' . self::coarse_order_status( $o->get_status() )
				. ' — ' . $this->fa_digits( number_format( (float) $o->get_total() ) ) . ' تومان';
		}
		$reply = "آخرین سفارش‌هات:\n" . implode( "\n", $lines );
		$tools[0]['ok'] = true;
		$this->log_chat( $session_key, $message, $reply, $tools );
		return rest_ensure_response( array( 'reply' => $reply, 'products' => array(), 'tools' => $tools ) );
	}

	/**
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_cart_query( $message ) {
		$patterns = array(
			'سبد',
			'سبدم',
			'سبد خرید',
			'سبدخرید',
		);
		foreach ( $patterns as $p ) {
			if ( false !== mb_strpos( $message, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * تطبیق کلمهٔ کامل (نه زیررشته) — مثل فیکس «تا» در parse_price_range.
	 *
	 * @param string $msg  پیام نرمال‌شده.
	 * @param string $word کلمه.
	 * @return bool
	 */
	private function has_word( $msg, $word ) {
		return (bool) preg_match( '/(^|[\s\p{P}])' . preg_quote( $word, '/' ) . '([\s\p{P}]|$)/iu', (string) $msg );
	}

	/**
	 * تشخیص نیت پیگیری سفارش (پارسا: P0 پشتیبانی).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_order_tracking_query( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		// افعال پیگیری به‌تنهایی کافی‌اند.
		foreach ( array( 'پیگیری', 'رهگیری', 'مرسوله' ) as $w ) {
			if ( $this->has_word( $msg, $w ) ) {
				return true;
			}
		}
		// «سفارشم کجاست» / «وضعیت سفارش» / «سفارش ۱۲۳۴».
		// «سفارش» به‌صورت زیررشته چک می‌شود تا پسوند ملکی (سفارشم/سفارشات) هم بگیرد.
		if ( false !== mb_strpos( $msg, 'سفارش' ) ) {
			if ( $this->has_word( $msg, 'کجاست' ) || $this->has_word( $msg, 'وضعیت' ) ) {
				return true;
			}
			$order_no = $this->extract_order_number( $message );
			if ( false !== $order_no && $this->is_plausible_order_number( $message, $order_no ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * آیا عدد استخراج‌شده واقعاً می‌تواند شماره سفارش باشد؟
	 * فیلتر مثبت‌کاذب‌ها: «۲ تا سفارش می‌خوام» (عدد قبل از «سفارش» = تعداد)
	 * و «سفارش ۵۰۰ تومنی» (عددِ قبل از واحد پولی = قیمت).
	 *
	 * @param string $message  پیام اصلی کاربر.
	 * @param int    $order_no شمارهٔ استخراج‌شده.
	 * @return bool
	 */
	private function is_plausible_order_number( $message, $order_no ) {
		$latin = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $message
		);
		$num = (string) (int) $order_no;
		// قیمت: عددی که واحد پولی دنبالش است، شماره سفارش نیست.
		if ( preg_match( '/' . preg_quote( $num, '/' ) . '\s*(تومان|تومن|ریال|هزار|میلیون|میلیارد)/u', $latin ) ) {
			return false;
		}
		// تعداد: عددی که قبل از کلمهٔ «سفارش» آمده (مثل «۲ تا سفارش»).
		$pos_num  = mb_strpos( $latin, $num );
		$pos_word = mb_strpos( mb_strtolower( $latin ), 'سفارش' );
		if ( false !== $pos_num && false !== $pos_word && $pos_num < $pos_word ) {
			return false;
		}
		return true;
	}

	/**
	 * استخراج شماره سفارش از پیام (ارقام فارسی/عربی هم پشتیبانی می‌شوند).
	 *
	 * @param string $message پیام کاربر.
	 * @return int|false شماره سفارش یا false.
	 */
	private function extract_order_number( $message ) {
		$msg = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $message
		);
		if ( preg_match( '/#?\s*(\d{1,10})/', $msg, $m ) ) {
			$id = (int) $m[1];
			return $id > 0 ? $id : false;
		}
		return false;
	}

	/**
	 * ارقام لاتین به فارسی (برای نمایش شماره سفارش).
	 *
	 * @param mixed $n عدد.
	 * @return string
	 */
	private function fa_digits( $n ) {
		return str_replace(
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
			(string) $n
		);
	}

	/**
	 * پاسخ وضعیت سفارش — اطلاعات حساس (نام، ایمیل، آدرس، مبلغ) هرگز نمایش داده نمی‌شود.
	 *
	 * احراز مالکیت (سارا — چک نهایی P1): شمارهٔ سفارش ترتیبی و قابل حدس است، پس
	 * جزئیات کامل (از جمله کد پیگیری پست) فقط به مالک واقعی می‌رسد:
	 *  - کاربر لاگین‌کرده‌ای که سفارش مال خودش است؛ یا
	 *  - مهمانی که عامل دومِ معتبر بدهد: موبایل یا ایمیلِ صورتحسابِ همان سفارش
	 *    (پارامتر verify درخواست، یا همان اطلاعات داخل متن پیامش).
	 * مهمانِ بدون عامل دوم فقط وضعیت کلی (bucket) می‌گیرد — بدون کد پیگیری.
	 *
	 * @param int    $order_id    شماره سفارش.
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @param string $verify      عامل دوم احراز (اختیاری).
	 * @return \WP_REST_Response
	 */
	private function handle_order_status( $order_id, $message, $session_key, $verify = '' ) {
		// پاسخ قطعی است — شمارندهٔ پاسخ ناموفق ریست شود.
		delete_transient( 'fy_fail_count_' . $session_key );
		if ( ! function_exists( 'wc_get_order' ) ) {
			$reply = 'متأسفانه پیگیری سفارش در حال حاضر در دسترس نیست.';
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array(),
				)
			);
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			$reply = 'سفارشی با شمارهٔ ' . $this->fa_digits( $order_id ) . ' پیدا نکردم. لطفاً شماره را دوباره بررسی کنید.';
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$reply .= "\n" . 'از «حساب کاربری ← سفارش‌ها» هم می‌توانید پیگیری کنید: ' . wc_get_page_permalink( 'myaccount' );
			}
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array( array( 'name' => 'order_tracking', 'ok' => false ) ),
				)
			);
		}
		// دروازهٔ مالکیت: بدون احراز، فقط وضعیت کلی — کد پیگیری داده نمی‌شود.
		if ( ! $this->order_full_access( $order, $verify, $message ) ) {
			$coarse = self::coarse_order_status( $order->get_status() );
			$reply  = '🧾 سفارش ' . $this->fa_digits( $order_id ) . ' — وضعیت کلی: ' . $coarse . '.';
			$reply .= "\n" . 'برای دیدن کد پیگیری پست، شمارهٔ موبایل یا ایمیلی را که هنگام خرید ثبت کرده‌اید هم بفرستید تا هویت‌تان تأیید شود.';
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array( array( 'name' => 'order_tracking', 'ok' => true, 'detail' => 'coarse' ) ),
				)
			);
		}

		$status_slug = $order->get_status();
		$statuses    = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$label       = isset( $statuses[ 'wc-' . $status_slug ] ) ? $statuses[ 'wc-' . $status_slug ] : $status_slug;
		$reply       = '🧾 سفارش ' . $this->fa_digits( $order_id ) . ' — وضعیت: ' . $label . '.';
		// کد پیگیری پست — فقط اگر فروشگاه ثبت کرده باشد.
		foreach ( array( '_tracking_code', 'tracking_code' ) as $meta_key ) {
			$code = $order->get_meta( $meta_key );
			if ( is_string( $code ) && '' !== trim( $code ) ) {
				$reply .= "\n" . 'کد پیگیری پست: ' . sanitize_text_field( $code );
				break;
			}
		}
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'order_tracking', 'ok' => true ) ),
			)
		);
	}

	/**
	 * آیا درخواست‌کننده مالک واقعی سفارش است و جزئیات کامل (کد پیگیری) می‌گیرد؟
	 *
	 * مسیرها: (۱) کاربر لاگین‌کرده و سفارش برای خودش است؛ (۲) عامل دوم (verify
	 * یا اطلاعات داخل پیام) با ایمیل صورتحساب (مقایسهٔ case-insensitive) یا با
	 * موبایل صورتحساب (مقایسهٔ ارقام نرمال‌شده) می‌خواند. مقایسه‌ها با
	 * hash_equals انجام می‌شود تا زمان‌بندیِ پاسخ، اطلاعات لو ندهد.
	 *
	 * @param \WC_Order $order   سفارش.
	 * @param string    $verify  عامل دومِ صریح (پارامتر درخواست).
	 * @param string    $message متن پیام کاربر (منبع جایگزین عامل دوم).
	 * @return bool
	 */
	private function order_full_access( $order, $verify, $message ) {
		// مالک لاگین‌کرده: دسترسی کامل به سفارش خودش.
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'get_current_user_id' ) ) {
			$uid         = (int) get_current_user_id();
			$customer_id = (int) $order->get_customer_id();
			if ( $uid > 0 && $customer_id > 0 && $uid === $customer_id ) {
				return true;
			}
		}

		$candidate = trim( (string) $verify );
		if ( '' === $candidate ) {
			$candidate = self::extract_verify_candidate( (string) $message );
		}
		if ( '' === $candidate ) {
			return false;
		}

		// ایمیل صورتحساب.
		$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $billing_email && hash_equals( $billing_email, strtolower( $candidate ) ) ) {
			return true;
		}

		// موبایل صورتحساب (ارقام نرمال‌شده؛ مقایسهٔ کامل و ۱۰ رقم پایانی).
		$cand_phone    = self::normalize_phone_digits( $candidate );
		$billing_phone = self::normalize_phone_digits( (string) $order->get_billing_phone() );
		if ( strlen( $cand_phone ) >= 10 && strlen( $billing_phone ) >= 10 ) {
			if ( hash_equals( $billing_phone, $cand_phone ) ) {
				return true;
			}
			if ( hash_equals( substr( $billing_phone, -10 ), substr( $cand_phone, -10 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * استخراج عامل دوم از متن پیام (وقتی verify صریح نیامده): اول ایمیل، بعد
	 * توالی ارقامِ شبیه شماره موبایل (با ارقام فارسی/عربی هم کار می‌کند).
	 *
	 * @param string $message متن پیام.
	 * @return string کاندید یا رشتهٔ خالی.
	 */
	public static function extract_verify_candidate( $message ) {
		if ( preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) $message, $m ) ) {
			return $m[0];
		}
		// متن پیام ممکن است شماره سفارش را هم داشته باشد؛ فقط وقتی به‌عنوان موبایل
		// قبولش می‌کنیم که یک توالی ۱۰ تا ۱۵ رقمیِ پیوسته در پیام باشد.
		if ( preg_match( '/[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}][0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}\s\-]{9,17}/u', (string) $message, $m2 ) ) {
			$seq = self::normalize_phone_digits( $m2[0] );
			if ( strlen( $seq ) >= 10 && strlen( $seq ) <= 15 ) {
				return $m2[0];
			}
		}
		return '';
	}

	/**
	 * نرمال‌سازی شماره تلفن به ارقام لاتینِ پیوسته با پیش‌شمارهٔ داخلی ۰:
	 * ارقام فارسی/عربی → لاتین؛ حذف همهٔ نویسه‌های غیررقمی؛ ۰۰۹۸ و ۹۸ اول → ۰.
	 *
	 * @param string $raw ورودی خام.
	 * @return string فقط ارقام.
	 */
	public static function normalize_phone_digits( $raw ) {
		$s = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $raw
		);
		$s = preg_replace( '/\D+/', '', $s );
		if ( 0 === strpos( $s, '0098' ) ) {
			$s = '0' . substr( $s, 4 );
		} elseif ( 0 === strpos( $s, '98' ) && 12 === strlen( $s ) ) {
			$s = '0' . substr( $s, 2 );
		}
		return $s;
	}

	/**
	 * نگاشت وضعیت دقیق سفارش به وضعیت کلی (برای مهمانِ احرازنشده) — عمداً
	 * جزئیات (برچسب دقیق فروشگاه، کد پیگیری) را لو نمی‌دهد.
	 *
	 * @param string $status_slug اسلاگ وضعیت ووکامرس.
	 * @return string برچسب کلی فارسی.
	 */
	public static function coarse_order_status( $status_slug ) {
		switch ( (string) $status_slug ) {
			case 'pending':
				return 'ثبت شده';
			case 'on-hold':
			case 'processing':
				return 'در حال انجام';
			case 'completed':
				return 'تکمیل شده';
			case 'cancelled':
			case 'refunded':
			case 'failed':
				return 'پایان‌یافته';
			default:
				return 'در حال انجام';
		}
	}

	/**
	 * هرس آرایهٔ لیدها تا سقف نگه‌داری (D-B1) — تازه‌ترین‌ها می‌مانند.
	 *
	 * نکتهٔ هم‌زمانی: هر سه نویسندهٔ این آپشن (دو مسیر چت + تاگل ادمین)
	 * از Settings::atomic_option_update با قفل نام‌دار MySQL استفاده
	 * می‌کنند؛ read-modify-write دیگر زیر بار هم‌زمان لید گم نمی‌کند.
	 *
	 * @param mixed $leads آرایهٔ لیدها.
	 * @return array حداکثر LEADS_LIMIT عضوِ آخر.
	 */
	public static function prune_leads( $leads ) {
		if ( ! is_array( $leads ) ) {
			return array();
		}
		if ( count( $leads ) > self::LEADS_LIMIT ) {
			$leads = array_slice( $leads, -self::LEADS_LIMIT );
		}
		return array_values( $leads );
	}

	/**
	 * تشخیص نیت مرجوعی کالا (پارسا: P1 پشتیبانی).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_return_query( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		foreach ( array( 'مرجوع', 'عودت' ) as $w ) {
			if ( false !== mb_strpos( $msg, $w ) ) {
				return true;
			}
		}
		if ( false !== mb_strpos( $msg, 'بازگشت' ) && false !== mb_strpos( $msg, 'کالا' ) ) {
			return true;
		}
		if ( false !== mb_strpos( $msg, 'برگرداندن' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * جواب ثابت مرجوعی قدم‌به‌قدم — یکدست و بدون توهم مدل.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response
	 */
	private function handle_return_query( $message, $session_key ) {
		// پاسخ قطعی است — شمارندهٔ پاسخ ناموفق ریست شود.
		delete_transient( 'fy_fail_count_' . $session_key );
		// نگار: اگر مدیر فروشگاه سیاست مرجوعی خودش را در تنظیمات نوشته، همان برنده است؛
		// وگرنه متن پیش‌فرض محصول. این دوگانگی «دانش فروشگاه در برابر پاسخ قطعی» را می‌بندد.
		$custom_policy = trim( (string) Settings::get( 'returns_policy', '' ) );
		$reply         = '' !== $custom_policy ? $custom_policy : "شرایط مرجوعی فروشگاه 📦\n\n"
			. "✅ تا ۷ روز بعد از تحویل، کالای سالم و استفاده‌نشده (با بسته‌بندی و فاکتور)\n"
			. "❌ کالای استفاده‌شده، آسیب‌دیده یا سفارشی‌سازی‌شده مرجوع نمی‌شود\n\n"
			. "قدم‌به‌قدم:\n"
			. "۱. از «حساب کاربری ← سفارش‌ها» درخواست مرجوعی ثبت کنید\n"
			. "۲. بعد از تأیید، کالا را با پست پیشتاز به آدرس اعلام‌شده بفرستید\n"
			. "۳. پس از بررسی سلامت کالا، مبلغ ظرف ۷۲ ساعت کاری به شما برمی‌گردد\n\n"
			. "💡 هزینه ارسال برگشت با مشتری است، مگر کالا معیوب یا مغایر باشد که آن‌وقت با فروشگاه است.";
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'return_policy', 'ok' => true ) ),
			)
		);
	}

	/**
	 * تشخیص درخواست مستقیم پشتیبانی انسانی (پارسا: P1).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_handoff_request( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		foreach ( array( 'اپراتور', 'کارشناس', 'نماینده' ) as $w ) {
			if ( false !== mb_strpos( $msg, $w ) ) {
				return true;
			}
		}
		// کلمهٔ کامل تا «مسئولیت» و امثال آن مثبت کاذب نشوند (پارسا: الحاقیه A-1 —
		// پوشش قبلی باریک بود و «پشتیبانی»/«آدم» را نمی‌گرفت).
		foreach ( array( 'انسان', 'انسانی', 'پشتیبانی', 'آدم', 'مسئول' ) as $w ) {
			if ( $this->has_word( $msg, $w ) ) {
				return true;
			}
		}
		if ( false !== mb_strpos( $msg, 'تلفنی' ) && false !== mb_strpos( $msg, 'صحبت' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * ایمیل پشتیبانی فروشگاه — از تنظیمات، وگرنه ایمیل مدیر وردپرس.
	 * (هم‌منطق با Chat::support_email برای یکدستی مسیر انسانی.)
	 *
	 * @return string
	 */
	private function support_email() {
		$email = sanitize_email( (string) Settings::get( 'support_email', '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = get_option( 'admin_email', '' );
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * شروع مسیر فرار به پشتیبانی انسانی — درخواست نام و شماره تماس.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response
	 */
	private function start_handoff( $message, $session_key ) {
		set_transient( 'fy_handoff_' . $session_key, 1, 30 * MINUTE_IN_SECONDS );
		$reply = 'مطمئناً! 🙏 لطفاً نام و شماره تماس‌تان را در یک پیام بنویسید تا همکاران پشتیبانی با شما تماس بگیرند.';
		// ایمیل پشتیبانی هم همان لحظه داده شود تا مشتری فقط منتظر تماس نماند.
		$support = $this->support_email();
		if ( '' !== $support ) {
			$reply .= "\n" . 'یا اگر ترجیح می‌دهید، مستقیم به ایمیل پشتیبانی بنویسید: ' . $support;
		}
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'human_handoff', 'ok' => true ) ),
			)
		);
	}

	/**
	 * تعدیل محتوای پیام: فحاشی → جواب مؤدبانه و کوتاه؛ هذیان → درخواست شفاف‌سازی.
	 * deterministic است (به مدل اتکا ندارد) تا دستیار هرگز با توهین جواب ندهد
	 * یا وارد بحث بی‌ربط نشود. در صورت تمیز بودن پیام false برمی‌گرداند.
	 *
	 * @param string $message پیام کاربر.
	 * @return string|false متن آمادهٔ پاسخ یا false.
	 */
	private function moderate_message( $message ) {
		$msg = str_replace( array( 'ي', 'ك', "‌" ), array( 'ی', 'ک', '' ), (string) $message );
		if ( $this->contains_profanity( $msg ) ) {
			return 'با این لحن نمی‌تونم کمکتون کنم 🙏 اگه سؤالی دربارهٔ محصولات یا سفارشتون دارید، در خدمتم.';
		}
		if ( $this->is_gibberish( $msg ) ) {
			return 'متوجه منظورتون نشدم 😅 دنبال چه محصولی می‌گردید؟';
		}
		return false;
	}

	/**
	 * فهرست فحش‌های رایج فارسی — فقط موارد واضح و پرتکرار.
	 * تطبیق با مرز واژه است تا «خر» داخل «خرید» یا «آخر» خطا ندهد.
	 *
	 * @return array
	 */
	private function profanity_list() {
		return array(
			'احمق', 'خنگ', 'کودن', 'بیشعور', 'بی‌شعور',
			'لعنتی', 'کسخل', 'جاکش', 'دیوث', 'قرمساق',
			'حرومزاده', 'حروم‌زاده', 'بیناموس', 'بی‌ناموس', 'مادرجنده',
			'پفیوز', 'الدنگ', 'عوضی', 'گوساله', 'الاغ', 'نفهم', 'ابله',
		);
	}

	/**
	 * آیا پیام فحاشی دارد؟
	 *
	 * @param string $msg پیام نرمال‌شده.
	 * @return bool
	 */
	private function contains_profanity( $msg ) {
		foreach ( $this->profanity_list() as $w ) {
			$w = str_replace( array( 'ي', 'ك', "‌" ), array( 'ی', 'ک', '' ), $w );
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', $msg ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * آیا پیام هذیان است؟ (بدون هیچ توکن حرفی، یا تکرار افراطی یک کاراکتر)
	 *
	 * @param string $msg پیام نرمال‌شده.
	 * @return bool
	 */
	private function is_gibberish( $msg ) {
		$t = trim( (string) $msg );
		if ( mb_strlen( $t ) < 2 ) {
			return true;
		}
		// هیچ توکن حرفیِ ۲+ حرفی نداشت (فقط ایموجی/عدد/نماد).
		if ( ! preg_match( '/\p{L}{2,}/u', $t ) ) {
			return true;
		}
		// تکرار یک کاراکتر ۷+ بار پشت سر هم («ااااااا»).
		if ( preg_match( '/(.)\1{6,}/u', $t ) ) {
			return true;
		}
		// حروف‌سوخاری لاتین («asdfghj»): فقط لاتین، بدون فاصله، بدون هیچ مصوتی.
		if ( preg_match( '/^[a-zA-Z]+$/', str_replace( ' ', '', $t ) ) ) {
			$latin = strtolower( str_replace( ' ', '', $t ) );
			if ( mb_strlen( $latin ) >= 4 && ! preg_match( '/[aeiou]/', $latin ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * استخراج شماره موبایل ایرانی از پیام (ارقام فارسی/عربی هم پشتیبانی می‌شوند).
	 *
	 * @param string $message پیام کاربر.
	 * @return string|false شماره نرمال‌شده یا false.
	 */
	private function extract_phone( $message ) {
		$msg = str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', ' ', '-', '‌', '.' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', '', '', '' ),
			(string) $message
		);
		if ( preg_match( '/09\d{9}/', $msg, $m ) ) {
			return $m[0];
		}
		return false;
	}

	/**
	 * دریافت مشخصات تماس برای پشتیبانی انسانی و ذخیره در لیدها.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response|false پاسخ، یا false اگر پیام سؤال تازه بود (نه اطلاعات تماس).
	 */
	private function handle_handoff_details( $message, $session_key ) {
		$msg   = trim( (string) $message );
		$lower = mb_strtolower( $msg );
		// انصراف.
		if ( 'نه' === $lower ) {
			delete_transient( 'fy_handoff_' . $session_key );
			$reply = 'باشه، من اینجام! 😊 سؤال دیگری دارید در خدمتم.';
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array(),
				)
			);
		}
		foreach ( array( 'بیخیال', 'نمیخوام', 'نمی‌خوام', 'نه ممنون', 'صرف نظر', 'صرفنظر' ) as $w ) {
			if ( false !== mb_strpos( $lower, $w ) ) {
				delete_transient( 'fy_handoff_' . $session_key );
				$reply = 'باشه، من اینجام! 😊 سؤال دیگری دارید در خدمتم.';
				$this->log_chat( $session_key, $message, $reply, array() );
				return rest_ensure_response(
					array(
						'reply'    => $reply,
						'products' => array(),
						'tools'    => array(),
					)
				);
			}
		}
		// تأیید کوتاه بدون مشخصات → دوباره مشخصات بخواه.
		if ( in_array( $lower, array( 'بله', 'آره', 'اره', 'باشه', 'اوکی', 'بفرست', 'بفرستید' ), true ) ) {
			$reply = 'حتماً! نام و شماره تماس‌تان را در یک پیام بنویسید. 📝';
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array(),
				)
			);
		}
		// راه فرار (پارسا): اگر پیام هیچ رقمی ندارد، اطلاعات تماس نیست —
		// یعنی کاربر سؤال تازه‌ای پرسیده («ساعت»، «تلفن»…). از حالت هندآف
		// خارج شو و بگذار مسیر عادی جواب بدهد. بدون این، حلقهٔ «شماره پیدا نکردم»
		// هر سؤال تازه را گروگان می‌گرفت.
		if ( ! preg_match( '/[0-9۰-۹٠-٩]/u', $msg ) ) {
			delete_transient( 'fy_handoff_' . $session_key );
			return false;
		}
		$phone = $this->extract_phone( $msg );
		if ( false === $phone ) {
			$reply = 'شماره تماسی در پیام‌تان پیدا نکردم. لطفاً نام و شماره موبایل‌تان (مثل ۰۹۱۲۳۴۵۶۷۸۹) را بنویسید. 📱';
			$this->log_chat( $session_key, $message, $reply, array() );
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array(),
				)
			);
		}
		// نام = متن پیام بدون شماره تماس.
		// شماره ممکن است با جداکننده (فاصله/خط‌تیره/نقطه) و ارقام فارسی نوشته
		// شده باشد («۰۹۱۲-۳۴۵۶۷۸۹»)؛ پس با regex حذفش می‌کنیم نه str_replace ساده.
		$digit_class = '[0-9۰-۹٠-٩]';
		$sep_class   = '[\s\-\.‌]*';
		$name = trim(
			(string) preg_replace(
				'/(?<!' . $digit_class . ')' . implode( $sep_class, array_fill( 0, 11, $digit_class ) ) . '(?!' . $digit_class . ')/u',
				'',
				$msg,
				1
			)
		);
		$name = mb_substr( sanitize_text_field( $name ), 0, 60 );
		$new_lead = array(
			'id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lead_', true ),
			'name'    => $name,
			'phone'   => $phone,
			'type'    => 'support_request',
			'handled' => false,
			'time'    => current_time( 'mysql' ),
			'session' => $session_key,
		);
		// سارا: افزودن اتمیک با قفل نام‌دار MySQL — دو درخواست هم‌زمان دیگر لید هم را بازنویسی نمی‌کنند.
		Settings::atomic_option_update(
			'forushyar_ai_leads',
			function ( $leads ) use ( $new_lead ) {
				if ( ! is_array( $leads ) ) {
					$leads = array();
				}
				$leads[] = $new_lead;
				return self::prune_leads( $leads ); // سقف نگه‌داری (D-B1).
			}
		);
		// اعلان فوری به ایمیل پشتیبانی — لید نباید بی‌صدا فقط در آپشن بماند؛
		// مشتری شنیده «تماس می‌گیریم» و یک انسان باید واقعاً خبردار شود (پارسا: P1-3).
		$support = $this->support_email();
		if ( '' !== $support ) {
			$shop_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			wp_mail(
				$support,
				'درخواست پشتیبانی انسانی جدید' . ( '' !== $shop_name ? ' — ' . $shop_name : '' ),
				"یک مشتری از طریق چت فروشگاه درخواست تماس پشتیبانی ثبت کرد.\n\n"
				. 'نام: ' . ( '' !== $name ? $name : '—' ) . "\n"
				. 'شماره تماس: ' . $phone . "\n"
				. 'زمان: ' . current_time( 'mysql' )
			);
		}
		delete_transient( 'fy_handoff_' . $session_key );
		delete_transient( 'fy_fail_count_' . $session_key );
		$reply = '✅ ثبت شد' . ( '' !== $name ? ' ' . $name . ' عزیز' : '' ) . '! همکاران پشتیبانی به‌زودی با شما تماس می‌گیرند. 🙏';
		$this->log_chat( $session_key, $message, $reply, array( array( 'name' => 'human_handoff', 'ok' => true ) ) );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'human_handoff', 'ok' => true ) ),
			)
		);
	}

	/**
	 * افزودن یادداشت کوپن خوش‌آمد به پاسخ (فقط چت اول هر سشن).
	 * کوپن در ابتدای handle_chat روی سبد اعمال می‌شود، ولی مسیرهای intent
	 * زود برمی‌گردند؛ این متد یادداشت را به همهٔ پاسخ‌های موفق اضافه می‌کند
	 * تا مشتری از تخفیفش باخبر شود.
	 *
	 * @param \WP_REST_Response|\WP_Error $response پاسخ آماده.
	 * @param string                      $note     یادداشت (خالی = کاری نکن).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function with_welcome_note( $response, $note ) {
		if ( '' !== $note && $response instanceof \WP_REST_Response && isset( $response->data['reply'] ) && is_string( $response->data['reply'] ) ) {
			$response->data['reply'] .= $note;
		}
		// بج سبد هدر (امیر): تعداد فعلی سبد همیشه همراه پاسخ می‌آید تا ویجت
		// بج را بدون رفرش صفحه آپدیت کند — چون wc-cart-fragments روی لندینگ
		// لود نیست و trigger آن بی‌اثر است.
		if ( $response instanceof \WP_REST_Response && function_exists( 'WC' ) && WC()->cart ) {
			$response->data['cart_count'] = (int) WC()->cart->get_cart_contents_count();
		}
		return $response;
	}

	/**
	 * شمارش پاسخ‌های ناموفق متوالی؛ بعد از ۲ بار، پیشنهاد پشتیبانی انسانی
	 * به جواب اضافه می‌شود (پارسا: P1 — مسیر فرار از بات).
	 *
	 * @param string $session_key کلید سشن.
	 * @param string $reply       پاسخ دستیار.
	 * @return string پاسخ (شاید با پیشنهاد پشتیبانی).
	 */
	private function failed_reply_offer( $session_key, $reply ) {
		// «ناموفق» = هر پاسخی که جملهٔ ثابت قانون ۲۴ را دارد — حتی با جایگزین‌های
		// قانون ۲۶. تجربه نشان داد جایگزین‌ها برای درخواست‌های واقعاً ناموجود
		// کمکی نمی‌کنند و کاربر بدون مسیر فرار گیر می‌کند (باگ ۳).
		$reply_str = (string) $reply;
		$failed = false !== mb_strpos( $reply_str, 'این مورد را در اطلاعات فروشگاه پیدا نکردم' );
		$key    = 'fy_fail_count_' . $session_key;
		if ( $failed ) {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, 30 * MINUTE_IN_SECONDS );
			if ( $count >= 2 ) {
				delete_transient( $key );
				set_transient( 'fy_handoff_' . $session_key, 1, 30 * MINUTE_IN_SECONDS );
				$reply .= "\n\n" . 'به نظر می‌رسد نتوانستم کمکتان کنم 😔 می‌خواهید پیام‌تان را برای پشتیبانی انسانی بفرستم؟ اگر بله، نام و شماره تماس‌تان را در یک پیام بنویسید.';
			}
		} else {
			delete_transient( $key );
		}
		return $reply;
	}

	/**
	 * تشخیص درخواست حذف یک قلم از سبد (از دکمه ×).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_remove_from_cart_query( $message ) {
		return (bool) preg_match( '/\(حذف از سبد:\s*([^)]+)\)/u', $message );
	}

	/**
	 * تشخیص ایمیل در پیام (برای جمع‌آوری لید).
	 *
	 * @param string $message پیام کاربر.
	 * @return string|false ایمیل یا false.
	 */
	private function extract_email( $message ) {
		if ( preg_match( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message, $m ) ) {
			$email = sanitize_email( $m[0] );
			if ( is_email( $email ) ) {
				return $email;
			}
		}
		return false;
	}

	/**
	 * تشخیص درخواست راهنما.
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_help_query( $message ) {
		$msg = trim( (string) $message );
		$patterns = array( 'راهنما', 'راهنمایی', 'help', 'چیکار میتونی', 'چه کار میتونی', 'چی بلدی' );
		foreach ( $patterns as $p ) {
			if ( false !== mb_strpos( $msg, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * تشخیص نیت جستجوی محصول (مثلاً «کفش می‌خوام»).
	 *
	 * @param string $message پیام کاربر.
	 * @return string|false عبارت جستجو یا false.
	 */
	private function extract_product_search( $message ) {
		$msg = trim( (string) $message );
		// الگوهای «X می‌خوام»، «X می‌خواهم»، «دنبال X می‌گردم»
		if ( preg_match( '/^(.+?)\s+(می‌خوام|می‌خواهم|میخوام|میخواهم)$/u', $msg, $m ) ) {
			$q = trim( $m[1] );
			if ( mb_strlen( $q ) >= 2 ) {
				return $q;
			}
		}
		if ( preg_match( '/دنبال\s+(.+?)\s+(می‌گردم|میگردم)/u', $msg, $m ) ) {
			$q = trim( $m[1] );
			if ( mb_strlen( $q ) >= 2 ) {
				return $q;
			}
		}
		return false;
	}

	/**
	 * تشخیص درخواست خالی کردن سبد (مثلاً «سبد رو خالی کن»).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_clear_cart_query( $message ) {
		$msg = mb_strtolower( trim( (string) $message ) );
		// نرمال‌سازی فاصله‌ها.
		$msg = preg_replace( '/\s+/', ' ', $msg );
		$patterns = array(
			'خالی کن', 'پاک کن',
			'حذف کن.*سبد', 'سبد.*حذف',
		);
		// باید هم «سبد» باشد هم فعل خالی/پاک/حذف.
		$has_cart = false !== mb_strpos( $msg, 'سبد' );
		if ( ! $has_cart ) {
			return false;
		}
		foreach ( $patterns as $p ) {
			if ( false !== mb_strpos( $msg, $p ) || preg_match( '/' . $p . '/u', $msg ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * تشخیص درخواست مستقیم افزودن به سبد (مثلاً «کیف چرم را به سبد اضافه کن»).
	 *
	 * @param string $message پیام کاربر.
	 * @return bool
	 */
	private function is_add_to_cart_query( $message ) {
		$patterns = array(
			'به سبد اضافه کن',
			'به سبد خرید اضافه کن',
			'به سبد اضاف کن',
			'به سبد خرید اضاف کن',
			'به سبد ببر',
			'به سبد خرید ببر',
			'اضافه کن به سبد',
			'بذار تو سبد',
			'بزار تو سبد',
			'می‌خرم',
			'میخرم',
			'همینو می‌خوام',
			'همین رو می‌خوام',
		);
		foreach ( $patterns as $p ) {
			if ( false !== mb_strpos( $message, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * افزودن مستقیم به سبد بدون AI: محصول را از روی نام پیدا و اضافه می‌کند.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response
	 */
	private function handle_add_to_cart_query( $message, $session_key ) {
		$catalog = new CatalogReader();
		$tools   = new SalesTools();

		// اگر شناسه محصول در پیام هست (از دکمه کارت)، مستقیم اضافه کن.
		if ( preg_match( '/\(شناسه\s*(\d+)\)/u', $message, $m ) ) {
			$pid = (int) $m[1];
			$product = wc_get_product( $pid );
			if ( $product && $product->is_in_stock() ) {
				$result = $tools->add_to_cart( $pid, 1 );
				if ( ! empty( $result['ok'] ) ) {
					$reply = '✅ «' . $product->get_name() . '» به سبد خرید اضافه شد.';
					if ( ! empty( $result['cart_count'] ) ) {
						$reply .= ' الان ' . $result['cart_count'] . ' قلم در سبد دارید.';
					}
					$this->log_chat( $session_key, $message, $reply, array( array( 'name' => 'add_to_cart', 'arg' => $product->get_name(), 'ok' => true ) ) );
					return rest_ensure_response(
						array(
							'reply'    => $reply,
							'products' => array(),
							'tools'    => array( array( 'name' => 'add_to_cart', 'arg' => $product->get_name(), 'ok' => true ) ),
						)
					);
				}
			}
			// اگر شناسه معتبر نبود، ادامه بده با جستجوی نام.
		}

		// استخراج نام محصول: حذف عبارت‌های دستوری.
		$clean = $message;
		$remove = array(
			'به سبد اضافه کن', 'به سبد خرید اضافه کن', 'به سبد اضاف کن', 'به سبد خرید اضاف کن',
			'به سبد ببر', 'به سبد خرید ببر', 'اضافه کن به سبد',
			'بذار تو سبد', 'بزار تو سبد', 'می‌خرم', 'میخرم',
			'همینو می‌خوام', 'همین رو می‌خوام',
			'افزودن', 'اضافه',
			'«', '»', '"', "'",
		);
		$clean = str_replace( $remove, ' ', $clean );
		// «را» و «رو» فقط به‌عنوان کلمهٔ مستقل در انتها حذف شوند (نه داخل نام محصول).
		$clean = preg_replace( '/\s+(را|رو)\s*$/u', ' ', $clean );
		$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );

		if ( '' === $clean ) {
			return rest_ensure_response(
				array(
					'reply'    => 'کدام محصول را به سبد اضافه کنم؟ نام محصول را بگویید.',
					'products' => array(),
					'tools'    => array(),
				)
			);
		}

		// جست‌وجوی محصول.
		$results = $catalog->search( $clean, array( 'limit' => 5 ) );
		if ( empty( $results ) ) {
			return rest_ensure_response(
				array(
					'reply'    => 'محصولی با این نام پیدا نکردم. لطفاً نام دقیق‌تری بگویید.',
					'products' => array(),
					'tools'    => array(),
				)
			);
		}

		// اگر چند محصول پیدا شد، خودسرانه انتخاب نکن — از مشتری بپرس.
		if ( count( $results ) > 1 ) {
			$names = array();
			foreach ( array_slice( $results, 0, 5 ) as $r ) {
				$names[] = '«' . $r['name'] . '» (' . $r['price_text'] . ')';
			}
			return rest_ensure_response(
				array(
					'reply'    => 'چند محصول با این نام پیدا کردم: ' . implode('، ', $names) . '. کدام را به سبد اضافه کنم؟',
					'products' => $results,
					'tools'    => array(),
				)
			);
		}

		$product = $results[0];
		$tools   = new SalesTools();
		$result  = $tools->add_to_cart( $product['id'], 1 );

		if ( ! empty( $result['ok'] ) ) {
			$reply = '✅ «' . $product['name'] . '» به سبد خرید اضافه شد.';
			if ( ! empty( $result['cart_count'] ) ) {
				$reply .= ' الان ' . $result['cart_count'] . ' قلم در سبد دارید.';
			}
			// آمار سبد این‌جا دیگر دستی زیاد نمی‌شود: شمارندهٔ دائمی carts در
			// منبع واقعی (SalesTools::add_to_cart) سیم‌کشی شده تا همهٔ مسیرهای
			// چت شمرده شوند و شمارش مضاعف رخ ندهد (D3).
			// پیشنهاد هوشمند: محصولات هم‌دسته.
			$suggestions = $tools->get_smart_suggestions( 3 );
			if ( ! empty( $suggestions ) ) {
				$reply .= "\n\n💡 پیشنهاد ما:";
				foreach ( $suggestions as $s ) {
					$reply .= "\n• " . $s['name'];
				}
			}
			// لاگ گفتگو.
			$this->log_chat(
				$session_key,
				$message,
				$reply,
				array( array( 'name' => 'add_to_cart', 'arg' => $product['name'], 'ok' => true ) )
			);
			return rest_ensure_response(
				array(
					'reply'    => $reply,
					'products' => array(),
					'tools'    => array( array( 'name' => 'add_to_cart', 'arg' => $product['name'], 'ok' => true ) ),
				)
			);
		}

		$err = ! empty( $result['message'] ) ? $result['message'] : 'افزودن به سبد ناموفق بود.';
		return rest_ensure_response(
			array(
				'reply'    => $err,
				'products' => array(),
				'tools'    => array( array( 'name' => 'add_to_cart', 'arg' => $product['name'], 'ok' => false ) ),
			)
		);
	}

	/**
	 * پاسخ مستقیم به سؤال سبد خرید (بدون AI).
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_email_capture( $email, $message, $session_key ) {
		$leads = get_option( 'forushyar_ai_leads', array() );
		if ( ! is_array( $leads ) ) {
			$leads = array();
		}
		// تکراری نباشد.
		$exists = false;
		foreach ( $leads as $l ) {
			if ( isset( $l['email'] ) && $l['email'] === $email ) {
				$exists = true;
				break;
			}
		}
		if ( ! $exists ) {
			// سارا: افزودن اتمیک با قفل نام‌دار MySQL (همان الگوی پشتیبانی انسانی).
			Settings::atomic_option_update(
				'forushyar_ai_leads',
				function ( $leads ) use ( $email, $session_key ) {
					if ( ! is_array( $leads ) ) {
						$leads = array();
					}
					foreach ( $leads as $l ) {
						if ( isset( $l['email'] ) && $l['email'] === $email ) {
							return $leads; // مسابقهٔ تکراری داخل قفل هم پوشش داده می‌شود.
						}
					}
					$leads[] = array(
						'id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lead_', true ),
						'email'   => $email,
						'type'    => 'email',
						'time'    => current_time( 'mysql' ),
						'session' => $session_key,
					);
					return self::prune_leads( $leads ); // سقف نگه‌داری (D-B1).
				}
			);
		}
		$reply = 'عالیه! 🎉 ایمیلت ثبت شد. از تخفیف‌های بعدی باخبر می‌شی.';
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'email_capture', 'ok' => true ) ),
			)
		);
	}

	private function handle_help_query( $message, $session_key ) {
		$reply = "راهنمای دستیار فروش 🤖\n\n";
		$reply .= "🔍 **جستجو:** مثلاً «کفش می‌خوام» یا «گوشی ارزان»\n";
		$reply .= "🛒 **سبد:** «سبد خرید» برای دیدن، «سبد رو خالی کن» برای خالی کردن\n";
		$reply .= "🎁 **تخفیف:** «کد تخفیف دارید؟»\n";
		$reply .= "📦 **سفارش:** «سفارش‌هام» برای پیگیری\n";
		$reply .= "💬 **سؤال:** هر سؤالی درباره محصولات بپرس!\n\n";
		$reply .= "چیزی دیگه‌ای لازم داری؟";
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array(),
			)
		);
	}

	/**
	 * تشخیص دستور «کارت محصول: نام محصول» (از کلیک روی نام محصول در فهرست متنی).
	 *
	 * @param string $message پیام کاربر.
	 * @return string|false نام محصول یا false.
	 */
	private function is_product_card_query( $message ) {
		$msg = trim( (string) $message );
		if ( preg_match( '/^کارت محصول:\s*(.+)$/u', $msg, $m ) ) {
			$name = trim( $m[1] );
			if ( mb_strlen( $name ) >= 2 ) {
				return $name;
			}
		}
		return false;
	}

	/**
	 * برگرداندن کارت تکی یک محصول (اولین نتیجهٔ جستجو).
	 */
	private function handle_product_card_query( $name, $message, $session_key ) {
		$tools    = new \ForushYar\SalesTools();
		$result   = $tools->search_products( $name, array( 'limit' => 3 ) );
		$products = isset( $result['products'] ) ? $result['products'] : array();
		if ( empty( $products ) ) {
			$reply    = 'متأسفانه کارت این محصول پیدا نشد.';
			$products = array();
		} else {
			$product  = $products[0];
			$reply    = isset( $product['name'] ) ? (string) $product['name'] : $name;
			$products = array( $product );
		}
		$this->log_chat( $session_key, $message, $reply, array( array( 'name' => 'show_product_card', 'arg' => $name, 'ok' => ! empty( $products ) ) ) );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => $products,
				'tools'    => array( array( 'name' => 'show_product_card', 'ok' => ! empty( $products ) ) ),
			)
		);
	}

	private function handle_product_search_query( $query, $message, $session_key ) {
		$tools = new \ForushYar\SalesTools();
		// اگر پیام فقط صفت است (مثل «مردانه»)، با جستجوی قبلی ترکیب کن.
		// لیست باید با چک handle_chat یکی باشد (ارزون هم هست).
		$refinements = array( 'مردانه', 'زنانه', 'بچگانه', 'ارزان', 'گران', 'جدید', 'ارزون' );
		$msg_trim = trim( (string) $message );
		if ( in_array( $msg_trim, $refinements, true ) ) {
			$last_q = get_transient( 'fy_last_search_' . $session_key );
			if ( $last_q ) {
				$query = $last_q . ' ' . $msg_trim;
			}
		}
		// ذخیره جستجوی فعلی برای refinement بعدی.
		set_transient( 'fy_last_search_' . $session_key, $query, 10 * MINUTE_IN_SECONDS );
		$result = $tools->search_products( $query, array( 'limit' => 5 ) );
		$products = isset( $result['products'] ) ? $result['products'] : array();
		if ( empty( $products ) ) {
			$reply = 'متأسفانه محصولی برای «' . $query . '» پیدا نکردم. می‌تونی دقیق‌تر بگی چی می‌خوای؟';
		} else {
			$count = count( $products );
			$reply = 'این ' . $count . ' محصول رو برای «' . $query . '» پیدا کردم:';
			// تکنیک ترانه: سؤال بستن.
			$reply .= "\n" . 'کدوم رو می‌خوای به سبد اضافه کنم؟ 🛒';
		}
		$this->log_chat( $session_key, $message, $reply, array( array( 'name' => 'search_products', 'arg' => $query, 'ok' => ! empty( $products ) ) ) );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => $products,
				'tools'    => array( array( 'name' => 'search_products', 'ok' => ! empty( $products ) ) ),
			)
		);
	}

	/**
	 * پیشنهاد شخصی‌سازی‌شده بر اساس تاریخچه خرید کاربر لاگین‌کرده.
	 * از دسته‌های خریدهای قبلی، محصول‌هایی که هنوز نخریده را پیشنهاد می‌دهد.
	 *
	 * @param string $message     پیام کاربر.
	 * @param string $session_key کلید سشن.
	 * @return \WP_REST_Response
	 */
	/**
	 * محصول‌های پیشنهادی برای کاربر — از دسته‌های خریدهای قبلی، به‌جز چیزی که خریده.
	 * عمومی است تا داشبورد مشتری هم از همین منطق استفاده کند.
	 *
	 * @param int $user_id شناسه کاربر.
	 * @return array آرایهٔ خلاصهٔ محصولات (summarize).
	 */
	public function get_recommended_products( $user_id ) {
		$history = get_user_meta( $user_id, 'forushyar_ai_purchase_history', true );
		$catalog = new CatalogReader();
		if ( empty( $history ) || ! is_array( $history ) ) {
			// بدون تاریخچه: پرفروش‌ترین‌ها.
			return array_slice( $catalog->search( '', array( 'limit' => 5 ) ), 0, 5 );
		}
		$bought_ids = array();
		$cat_ids    = array();
		foreach ( $history as $h ) {
			if ( ! empty( $h['product_id'] ) ) {
				$bought_ids[] = (int) $h['product_id'];
			}
			if ( ! empty( $h['categories'] ) && is_array( $h['categories'] ) ) {
				$cat_ids = array_merge( $cat_ids, array_map( 'intval', $h['categories'] ) );
			}
		}
		$bought_ids = array_unique( $bought_ids );
		$cat_ids    = array_unique( $cat_ids );
		$products   = array();
		if ( ! empty( $cat_ids ) ) {
			$seen = array();
			// هر دستهٔ خرید قبلی جدا کوئری می‌شود تا همهٔ دسته‌ها پوشش داده شوند.
			foreach ( $cat_ids as $cid ) {
				$term = get_term( (int) $cid, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				foreach ( $catalog->search( '', array( 'limit' => 12, 'category' => $term->slug ) ) as $p ) {
					$pid = (int) $p['id'];
					if ( isset( $seen[ $pid ] ) || in_array( $pid, $bought_ids, true ) ) {
						continue;
					}
					$seen[ $pid ] = true;
					$products[]   = $p;
					if ( count( $products ) >= 5 ) {
						break 2;
					}
				}
			}
		}
		return $products;
	}

	private function handle_recommendation_query( $message, $session_key ) {
		$user_id = get_current_user_id();
		$tools   = array( array( 'name' => 'recommend_for_user', 'ok' => false ) );
		if ( ! $user_id ) {
			$reply = 'برای پیشنهاد مخصوص خودت، لطفاً اول وارد حساب کاربری‌ات شو 🙂';
			$this->log_chat( $session_key, $message, $reply, $tools );
			return rest_ensure_response( array( 'reply' => $reply, 'products' => array(), 'tools' => $tools ) );
		}
		$history = get_user_meta( $user_id, 'forushyar_ai_purchase_history', true );
		if ( empty( $history ) || ! is_array( $history ) ) {
			$products = $this->get_recommended_products( $user_id );
			$reply    = 'هنوز خریدی ثبت نشده؛ این پرفروش‌ترین‌های فروشگاه‌اند:';
			$tools[0]['ok'] = ! empty( $products );
			$this->log_chat( $session_key, $message, $reply, $tools );
			return rest_ensure_response( array( 'reply' => $reply, 'products' => $products, 'tools' => $tools ) );
		}
		$names = array();
		foreach ( $history as $h ) {
			if ( ! empty( $h['product_name'] ) ) {
				$names[] = $h['product_name'];
			}
		}
		$names    = array_slice( array_unique( $names ), -3 );
		$products = $this->get_recommended_products( $user_id );
		if ( empty( $products ) ) {
			$reply = 'بر اساس خریدهای قبلیت چیز تازه‌ای پیدا نکردم؛ بگو دنبال چه محصولی می‌گردی تا دقیق پیشنهاد بدم 🙂';
		} else {
			$reply = 'بر اساس خریدهای قبلیت (' . implode( '، ', $names ) . ')، این‌ها رو پیشنهاد می‌کنم:';
		}
		$tools[0]['ok'] = ! empty( $products );
		$this->log_chat( $session_key, $message, $reply, $tools );
		return rest_ensure_response( array( 'reply' => $reply, 'products' => $products, 'tools' => $tools ) );
	}

	private function handle_remove_from_cart_query( $message, $session_key ) {
		preg_match( '/\(حذف از سبد:\s*([^)]+)\)/u', $message, $m );
		$cart_key = isset( $m[1] ) ? trim( $m[1] ) : '';
		$tools = new \ForushYar\SalesTools();
		$result = $tools->remove_from_cart( $cart_key );
		if ( ! empty( $result['ok'] ) ) {
			$reply = '🗑️ محصول از سبد حذف شد.';
			$count = isset( $result['cart_count'] ) ? (int) $result['cart_count'] : 0;
			if ( $count > 0 ) {
				$reply .= ' (' . $count . ' قلم باقی مانده)';
			} else {
				$reply .= ' سبد خالی شد.';
			}
		} else {
			$reply = 'حذف از سبد ناموفق بود.';
		}
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'remove_from_cart', 'ok' => ! empty( $result['ok'] ) ) ),
			)
		);
	}

	private function handle_clear_cart_query( $message, $session_key ) {
		$tools = new \ForushYar\SalesTools();
		// ensure_cart را صدا بزن تا سبد در کانتکست REST لود شود.
		$summary = $tools->get_cart_summary_public();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$count = WC()->cart->get_cart_contents_count();
			WC()->cart->empty_cart();
			$reply = $count > 0
				? '🗑️ سبد خرید خالی شد (' . $count . ' قلم حذف شد).'
				: 'سبد خرید شما از قبل خالی بود.';
		} else {
			$reply = 'سبد خرید در دسترس نیست.';
		}
		$this->log_chat( $session_key, $message, $reply, array() );
		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array(),
				'tools'    => array( array( 'name' => 'clear_cart', 'ok' => true ) ),
			)
		);
	}

	private function handle_cart_query( $message, $session_key ) {
		$tools = new \ForushYar\SalesTools();
		$summary = $tools->get_cart_summary_public();
		if ( ! $summary || empty( $summary['items_count'] ) ) {
			$reply = 'سبد خرید شما در حال حاضر خالی است. می‌خواهید محصولی پیشنهاد بدهم؟';
		} else {
			$reply = 'سبد خرید شما (' . (int) $summary['items_count'] . ' قلم):' . "\n";
			if ( ! empty( $summary['items'] ) && is_array( $summary['items'] ) ) {
				foreach ( $summary['items'] as $item ) {
					$name = isset( $item['name'] ) ? $item['name'] : '';
					$qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
					$price = isset( $item['price'] ) ? $item['price'] : '';
					$reply .= '• ' . $name . ' ×' . $qty . ' (' . $price . ")\n";
				}
			}
			$reply .= 'جمع: ' . ( isset( $summary['total_text'] ) ? $summary['total_text'] : '' );
			// لینک تسویه برای راحتی مشتری.
			if ( function_exists( 'wc_get_checkout_url' ) ) {
				$reply .= "\n" . __( 'تسویه حساب:', 'forushyar-ai' ) . ' ' . wc_get_checkout_url();
			}
		}
		$reply = trim( $reply );
		// ثبت در لاگ گفتگوها مثل مسیر عادی چت (بدون ابزار).
		$this->log_chat( $session_key, $message, $reply, array() );
		return new \WP_REST_Response( array(
			'ok'        => true,
			'reply'     => $reply,
			'products'  => array(),
			'cart_items' => isset( $summary['items'] ) ? $summary['items'] : array(),
		), 200 );
	}

	/**
	 * ثبت بازخورد کاربر (امتیاز مثبت/منفی به پاسخ).
	 *
	 * @param \WP_REST_Request $request درخواست.
	 * @return \WP_REST_Response
	 */
	public function handle_feedback( $request ) {
		// Rate limit برای جلوگیری از سوءاستفاده.
		$sid = sanitize_key( (string) $request->get_param( 'sid' ) );
		if ( $this->is_rate_limited( 'fb_' . $sid ) ) {
			return new \WP_Error( 'forushyar_ai_rate_limit', __( 'تعداد درخواست‌ها زیاد است.', 'forushyar-ai' ), array( 'status' => 429 ) );
		}
		$score = (int) $request->get_param( 'score' );
		$score = $score > 0 ? 1 : -1;
		// ذخیره در لاگ برای تحلیل آینده.
		$log = get_option( 'forushyar_ai_feedback', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'  => current_time( 'mysql' ),
			'score' => $score,
			'sid'   => sanitize_key( (string) $request->get_param( 'sid' ) ),
		);
		// فقط ۵۰۰ رکورد آخر نگه دار.
		$log = array_slice( $log, -500 );
		update_option( 'forushyar_ai_feedback', $log, false );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * بررسی دسترسی: دو مسیر مجاز.
	 * ۱) مدیریت/تست: کاربر لاگین‌کرده با capability + nonce استاندارد wp_rest.
	 * ۲) ویجت فرانت‌اند: مهمان یا کاربر عادی با nonce مخصوص ویجت (forushyar_ai_widget).
	 *
	 * @param \WP_REST_Request $request درخواست.
	 * @return bool
	 */
	public function check_permission( $request ) {
		if ( is_user_logged_in() ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce ) {
				$nonce = $request->get_param( 'nonce' );
			}
			if ( wp_verify_nonce( $nonce, 'wp_rest' ) && current_user_can( Plugin::instance()->admin_capability() ) ) {
				return true;
			}
		}

		// مسیر عمومی ویجت — مهمان‌ها هم مجازند (با rate limit جداگانهٔ هر سشن).
		$widget_nonce = $request->get_header( 'X-ForushYar-Nonce' );
		if ( ! $widget_nonce ) {
			$widget_nonce = $request->get_param( 'widget_nonce' );
		}
		if ( wp_verify_nonce( $widget_nonce, 'forushyar_ai_widget' ) ) {
			return true;
		}
		// رد دسترسی با پیام فارسی؛ کد وضعیت مثل قبل: ۴۰۱ برای مهمان، ۴۰۳ برای کاربر لاگین‌کرده.
		return new \WP_Error(
			'rest_forbidden',
			__( 'نشست شما منقضی شده است؛ لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.', 'forushyar-ai' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * هندلر چت.
	 *
	 * @param \WP_REST_Request $request درخواست.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_chat( $request ) {
		// زنجیرهٔ ابزارها (دو رفت‌وبرگشت به سرویس AI) ممکن است از ۳۰ ثانیهٔ
		// پیش‌فرض PHP بیشتر شود؛ سقف را بالا می‌بریم تا ۵۰۲ نگیریم.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		// کلید سشن: کاربر لاگین → شناسهٔ کاربری؛ مهمان → sid مرورگر (یا هش IP به‌عنوان fallback).
		if ( is_user_logged_in() ) {
			$session_key = 'u_' . get_current_user_id();
		} else {
			$sid = $request->get_param( 'sid' );
			$sid = is_string( $sid ) ? substr( sanitize_key( $sid ), 0, 64 ) : '';
			if ( '' === $sid ) {
				$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
				$sid = 'ip_' . md5( $ip );
			}
			$session_key = 'g_' . $sid;
		}

		if ( $this->is_rate_limited( $session_key ) ) {
			return new \WP_Error(
				'forushyar_ai_rate_limit',
				__( 'تعداد درخواست‌ها زیاد است؛ لطفاً یک دقیقه صبر کنید.', 'forushyar-ai' ),
				array( 'status' => 429 )
			);
		}

		// لایه دوم rate limit بر اساس IP (جلوگیری از دور زدن با چرخش sid).
		if ( ! is_user_logged_in() ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
			if ( $this->is_rate_limited( 'ip_' . md5( $ip ) ) ) {
				return new \WP_Error(
					'forushyar_ai_rate_limit',
					__( 'تعداد درخواست‌ها زیاد است؛ لطفاً یک دقیقه صبر کنید.', 'forushyar-ai' ),
					array( 'status' => 429 )
				);
			}
		}

		// هرس تنبل (~۱٪ هر پیام): اگر wp-cron روی هاست غیرفعال/کم‌ترافیک باشد،
		// لاگ‌های قدیمی و آمار ماهانه باز هم هرس می‌شوند — بدون اتکا به کرون.
		if ( 1 === mt_rand( 1, 100 ) ) {
			Plugin::instance()->cleanup_old_logs();
			$monthly = get_option( 'forushyar_ai_api_monthly', array() );
			if ( is_array( $monthly ) && count( $monthly ) > 12 ) {
				ksort( $monthly );
				update_option( 'forushyar_ai_api_monthly', array_slice( $monthly, -12, 12, true ), false );
			}
		}

		// اعتبارسنجی پیام خالی — قبل از مصرف کوپن خوش‌آمد.
		$message = $request->get_param( 'message' );
		$message = is_string( $message ) ? trim( sanitize_text_field( $message ) ) : '';
		if ( '' === $message ) {
			return new \WP_Error(
				'forushyar_ai_empty',
				__( 'پیام خالی ارسال شد.', 'forushyar-ai' ),
				array( 'status' => 400 )
			);
		}
		$message = mb_substr( $message, 0, 1000 );

		// عامل دوم احراز مالکیت برای پیگیری سفارش مهمان (اختیاری): شماره موبایل یا
		// ایمیلِ ثبت‌شده روی سفارش. اگر نیاید، پیگیری مهمان فقط وضعیت کلی می‌گیرد.
		$verify = $request->get_param( 'verify' );
		$verify = is_string( $verify ) ? trim( sanitize_text_field( $verify ) ) : '';

		// کوپن خوش‌آمد: اولین چت هر سشن → اعمال خودکار کد تخفیف.
		$welcome_note = '';
		$welcome_code = trim( (string) Settings::get( 'welcome_coupon', '' ) );
		if ( '' !== $welcome_code ) {
			$welcomed = get_transient( 'fy_welcomed_' . $session_key );
			if ( ! $welcomed ) {
				$tools = new SalesTools();
				$applied = $tools->apply_coupon( $welcome_code );
				if ( ! empty( $applied['ok'] ) ) {
					$welcome_note = "\n\n🎁 به‌عنوان خوش‌آمد، کد تخفیف «" . $welcome_code . "» برای شما فعال شد!";
				}
				set_transient( 'fy_welcomed_' . $session_key, 1, DAY_IN_SECONDS * 30 );
			}
		}

		// راهنما — قبل از جستجو چک شود.
		if ( $this->is_help_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_help_query( $message, $session_key ), $welcome_note );
		}

		// «سفارش‌های من» — deterministic: مدل نباید وعدهٔ بررسی بدهد و بعد هیچی نگوید (باگ ۵).
		if ( $this->is_my_orders_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_my_orders_query( $message, $session_key ), $welcome_note );
		}

		// پیگیری سفارش (پارسا: P0) — قبل از بقیهٔ intentها.
		if ( get_transient( 'fy_awaiting_order_' . $session_key ) ) {
			$order_no = $this->extract_order_number( $message );
			if ( false !== $order_no ) {
				delete_transient( 'fy_awaiting_order_' . $session_key );
				return $this->with_welcome_note( $this->handle_order_status( $order_no, $message, $session_key, $verify ), $welcome_note );
			}
		}
		if ( $this->is_order_tracking_query( $message ) ) {
			$order_no = $this->extract_order_number( $message );
			if ( false === $order_no ) {
				set_transient( 'fy_awaiting_order_' . $session_key, 1, 10 * MINUTE_IN_SECONDS );
				$reply = 'حتماً! 🧾 لطفاً شمارهٔ سفارش‌تان را بفرستید تا وضعیتش را بگویم.';
				$this->log_chat( $session_key, $message, $reply, array() );
				return $this->with_welcome_note(
					rest_ensure_response(
						array(
							'reply'    => $reply,
							'products' => array(),
							'tools'    => array( array( 'name' => 'order_tracking', 'ok' => true ) ),
						)
					),
					$welcome_note
				);
			}
			return $this->with_welcome_note( $this->handle_order_status( $order_no, $message, $session_key, $verify ), $welcome_note );
		}

		// درخواست پشتیبانی انسانی (پارسا: P1) — مستقیم یا ادامهٔ درخواست قبلی.
		if ( $this->is_handoff_request( $message ) ) {
			return $this->with_welcome_note( $this->start_handoff( $message, $session_key ), $welcome_note );
		}
		if ( get_transient( 'fy_handoff_' . $session_key ) ) {
			$handoff_res = $this->handle_handoff_details( $message, $session_key );
			if ( false !== $handoff_res ) {
				return $this->with_welcome_note( $handoff_res, $welcome_note );
			}
			// false یعنی پیام سؤال تازه بود، نه اطلاعات تماس — مسیر عادی ادامه می‌یابد.
		}

		// مرجوعی قدم‌به‌قدم (پارسا: P1) — قبل از AI.
		if ( $this->is_return_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_return_query( $message, $session_key ), $welcome_note );
		}

		// کارت تکی محصول (کلیک روی نام محصول در فهرست متنی دستیار).
		$card_name = $this->is_product_card_query( $message );
		if ( false !== $card_name ) {
			return $this->with_welcome_note( $this->handle_product_card_query( $card_name, $message, $session_key ), $welcome_note );
		}

		// اگر کاربر مستقیماً می‌خواهد محصولی به سبد اضافه شود، بدون AI انجام بده.
		// (قبل از جستجوی محصول چک شود تا «همینو می‌خوام» به‌اشتباه جستجو نشود.)
		if ( $this->is_add_to_cart_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_add_to_cart_query( $message, $session_key ), $welcome_note );
		}

		// پیشنهاد شخصی‌سازی‌شده بر اساس خریدهای قبلی — قبل از جستجوی عادی.
		if ( $this->is_recommendation_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_recommendation_query( $message, $session_key ), $welcome_note );
		}

		// جستجوی مستقیم محصول («کفش می‌خوام») — بدون وابستگی به AI.
		$search_q = $this->extract_product_search( $message );
		// refinement تکی («مردانه») هم از طریق جستجو برود.
		if ( false === $search_q ) {
			$refinements = array( 'مردانه', 'زنانه', 'بچگانه', 'ارزان', 'گران', 'جدید', 'ارزون' );
			if ( in_array( trim( (string) $message ), $refinements, true ) ) {
				$last_q = get_transient( 'fy_last_search_' . $session_key );
				if ( $last_q ) {
					$search_q = $last_q; // handle_product_search_query ترکیب می‌کند.
				}
			}
		}
		if ( false !== $search_q ) {
			return $this->with_welcome_note( $this->handle_product_search_query( $search_q, $message, $session_key ), $welcome_note );
		}

		// حذف یک قلم از سبد (دکمه ×) — قبل از بقیه چک شود.
		if ( $this->is_remove_from_cart_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_remove_from_cart_query( $message, $session_key ), $welcome_note );
		}

		// ایمیل برای خبرنامه (ترانه: آخر چت).
		$email = $this->extract_email( $message );
		if ( false !== $email ) {
			return $this->with_welcome_note( $this->handle_email_capture( $email, $message, $session_key ), $welcome_note );
		}

		// خالی کردن سبد — قبل از نمایش سبد چک شود.
		if ( $this->is_clear_cart_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_clear_cart_query( $message, $session_key ), $welcome_note );
		}

		// اگر کاربر سبد خرید را می‌خواهد، مستقیم از PHP پاسخ بده (بدون AI)
		// چون round-trip ابزار در AvalAI با خطای 400 مواجه می‌شود.
		if ( $this->is_cart_query( $message ) ) {
			return $this->with_welcome_note( $this->handle_cart_query( $message, $session_key ), $welcome_note );
		}

		// تعدیل محتوا (پارسا/ترانه): فحاشی و هذیان‌گویی — deterministic و بدون
		// صدا زدن AI. این‌جا (بعد از همهٔ روت‌های قطعی، قبل از AI) است تا
		// شماره سفارش/قیمت (مثل «۱۲۳۴۵») اشتباهی هذیان تشخیص داده نشود.
		$mod = $this->moderate_message( $message );
		if ( false !== $mod ) {
			$this->log_chat( $session_key, $message, $mod, array( array( 'name' => 'moderation', 'ok' => true ) ) );
			return rest_ensure_response(
				array(
					'reply'    => $mod,
					'products' => array(),
					'tools'    => array( array( 'name' => 'moderation', 'ok' => true ) ),
				)
			);
		}

		$history = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$chat   = new Chat();
		$result = $chat->ask( $message, $history, $session_key );

		if ( ! $result['ok'] ) {
			// حالت آفلاین: پاسخ مؤدبانه در قالب عادی تا ویجت آن را به‌عنوان پیام نشان دهد.
			// با پرچم offline در لاگ/پاسخ، از پاسخ واقعی قابل تشخیص است.
			if ( ! empty( $result['offline'] ) ) {
				$this->log_chat( $session_key, $message, $result['message'], array(), true );
				return $this->with_welcome_note(
					rest_ensure_response(
						array(
							'reply'    => $result['message'],
							'products' => array(),
							'tools'    => array(),
							'offline' => true,
						)
					),
					$welcome_note
				);
			}
			return new \WP_Error(
				'forushyar_ai_chat_failed',
				$result['message'],
				array( 'status' => 502 )
			);
		}

		$this->log_chat( $session_key, $message, $result['reply'], isset( $result['tools'] ) && is_array( $result['tools'] ) ? $result['tools'] : array() );

		// پارسا (P1): دو پاسخ ناموفق متوالی → پیشنهاد پشتیبانی انسانی.
		$reply = $this->failed_reply_offer( $session_key, $result['reply'] );

		// کوپن خوش‌آمد را به جواب اضافه کن.
		$reply = $reply . $welcome_note;

		return rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => isset( $result['products'] ) && is_array( $result['products'] ) ? array_values( $result['products'] ) : array(),
				'tools'    => isset( $result['tools'] ) && is_array( $result['tools'] ) ? array_values( $result['tools'] ) : array(),
			)
		);
	}

	/**
	 * rate limit ساده: حداکثر RATE_LIMIT پیام در ۶۰ ثانیه برای هر سشن.
	 *
	 * @param string $key کلید سشن.
	 * @return bool true اگر محدود شده.
	 */
	private function is_rate_limited( $key ) {
		$transient = 'forushyar_ai_rl_' . $key;
		$hits      = get_transient( $transient );
		if ( ! is_array( $hits ) ) {
			$hits = array();
		}
		$now  = time();
		$hits = array_values(
			array_filter(
				$hits,
				function ( $ts ) use ( $now ) {
					return $ts > $now - 60;
				}
			)
		);
		if ( count( $hits ) >= self::RATE_LIMIT ) {
			return true;
		}
		$hits[] = $now;
		set_transient( $transient, $hits, 70 );
		return false;
	}

	/**
	 * ماسک شماره موبایل ایرانی در متن لاگ (مثلاً ۰۹۱۲***۴۵۶۷) — حریم خصوصی.
	 * هم ارقام لاتین و هم فارسی/عربی پوشش داده می‌شوند.
	 *
	 * @param string $text متن خام.
	 * @return string متن ماسک‌شده.
	 */
	private function mask_phones( $text ) {
		return preg_replace_callback(
			'/[0۰][9۹][0-9۰-۹]{9}/u',
			function ( $m ) {
				$d = $m[0];
				return mb_substr( $d, 0, 4 ) . '***' . mb_substr( $d, -4 );
			},
			(string) $text
		);
	}

	/**
	 * ثبت گفتگو در لاگ (بدون هیچ اطلاعات حساس).
	 *
	 * @param string $session شناسهٔ سشن.
	 * @param string $user    پیام کاربر (پاک‌سازی‌شده).
	 * @param string $reply   پاسخ دستیار.
	 * @param array  $tools   ابزارهای صدا زده‌شده: [['name','arg','ok'], ...].
	 * @param bool   $offline true اگر پاسخ در حالت آفلاین (خرابی سرویس) ثبت می‌شود.
	 */
	private function log_chat( $session, $user, $reply, $tools = array(), $offline = false ) {
		// شمارندهٔ دائمی گفتگو (D3): هر تبادل ثبت‌شده یک گفتگوست و در منبع
		// واقعی‌اش زیاد می‌شود — کارت «کل» داشبورد دیگر از لاگ غلتان خوانده نمی‌شود.
		Plugin::bump_stats( 'chats' );
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$clean_tools = array();
		foreach ( array_slice( $tools, 0, 12 ) as $t ) {
			if ( ! is_array( $t ) || empty( $t['name'] ) ) {
				continue;
			}
			$clean_tools[] = array(
				'name' => sanitize_key( $t['name'] ),
				'arg'  => isset( $t['arg'] ) ? mb_substr( sanitize_text_field( (string) $t['arg'] ), 0, 80 ) : '',
				'ok'   => ! empty( $t['ok'] ),
			);
		}
		$logs[] = array(
			'time'    => current_time( 'mysql' ),
			'session' => sanitize_key( $session ),
			'user'    => mb_substr( $this->mask_phones( $user ), 0, 500 ),
			'reply'   => mb_substr( $this->mask_phones( $reply ), 0, 2000 ),
			'tools'   => $clean_tools,
			'offline' => (bool) $offline,
		);
		if ( count( $logs ) > self::LOG_LIMIT ) {
			$logs = array_slice( $logs, -self::LOG_LIMIT );
		}
		update_option( self::LOG_OPTION, $logs, false );
	}
}
