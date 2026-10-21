<?php
/**
 * ساخت ۳ مشتری دموی دیجی‌مارکت با تاریخچه واقعی:
 * سفارش‌های تکمیل‌شده (→ حافظه خرید پلاگین)، نقد و بررسی، لایک و بازدید.
 * اجرا: wp eval-file (داخل کانتینر fy-app).
 */

function fy_find_pid( $needle ) {
	$ps = wc_get_products( array( 'status' => 'publish', 'limit' => 60 ) );
	foreach ( $ps as $p ) {
		if ( false !== mb_strpos( $p->get_name(), $needle ) ) {
			return $p->get_id();
		}
	}
	return 0;
}

function fy_make_customer( $login, $email, $first, $last, $pass ) {
	$uid = username_exists( $login );
	if ( ! $uid ) {
		$uid = wp_create_user( $login, $pass, $email );
	}
	$user = new WP_User( $uid );
	$user->set_role( 'customer' );
	update_user_meta( $uid, 'first_name', $first );
	update_user_meta( $uid, 'last_name', $last );
	update_user_meta( $uid, 'billing_first_name', $first );
	update_user_meta( $uid, 'billing_last_name', $last );
	update_user_meta( $uid, 'billing_phone', '09123456789' );
	update_user_meta( $uid, 'billing_city', 'تهران' );
	return $uid;
}

function fy_make_order( $uid, $items, $days_ago ) {
	// items: [ ['needle'=>'کیف دوشی', 'qty'=>1], ... ]
	$order = wc_create_order( array( 'customer_id' => $uid ) );
	foreach ( $items as $it ) {
		$pid = fy_find_pid( $it['needle'] );
		if ( $pid ) {
			$order->add_product( wc_get_product( $pid ), $it['qty'] );
		}
	}
	$order->set_billing_first_name( get_user_meta( $uid, 'first_name', true ) );
	$order->set_billing_last_name( get_user_meta( $uid, 'last_name', true ) );
	$order->set_billing_phone( '09123456789' );
	$order->set_billing_city( 'تهران' );
	$order->set_payment_method( 'cod' );
	$order->set_payment_method_title( 'پرداخت در محل' );
	$order->calculate_totals();
	$order->set_date_created( gmdate( 'Y-m-d H:i:s', time() - $days_ago * 86400 ) );
	// اول pending، بعد completed تا هوک track_purchase شلیک شود.
	$order->set_status( 'completed' );
	$order->save();
	echo 'order ' . $order->get_id() . " completed for user $uid\n";
	return $order->get_id();
}

function fy_make_review( $uid, $needle, $rating, $text ) {
	$pid = fy_find_pid( $needle );
	if ( ! $pid ) {
		echo "REVIEW SKIP: not found [$needle]\n";
		return;
	}
	$u   = get_user_by( 'id', $uid );
	$cid = wp_insert_comment( array(
		'comment_post_ID'      => $pid,
		'comment_author'       => $u->display_name,
		'comment_author_email' => $u->user_email,
		'comment_content'      => $text,
		'comment_type'         => 'review',
		'comment_approved'     => 1,
		'user_id'              => $uid,
	) );
	if ( $cid ) {
		update_comment_meta( $cid, 'rating', $rating );
		echo "review $cid on product $pid\n";
	}
}

function fy_meta_ids( $uid, $key, $needles ) {
	$ids = array();
	foreach ( $needles as $n ) {
		$pid = fy_find_pid( $n );
		if ( $pid ) {
			$ids[] = $pid;
		}
	}
	update_user_meta( $uid, $key, $ids );
	echo "$key = " . implode( ',', $ids ) . "\n";
}

// ---------- مشتری ۱: سارا محمدی — عاشق چرم ----------
$sara = fy_make_customer( 'sara_demo', 'sara@example.com', 'سارا', 'محمدی', 'Demo1234!' );
fy_make_order( $sara, array( array( 'needle' => 'میلانو', 'qty' => 1 ) ), 40 );
fy_make_order( $sara, array( array( 'needle' => 'کمربند چرم دستدوز', 'qty' => 1 ), array( 'needle' => 'جاکارتی چرم دستدوز', 'qty' => 1 ) ), 12 );
fy_make_review( $sara, 'میلانو', 5, 'کیفیت چرمش عالیه، دوخت تمیزی داره. پیشنهاد می‌کنم.' );
fy_make_review( $sara, 'جاکارتی چرم دستدوز', 4, 'خوب و جمع‌وجوره، فقط کاش رنگ‌های بیشتری داشت.' );
fy_meta_ids( $sara, 'forushyar_ai_liked_products', array( 'ماهدشت', 'کمربند چرم دستدوز' ) );
fy_meta_ids( $sara, 'forushyar_ai_viewed_products', array( 'میلانو', 'لوفر', 'کمربند چرم دستدوز', 'جاکارتی چرم دستدوز', 'ماهدشت' ) );

// ---------- مشتری ۲: رضا کریمی — دیجیتال ----------
$reza = fy_make_customer( 'reza_demo', 'reza@example.com', 'رضا', 'کریمی', 'Demo1234!' );
fy_make_order( $reza, array( array( 'needle' => 'ساعت هوشمند', 'qty' => 1 ) ), 25 );
fy_make_order( $reza, array( array( 'needle' => 'هندزفری بلوتوثی', 'qty' => 1 ), array( 'needle' => 'ایربادز', 'qty' => 1 ) ), 8 );
fy_make_review( $reza, 'ساعت هوشمند', 5, 'باتریش عالیه، دقیقاً همونی بود که می‌خواستم.' );
fy_meta_ids( $reza, 'forushyar_ai_liked_products', array( 'ایربادز', 'اسپیکر بلوتوثی' ) );
fy_meta_ids( $reza, 'forushyar_ai_viewed_products', array( 'ساعت هوشمند', 'هندزفری بلوتوثی', 'ایربادز', 'اسپیکر بلوتوثی' ) );

// ---------- مشتری ۳: مریم احمدی — خانه و آشپزخانه + زیبایی ----------
$maryam = fy_make_customer( 'maryam_demo', 'maryam@example.com', 'مریم', 'احمدی', 'Demo1234!' );
fy_make_order( $maryam, array( array( 'needle' => 'سرویس قابلمه', 'qty' => 1 ) ), 30 );
fy_make_order( $maryam, array( array( 'needle' => 'ست مراقبت پوست', 'qty' => 1 ) ), 5 );
fy_make_review( $maryam, 'سرویس قابلمه', 5, 'خیلی باکیفیته، پخش حرارتش یکدسته.' );
fy_meta_ids( $maryam, 'forushyar_ai_liked_products', array( 'سرویس قابلمه', 'ست مراقبت پوست' ) );
fy_meta_ids( $maryam, 'forushyar_ai_viewed_products', array( 'سرویس قابلمه', 'ست مراقبت پوست', 'جاروبرقی رباتیک', 'اسپرسوساز' ) );

echo "DONE\n";
