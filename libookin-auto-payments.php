<?php
/**
 * Plugin Name: Libookin Auto Payments
 * Description: Automated Stripe Connect payments system for Libookin authors and publishers with royalty management.
 * Version: 1.0.0
 * Author: Abu Hena
 * Author URI: https://profiles.wordpress.org/codexa
 * Text Domain: libookin-auto-payments
 * Domain Path: /languages
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'LIBOOKIN_AUTO_PAYMENTS_VERSION', '1.0.0' );
define( 'LIBOOKIN_AUTO_PAYMENTS_PLUGIN_FILE', __FILE__ );
define( 'LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIBOOKIN_AUTO_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LIBOOKIN_AUTO_PAYMENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload Composer dependencies
require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'vendor/autoload.php';

// Check for required dependencies
register_activation_hook( __FILE__, 'libookin_auto_payments_check_dependencies' );

/**
 * Check if required plugins are active
 *
 * @since 1.0.0
 */
function libookin_auto_payments_check_dependencies() {
	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			__( 'Libookin Auto Payments requires WooCommerce to be installed and active.', 'libookin-auto-payments' ),
			__( 'Plugin Dependency Error', 'libookin-auto-payments' ),
			array( 'back_link' => true )
		);
	}
}

// Initialize the plugin
add_action( 'plugins_loaded', 'libookin_auto_payments_init' );

/**
 * Initialize the plugin
 *
 * @since 1.0.0
 */
function libookin_auto_payments_init() {
	// Check if WooCommerce is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'libookin_auto_payments_wc_missing_notice' );
		return;
	}

	// Load text domain
	load_plugin_textdomain( 'libookin-auto-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	// Include required files
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-libookin-auto-payments.php';
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-stripe-connect-manager.php';
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-payout-scheduler.php';
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-vendor-dashboard.php';
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-admin-interface.php';
	require_once LIBOOKIN_AUTO_PAYMENTS_PLUGIN_DIR . 'includes/class-email-notifications.php';

	// Initialize the main plugin class
	Libookin_Auto_Payments::get_instance();
}

/**
 * WooCommerce missing notice
 *
 * @since 1.0.0
 */
function libookin_auto_payments_wc_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %1$s: Plugin name, %2$s: WooCommerce link */
				__( '%1$s requires %2$s to be installed and active.', 'libookin-auto-payments' ),
				'<strong>' . __( 'Libookin Auto Payments', 'libookin-auto-payments' ) . '</strong>',
				'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
			);
			?>
		</p>
	</div>
	<?php
}

// Plugin activation hook
register_activation_hook( __FILE__, array( 'Libookin_Auto_Payments', 'activate' ) );

// Plugin deactivation hook
register_deactivation_hook( __FILE__, array( 'Libookin_Auto_Payments', 'deactivate' ) );