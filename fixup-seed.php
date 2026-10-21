<?php
// دیباگ نام‌های واقعی + تکمیل سید سارا.
function fy_n( $s ) {
	return str_replace( array( 'ي', 'ك', "‌", '‌' ), array( 'ی', 'ک', '', '' ), (string) $s );
}
function fy_find( $needle ) {
	$n = fy_n( $needle );
	foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 60, 'orderby' => 'id', 'order' => 'ASC' ) ) as $p ) {
		if ( false !== mb_strpos( fy_n( $p->get_name() ), $n ) ) {
			return $p->get_id();
		}
	}
	return 0;
}
// ۱) نام واقعی محصولات ۱۳، ۱۶، ۲۶ و هرچه «دستدوز»/«ماهدشت»/«ایربادز» دارد.
foreach ( array( 13, 16, 26, 62, 63 ) as $pid ) {
	$p = wc_get_product( $pid );
	if ( $p ) {
		echo $pid . ' => ' . urlencode( $p->get_name() ) . "\n";
	}
}
echo "find دستدوز: " . fy_find( 'دستدوز' ) . "\n";
echo "find ماهدشت: " . fy_find( 'ماهدشت' ) . "\n";
echo "find ایربادز: " . fy_find( 'ایربادز' ) . "\n";
echo "find کمربند: " . fy_find( 'کمربند' ) . "\n";

// ۲) تکمیل سید سارا: سفارش کمربند+جاکارتی، ریویو جاکارتی، لایک‌ها.
$sara = get_user_by( 'login', 'sara_demo' );
if ( $sara ) {
	$uid = $sara->ID;
	$belt = fy_find( 'کمربند' );
	$card = fy_find( 'جاکارتی' );
	echo "belt=$belt card=$card\n";
	if ( $belt && $card ) {
		$order = wc_create_order( array( 'customer_id' => $uid ) );
		$order->add_product( wc_get_product( $belt ), 1 );
		$order->add_product( wc_get_product( $card ), 1 );
		$order->set_billing_first_name( 'سارا' );
		$order->set_billing_last_name( 'محمدی' );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		echo 'fixup order ' . $order->get_id() . "\n";
		$cid = wp_insert_comment( array(
			'comment_post_ID' => $card, 'comment_author' => 'سارا محمدی',
			'comment_author_email' => 'sara@example.com',
			'comment_content' => 'خوب و جمع‌وجوره، فقط کاش رنگ‌های بیشتری داشت.',
			'comment_type' => 'review', 'comment_approved' => 1, 'user_id' => $uid,
		) );
		if ( $cid ) { update_comment_meta( $cid, 'rating', 4 ); echo "review $cid\n"; }
		$mah = fy_find( 'ماهدشت' );
		update_user_meta( $uid, 'forushyar_ai_liked_products', array_filter( array( $mah, $belt ) ) );
		update_user_meta( $uid, 'forushyar_ai_viewed_products', array_filter( array( 24, 22, $belt, $card, $mah ) ) );
		echo "liked/viewed fixed\n";
	}
}
echo "DONE\n";
