<?php
/**
 * Stripe Connect Manager for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe Connect Manager class
 *
 * Handles all Stripe Connect operations including account creation,
 * verification, and payout management.
 *
 * @class Libookin_Stripe_Connect_Manager
 */
class Libookin_Stripe_Connect_Manager {

	/**
	 * Stripe API key
	 *
	 * @var string
	 */
	private $stripe_secret_key;

	/**
	 * Stripe publishable key
	 *
	 * @var string
	 */
	private $stripe_publishable_key;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_stripe_keys();
		$this->init_hooks();
	}

	/**
	 * Initialize Stripe API keys
	 *
	 * @since 1.0.0
	 */
	private function init_stripe_keys() {
		$this->stripe_secret_key     = get_option( 'libookin_stripe_secret_key', '' );
		$this->stripe_publishable_key = get_option( 'libookin_stripe_publishable_key', '' );

		if ( ! empty( $this->stripe_secret_key ) ) {
			\Stripe\Stripe::setApiKey( $this->stripe_secret_key );
		}
	}

	/**
	 * Initialize WordPress hooks
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_create_stripe_connect_account', array( $this, 'ajax_create_connect_account' ) );
		add_action( 'wp_ajax_get_stripe_account_status', array( $this, 'ajax_get_account_status' ) );
		add_action( 'wp_ajax_get_stripe_balance', array( $this, 'ajax_get_stripe_balance' ) );
		add_action( 'user_register', array( $this, 'maybe_create_connect_account' ) );
		add_action( 'wp_ajax_get_stripe_account_url', array( $this, 'ajax_get_stripe_account_url' ) );
	}

	/**
	 * Create Stripe Connect account for a vendor
	 *
	 * @since 1.0.0
	 * @param int    $vendor_id The vendor user ID.
	 * @param string $country   The vendor's country code.
	 * @param string $email     The vendor's email address.
	 * @return array|WP_Error Account creation result or error.
	 */
	public function create_connect_account( $vendor_id, $country = 'FR', $email = '' ) {
		if ( empty( $this->stripe_secret_key ) ) {
			error_log( 'Stripe secret key is not configured.' );
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not properly configured.', 'libookin-auto-payments' ) );
		}

		$user = get_user_by( 'ID', $vendor_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'libookin-auto-payments' ) );
		}

		// Check if account already exists
		$existing_account = get_user_meta( $vendor_id, 'stripe_connect_account_id', true );
		if ( ! empty( $existing_account ) ) {
			return new WP_Error( 'account_exists', __( 'Stripe Connect account already exists for this user.', 'libookin-auto-payments' ) );
		}

		$email = ! empty( $email ) ? $email : $user->user_email;

		try {
			$account = \Stripe\Account::create(
				array(
					'type'         => 'express',
					'country'      => $country,
					'email'        => $email,
					'capabilities' => array(
						'transfers' => array( 'requested' => true ),
					),
					'tos_acceptance' => array(
						'service_agreement' => 'recipient',
					),
					'business_type' => 'individual',
					'metadata'      => array(
						'vendor_id'   => $vendor_id,
						'platform'    => 'Libookin',
					),
				)
			);

			// Store account ID in user meta
			update_user_meta( $vendor_id, 'stripe_connect_account_id', $account->id );
			update_user_meta( $vendor_id, 'stripe_connect_status', 'created' );
			update_user_meta( $vendor_id, 'stripe_connect_created_at', current_time( 'mysql' ) );

			return array(
				'success'    => true,
				'account_id' => $account->id,
				'status'     => $account->charges_enabled ? 'active' : 'pending_verification',
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			error_log( 'Stripe error: ' . $e->getMessage() );
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Generate AccountLink for a vendor stripe connected account
	 * 
	 * @since 1.0.0
	 */
	public function create_express_account_onboarding_link( $account_id, $return_url, $refresh_url ){

		try{
			$link = \Stripe\AccountLink::create(
				array(
					'account' => $account_id,
					'refresh_url' => $refresh_url,
					'return_url' => $return_url,
					'type' => 'account_onboarding',
				)
			);

			return array(
				'success' => true,
				'onboarding_url' => $link->url,
				'expires_at' => $link->expires_at,
			);
		} catch( \Stripe\Exception\ApiErrorException $e ){
			error_log( 'Stripe error: ' . $e->getMessage() );
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get Stripe Connect account status
	 *
	 * @since 1.0.0
	 * @param string $account_id Stripe account ID.
	 * @return array|WP_Error Account status or error.
	 */
	public function get_account_status( $account_id ) {
		if ( empty( $this->stripe_secret_key ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not properly configured.', 'libookin-auto-payments' ) );
		}

		try {
			$account = \Stripe\Account::retrieve( $account_id );

			$requirements = array();
			if ( isset( $account->requirements->currently_due ) ) {
				$requirements = $account->requirements->currently_due;
			}

			return array(
				'success'         => true,
				'charges_enabled' => $account->charges_enabled,
				'payouts_enabled' => $account->payouts_enabled,
				'details_submitted' => $account->details_submitted,
				'requirements'    => $requirements,
				'verification_status' => $this->get_verification_status( $account ),
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get account balance from Stripe
	 *
	 * @since 1.0.0
	 * @param string $account_id Stripe account ID.
	 * @return array|WP_Error Balance information or error.
	 */
	public function get_account_balance( $account_id ) {
		if ( empty( $this->stripe_secret_key ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not properly configured.', 'libookin-auto-payments' ) );
		}

		try {
			$balance = \Stripe\Balance::retrieve(
				array(),
				array( 'stripe_account' => $account_id )
			);

			$available_amount = 0;
			$pending_amount   = 0;

			if ( ! empty( $balance->available ) ) {
				foreach ( $balance->available as $available ) {
					if ( 'eur' === $available->currency ) {
						$available_amount = $available->amount / 100; // Convert from cents
						break;
					}
				}
			}

			if ( ! empty( $balance->pending ) ) {
				foreach ( $balance->pending as $pending ) {
					if ( 'eur' === $pending->currency ) {
						$pending_amount = $pending->amount / 100; // Convert from cents
						break;
					}
				}
			}

			return array(
				'success'   => true,
				'available' => $available_amount,
				'pending'   => $pending_amount,
				'currency'  => 'EUR',
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Create payout to vendor's bank account
	 *
	 * @since 1.0.0
	 * @param string $account_id Stripe account ID.
	 * @param float  $amount     Amount to payout in EUR.
	 * @param array  $metadata   Additional metadata for the payout.
	 * @return array|WP_Error Payout result or error.
	 */
	public function create_payout( $account_id, $amount, $metadata = array() ) {
		if ( empty( $this->stripe_secret_key ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Stripe is not properly configured.', 'libookin-auto-payments' ) );
		}

		if ( $amount < 15 ) {
			return new WP_Error( 'minimum_amount', __( 'Minimum payout amount is €15.', 'libookin-auto-payments' ) );
		}

		try {
			$payout = \Stripe\Payout::create(
				array(
					'amount'   => intval( $amount * 100 ), // Convert to cents
					'currency' => 'eur',
					'metadata' => $metadata,
				),
				array( 'stripe_account' => $account_id )
			);

			return array(
				'success'   => true,
				'payout_id' => $payout->id,
				'amount'    => $amount,
				'status'    => $payout->status,
				'arrival_date' => $payout->arrival_date,
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Get verification status from account object
	 *
	 * @since 1.0.0
	 * @param object $account Stripe account object.
	 * @return string Verification status.
	 */
	private function get_verification_status( $account ) {
		if ( $account->charges_enabled && $account->payouts_enabled ) {
			return 'verified';
		}

		if ( $account->details_submitted ) {
			return 'pending_verification';
		}

		if ( ! empty( $account->requirements->currently_due ) ) {
			return 'requires_information';
		}

		return 'created';
	}

	/**
	 * Maybe create Stripe Connect account on user registration
	 *
	 * @since 1.0.0
	 * @param int $user_id The newly registered user ID.
	 */
	public function maybe_create_connect_account( $user_id ) {
		$user = get_user_by( 'ID', $user_id );
		
		// Only create for vendors/sellers
		if ( ! $user || ! in_array( 'seller', $user->roles, true ) ) {
			return;
		}

		// Auto-create Stripe Connect account
		$result = $this->create_connect_account( $user_id );
		
		if ( is_wp_error( $result ) ) {
			error_log( 'Failed to create Stripe Connect account for user ' . $user_id . ': ' . $result->get_error_message() );
		}
	}

	/**
	 * AJAX handler for creating Stripe Connect account
	 *
	 * @since 1.0.0
	 */
	public function ajax_create_connect_account() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		if ( ! current_user_can( 'dokandar' ) ) {
			wp_send_json_error( __( 'You do not have permission to perform this action.', 'libookin-auto-payments' ) );
		}

		$vendor_id = get_current_user_id();
		$country   = sanitize_text_field( $_POST['country'] ?? 'FR' );
		$email     = get_user_by( 'ID', $vendor_id )->user_email;

		//Create connect account
		$result = $this->create_connect_account( $vendor_id, $country, $email );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		
		$return_url  = add_query_arg( ['stripe_onboarding' => 'done'], dokan_get_navigation_url('stripe-connect') );
		$refresh_url = add_query_arg( ['stripe_onboarding' => 'refresh'], dokan_get_navigation_url('stripe-connect') );

		//get onboarding link
		$onboarding_link = $this->create_express_account_onboarding_link( $result['account_id'], $return_url, $refresh_url );

		if( is_wp_error( $onboarding_link ) ) {
			wp_send_json_error( $onboarding_link->get_error_message() );
		}

		wp_send_json_success( [
			'account_id'      => $result[ 'account_id' ],
			'onboarding_link' => $onboarding_link[ 'onboarding_url' ],
			'return_url'      => $return_url,
			'refresh_url'     => $refresh_url,
		] );
	}

	/**
	 * AJAX handler for getting account status
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_account_status() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		$account_id = sanitize_text_field( $_POST['account_id'] ?? '' );

		if ( empty( $account_id ) ) {
			wp_send_json_error( __( 'Account ID is required.', 'libookin-auto-payments' ) );
		}

		$result = $this->get_account_status( $account_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for getting Stripe balance
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_stripe_balance() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		$account_id = sanitize_text_field( $_POST['account_id'] ?? '' );

		if ( empty( $account_id ) ) {
			wp_send_json_error( __( 'Account ID is required.', 'libookin-auto-payments' ) );
		}

		$result = $this->get_account_balance( $account_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Get vendor's pending royalties from database
	 *
	 * @since 1.0.0
	 * @param int $vendor_id The vendor user ID.
	 * @return float Total pending royalties.
	 */
	public function get_vendor_pending_royalties( $vendor_id ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(royalty_amount) FROM {$wpdb->prefix}libookin_royalties 
				WHERE vendor_id = %d AND payout_status = 'pending'",
				$vendor_id
			)
		);

		return floatval( $result );
	}

	/**
	 * Mark royalties as paid
	 *
	 * @since 1.0.0
	 * @param int    $vendor_id      The vendor user ID.
	 * @param string $payout_id      Stripe payout ID.
	 * @param string $period_start   Period start date.
	 * @param string $period_end     Period end date.
	 * @return bool Success status.
	 */
	public function mark_royalties_as_paid( $vendor_id, $payout_id, $period_start, $period_end ) {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'libookin_royalties',
			array(
				'payout_status'    => 'paid',
				'stripe_payout_id' => $payout_id,
				'payout_date'      => current_time( 'mysql' ),
			),
			array(
				'vendor_id'     => $vendor_id,
				'payout_status' => 'pending',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $updated;
	}

	/**
	 * get stripe dashboard url
	 */
	public function ajax_get_stripe_account_url() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		$vendor_id = sanitize_text_field( $_POST['vendor_id'] ?? '' );

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( __( 'Vendor ID is required.', 'libookin-auto-payments' ) );
		}

		$account_id = get_user_meta( $vendor_id, 'stripe_connect_account_id', true );

		if ( empty( $account_id ) ) {
			wp_send_json_error( __( 'Account ID is required.', 'libookin-auto-payments' ) );
		}

		$account_url = $this->get_account_url( $account_id );

		if ( is_wp_error( $account_url ) ) {
			wp_send_json_error( $account_url->get_error_message() );
		}

		wp_send_json_success( array('url' => $account_url) );
	}

	/**
	 * get stripe dashboard url
	 */
	public function get_account_url( $account_id ) {
		//create the login link
		try{
			$login_link = \Stripe\Account::createLoginLink( $account_id );
			return $login_link->url;
		} catch(\Exception $e){
			error_log( $e->getMessage() );
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}
}
