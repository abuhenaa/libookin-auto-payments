<?php
/**
 * Payout Scheduler for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payout Scheduler class
 *
 * Handles automated payout scheduling and processing every 2 months
 * with minimum €15 threshold and proper date calculations.
 *
 * @class Libookin_Payout_Scheduler
 */
class Libookin_Payout_Scheduler {

	/**
	 * Minimum payout amount in EUR
	 *
	 * @var float
	 */
	const MINIMUM_PAYOUT_AMOUNT = 15.00;

	/**
	 * Payout frequency in months
	 *
	 * @var int
	 */
	const PAYOUT_FREQUENCY_MONTHS = 2;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'libookin_daily_payout_check', array( $this, 'check_and_process_payouts' ) );
		add_action( 'libookin_process_scheduled_payouts', array( $this, 'process_scheduled_payouts' ) );
		add_action( 'wp_ajax_trigger_manual_payout', array( $this, 'ajax_trigger_manual_payout' ) );
		add_action( 'wp_ajax_get_payout_preview', array( $this, 'ajax_get_payout_preview' ) );
	}

	/**
	 * Check if payouts should be processed today
	 *
	 * @since 1.0.0
	 */
	public function check_and_process_payouts() {
		$today = new DateTime();
		
		// Check if today is the 1st of the month or next working day
		if ( ! $this->is_payout_day( $today ) ) {
			return;
		}

		$eligible_vendors = $this->get_eligible_vendors();
		
		if ( empty( $eligible_vendors ) ) {
			return;
		}

		// Schedule batch processing with 6-hour delay for admin review
		$this->schedule_payout_batch( $eligible_vendors );
	}

	/**
	 * Check if today is a valid payout day
	 *
	 * @since 1.0.0
	 * @param DateTime $date The date to check.
	 * @return bool True if it's a payout day.
	 */
	private function is_payout_day( DateTime $date ) {
		$day_of_month = intval( $date->format( 'd' ) );
		$day_of_week  = intval( $date->format( 'N' ) ); // 1 = Monday, 7 = Sunday

		// If it's the 1st of the month
		if ( 1 === $day_of_month ) {
			// If it's a weekend, move to next Monday
			if ( $day_of_week >= 6 ) {
				return false;
			}
			return true;
		}

		// If it's the 2nd and the 1st was a Saturday
		if ( 2 === $day_of_month && 1 === $day_of_week ) {
			$yesterday = clone $date;
			$yesterday->sub( new DateInterval( 'P1D' ) );
			return 6 === intval( $yesterday->format( 'N' ) );
		}

		// If it's the 3rd and the 1st was a Sunday
		if ( 3 === $day_of_month && 1 === $day_of_week ) {
			$two_days_ago = clone $date;
			$two_days_ago->sub( new DateInterval( 'P2D' ) );
			return 7 === intval( $two_days_ago->format( 'N' ) );
		}

		return false;
	}

	/**
	 * Get vendors eligible for payout
	 *
	 * @since 1.0.0
	 * @return array Array of vendor data eligible for payout.
	 */
	public function get_eligible_vendors() {
		global $wpdb;

		// Define the start and end of the month exactly two months ago
		$start_date = new DateTime('first day of -3 months');
		$start_date->setTime(0, 0, 0);
		$end_date = new DateTime('last day of -3 months');
		$end_date->setTime(23, 59, 59);

		// Get formatted timestamps
		$start = $start_date->format('Y-m-d H:i:s');
		$end   = $end_date->format('Y-m-d H:i:s');

		// Get vendors with pending royalties >= €15 and older than 2 months
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					r.vendor_id,
					u.user_email,
					u.display_name,
					SUM(r.royalty_amount) as total_pending,
					MIN(r.created_at) as oldest_royalty,
					COUNT(r.id) as royalty_count,
					um.meta_value as stripe_account_id
				FROM {$wpdb->prefix}libookin_royalties r
				INNER JOIN {$wpdb->users} u ON r.vendor_id = u.ID
				WHERE r.payout_status = 'pending' 
				AND r.created_at <= %s
				AND um.meta_value IS NOT NULL
				AND um.meta_value != ''
				GROUP BY r.vendor_id
				HAVING total_pending >= %f
				ORDER BY oldest_royalty ASC",
				$start,
				$end,
				self::MINIMUM_PAYOUT_AMOUNT
			)
		);

		$eligible_vendors = array();

		foreach ( $results as $result ) {
			// Verify Stripe account is active
			$stripe_manager = new Libookin_Stripe_Connect_Manager();
			$account_status = $stripe_manager->get_account_status( $result->stripe_account_id );

			if ( ! is_wp_error( $account_status ) && $account_status['payouts_enabled'] ) {
				$eligible_vendors[] = array(
					'vendor_id'         => $result->vendor_id,
					'email'             => $result->user_email,
					'name'              => $result->display_name,
					'total_pending'     => floatval( $result->total_pending ),
					'royalty_count'     => intval( $result->royalty_count ),
					'oldest_royalty'    => $result->oldest_royalty,
					'stripe_account_id' => $result->stripe_account_id,
				);
			}
		}

		return $eligible_vendors;
	}

	/**
	 * Schedule payout batch with admin notification
	 *
	 * @since 1.0.0
	 * @param array $eligible_vendors Array of eligible vendors.
	 */
	private function schedule_payout_batch( $eligible_vendors ) {
		$total_amount  = array_sum( array_column( $eligible_vendors, 'total_pending' ) );
		$vendor_count  = count( $eligible_vendors );
		$scheduled_time = time() + ( 6 * HOUR_IN_SECONDS ); // 6 hours delay

		// Store batch data for processing
		update_option( 'libookin_pending_payout_batch', array(
			'vendors'        => $eligible_vendors,
			'total_amount'   => $total_amount,
			'vendor_count'   => $vendor_count,
			'scheduled_time' => $scheduled_time,
			'status'         => 'scheduled',
		) );

		// Schedule the actual payout processing
		wp_schedule_single_event( $scheduled_time, 'libookin_process_scheduled_payouts' );

		// Send notification to admin
		$this->send_admin_notification( $vendor_count, $total_amount, $scheduled_time );
	}

	/**
	 * Process scheduled payouts
	 *
	 * @since 1.0.0
	 */
	public function process_scheduled_payouts() {
		$batch_data = get_option( 'libookin_pending_payout_batch' );

		if ( empty( $batch_data ) || 'scheduled' !== $batch_data['status'] ) {
			return;
		}

		// Update status to processing
		$batch_data['status'] = 'processing';
		update_option( 'libookin_pending_payout_batch', $batch_data );

		$stripe_manager = new Libookin_Stripe_Connect_Manager();
		$processed_count = 0;
		$failed_count    = 0;
		$results         = array();

		foreach ( $batch_data['vendors'] as $vendor ) {
			$result = $this->process_vendor_payout( $vendor, $stripe_manager );
			$results[] = $result;

			if ( $result['success'] ) {
				$processed_count++;
			} else {
				$failed_count++;
			}

			// Small delay between payouts to avoid rate limits
			sleep( 1 );
		}

		// Update batch status
		$batch_data['status']          = 'completed';
		$batch_data['processed_count'] = $processed_count;
		$batch_data['failed_count']    = $failed_count;
		$batch_data['results']         = $results;
		$batch_data['completed_at']    = current_time( 'mysql' );

		update_option( 'libookin_completed_payout_batch', $batch_data );
		delete_option( 'libookin_pending_payout_batch' );

		// Send completion notification
		$this->send_completion_notification( $batch_data );
	}

	/**
	 * Process payout for a single vendor
	 *
	 * @since 1.0.0
	 * @param array                           $vendor         Vendor data.
	 * @param Libookin_Stripe_Connect_Manager $stripe_manager Stripe manager instance.
	 * @return array Processing result.
	 */
	private function process_vendor_payout( $vendor, $stripe_manager ) {
		global $wpdb;

		$vendor_id      = $vendor['vendor_id'];
		$amount         = $vendor['total_pending'];
		$stripe_account = $vendor['stripe_account_id'];

		// Calculate period dates
		$period_end   = new DateTime();
		$period_start = clone $period_end;
		$period_start->sub( new DateInterval( 'P2M' ) );

		// Create Stripe payout
		$payout_result = $stripe_manager->create_payout(
			$stripe_account,
			$amount,
			array(
				'vendor_id'    => $vendor_id,
				'period_start' => $period_start->format( 'Y-m-d' ),
				'period_end'   => $period_end->format( 'Y-m-d' ),
				'royalty_count' => $vendor['royalty_count'],
			)
		);

		if ( is_wp_error( $payout_result ) ) {
			return array(
				'success'    => false,
				'vendor_id'  => $vendor_id,
				'vendor_name' => $vendor['name'],
				'amount'     => $amount,
				'error'      => $payout_result->get_error_message(),
			);
		}

		// Record payout in database
		$payout_id = $wpdb->insert(
			$wpdb->prefix . 'libookin_payouts',
			array(
				'vendor_id'        => $vendor_id,
				'amount'           => $amount,
				'currency'         => 'EUR',
				'stripe_payout_id' => $payout_result['payout_id'],
				'stripe_account_id' => $stripe_account,
				'status'           => $payout_result['status'],
				'period_start'     => $period_start->format( 'Y-m-d' ),
				'period_end'       => $period_end->format( 'Y-m-d' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $payout_id ) {
			// Mark royalties as paid
			$stripe_manager->mark_royalties_as_paid(
				$vendor_id,
				$payout_result['payout_id'],
				$period_start->format( 'Y-m-d' ),
				$period_end->format( 'Y-m-d' )
			);

			// Send vendor notification
			$this->send_vendor_notification( $vendor, $payout_result, $period_start, $period_end );
		}

		return array(
			'success'     => true,
			'vendor_id'   => $vendor_id,
			'vendor_name' => $vendor['name'],
			'amount'      => $amount,
			'payout_id'   => $payout_result['payout_id'],
			'status'      => $payout_result['status'],
		);
	}

	/**
	 * Send admin notification about scheduled payouts
	 *
	 * @since 1.0.0
	 * @param int   $vendor_count   Number of vendors.
	 * @param float $total_amount   Total payout amount.
	 * @param int   $scheduled_time Scheduled processing time.
	 */
	private function send_admin_notification( $vendor_count, $total_amount, $scheduled_time ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$cancel_url  = admin_url( 'admin.php?page=libookin-payouts&action=cancel_batch' );

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Payout Batch Scheduled', 'libookin-auto-payments' ),
			$site_name
		);

		$message = sprintf(
			/* translators: %1$d: vendor count, %2$s: total amount, %3$s: scheduled time, %4$s: cancel URL */
			__( "Payment batch ready:\n\n- %1\$d authors\n- Total: €%2\$.2f\n- Scheduled: %3\$s\n\nCancel if needed: %4\$s\n\n(Without action, automatic payment will proceed)", 'libookin-auto-payments' ),
			$vendor_count,
			$total_amount,
			gmdate( 'Y-m-d H:i:s', $scheduled_time ),
			$cancel_url
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Send vendor notification about payout
	 *
	 * @since 1.0.0
	 * @param array    $vendor       Vendor data.
	 * @param array    $payout_result Payout result.
	 * @param DateTime $period_start  Period start date.
	 * @param DateTime $period_end    Period end date.
	 */
	private function send_vendor_notification( $vendor, $payout_result, $period_start, $period_end ) {
		$email_notifications = new Libookin_Email_Notifications();
		$email_notifications->send_payout_confirmation(
			$vendor['vendor_id'],
			$vendor['total_pending'],
			$period_start->format( 'Y-m-d' ),
			$period_end->format( 'Y-m-d' ),
			$payout_result['payout_id']
		);
	}

	/**
	 * Send completion notification to admin
	 *
	 * @since 1.0.0
	 * @param array $batch_data Completed batch data.
	 */
	private function send_completion_notification( $batch_data ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Payout Batch Completed', 'libookin-auto-payments' ),
			$site_name
		);

		$message = sprintf(
			/* translators: %1$d: processed count, %2$d: failed count, %3$s: total amount */
			__( "Payout batch completed:\n\n- Processed: %1\$d payouts\n- Failed: %2\$d payouts\n- Total amount: €%3\$.2f\n\nView details in admin dashboard.", 'libookin-auto-payments' ),
			$batch_data['processed_count'],
			$batch_data['failed_count'],
			$batch_data['total_amount']
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Get payout preview for admin
	 *
	 * @since 1.0.0
	 * @return array Payout preview data.
	 */
	public function get_payout_preview() {
		$eligible_vendors = $this->get_eligible_vendors();
		$total_amount     = array_sum( array_column( $eligible_vendors, 'total_pending' ) );

		return array(
			'vendors'      => $eligible_vendors,
			'vendor_count' => count( $eligible_vendors ),
			'total_amount' => $total_amount,
			'next_payout_date' => $this->get_next_payout_date(),
		);
	}

	/**
	 * Get next scheduled payout date
	 *
	 * @since 1.0.0
	 * @return string Next payout date.
	 */
	private function get_next_payout_date() {
		$next_month = new DateTime( 'first day of next month' );
		
		// If it falls on weekend, move to next Monday
		$day_of_week = intval( $next_month->format( 'N' ) );
		if ( $day_of_week >= 6 ) {
			$days_to_add = 8 - $day_of_week; // Move to next Monday
			$next_month->add( new DateInterval( "P{$days_to_add}D" ) );
		}

		return $next_month->format( 'Y-m-d' );
	}

	/**
	 * AJAX handler for manual payout trigger
	 *
	 * @since 1.0.0
	 */
	public function ajax_trigger_manual_payout() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'libookin-auto-payments' ) );
		}

		$eligible_vendors = $this->get_eligible_vendors();

		if ( empty( $eligible_vendors ) ) {
			wp_send_json_error( __( 'No vendors eligible for payout.', 'libookin-auto-payments' ) );
		}

		$this->schedule_payout_batch( $eligible_vendors );

		wp_send_json_success( array(
			'message'      => __( 'Payout batch scheduled successfully.', 'libookin-auto-payments' ),
			'vendor_count' => count( $eligible_vendors ),
			'total_amount' => array_sum( array_column( $eligible_vendors, 'total_pending' ) ),
		) );
	}

	/**
	 * AJAX handler for payout preview
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_payout_preview() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'libookin-auto-payments' ) );
		}

		$preview = $this->get_payout_preview();
		wp_send_json_success( $preview );
	}

	/**
	 * Cancel scheduled payout batch
	 *
	 * @since 1.0.0
	 * @return bool Success status.
	 */
	public function cancel_scheduled_batch() {
		$batch_data = get_option( 'libookin_pending_payout_batch' );

		if ( empty( $batch_data ) || 'scheduled' !== $batch_data['status'] ) {
			return false;
		}

		// Clear scheduled event
		wp_clear_scheduled_hook( 'libookin_process_scheduled_payouts' );

		// Update batch status
		$batch_data['status']     = 'cancelled';
		$batch_data['cancelled_at'] = current_time( 'mysql' );
		update_option( 'libookin_cancelled_payout_batch', $batch_data );
		delete_option( 'libookin_pending_payout_batch' );

		return true;
	}
}
