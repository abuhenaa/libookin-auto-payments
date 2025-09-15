<?php
/**
 * Admin Interface for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Interface class
 */
class Libookin_Admin_Interface {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Libookin Payments', 'libookin-auto-payments' ),
			__( 'Libookin Payments', 'libookin-auto-payments' ),
			'manage_options',
			'libookin-auto-payments',
			array( $this, 'render_main_page' ),
			'dashicons-money-alt',
			56
		);

		add_submenu_page(
			'libookin-auto-payments',
			__( 'Settings', 'libookin-auto-payments' ),
			__( 'Settings', 'libookin-auto-payments' ),
			'manage_options',
			'libookin-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'libookin_settings', 'libookin_stripe_secret_key' );
		register_setting( 'libookin_settings', 'libookin_stripe_publishable_key' );
	}

	/**
	 * Render main admin page
	 */
	public function render_main_page() {
		$payout_scheduler = new Libookin_Payout_Scheduler();
		$preview = $payout_scheduler->get_payout_preview();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Libookin Auto Payments', 'libookin-auto-payments' ); ?></h1>
			
			<div class="libookin-admin-dashboard">
				<div class="payout-overview">
					<h2><?php esc_html_e( 'Next Payout Preview', 'libookin-auto-payments' ); ?></h2>
					<p><?php printf( esc_html__( '%d vendors eligible for €%s total', 'libookin-auto-payments' ), $preview['vendor_count'], number_format( $preview['total_amount'], 2 ) ); ?></p>
					<button id="trigger-manual-payout" class="button button-primary">
						<?php esc_html_e( 'Trigger Manual Payout', 'libookin-auto-payments' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Libookin Payment Settings', 'libookin-auto-payments' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'libookin_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe Secret Key', 'libookin-auto-payments' ); ?></th>
						<td>
							<input type="password" name="libookin_stripe_secret_key" value="<?php echo esc_attr( get_option( 'libookin_stripe_secret_key' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe Publishable Key', 'libookin-auto-payments' ); ?></th>
						<td>
							<input type="text" name="libookin_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'libookin_stripe_publishable_key' ) ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
