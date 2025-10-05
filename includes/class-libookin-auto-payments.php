<?php
/**
 * Main plugin class for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Libookin Auto Payments class
 *
 * @class Libookin_Auto_Payments
 */
class Libookin_Auto_Payments {

	/**
	 * Single instance of the class
	 *
	 * @var Libookin_Auto_Payments
	 */
	private static $instance = null;

	/**
	 * Stripe Connect Manager instance
	 *
	 * @var Libookin_Stripe_Connect_Manager
	 */
	public $stripe_connect;

	/**
	 * Payout Scheduler instance
	 *
	 * @var Libookin_Payout_Scheduler
	 */
	public $payout_scheduler;

	/**
	 * Vendor Dashboard instance
	 *
	 * @var Libookin_Vendor_Dashboard
	 */
	public $vendor_dashboard;

	/**
	 * Admin Interface instance
	 *
	 * @var Libookin_Admin_Interface
	 */
	public $admin_interface;

	/**
	 * Email Notifications instance
	 *
	 * @var Libookin_Email_Notifications
	 */
	public $email_notifications;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_loaded', array( $this, 'init_classes' ) );
	}

	/**
	 * Get the single instance of the class
	 *
	 * @since 1.0.0
	 * @return Libookin_Auto_Payments
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Hook into WordPress actions
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		
		// Initialize hooks for royalty processing
		add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_royalties' ) );
		add_action( 'libookin_process_royalties_async', array( $this, 'process_royalties_immediate' ), 10, 1 );
		// Add custom user meta fields for Stripe Connect
		add_action( 'show_user_profile', array( $this, 'add_stripe_connect_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_stripe_connect_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_stripe_connect_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_stripe_connect_fields' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Initialize plugin classes
	 *
	 * @since 1.0.0
	 */
	public function init_classes() {
		// Initialize Stripe Connect Manager
		if ( class_exists( 'Libookin_Stripe_Connect_Manager' ) ) {
			$this->stripe_connect = new Libookin_Stripe_Connect_Manager();
		}

		// Initialize Payout Scheduler
		if ( class_exists( 'Libookin_Payout_Scheduler' ) ) {
			$this->payout_scheduler = new Libookin_Payout_Scheduler();
		}

		// Initialize Vendor Dashboard
		if ( class_exists( 'Libookin_Vendor_Dashboard' ) ) {
			$this->vendor_dashboard = new Libookin_Vendor_Dashboard();
		}

		// Initialize Admin Interface
		if ( class_exists( 'Libookin_Admin_Interface' ) ) {
			$this->admin_interface = new Libookin_Admin_Interface();
		}

		// Initialize Email Notifications
		if ( class_exists( 'Libookin_Email_Notifications' ) ) {
			$this->email_notifications = new Libookin_Email_Notifications();
		}
	}

	/**
	 * Enqueue frontend scripts and styles
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_style(
			'libookin-auto-payments-frontend',
			LIBOOKIN_AUTO_PAYMENTS_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			LIBOOKIN_AUTO_PAYMENTS_VERSION
		);

		wp_enqueue_script(
			'libookin-auto-payments-frontend',
			LIBOOKIN_AUTO_PAYMENTS_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			LIBOOKIN_AUTO_PAYMENTS_VERSION,
			true
		);

		//localize scripts
		wp_localize_script(
			'libookin-auto-payments-frontend',
			'libookinAutoPayments',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'libookin_auto_payments_nonce' ),
				'user_id'  => get_current_user_id(),
			)
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, 'libookin' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'libookin-auto-payments-admin',
			LIBOOKIN_AUTO_PAYMENTS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LIBOOKIN_AUTO_PAYMENTS_VERSION
		);

		wp_enqueue_script(
			'libookin-auto-payments-admin',
			LIBOOKIN_AUTO_PAYMENTS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			LIBOOKIN_AUTO_PAYMENTS_VERSION,
			true
		);

		// Localize script for AJAX
		wp_localize_script(
			'libookin-auto-payments-admin',
			'libookin_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'libookin_auto_payments_nonce' ),
			)
		);
	}

	/**
	 * Process royalties when an order is completed
	 *
	 * @since 1.0.0
	 * @param int $order_id The order ID.
	 */
	public function process_order_royalties( $order_id ) {
		// Schedule async processing to avoid blocking the order completion
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 5,
				'libookin_process_royalties_async',
				array( $order_id ),
				'libookin-auto-payments'
			);
		} else {
			// Fallback to immediate processing if Action Scheduler is not available
			$this->process_royalties_immediate( $order_id );
		}
	}

	/**
	 * Process royalties immediately (fallback method)
	 *
	 * @since 1.0.0
	 * @param int $order_id The order ID.
	 */
	public function process_royalties_immediate( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$product    = $item->get_product();
			$price_ht   = $product->get_price_excluding_tax();

			// Apply promo discount if active
			$promo_discount = floatval( get_post_meta( $product_id, '_libookin_promo_discount', true ) );
			$promo_end_date = get_post_meta( $product_id, '_libookin_promo_end_date', true );
			$current_date   = gmdate( 'Y-m-d' );

			if ( $promo_discount > 0 && $promo_end_date && $current_date <= $promo_end_date ) {
				$price_ht *= ( 100 - $promo_discount ) / 100;
			}

			// Calculate royalty percentage based on tiered structure
			$royalty_percent = $this->calculate_royalty_percentage( $price_ht );
			$royalty_amount  = ( $price_ht * $royalty_percent ) / 100;
			$vendor_id       = get_post_field( 'post_author', $product_id );

			// Insert royalty record
			$wpdb->insert(
				$wpdb->prefix . 'libookin_royalties',
				array(
					'order_id'        => $order_id,
					'product_id'      => $product_id,
					'vendor_id'       => $vendor_id,
					'price_ht'        => $price_ht,
					'royalty_percent' => $royalty_percent,
					'royalty_amount'  => $royalty_amount,
					'created_at'      => current_time( 'mysql' ),
					'payout_status'   => 'pending',
				),
				array( '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s' )
			);
		}
	}

	/**
	 * Calculate royalty percentage based on price tiers
	 *
	 * @since 1.0.0
	 * @param float $price_ht Price excluding tax.
	 * @return float Royalty percentage.
	 */
	public function calculate_royalty_percentage( $price_ht ) {

		if ( $price_ht < 2.83 ) {
			return 50;
		} elseif ( $price_ht < 4.73 ) {
			return 75;
		} elseif ( $price_ht < 9.47 ) {
			return 80;
		} else if ( $price_ht < 14.21 ) {
			return 70;
		} else {
			return 50;
		}
	}

	/**
	 * Add Stripe Connect fields to user profile
	 *
	 * @since 1.0.0
	 * @param WP_User $user The user object.
	 */
	public function add_stripe_connect_fields( $user ) {
		// Only show for vendors/authors
		if ( ! in_array( 'seller', $user->roles, true ) && ! in_array( 'author', $user->roles, true ) ) {
			return;
		}

		$stripe_account_id = get_user_meta( $user->ID, 'stripe_connect_account_id', true );
		$account_status    = get_user_meta( $user->ID, 'stripe_connect_status', true );
		?>
		<h3><?php esc_html_e( 'Stripe Connect Information', 'libookin-auto-payments' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="stripe_connect_account_id"><?php esc_html_e( 'Stripe Account ID', 'libookin-auto-payments' ); ?></label></th>
				<td>
					<input type="text" name="stripe_connect_account_id" id="stripe_connect_account_id" value="<?php echo esc_attr( $stripe_account_id ); ?>" class="regular-text" readonly />
					<p class="description"><?php esc_html_e( 'This field is automatically populated when the Stripe Connect account is created.', 'libookin-auto-payments' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="stripe_connect_status"><?php esc_html_e( 'Account Status', 'libookin-auto-payments' ); ?></label></th>
				<td>
					<input type="text" name="stripe_connect_status" id="stripe_connect_status" value="<?php echo esc_attr( $account_status ); ?>" class="regular-text" readonly />
					<p class="description"><?php esc_html_e( 'Current verification status of the Stripe Connect account.', 'libookin-auto-payments' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save Stripe Connect fields (admin only)
	 *
	 * @since 1.0.0
	 * @param int $user_id The user ID.
	 */
	public function save_stripe_connect_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['stripe_connect_account_id'] ) ) {
			update_user_meta( $user_id, 'stripe_connect_account_id', sanitize_text_field( wp_unslash( $_POST['stripe_connect_account_id'] ) ) );
		}

		if ( isset( $_POST['stripe_connect_status'] ) ) {
			update_user_meta( $user_id, 'stripe_connect_status', sanitize_text_field( wp_unslash( $_POST['stripe_connect_status'] ) ) );
		}
	}

	/**
	 * Plugin activation hook
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		// Create database tables
		self::create_tables();

		// Schedule cron events
		if ( ! wp_next_scheduled( 'libookin_daily_payout_check' ) ) {
			wp_schedule_event( time(), 'daily', 'libookin_daily_payout_check' );
		}

		// Check for WooCommerce dependency
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				__( 'Libookin Auto Payments requires WooCommerce to be installed and active.', 'libookin-auto-payments' ),
				__( 'Plugin Dependency Error', 'libookin-auto-payments' ),
				array( 'back_link' => true )
			);
		}

		//set transient for flush rewrite rules
		set_transient( 'libookin_flush_rewrite_rules', true );		

	}

	/**
	 * Plugin deactivation hook
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear scheduled events
		wp_clear_scheduled_hook( 'libookin_daily_payout_check' );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 *
	 * @since 1.0.0
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Enhanced royalties table with Stripe Connect support
		$royalties_table = $wpdb->prefix . 'libookin_royalties';
		$royalties_sql   = "CREATE TABLE $royalties_table (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT NOT NULL,
			product_id BIGINT NOT NULL,
			vendor_id BIGINT NOT NULL,
			price_ht DECIMAL(10,2) NOT NULL,
			royalty_percent DECIMAL(5,2) NOT NULL,
			royalty_amount DECIMAL(10,2) NOT NULL,
			payout_status ENUM('pending', 'processing', 'paid', 'failed') DEFAULT 'pending',
			stripe_payout_id VARCHAR(255) NULL,
			payout_date DATETIME NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_vendor_status (vendor_id, payout_status),
			INDEX idx_order_product (order_id, product_id),
			INDEX idx_created_at (created_at)
		) $charset_collate;";

		// Payout history table
		$payouts_table = $wpdb->prefix . 'libookin_payouts';
		$payouts_sql   = "CREATE TABLE $payouts_table (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			vendor_id BIGINT NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			currency VARCHAR(3) DEFAULT 'EUR',
			stripe_payout_id VARCHAR(255) NOT NULL,
			stripe_account_id VARCHAR(255) NOT NULL,
			status ENUM('pending', 'in_transit', 'paid', 'failed', 'canceled') DEFAULT 'pending',
			failure_reason TEXT NULL,
			period_start DATE NOT NULL,
			period_end DATE NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_vendor_id (vendor_id),
			INDEX idx_status (status),
			INDEX idx_created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $royalties_sql );
		dbDelta( $payouts_sql );
	}

	//flush rewrite rules if transient is set
	public function maybe_flush_rewrite_rules() {
		if ( get_transient( 'libookin_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_transient( 'libookin_flush_rewrite_rules' );
		}
	}

}