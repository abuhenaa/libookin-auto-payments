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
		
		// Check for pending batch
		$pending_batch = get_option( 'libookin_pending_payout_batch' );
		$completed_batch = get_option( 'libookin_completed_payout_batch' );
		$cancelled_batch = get_option( 'libookin_cancelled_payout_batch' );
		
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Libookin Auto Payments', 'libookin-auto-payments' ); ?></h1>
			
			<div class="libookin-admin-dashboard">
				<div class="payout-overview">
					<h2><?php esc_html_e( 'Next Transfer Preview', 'libookin-auto-payments' ); ?></h2>
					<p><?php printf( esc_html__( '%d vendors eligible for €%s total', 'libookin-auto-payments' ), $preview['vendor_count'], number_format( $preview['total_amount'], 2 ) ); ?></p>
					<?php if ( $preview['vendor_count'] > 0 ) : ?>
						<button id="review-payout" class="button button-secondary" style="margin-right: 10px;">
							<?php esc_html_e( 'Review Vendors', 'libookin-auto-payments' ); ?>
						</button>
						<button id="trigger-daily-check" class="button button-secondary" style="margin-right: 10px;">
							<?php esc_html_e( 'Run Daily Check', 'libookin-auto-payments' ); ?>
						</button>
					<?php endif; ?>
					<button id="trigger-manual-payout" class="button button-primary">
						<?php esc_html_e( 'Trigger Manual Transfer', 'libookin-auto-payments' ); ?>
					</button>
				</div>
				
				<?php if ( $pending_batch ) : ?>
				<div class="payout-scheduled">
					<h2><?php esc_html_e( 'Scheduled Transfer', 'libookin-auto-payments' ); ?></h2>
					<p><?php printf( esc_html__( '%d vendors, €%s total', 'libookin-auto-payments' ), $pending_batch['vendor_count'], number_format( $pending_batch['total_amount'], 2 ) ); ?></p>
					<p><?php printf( esc_html__( 'Scheduled for: %s', 'libookin-auto-payments' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pending_batch['scheduled_time'] ) ); ?></p>
					<p><?php 
						$remaining = $pending_batch['scheduled_time'] - time();
						if ( $remaining > 0 ) {
							$hours = floor( $remaining / 3600 );
							$minutes = floor( ( $remaining % 3600 ) / 60 );
							printf( esc_html__( 'Time remaining: %d hours %d minutes', 'libookin-auto-payments' ), $hours, $minutes );
						} else {
							esc_html_e( 'Processing...', 'libookin-auto-payments' );
						}
					?></p>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Vendor', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Email', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Royalties', 'libookin-auto-payments' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_batch['vendors'] as $vendor ) : ?>
							<tr>
								<td><?php echo esc_html( $vendor['name'] ); ?></td>
								<td><?php echo esc_html( $vendor['email'] ); ?></td>
								<td>€<?php echo number_format( $vendor['total_pending'], 2 ); ?></td>
								<td><?php echo intval( $vendor['royalty_count'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
				
				<?php if ( $completed_batch ) : ?>
				<div class="payout-completed">
					<h2><?php esc_html_e( 'Last Transfer Results', 'libookin-auto-payments' ); ?></h2>
					<p><?php printf( esc_html__( 'Completed: %s', 'libookin-auto-payments' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $completed_batch['completed_at'] ) ) ); ?></p>
					<p><?php printf( esc_html__( 'Processed: %d, Failed: %d, Total: €%s', 'libookin-auto-payments' ), $completed_batch['processed_count'], $completed_batch['failed_count'], number_format( $completed_batch['total_amount'], 2 ) ); ?></p>
				</div>
				<?php endif; ?>
			</div>
			
			<!-- Modal for vendor review -->
			<div id="payout-review-modal" class="libookin-modal" style="display: none;">
				<div class="libookin-modal-content">
					<span class="libookin-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Transfer Preview', 'libookin-auto-payments' ); ?></h2>
					<div id="payout-vendor-list">
						<p><?php esc_html_e( 'Loading...', 'libookin-auto-payments' ); ?></p>
					</div>
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
