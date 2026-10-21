<?php
/**
 * داشبورد مشتری «دستیار فروش هوشمند kavexiron».
 *
 * یک تب اختصاصی در «حساب کاربری» ووکامرس با سه بخش:
 *  ۱. پیشنهادهای مخصوص شما (بر اساس خریدهای قبلی)
 *  ۲. جستجوی محصول
 *  ۳. گفتگو با دستیار (چت داخلی داشبورد)
 *
 * @package ForushYar
 */

namespace ForushYar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customer_Dashboard {

	const ENDPOINT = 'forushyar-assistant';

	public function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * ثبت اندپوینت + فلاش یک‌بارهٔ ریرایت.
	 */
	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
		if ( ! get_option( 'forushyar_ai_customer_endpoint_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'forushyar_ai_customer_endpoint_flushed', 1 );
		}
	}

	/**
	 * آیتم منوی حساب کاربری — بعد از «سفارش‌ها».
	 */
	public function menu_item( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'دستیار فروش هوشمند', 'forushyar-ai' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'دستیار فروش هوشمند', 'forushyar-ai' );
		}
		return $out;
	}

	public function endpoint_title( $title ) {
		return __( 'دستیار فروش هوشمند kavexiron', 'forushyar-ai' );
	}

	/**
	 * فقط در صفحهٔ همین اندپوینت، اسکریپت/استایل داشبورد.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		$ver = FORUSHYAR_AI_VERSION;
		wp_enqueue_style(
			'forushyar-dashboard',
			FORUSHYAR_AI_URL . 'assets/css/forushyar-dashboard.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'forushyar-dashboard',
			FORUSHYAR_AI_URL . 'assets/js/forushyar-dashboard.js',
			array(),
			$ver,
			true
		);
		// نکته: کانفیگ به‌صورت inline در render() چاپ می‌شود (قابل‌اعتمادتر از
		// wp_localize_script در صفحهٔ حساب کاربری).
	}

	/**
	 * کانفیگ JS داشبورد.
	 */
	private function js_config() {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'forushyar/v1/chat' ) ),
			'nonce'   => wp_create_nonce( 'forushyar_ai_widget' ),
			'welcome' => (string) Settings::get( 'welcome', '' ),
			'i18n'    => array(
				'you'        => __( 'شما', 'forushyar-ai' ),
				'assistant'  => __( 'دستیار فروش', 'forushyar-ai' ),
				'addToCart'  => __( 'افزودن به سبد', 'forushyar-ai' ),
				'added'      => __( 'به سبد اضافه شد ✓', 'forushyar-ai' ),
				'viewProduct'=> __( 'مشاهده محصول', 'forushyar-ai' ),
				'thinking'   => __( 'دستیار دارد می‌نویسد…', 'forushyar-ai' ),
				'error'      => __( 'خطا در ارتباط؛ دوباره تلاش کنید.', 'forushyar-ai' ),
				'searchPh'   => __( 'نام محصول را بنویسید…', 'forushyar-ai' ),
				'chatPh'     => __( 'پیامت را بنویس…', 'forushyar-ai' ),
				'noResult'   => __( 'محصولی پیدا نشد.', 'forushyar-ai' ),
			),
		);
	}

	/**
	 * رندر داشبورد.
	 */
	public function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$user      = wp_get_current_user();
		$first     = get_user_meta( $user_id, 'first_name', true );
		$hello     = $first ? $first : $user->display_name;
		$rest      = new REST();
		$recs      = $rest->get_recommended_products( $user_id );
		$has_hist  = $this->has_purchase_history( $user_id );
		?>
		<script>var forushyarDashboard = <?php echo wp_json_encode( $this->js_config() ); ?>;</script>
		<div class="fy-dash">
			<div class="fy-dash-head">
				<h3><?php echo esc_html( sprintf( __( 'سلام %s! 👋', 'forushyar-ai' ), $hello ) ); ?></h3>
				<p class="fy-dash-sub"><?php esc_html_e( 'دستیار فروش هوشمند این‌جاست تا کمکت کند؛ پیشنهاد مخصوص تو، جستجو و گفتگو — همه در یک‌جا.', 'forushyar-ai' ); ?></p>
			</div>

			<section class="fy-dash-sec">
				<h4><?php esc_html_e( '✨ پیشنهادهای مخصوص شما', 'forushyar-ai' ); ?></h4>
				<?php if ( $has_hist ) : ?>
					<p class="fy-dash-note"><?php esc_html_e( 'بر اساس خریدهای قبلی‌ات انتخاب شده‌اند.', 'forushyar-ai' ); ?></p>
				<?php else : ?>
					<p class="fy-dash-note"><?php esc_html_e( 'هنوز خریدی ثبت نکرده‌ای؛ این‌ها پرفروش‌ترین‌های فروشگاه‌اند.', 'forushyar-ai' ); ?></p>
				<?php endif; ?>
				<div class="fy-dash-grid">
					<?php foreach ( $recs as $p ) : ?>
						<?php $this->card( $p ); ?>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="fy-dash-sec">
				<h4><?php esc_html_e( '🔍 جستجوی محصول', 'forushyar-ai' ); ?></h4>
				<form id="fy-dash-search" class="fy-dash-search" onsubmit="return false;">
					<input type="search" id="fy-dash-search-q" placeholder="<?php esc_attr_e( 'نام محصول را بنویسید…', 'forushyar-ai' ); ?>" />
					<button type="submit" id="fy-dash-search-btn"><?php esc_html_e( 'جستجو', 'forushyar-ai' ); ?></button>
				</form>
				<div id="fy-dash-search-results" class="fy-dash-grid"></div>
			</section>

			<section class="fy-dash-sec">
				<h4><?php esc_html_e( '💬 گفتگو با دستیار', 'forushyar-ai' ); ?></h4>
				<div id="fy-dash-chat" class="fy-dash-chat">
					<div id="fy-dash-chat-log" class="fy-dash-chat-log"></div>
					<div class="fy-dash-chat-input">
						<input type="text" id="fy-dash-chat-q" placeholder="<?php esc_attr_e( 'پیامت را بنویس…', 'forushyar-ai' ); ?>" autocomplete="off" />
						<button type="button" id="fy-dash-chat-send"><?php esc_html_e( 'ارسال', 'forushyar-ai' ); ?></button>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * کارت محصول (سرور-رندر).
	 */
	private function card( $p ) {
		$id    = isset( $p['id'] ) ? (int) $p['id'] : 0;
		$name  = isset( $p['name'] ) ? $p['name'] : '';
		$img   = isset( $p['image'] ) ? $p['image'] : '';
		$url   = isset( $p['url'] ) ? $p['url'] : '';
		$price = isset( $p['price_text'] ) ? $p['price_text'] : '';
		if ( ! empty( $p['on_sale'] ) && ! empty( $p['sale_price'] ) ) {
			$price = '<span class="fy-dash-price-sale">' . esc_html( $p['sale_price'] ) . '</span>'
				. '<del>' . esc_html( isset( $p['regular_price'] ) ? $p['regular_price'] : '' ) . '</del>';
		} else {
			$price = esc_html( $price );
		}
		?>
		<div class="fy-dash-card" data-pid="<?php echo esc_attr( $id ); ?>">
			<?php if ( $img ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" class="fy-dash-card-img"><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" /></a>
			<?php endif; ?>
			<div class="fy-dash-card-body">
				<a href="<?php echo esc_url( $url ); ?>" class="fy-dash-card-name"><?php echo esc_html( $name ); ?></a>
				<div class="fy-dash-card-price"><?php echo wp_kses_post( $price ); ?> <?php esc_html_e( 'تومان', 'forushyar-ai' ); ?></div>
				<button type="button" class="fy-dash-add" data-pid="<?php echo esc_attr( $id ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
					<?php esc_html_e( 'افزودن به سبد', 'forushyar-ai' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function has_purchase_history( $user_id ) {
		$h = get_user_meta( $user_id, 'forushyar_ai_purchase_history', true );
		return ! empty( $h ) && is_array( $h );
	}
}
