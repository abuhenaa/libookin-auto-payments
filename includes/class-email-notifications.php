<?php
/**
 * Email Notifications for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Notifications class
 */
class Libookin_Email_Notifications {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
	}

	/**
	 * Set HTML content type for emails
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Send payout confirmation to vendor
	 *
	 * @param int    $vendor_id    Vendor user ID.
	 * @param float  $amount       Payout amount.
	 * @param string $period_start Period start date.
	 * @param string $period_end   Period end date.
	 * @param string $payout_id    Stripe payout ID.
	 */
	public function send_payout_confirmation( $vendor_id, $amount, $period_start, $period_end, $payout_id ) {
		$user = get_user_by( 'ID', $vendor_id );
		if ( ! $user ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: Amount */
			__( 'Your royalty payment of €%s has been processed', 'libookin-auto-payments' ),
			number_format( $amount, 2 )
		);

		$message = sprintf(
			/* translators: %1$s: User name, %2$s: Amount, %3$s: Period start, %4$s: Period end */
			__( 'Dear %1$s,

Your royalties of €%2$s for period %3$s to %4$s have been transferred.
Expected in your bank account: 1-2 business days.

Best regards,
LiBookin Team', 'libookin-auto-payments' ),
			$user->display_name,
			number_format( $amount, 2 ),
			$period_start,
			$period_end
		);

		wp_mail( $user->user_email, $subject, $message );
	}
}
