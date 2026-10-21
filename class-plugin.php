<?php
/**
 * کلاس اصلی پلاگین — Singleton و ثبت هوک‌ها.
 *
 * @package ForushYar
 */

namespace ForushYar;

// جلوگیری از دسترسی مستقیم.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Plugin
 *
 * نقطهٔ ورود پلاگین؛ همهٔ هوک‌های سراسری این‌جا ثبت می‌شوند.
 */
class Plugin {

	/**
	 * تنها نمونهٔ کلاس.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * گرفتن نمونهٔ تکی.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * سازندهٔ خصوصی — ثبت هوک‌ها.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_css' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget_assets' ) );
		// مهاجرت امن تنظیمات بعد از آپدیت (کلیدهای جدید مقدار پیش‌فرض می‌گیرند).
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		add_action( 'admin_post_forushyar_ai_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_forushyar_ai_import_settings', array( $this, 'handle_import_settings' ) );

		// سرویس‌های همیشه‌فعال: REST امن چت + نمونهٔ مدیریت (ذخیرهٔ تنظیمات).
		new REST();
		Admin::instance();

		// بدون ووکامرس هم پلاگین بالا می‌آید؛ فقط اخطار مؤدبانه می‌دهد.
		// بررسی در زمان نمایش انجام می‌شود (نه در سازنده) تا ترتیب بارگذاری پلاگین‌ها مهم نباشد.
		add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		// کرون روزانه پاکسازی لاگ‌های قدیمی‌تر از ۹۰ روز.
		add_action( 'forushyar_ai_cleanup_logs', array( $this, 'cleanup_old_logs' ) );
		if ( ! wp_next_scheduled( 'forushyar_ai_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'forushyar_ai_cleanup_logs' );
		}
		// گزارش هفتگی ۱۰ سؤال پرتکرار.
		add_action( 'forushyar_ai_weekly_report', array( $this, 'weekly_questions_report' ) );
		if ( ! wp_next_scheduled( 'forushyar_ai_weekly_report' ) ) {
			wp_schedule_event( time(), 'weekly', 'forushyar_ai_weekly_report' );
		}
		add_action( 'wp_ajax_forushyar_ai_e2e_test', array( Admin::instance(), 'ajax_e2e_test' ) );
		add_action( 'wp_ajax_forushyar_ai_get_logs', array( Admin::instance(), 'ajax_get_logs' ) );
		add_action( 'wp_ajax_forushyar_ai_export_csv', array( Admin::instance(), 'ajax_export_csv' ) );
		add_action( 'wp_ajax_forushyar_ai_chart_data', array( Admin::instance(), 'ajax_chart_data' ) );
		add_action( 'wp_ajax_forushyar_ai_unanswered', array( Admin::instance(), 'ajax_unanswered' ) );
		add_action( 'wp_ajax_forushyar_ai_dismiss_tour', array( Admin::instance(), 'ajax_dismiss_tour' ) );
		add_action( 'wp_ajax_forushyar_ai_export_leads', array( Admin::instance(), 'ajax_export_leads' ) );
		add_action( 'wp_ajax_forushyar_ai_lead_toggle', array( Admin::instance(), 'ajax_lead_toggle' ) );
		add_action( 'wp_ajax_forushyar_ai_rag_test', array( Admin::instance(), 'ajax_rag_test' ) );
		add_action( 'wp_ajax_forushyar_ai_rag_clear_cache', array( Admin::instance(), 'ajax_rag_clear_cache' ) );
		add_action( 'wp_ajax_forushyar_ai_embedding_test', array( Admin::instance(), 'ajax_embedding_test' ) );
		add_action( 'wp_ajax_forushyar_ai_vector_build', array( Admin::instance(), 'ajax_vector_build' ) );
		// ایندکس وکتوری: تغییر محصول یا دانش فروشگاه → پرچم «نیازمند بازسازی».
		add_action( 'save_post_product', array( $this, 'vector_index_dirty_on_product' ) );
		add_action( 'trashed_post', array( $this, 'vector_index_dirty_on_product' ) );
		add_action( 'deleted_post', array( $this, 'vector_index_dirty_always' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'vector_index_dirty_on_settings' ), 10, 2 );
		add_action( 'wp_ajax_forushyar_ai_export_session', array( Admin::instance(), 'ajax_export_session_csv' ) );
		add_action( 'wp_ajax_forushyar_ai_delete_session', array( Admin::instance(), 'ajax_delete_session' ) );
		// بازیابی سبد رها شده: ثبت زمان آخرین تغییر سبد.
		add_action( 'woocommerce_add_to_cart', array( $this, 'track_cart_activity' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'track_cart_activity' ) );
		// حافظه بلندمدت: ثبت خریدهای مشتری.
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_purchase' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'track_purchase' ) );
		// انتساب فروش به چت (D1): وقتی سبد با کمک چت ساخته شده، روی سفارش متا می‌نشیند
		// تا کارت «سفارش‌های چت» داشبورد فقط سفارش‌های واقعاً منتسب را نشان بدهد.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'mark_order_chat_assisted' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'finalize_order_attribution' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'finalize_order_attribution_obj' ), 10, 1 );
	}

	/**
	 * مهاجرت تنظیمات بعد از آپدیت پلاگین.
	 *
	 * اگر نسخهٔ ذخیره‌شده قدیمی‌تر از FORUSHYAR_AI_DB_VERSION باشد، همهٔ
	 * کلیدهای جدید مقدار پیش‌فرض می‌گیرند (بدون پاک شدن تنظیمات فعلی).
	 * این جلوی notice و رفتار خراب بعد از «Upload ZIP + Replace» را می‌گیرد.
	 */
	public function maybe_migrate() {
		if ( ! current_user_can( $this->admin_capability() ) ) {
			return;
		}
		$saved = get_option( 'forushyar_ai_db_version', '0' );
		if ( version_compare( (string) $saved, FORUSHYAR_AI_DB_VERSION, '>=' ) ) {
			return;
		}
		// ادغام با پیش‌فرض‌ها: Settings::get_all() خودش این کار را می‌کند؛
		// با یک save خالی، پیش‌فرض‌های جدید در دیتابیس هم ثبت می‌شوند.
		Settings::save( array() );
		// جدول ایندکس وکتوری (از نسخهٔ ساختار ۲) — dbDelta idempotent است.
		VectorStore::create_table();
		update_option( 'forushyar_ai_db_version', FORUSHYAR_AI_DB_VERSION, false );
	}

	/**
	 * پرچم «ایندکس وکتوری نیازمند بازسازی است» — با تغییر محصول.
	 * فقط وقتی جدول ایندکس وجود دارد معنا دارد؛ mark_dirty ارزان است و
	 * ساخت واقعی ایندکس فقط با کلیک مدیر در تب RAG انجام می‌شود.
	 *
	 * @param int $post_id شناسهٔ پست.
	 */
	public function vector_index_dirty_on_product( $post_id ) {
		if ( function_exists( 'get_post_type' ) && 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		VectorStore::mark_dirty();
	}

	/**
	 * پرچم بازسازی بعد از حذف کامل یک پست (نوع پست بعد از حذف دیگر
	 * قابل تشخیص نیست؛ پرچم اضافی بی‌ضرر است — بازسازی افزایشی است).
	 */
	public function vector_index_dirty_always() {
		VectorStore::mark_dirty();
	}

	/**
	 * پرچم بازسازی وقتی «دانش فروشگاه» یا تنظیمات RAG عوض شود.
	 *
	 * @param mixed $old_value مقدار قبلی آپشن تنظیمات.
	 * @param mixed $value     مقدار جدید.
	 */
	public function vector_index_dirty_on_settings( $old_value, $value ) {
		if ( ! is_array( $old_value ) || ! is_array( $value ) ) {
			return;
		}
		foreach ( array( 'store_knowledge', 'embedding_model', 'rag_mode' ) as $key ) {
			$old = isset( $old_value[ $key ] ) ? (string) $old_value[ $key ] : '';
			$new = isset( $value[ $key ] ) ? (string) $value[ $key ] : '';
			if ( $old !== $new ) {
				VectorStore::mark_dirty();
				return;
			}
		}
	}

	/**
	 * Asset version for cache-busting: a content hash of the file.
	 * With the fixed plugin version, browsers kept serving stale CSS/JS from
	 * cache after visual fixes (the chat status-dot fix never reached the
	 * user's phone). A content hash changes the URL on every real change.
	 */
	private function asset_version( $relative ) {
		$path = FORUSHYAR_AI_DIR . $relative;
		if ( ! file_exists( $path ) ) {
			return FORUSHYAR_AI_VERSION;
		}
		// نسخه بر اساس محتوای فایل (نه زمان تغییر): زیپ‌های دیپلوی زمان فایل را
		// حفظ نمی‌کنند و نسخهٔ زمانی ثابت می‌ماند؛ هش محتوا فقط با تغییر واقعی عوض می‌شود.
		$hash = hash_file( 'crc32b', $path );
		return $hash ? (string) hexdec( $hash ) : FORUSHYAR_AI_VERSION;
	}

	/**
	 * خروجی JSON تنظیمات (بک‌آپ) — دانلود فایل.
	 */
	public function handle_export_settings() {
		if ( ! current_user_can( $this->admin_capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'forushyar-ai' ) );
		}
		check_admin_referer( 'forushyar_ai_export_settings' );
		$settings = Settings::get_all();
		// کلید API رمزنگاری‌شده است؛ در خروجی نگهش می‌داریم تا ایمپورت کامل باشد.
		$payload = array(
			'plugin'   => 'forushyar-ai',
			'version'  => FORUSHYAR_AI_VERSION,
			'exported' => current_time( 'mysql' ),
			'settings' => $settings,
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=forushyar-ai-settings-' . date( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * ورودی JSON تنظیمات (بازیابی بک‌آپ).
	 */
	public function handle_import_settings() {
		if ( ! current_user_can( $this->admin_capability() ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این صفحه را ندارید.', 'forushyar-ai' ) );
		}
		check_admin_referer( 'forushyar_ai_import_settings' );

		$redirect = add_query_arg(
			array( 'page' => 'forushyar-ai', 'tab' => 'connection' ),
			admin_url( 'admin.php' )
		);

		if ( empty( $_FILES['forushyar_ai_settings_file'] ) || ! is_array( $_FILES['forushyar_ai_settings_file'] ) ) {
			wp_safe_redirect( add_query_arg( 'import', 'nofile', $redirect ) );
			exit;
		}
		$file = $_FILES['forushyar_ai_settings_file'];
		if ( ! isset( $file['tmp_name'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'import', 'uploaderr', $redirect ) );
			exit;
		}
		// سقف ۱ مگابایت — فایل تنظیمات خیلی کوچک‌تر است.
		if ( (int) $file['size'] > 1048576 ) {
			wp_safe_redirect( add_query_arg( 'import', 'toobig', $redirect ) );
			exit;
		}
		$raw  = file_get_contents( $file['tmp_name'] );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_safe_redirect( add_query_arg( 'import', 'badjson', $redirect ) );
			exit;
		}
		// فقط کلیدهای شناخته‌شده قبول می‌شوند (دفاع در برابر فایل دست‌کاری‌شده).
		$allowed  = Settings::defaults();
		$incoming = array_intersect_key( $data['settings'], $allowed );
		Settings::save( $incoming );
		wp_safe_redirect( add_query_arg( 'import', 'ok', $redirect ) );
		exit;
	}

	/**
	 * بارگذاری ترجمه‌ها.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'forushyar-ai', false, dirname( FORUSHYAR_AI_BASENAME ) . '/languages' );
	}

	/**
	 * اعلام سازگاری با HPOS (جداول سفارش اختصاصی ووکامرس).
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FORUSHYAR_AI_FILE, true );
		}
	}

	/**
	 * آیا ووکامرس فعال است؟
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * سطح دسترسی لازم برای منوی مدیریت.
	 * اگر ووکامرس نباشد، به manage_options برمی‌گردیم تا صفحه باز بماند.
	 *
	 * @return string
	 */
	public function admin_capability() {
		return $this->is_woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * آیکن منوی مدیریت: SVG لوگوی برند با سایز دقیق ۲۰×۲۰.
	 * استفاده از data-URI باعث می‌شود آیکن همیشه در سایز درست رندر شود
	 * (PNG قبلی ۴۸ پیکسلی بود و در منوی پیشخوان به‌هم‌ریخته نمایش داده می‌شد).
	 *
	 * @return string
	 */
	public function menu_icon() {
		$svg_path = FORUSHYAR_AI_DIR . 'assets/img/logo-icon.svg';
		if ( is_readable( $svg_path ) ) {
			$svg = file_get_contents( $svg_path );
			if ( $svg ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $svg );
			}
		}
		return FORUSHYAR_AI_URL . 'assets/img/logo-menu.png';
	}

	/**
	 * ثبت منوی مدیریت با آیکون لوگوی برند.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'دستیار فروش هوشمند kavexiron', 'forushyar-ai' ),
			__( 'دستیار فروش', 'forushyar-ai' ),
			$this->admin_capability(),
			'forushyar-ai',
			array( Admin::instance(), 'render_settings_page' ),
			$this->menu_icon(),
			56
		);
		add_submenu_page(
			'forushyar-ai',
			__( 'تست چت', 'forushyar-ai' ),
			__( 'تست چت', 'forushyar-ai' ),
			$this->admin_capability(),
			'forushyar-ai-test',
			array( Admin::instance(), 'render_test_page' )
		);
		add_submenu_page(
			'forushyar-ai',
			__( 'شروع سریع', 'forushyar-ai' ),
			__( 'شروع سریع', 'forushyar-ai' ),
			$this->admin_capability(),
			'forushyar-ai-quickstart',
			array( Admin::instance(), 'render_quickstart_page' )
		);
		add_submenu_page(
			'forushyar-ai',
			__( 'تست خرید کامل', 'forushyar-ai' ),
			__( 'تست خرید کامل', 'forushyar-ai' ),
			$this->admin_capability(),
			'forushyar-ai-e2e',
			array( Admin::instance(), 'render_e2e_page' )
		);
	}

	/**
	 * CSS محدودکنندهٔ اندازهٔ آیکن منو — در همهٔ صفحه‌های مدیریت.
	 */
	public function menu_icon_css() {
		echo '<style>#toplevel_page_forushyar-ai .wp-menu-image img { width: 20px !important; height: 20px !important; max-width: 20px !important; max-height: 20px !important; padding: 0 !important; }</style>';
	}

	/**
	 * لود استایل مدیریت — در همهٔ صفحه‌های مدیریت پلاگین.
	 * اسکریپت‌ها صفحه‌اختصاصی می‌مانند (تنظیمات فقط صفحهٔ اصلی، تست فقط صفحهٔ تست)،
	 * ولی استایل‌های مشترک (فونت + admin.css) روی هر چهار صفحه لود می‌شوند تا
	 * «شروع سریع» و «تست خرید کامل» هم بی‌استایل نمانند.
	 * enqueue با هندل یکسان در وردپرس ذاتاً یک‌بار انجام می‌شود، پس لود تکراری نداریم.
	 *
	 * @param string $hook_suffix هوک صفحهٔ جاری.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// همهٔ slugهای صفحه‌های مدیریت پلاگین => نوع صفحه.
		$our_pages = array(
			'forushyar-ai'            => 'main',
			'forushyar-ai-test'       => 'test',
			'forushyar-ai-quickstart' => 'quickstart',
			'forushyar-ai-e2e'        => 'e2e',
		);
		// تشخیص از روی هوک: صفحهٔ اصلی toplevel_page_* و زیرمنوها forushyar-ai_page_*.
		$page = null;
		foreach ( $our_pages as $slug => $kind ) {
			if ( 'toplevel_page_' . $slug === $hook_suffix || 'forushyar-ai_page_' . $slug === $hook_suffix ) {
				$page = $kind;
				break;
			}
		}
		// پوشش احتیاطی: اگر هوک به هر دلیلی متفاوت بود، از پارامتر page هم تشخیص بده.
		if ( null === $page && is_admin() && isset( $_GET['page'] ) ) {
			$requested = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط برای تشخیص صفحهٔ جاری.
			if ( isset( $our_pages[ $requested ] ) ) {
				$page = $our_pages[ $requested ];
			}
		}
		if ( null === $page ) {
			return;
		}
		$is_main = ( 'main' === $page );
		$is_test = ( 'test' === $page );
		wp_enqueue_style(
			'forushyar-ai-fonts',
			FORUSHYAR_AI_URL . 'assets/css/forushyar-fonts.css',
			array(),
			$this->asset_version( 'assets/css/forushyar-fonts.css' )
		);
		wp_enqueue_style(
			'forushyar-ai-admin',
			FORUSHYAR_AI_URL . 'assets/css/admin.css',
			array( 'forushyar-ai-fonts' ),
			$this->asset_version( 'assets/css/admin.css' )
		);
		// لایهٔ تم روشن/تاریک داشبورد (دانیال — fix-theme): روی همهٔ صفحه‌های پلاگین.
		wp_enqueue_style(
			'forushyar-ai-admin-theme',
			FORUSHYAR_AI_URL . 'assets/css/forushyar-admin-theme.css',
			array( 'forushyar-ai-admin' ),
			$this->asset_version( 'assets/css/forushyar-admin-theme.css' )
		);
		wp_enqueue_script(
			'forushyar-ai-admin-theme',
			FORUSHYAR_AI_URL . 'assets/js/forushyar-admin-theme.js',
			array(),
			$this->asset_version( 'assets/js/forushyar-admin-theme.js' ),
			true
		);
		if ( $is_test ) {
			wp_enqueue_script(
				'forushyar-ai-test',
				FORUSHYAR_AI_URL . 'assets/js/admin-test.js',
				array(),
				$this->asset_version( 'assets/js/admin-test.js' ),
				true
			);
			wp_localize_script(
				'forushyar-ai-test',
				'ForushYarTest',
				array(
					'restUrl' => esc_url_raw( rest_url( 'forushyar/v1/chat' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
		if ( $is_main ) {
			// استایل پایهٔ راهنمای درجا (Help UI) — فقط صفحهٔ تنظیمات.
			wp_enqueue_style(
				'forushyar-ai-admin-help',
				FORUSHYAR_AI_URL . 'assets/css/admin-help.css',
				array( 'forushyar-ai-admin' ),
				$this->asset_version( 'assets/css/admin-help.css' )
			);
			wp_enqueue_script(
				'forushyar-ai-settings',
				FORUSHYAR_AI_URL . 'assets/js/admin-settings.js',
				array(),
				$this->asset_version( 'assets/js/admin-settings.js' ),
				true
			);
			wp_localize_script(
				'forushyar-ai-settings',
				'ForushYarAdmin',
				array(
					'ajaxUrl'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'nonce'        => wp_create_nonce( 'forushyar_ai_admin' ),
					'testing'      => __( 'در حال تست اتصال…', 'forushyar-ai' ),
					'scanning'     => __( 'در حال خواندن فروشگاه…', 'forushyar-ai' ),
					'scanDone'     => __( 'پیش‌نویس ساخته شد — بررسی کنید و «ذخیره تنظیمات» را بزنید.', 'forushyar-ai' ),
					'confirmScanReplace' => __( 'در فیلد «دانش فروشگاه» متن هست. پیش‌نویس خودکار جایگزینش شود؟ (هنوز چیزی ذخیره نمی‌شود و تا «ذخیره تنظیمات» را نزنید، متن فعلی سر جایش است.)', 'forushyar-ai' ),
					'error'        => __( 'خطا در ارتباط با سرور.', 'forushyar-ai' ),
					'confirmClear' => __( 'همهٔ گفتگوهای ثبت‌شده پاک شود؟ این عمل قابل بازگشت نیست.', 'forushyar-ai' ),
				)
			);
		}
	}

	/**
	 * پالت‌های رنگ ویجت (هماهنگ با برند).
	 *
	 * @return array
	 */
	public function widget_colors() {
		return array(
			'gold'   => array(
				'label'   => __( 'طلایی (برند kavexiron)', 'forushyar-ai' ),
				'primary' => '#C9A227',
				'text'    => '#111111',
			),
			'blue'   => array(
				'label'   => __( 'آبی', 'forushyar-ai' ),
				'primary' => '#1E88E5',
				'text'    => '#ffffff',
			),
			'green'  => array(
				'label'   => __( 'سبز', 'forushyar-ai' ),
				'primary' => '#2E7D32',
				'text'    => '#ffffff',
			),
			'purple' => array(
				'label'   => __( 'بنفش', 'forushyar-ai' ),
				'primary' => '#6A1B9A',
				'text'    => '#ffffff',
			),
		);
	}

	/**
	 * لود تنبل ویجت چت در فرانت‌اند — فقط طبق تنظیمات نمایش.
	 */
	public function enqueue_widget_assets() {
		if ( is_admin() ) {
			return;
		}
		$display = Settings::get( 'widget_display', 'all' );
		if ( 'off' === $display ) {
			return;
		}
		if ( 'home' === $display ) {
			if ( ! ( function_exists( 'is_front_page' ) && is_front_page() ) ) {
				return;
			}
		} elseif ( 'product' === $display ) {
			if ( ! ( function_exists( 'is_product' ) && is_product() ) ) {
				return;
			}
		} elseif ( 'shop' === $display ) {
			$is_shop_page = function_exists( 'is_woocommerce' ) && is_woocommerce();
			if ( ! $is_shop_page && ! ( function_exists( 'is_cart' ) && is_cart() ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
				return;
			}
		}

		wp_enqueue_script(
			'forushyar-ai-widget-loader',
			FORUSHYAR_AI_URL . 'assets/js/forushyar-widget-loader.js',
			array(),
			$this->asset_version( 'assets/js/forushyar-widget-loader.js' ),
			true
		);

		$colors = $this->widget_colors();
		$color  = Settings::get( 'widget_color', 'gold' );
		if ( ! isset( $colors[ $color ] ) ) {
			$color = 'gold';
		}

		$quick_replies = array();
		if ( (int) Settings::get( 'quick_replies_enabled', 1 ) ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'quick_replies', '' ) ) ) );
			$quick_replies = array_values( array_slice( $lines, 0, 5 ) );
			// اگر خالی بود، از پیش‌فرض‌ها استفاده کن.
			if ( empty( $quick_replies ) ) {
				$quick_replies = array(
					'هزینه ارسال چقدر است؟',
					'شرایط مرجوعی چیست؟',
					'پیگیری سفارش',
					'تخفیف‌های فعال',
				);
			}
		}

		$config = array(
			'restUrl'            => esc_url_raw( rest_url( 'forushyar/v1/chat' ) ),
			'nonce'              => wp_create_nonce( 'forushyar_ai_widget' ),
			'welcome'            => Settings::get( 'welcome', '' ),
			'title'              => __( 'دستیار فروش هوشمند kavexiron', 'forushyar-ai' ),
			'logoUrl'            => FORUSHYAR_AI_URL . 'assets/img/logo-robot-avatar.png',
			'lazyDelay'          => (int) Settings::get( 'lazy_delay', 3 ),
			'widgetJsUrl'        => FORUSHYAR_AI_URL . 'assets/js/forushyar-widget.js?v=' . $this->asset_version( 'assets/js/forushyar-widget.js' ),
			'widgetCssUrl'       => FORUSHYAR_AI_URL . 'assets/css/forushyar-widget.css?v=' . $this->asset_version( 'assets/css/forushyar-widget.css' ),
			'primary'            => $colors[ $color ]['primary'],
			'primaryText'        => $colors[ $color ]['text'],
			'proactiveOpen'      => (bool) (int) Settings::get( 'proactive_open', 0 ),
			'proactiveNudge'     => (bool) (int) Settings::get( 'proactive_nudge', 1 ),
			'proactiveDelay'     => (int) Settings::get( 'proactive_delay', 25 ),
			'proactiveNudgeText' => Settings::get( 'proactive_nudge_text', '' ),
			'quickRepliesEnabled' => (bool) (int) Settings::get( 'quick_replies_enabled', 1 ),
			'quickReplies'       => $quick_replies,
			'abandonedCart'      => $this->get_abandoned_cart(),
			'cartUrl'            => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
		);
		wp_add_inline_script(
			'forushyar-ai-widget-loader',
			'window.ForushYarWidgetConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * اخطار نبود ووکامرس — مؤدبانه و بدون خطای مهلک.
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( $this->admin_capability() ) ) {
			return;
		}
		// بررسی اینجا انجام می‌شود تا ترتیب بارگذاری پلاگین‌ها (ووکامرس بعد از ما) مهم نباشد.
		if ( $this->is_woocommerce_active() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'پلاگین «دستیار فروش هوشمند kavexiron» برای عملکرد کامل به ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'forushyar-ai' );
		echo '</p></div>';
	}

	/**
	 * ثبت فعالیت سبد برای بازیابی سبد رها شده.
	 */
	public function track_cart_activity() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$count = WC()->cart->get_cart_contents_count();
		if ( $count > 0 ) {
			// سبد پر است — زمان را ثبت کن.
			set_transient( 'fy_cart_active_' . $this->get_visitor_key(), time(), DAY_IN_SECONDS );
		} else {
			// سبد خالی شد — پرچم را پاک کن.
			delete_transient( 'fy_cart_active_' . $this->get_visitor_key() );
			delete_transient( 'fy_cart_reminded_' . $this->get_visitor_key() );
			// انتساب چت هم با خالی شدن کامل سبد باطل می‌شود: خرید بعدیِ دستیِ
			// همان سشن نباید «فروش چت» شمرده شود (صداقت آمار D1/D3).
			self::clear_chat_attribution_flag();
		}
	}

	/**
	 * آیا سبد سشن جاری با کمک چت ساخته شده است؟ (پرچم D1)
	 *
	 * پرچم را SalesTools هنگام افزودن موفق از مسیر چت روی سشن ووکامرس می‌گذارد.
	 *
	 * @return bool
	 */
	public static function cart_session_is_chat_assisted() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return false;
		}
		try {
			return '1' === (string) WC()->session->get( 'forushyar_ai_assisted', '' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * پاک کردن پرچم انتساب چت از سشن ووکامرس (بی‌صدا و امن).
	 */
	public static function clear_chat_attribution_flag() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}
		try {
			WC()->session->set( 'forushyar_ai_assisted', null );
			WC()->session->set( 'forushyar_ai_assisted_at', null );
		} catch ( \Throwable $e ) {
			// سشن در دسترس نیست — انتساب فقط از روی متای سفارش خوانده می‌شود.
		}
	}

	/**
	 * مهر انتساب روی آبجکت سفارش هنگام ساخت در تسویهٔ کلاسیک (D1).
	 *
	 * متا این‌جا روی آبجکت گذاشته می‌شود تا قبل از هر رویداد دیگری روی
	 * سفارش ذخیره شده باشد؛ شمارش آمار در finalize انجام می‌شود.
	 *
	 * @param \WC_Order $order سفارش در حال ساخت.
	 * @param array     $data  داده‌های تسویه (استفاده نمی‌شود).
	 */
	public function mark_order_chat_assisted( $order, $data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $order instanceof \WC_Order && self::cart_session_is_chat_assisted() ) {
			$order->update_meta_data( '_forushyar_ai_assisted', '1' );
		}
	}

	/**
	 * نهایی‌کردن انتساب برای تسویهٔ کلاسیک (D1 + شمارندهٔ سفارش D3).
	 *
	 * @param int $order_id شناسهٔ سفارش ساخته‌شده.
	 */
	public function finalize_order_attribution( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->finalize_order_attribution_obj( $order );
		}
	}

	/**
	 * نهایی‌کردن انتساب روی آبجکت سفارش (مسیر کلاسیک و بلاک/Store API).
	 *
	 * - اگر متا از قدم ساخت سفارش نیامده ولی پرچم سشن هست، متا را می‌گذارد.
	 * - شمارندهٔ دائمی سفارش منتسب + مبلغ فقط یک بار برای هر سفارش زیاد می‌شود
	 *   (متای `_forushyar_ai_counted` جلوی شمارش مضاعف را می‌گیرد).
	 * - در پایان پرچم سشن پاک می‌شود تا سفارش دستی بعدی منتسب نشود.
	 *
	 * @param \WC_Order $order سفارش.
	 */
	public function finalize_order_attribution_obj( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$dirty = false;
		if ( '1' !== (string) $order->get_meta( '_forushyar_ai_assisted' ) ) {
			if ( ! self::cart_session_is_chat_assisted() ) {
				return; // این سفارش ربطی به چت ندارد.
			}
			$order->update_meta_data( '_forushyar_ai_assisted', '1' );
			$dirty = true;
		}
		if ( '1' !== (string) $order->get_meta( '_forushyar_ai_counted' ) ) {
			self::bump_stats( 'orders', 1, (float) $order->get_total() );
			$order->update_meta_data( '_forushyar_ai_counted', '1' );
			$dirty = true;
		}
		if ( $dirty ) {
			$order->save();
		}
		self::clear_chat_attribution_flag();
	}

	/**
	 * مقادیر پیش‌فرض یک سطل آمار (D3).
	 *
	 * @return array
	 */
	public static function stats_bucket_defaults() {
		return array(
			'chats'   => 0,
			'carts'   => 0,
			'orders'  => 0,
			'revenue' => 0.0,
		);
	}

	/**
	 * خواندن آمار دائمی داشبورد با ساختار نرمال‌شده (D3).
	 *
	 * ساختار آپشن `forushyar_ai_stats`:
	 * - کلیدهای «Y-m» (سازگار با نسخهٔ قبلی): سطل ماهانهٔ chats/carts/orders/revenue
	 * - `_total`: مجموع همهٔ زمان‌ها (از سطل‌های ماهانهٔ قدیمی + لاگ موجود seed می‌شود)
	 * - `_days`: سطل‌های روزانهٔ ۳۵ روز اخیر برای کارت‌های امروز/هفته
	 *
	 * قبلاً «کل گفتگوها» از روی لاگ غلتان ۵۰۰تایی شمرده می‌شد و سقف داشت؛
	 * از این نسخه کارت «کل» فقط از همین شمارنده‌های دائمی خوانده می‌شود.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( 'forushyar_ai_stats', array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}
		if ( empty( $stats['_seeded'] ) ) {
			// Seed یک‌باره از دادهٔ موجود تا «کل» بعد از آپدیت صفر نشود:
			// سبدها از سطل‌های ماهانهٔ قبلی، گفتگوها از بزرگ‌ترینِ (جمع ماهانه، تعداد لاگ فعلی).
			$total = self::stats_bucket_defaults();
			foreach ( $stats as $k => $v ) {
				if ( is_array( $v ) && preg_match( '/^\d{4}-\d{2}$/', (string) $k ) ) {
					$total['chats']   += isset( $v['chats'] ) ? (int) $v['chats'] : 0;
					$total['carts']   += isset( $v['carts'] ) ? (int) $v['carts'] : 0;
					$total['orders']  += isset( $v['orders'] ) ? (int) $v['orders'] : 0;
					$total['revenue'] += isset( $v['revenue'] ) ? (float) $v['revenue'] : 0.0;
				}
			}
			$logs = get_option( 'forushyar_ai_chat_logs', array() );
			if ( is_array( $logs ) ) {
				$total['chats'] = max( $total['chats'], count( $logs ) );
			}
			$stats['_total']  = $total;
			$stats['_days']   = array();
			$stats['_seeded'] = 1;
			update_option( 'forushyar_ai_stats', $stats, false );
		}
		// نرمال‌سازی: همهٔ سطل‌ها همهٔ کلیدها را داشته باشند.
		$stats['_total'] = array_merge( self::stats_bucket_defaults(), is_array( $stats['_total'] ) ? $stats['_total'] : array() );
		if ( ! isset( $stats['_days'] ) || ! is_array( $stats['_days'] ) ) {
			$stats['_days'] = array();
		}
		foreach ( $stats as $k => $v ) {
			if ( is_array( $v ) && preg_match( '/^\d{4}-\d{2}$/', (string) $k ) ) {
				$stats[ $k ] = array_merge( self::stats_bucket_defaults(), $v );
			}
		}
		return $stats;
	}

	/**
	 * زیاد کردن شمارندهٔ دائمی در رویداد واقعی‌اش (D3).
	 *
	 * منابع سیم‌کشی‌شده: chats از REST::log_chat (هر تبادل گفتگو)، carts از
	 * SalesTools::add_to_cart موفق (همهٔ مسیرهای چت)، orders از نهایی‌شدن
	 * انتساب سفارش (همراه مبلغ برای کارت فروش منتسب).
	 *
	 * @param string $key     یکی از chats|carts|orders.
	 * @param int    $amount  مقدار افزایش.
	 * @param float  $revenue مبلغ (فقط برای orders).
	 */
	public static function bump_stats( $key, $amount = 1, $revenue = 0.0 ) {
		if ( ! in_array( $key, array( 'chats', 'carts', 'orders' ), true ) ) {
			return;
		}
		$amount = (int) $amount;
		if ( $amount <= 0 ) {
			return;
		}
		$stats = self::get_stats();
		$month = current_time( 'Y-m' );
		$day   = current_time( 'Y-m-d' );
		if ( ! isset( $stats[ $month ] ) || ! is_array( $stats[ $month ] ) ) {
			$stats[ $month ] = self::stats_bucket_defaults();
		}
		if ( ! isset( $stats['_days'][ $day ] ) || ! is_array( $stats['_days'][ $day ] ) ) {
			$stats['_days'][ $day ] = self::stats_bucket_defaults();
		}
		foreach ( array( '_total', $month ) as $bucket ) {
			$stats[ $bucket ][ $key ] += $amount;
			if ( $revenue > 0 ) {
				$stats[ $bucket ]['revenue'] += (float) $revenue;
			}
		}
		$stats['_days'][ $day ][ $key ] += $amount;
		if ( $revenue > 0 ) {
			$stats['_days'][ $day ]['revenue'] += (float) $revenue;
		}
		// سطل‌های روزانه فقط ۳۵ روز نگه داشته می‌شوند تا آپشن باد نکند.
		$cutoff = date( 'Y-m-d', current_time( 'timestamp' ) - 35 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		foreach ( array_keys( $stats['_days'] ) as $d ) {
			if ( (string) $d < $cutoff ) {
				unset( $stats['_days'][ $d ] );
			}
		}
		update_option( 'forushyar_ai_stats', $stats, false );
	}

	/**
	 * ثبت خرید مشتری برای حافظه بلندمدت.
	 *
	 * @param int $order_id شناسه سفارش.
	 */
	public function track_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		$history = get_user_meta( $user_id, 'forushyar_ai_purchase_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			$history[] = array(
				'product_id'   => $pid,
				'product_name' => $item->get_name(),
				'date'         => current_time( 'mysql' ),
				'categories'   => wc_get_product_term_ids( $pid, 'product_cat' ),
			);
		}
		// فقط ۵۰ خرید آخر.
		$history = array_slice( $history, -50 );
		update_user_meta( $user_id, 'forushyar_ai_purchase_history', $history );
	}

	/**
	 * دریافت حافظه خرید مشتری برای شخصی‌سازی.
	 *
	 * @param int $user_id شناسه کاربر.
	 * @return string خلاصه حافظه برای پرامپت.
	 */
	public static function get_customer_memory( $user_id ) {
		if ( ! $user_id ) {
			return '';
		}
		$history = get_user_meta( $user_id, 'forushyar_ai_purchase_history', true );
		if ( empty( $history ) || ! is_array( $history ) ) {
			return '';
		}
		$names = array();
		$cats = array();
		foreach ( array_slice( $history, -5 ) as $h ) {
			if ( ! empty( $h['product_name'] ) ) {
				$names[] = $h['product_name'];
			}
			if ( ! empty( $h['categories'] ) && is_array( $h['categories'] ) ) {
				$cats = array_merge( $cats, $h['categories'] );
			}
		}
		$names = array_unique( $names );
		if ( empty( $names ) ) {
			return '';
		}
		$memory = 'این مشتری قبلاً این محصولات را خریده: ' . implode( '، ', $names ) . '. می‌توانی محصولات مرتبط پیشنهاد بدهی.';
		// پسندیده‌ها (دمو/آینده): اگر مشتری محصولی را لایک کرده، در پیشنهادها لحاظ کن.
		$liked = get_user_meta( $user_id, 'forushyar_ai_liked_products', true );
		if ( ! empty( $liked ) && is_array( $liked ) ) {
			$liked_names = array();
			foreach ( array_slice( $liked, 0, 5 ) as $pid ) {
				$p = wc_get_product( (int) $pid );
				if ( $p ) {
					$liked_names[] = $p->get_name();
				}
			}
			if ( ! empty( $liked_names ) ) {
				$memory .= ' این محصولات را هم پسندیده: ' . implode( '، ', array_unique( $liked_names ) ) . '.';
			}
		}
		return $memory;
	}
	private function get_visitor_key() {
		if ( is_user_logged_in() ) {
			return 'u_' . get_current_user_id();
		}
		// از کوکی سشن ووکامرس استفاده کن.
		if ( function_exists( 'WC' ) && WC()->session ) {
			$cid = WC()->session->get_customer_id();
			if ( $cid ) {
				return 'g_' . substr( sanitize_key( $cid ), 0, 32 );
			}
		}
		return 'g_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown' );
	}

	/**
	 * بررسی سبد رها شده: اگر بیش از ۱ ساعت از آخرین فعالیت گذشته و یادآوری نشده.
	 *
	 * @return array|null اطلاعات سبد رها شده یا null.
	 */
	public function get_abandoned_cart() {
		$key = $this->get_visitor_key();
		$last_active = get_transient( 'fy_cart_active_' . $key );
		$reminded = get_transient( 'fy_cart_reminded_' . $key );
		if ( ! $last_active || $reminded ) {
			return null;
		}
		// بیش از ۱ ساعت گذشته؟
		if ( time() - (int) $last_active < HOUR_IN_SECONDS ) {
			return null;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$count = WC()->cart->get_cart_contents_count();
		if ( $count < 1 ) {
			return null;
		}
		// علامت بزن که یادآوری شده.
		set_transient( 'fy_cart_reminded_' . $key, 1, DAY_IN_SECONDS );
		return array(
			'count' => $count,
			'total' => WC()->cart->get_cart_contents_total(),
		);
	}

	/**
	 * گزارش هفتگی ۱۰ سؤال پرتکرار.
	 */
	public function weekly_questions_report() {
		$logs = get_option( 'forushyar_ai_chat_logs', array() );
		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return;
		}
		$week_ago = strtotime( '-7 days' );
		$questions = array();
		foreach ( $logs as $l ) {
			$t = isset( $l['time'] ) ? strtotime( (string) $l['time'] ) : 0;
			if ( $t < $week_ago ) {
				continue;
			}
			$u = isset( $l['user'] ) ? trim( (string) $l['user'] ) : '';
			if ( mb_strlen( $u ) < 5 ) {
				continue;
			}
			$key = mb_substr( $u, 0, 100 );
			$questions[ $key ] = isset( $questions[ $key ] ) ? $questions[ $key ] + 1 : 1;
		}
		arsort( $questions );
		$top = array_slice( $questions, 0, 10, true );
		update_option( 'forushyar_ai_weekly_top', array(
			'date'  => current_time( 'mysql' ),
			'items' => $top,
		), false );
	}

	/**
	 * پاکسازی لاگ‌های قدیمی‌تر از ۹۰ روز.
	 */
	public function cleanup_old_logs() {
		$logs = get_option( 'forushyar_ai_chat_logs', array() );
		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return;
		}
		$cutoff = strtotime( '-90 days' );
		$kept = array();
		foreach ( $logs as $l ) {
			$t = isset( $l['time'] ) ? strtotime( (string) $l['time'] ) : time();
			if ( $t >= $cutoff ) {
				$kept[] = $l;
			}
		}
		if ( count( $kept ) !== count( $logs ) ) {
			update_option( 'forushyar_ai_chat_logs', $kept, false );
		}
	}

	/**
	 * جلوگیری از کلون شدن نمونهٔ تکی.
	 */
	private function __clone() {
	}

	/**
	 * جلوگیری از unserialize شدن نمونهٔ تکی.
	 */
	public function __wakeup() {
	}
}
