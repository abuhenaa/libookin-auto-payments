<?php
/**
 * Vendor Dashboard for Libookin Auto Payments
 *
 * @package Libookin_Auto_Payments
 * @since   1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor Dashboard class
 *
 * Manages the enhanced vendor dashboard with real-time Stripe data,
 * removes withdrawal requests, and provides sales analytics.
 *
 * @class Libookin_Vendor_Dashboard
 */
class Libookin_Vendor_Dashboard {

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
		// Remove Dokan withdrawal requests
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'remove_withdrawal_nav' ) );
		add_filter( 'dokan_query_var_filter', array( $this, 'remove_withdrawal_query_vars' ) );
		//add custom query vars to dokan dashboard
		add_filter( 'dokan_query_var_filter', array( $this, 'add_custom_query_vars' ) );
		// Add custom dashboard sections
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'add_royalty_nav' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'load_royalty_template' ) );


		
		// Rename "Orders" to "Books Sold"
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'rename_orders_nav' ) );
		
		// Add AJAX handlers
		add_action( 'wp_ajax_get_vendor_balance', array( $this, 'ajax_get_vendor_balance' ) );
		add_action( 'wp_ajax_get_vendor_sales_data', array( $this, 'ajax_get_vendor_sales_data' ) );
		
		// Enqueue dashboard scripts
		add_action( 'dokan_dashboard_content_before', array( $this, 'enqueue_dashboard_scripts' ) );
	}

	/**
	 * Remove withdrawal navigation from Dokan dashboard
	 *
	 * @since 1.0.0
	 * @param array $urls Dashboard navigation URLs.
	 * @return array Modified URLs.
	 */
	public function remove_withdrawal_nav( $urls ) {
		unset( $urls['withdraw'] );
		unset( $urls['orders']);
		unset( $urls['settings']['submenu']['payment'] );
		return $urls;
	}

	/**
	 * Remove withdrawal query vars
	 *
	 * @since 1.0.0
	 * @param array $query_vars Query variables.
	 * @return array Modified query variables.
	 */
	public function remove_withdrawal_query_vars( $query_vars ) {
		unset( $query_vars['withdraw'] );
		return $query_vars;
	}

	/**
	 * Add custom query vars
	 *
	 * @since 1.0.0
	 * @param array $query_vars Existing query variables.
	 * @return array Modified query variables.
	 */
	public function add_custom_query_vars( $query_vars ) {
		$query_vars['royalties'] = 'royalties';
		$query_vars['stripe-connect'] = 'stripe-connect';
		return $query_vars;	
	}

	/**
	 * Rename Orders navigation to "Books Sold"
	 *
	 * @since 1.0.0
	 * @param array $urls Dashboard navigation URLs.
	 * @return array Modified URLs.
	 */
	public function rename_orders_nav( $urls ) {
		if ( isset( $urls['orders'] ) ) {
			$urls['orders']['title'] = __( 'Books Sold', 'libookin-auto-payments' );
		}
		return $urls;
	}

	/**
	 * Add royalty navigation to dashboard
	 *
	 * @since 1.0.0
	 * @param array $urls Dashboard navigation URLs.
	 * @return array Modified URLs.
	 */
	public function add_royalty_nav( $urls ) {
		$urls['royalties'] = array(
			'title' => __( 'My Royalties', 'libookin-auto-payments' ),
			'icon'  => '<i class="fas fa-money-bill-wave"></i>',
			'url'   => dokan_get_navigation_url( 'royalties' ),
			'pos'   => 25,
		);

		$urls['stripe-connect'] = array(
			'title' => __( 'Payment Settings', 'libookin-auto-payments' ),
			'icon'  => '<i class="fab fa-stripe"></i>',
			'url'   => dokan_get_navigation_url( 'stripe-connect' ),
			'pos'   => 26,
		);

		return $urls;
	}


	/**
	 * Load custom templates for royalty pages
	 *
	 * @since 1.0.0
	 * @param array $query_vars Current query variables.
	 */
	public function load_royalty_template( $query_vars ) {
		if ( ! function_exists( 'dokan' ) || ! dokan_is_seller_dashboard() ) {
			return;
		}
		?>
		<div class="dokan-dashboard-wrap">
		<?php
			do_action( 'dokan_dashboard_content_before' );
		?>
			<div class="dokan-dashboard-content">
			<?php
			do_action( 'dokan_dashboard_content_inside_before' );
			if ( isset( $query_vars['royalties'] ) ) {
				$this->render_royalties_page();
			} elseif ( isset( $query_vars['stripe-connect'] ) ) {
				$this->render_stripe_connect_page();
			}
			?>
			</div>
		<?php
			do_action( 'dokan_dashboard_content_inside_after' );
			do_action( 'dokan_dashboard_content_after' );
		?>
		</div>
		<?php
	}

	/**
	 * Render royalties dashboard page
	 *
	 * @since 1.0.0
	 */
	private function render_royalties_page() {
		$vendor_id = get_current_user_id();
		$balance_data = $this->get_vendor_balance_data( $vendor_id );
		$sales_data = $this->get_vendor_sales_data( $vendor_id );
		$payout_history = $this->get_vendor_payout_history( $vendor_id );
		//get order data and royalties
		$order_data = $this->get_order_data_and_royalties( $vendor_id );

		?>
		<div class="libookin-royalties-dashboard">
			<div class="dokan-dashboard-header">
				<h1><?php esc_html_e( 'My Royalties', 'libookin-auto-payments' ); ?></h1>
			</div>

			<!-- Balance Overview -->
			<div class="libookin-balance-overview">
				<div class="balance-cards">
					<div class="balance-card available">
						<h3><?php esc_html_e( 'Available Balance (From Stripe)', 'libookin-auto-payments' ); ?></h3>
						<div class="amount">€<?php echo esc_html( number_format( $balance_data['available'], 2 ) ); ?></div>
						<p class="description"><?php esc_html_e( 'Ready for payout', 'libookin-auto-payments' ); ?></p>
					</div>
					
					<div class="balance-card pending">
						<h3><?php esc_html_e( 'Pending Royalties', 'libookin-auto-payments' ); ?></h3>
						<div class="amount">€<?php echo esc_html( number_format( $balance_data['pending'], 2 ) ); ?></div>
						<p class="description"><?php esc_html_e( 'Awaiting 2-month period', 'libookin-auto-payments' ); ?></p>
					</div>
					
					<div class="balance-card total">
						<h3><?php esc_html_e( 'Total Earned', 'libookin-auto-payments' ); ?></h3>
						<div class="amount">€<?php echo esc_html( number_format( $balance_data['total_year'], 2 ) ); ?></div>
						<p class="description"><?php echo esc_html( $balance_data['books_sold'] ); ?> <?php esc_html_e( 'books sold', 'libookin-auto-payments' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Order and Royalties details -->
			 <div class="libookin-order-royalties-details">
				<h3><?php esc_html_e( 'Order and Royalties Details', 'libookin-auto-payments' ); ?></h3>
				<div class="order-royalties">
					<div class="order-royalties-table">
						<?php 
							if( ! empty( $order_data)){
						?>
						<table>
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order ID', 'libookin-auto-payments' ); ?></th>
									<th><?php esc_html_e( 'Order Details', 'libookin-auto-payments' ); ?></th>
									<th><?php esc_html_e( 'HT Price', 'libookin-auto-payments' ); ?></th>
									<th><?php esc_html_e( 'Royalty', 'libookin-auto-payments' ); ?></th>
									<th><?php esc_html_e( 'Status', 'libookin-auto-payments' ); ?></th>
									<th><?php esc_html_e( 'Created', 'libookin-auto-payments' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
									foreach ( $order_data as $order ) : ?>
										<tr>
											<td><?php echo $order->order_id; ?></td>
											<td>
												<?php $product_title = get_the_title($order->product_id); ?>
												<span><?php echo $product_title; ?></span>
											</td>
											<td><?php echo wc_price( $order->price_ht ); ?></td>
											<td><?php echo wc_price( $order->royalty_amount ); ?></td>
											<td><?php echo $order->payout_status; ?></td>
											<td><?php echo $order->created_at; ?></td>
										</tr>
									<?php
								endforeach;
							} else{
								echo esc_html__( 'No data to show!', 'libookin-auto-payments');
							}
							 ?>
							</tbody>
						</table>
					</div>
				</div>
			 </div>

			<!-- Next Payout Info -->
			<div class="libookin-next-payout">
				<h3><?php esc_html_e( 'Next Payout Information', 'libookin-auto-payments' ); ?></h3>
				<div class="payout-info">
					<?php if ( $sales_data['eligible_payouts'] >= 15 ) : ?>
						<div class="payout-eligible">
							<i class="fas fa-check-circle"></i>
							<span><?php esc_html_e( 'Eligible for next payout', 'libookin-auto-payments' ); ?></span>
							<strong>€<?php echo esc_html( number_format( $sales_data['eligible_payouts'], 2 ) ); ?></strong>
						</div>
					<?php else : ?>
						<div class="payout-pending">
							<i class="fas fa-clock"></i>
							<span><?php printf( esc_html__( 'Need €%s more to reach minimum payout (€15)', 'libookin-auto-payments' ), number_format( 15 -  $sales_data['eligible_payouts'], 2 ) ); ?></span>
						</div>
					<?php endif; ?>
					<p class="next-date"><?php printf( esc_html__( 'Next payout date: %s', 'libookin-auto-payments' ), esc_html( $balance_data['next_payout_date'] ) ); ?></p>
				</div>
			</div>

			<!-- Sales Chart -->
			<div class="libookin-sales-chart">
				<h3><?php esc_html_e( 'Sales Performance (Last 12 Months)', 'libookin-auto-payments' ); ?></h3>
				<canvas id="libookinSalesChart" width="400" height="200"></canvas>
			</div>

			<!-- Payout History -->
			<div class="libookin-payout-history">
				<h3><?php esc_html_e( 'Payout History', 'libookin-auto-payments' ); ?></h3>
				<?php if ( ! empty( $payout_history ) ) : ?>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Period', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Status', 'libookin-auto-payments' ); ?></th>
								<th><?php esc_html_e( 'Bank Arrival', 'libookin-auto-payments' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payout_history as $payout ) : ?>
								<tr>
									<td><?php echo esc_html( gmdate( 'Y-m-d', strtotime( $payout->created_at ) ) ); ?></td>
									<td>€<?php echo esc_html( number_format( $payout->amount, 2 ) ); ?></td>
									<td><?php echo esc_html( $payout->period_start . ' to ' . $payout->period_end ); ?></td>
									<td>
										<span class="status-badge status-<?php echo esc_attr( $payout->status ); ?>">
											<?php echo esc_html( ucfirst( str_replace( '_', ' ', $payout->status ) ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $this->get_bank_arrival_estimate( $payout->created_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="no-payouts"><?php esc_html_e( 'No payouts yet. Keep selling to earn your first royalties!', 'libookin-auto-payments' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		// Initialize sales chart
		document.addEventListener('DOMContentLoaded', function() {
			const ctx = document.getElementById('libookinSalesChart').getContext('2d');
			const salesData = <?php echo wp_json_encode( $sales_data ); ?>;
			
			new Chart(ctx, {
				type: 'line',
				data: {
					labels: salesData.labels,
					datasets: [{
						label: '<?php esc_html_e( 'Monthly Royalties (€)', 'libookin-auto-payments' ); ?>',
						data: salesData.royalties,
						borderColor: '#3498db',
						backgroundColor: 'rgba(52, 152, 219, 0.1)',
						tension: 0.4
					}, {
						label: '<?php esc_html_e( 'Books Sold', 'libookin-auto-payments' ); ?>',
						data: salesData.books_sold,
						borderColor: '#2ecc71',
						backgroundColor: 'rgba(46, 204, 113, 0.1)',
						yAxisID: 'y1',
						tension: 0.4
					}]
				},
				options: {
					responsive: true,
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: '<?php esc_html_e( 'Royalties (€)', 'libookin-auto-payments' ); ?>'
							}
						},
						y1: {
							type: 'linear',
							display: true,
							position: 'right',
							beginAtZero: true,
							title: {
								display: true,
								text: '<?php esc_html_e( 'Books Sold', 'libookin-auto-payments' ); ?>'
							},
							grid: {
								drawOnChartArea: false,
							},
						}
					}
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Get order details and royalties for the current vendor
	 *
	 * @return array
	 */
	private function get_order_data_and_royalties( $vendor_id ) {
		global $wpdb;
		$sql = "SELECT
		 	r.order_id, 
			r.product_id, 
			r.vendor_id, 
			r.price_ht, 
			r.royalty_percent,
			r.royalty_amount,
			r.payout_status,
			r.created_at
			FROM {$wpdb->prefix}libookin_royalties as r
			WHERE r.vendor_id = %d
			ORDER BY r.created_at DESC";

		$vendor_sales_data = $wpdb->prepare( $sql, $vendor_id );
		$vendor_sales_data = $wpdb->get_results( $vendor_sales_data );

		return $vendor_sales_data;
	}

	/**
	 * Render Stripe Connect settings page
	 *
	 * @since 1.0.0
	 */
	private function render_stripe_connect_page() {
		$vendor_id = get_current_user_id();
		$stripe_account_id = get_user_meta( $vendor_id, 'stripe_connect_account_id', true );
		$account_status = get_user_meta( $vendor_id, 'stripe_connect_status', true );
		
		$stripe_manager = new Libookin_Stripe_Connect_Manager();
		$account_details = null;
		
		if ( ! empty( $stripe_account_id ) ) {
			$account_details = $stripe_manager->get_account_status( $stripe_account_id );
		}
		?>
		<div class="libookin-stripe-connect-dashboard">
			<div class="dokan-dashboard-header">
				<h1><?php esc_html_e( 'Payment Settings', 'libookin-auto-payments' ); ?></h1>
			</div>

			<div class="stripe-connect-status">
				<?php if ( empty( $stripe_account_id ) ) : ?>
					<!-- Account Creation -->
					<div class="status-card needs-setup">
						<h3><?php esc_html_e( 'Setup Required', 'libookin-auto-payments' ); ?></h3>
						<p><?php esc_html_e( 'You need to set up your payment account to receive royalties.', 'libookin-auto-payments' ); ?></p>
						<p><?php esc_html_e( 'The onboarding link is one time so make sure you complete it with phone and email at least.', 'libookin-auto-payments' ); ?></p>
						<button id="create-stripe-account" class="button button-primary">
							<?php esc_html_e( 'Setup Payment Account', 'libookin-auto-payments' ); ?>
						</button>
					</div>
				<?php elseif ( is_wp_error( $account_details ) ) : ?>
					<!-- Error State -->
					<div class="status-card error">
						<h3><?php esc_html_e( 'Account Error', 'libookin-auto-payments' ); ?></h3>
						<p><?php echo esc_html( $account_details->get_error_message() ); ?></p>
						<button id="refresh-account-status" class="button">
							<?php esc_html_e( 'Refresh Status', 'libookin-auto-payments' ); ?>
						</button>
					</div>
				<?php else : ?>
					<!-- Account Status -->
					<div class="status-card <?php echo esc_attr( $account_details['verification_status'] ); ?>">
						<h3><?php esc_html_e( 'Payment Account Status', 'libookin-auto-payments' ); ?></h3>
						
						<div class="account-info">
							<div class="info-row">
								<span class="label"><?php esc_html_e( 'Account ID:', 'libookin-auto-payments' ); ?></span>
								<span class="value"><?php echo esc_html( substr( $stripe_account_id, 0, 12 ) . '...' ); ?></span>
							</div>
							
							<div class="info-row">
								<span class="label"><?php esc_html_e( 'Status:', 'libookin-auto-payments' ); ?></span>
								<span class="value status-<?php echo esc_attr( $account_details['verification_status'] ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', $account_details['verification_status'] ) ) ); ?>
								</span>
							</div>
							
							<div class="info-row">
								<span class="label"><?php esc_html_e( 'Payouts Enabled:', 'libookin-auto-payments' ); ?></span>
								<span class="value">
									<?php echo $account_details['payouts_enabled'] ? '✅' : '❌'; ?>
								</span>
							</div>
							<div class="info-row">
								<button class="lap-stripe-dashboard-link button button-primary">
									<?php esc_html_e( 'Go to Stripe Dashboard', 'libookin-auto-payments' ); ?>
								</button>
							</div>
						</div>

						<?php if ( ! empty( $account_details['requirements'] ) ) : ?>
							<div class="requirements-section">
								<h4><?php esc_html_e( 'Required Information', 'libookin-auto-payments' ); ?></h4>
								<ul class="requirements-list">
									<?php foreach ( $account_details['requirements'] as $requirement ) : ?>
										<li><?php echo esc_html( $this->format_requirement( $requirement ) ); ?></li>
									<?php endforeach; ?>
								</ul>
								<p class="help-text">
									<?php esc_html_e( 'Please contact support to complete your account verification.', 'libookin-auto-payments' ); ?>
								</p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Payment Information -->
			<div class="payment-info-section">
				<h3><?php esc_html_e( 'How Payments Work', 'libookin-auto-payments' ); ?></h3>
				<div class="info-grid">
					<div class="info-item">
						<i class="fas fa-calendar-alt"></i>
						<h4><?php esc_html_e( 'Payment Schedule', 'libookin-auto-payments' ); ?></h4>
						<p><?php esc_html_e( 'Automatic payouts every 2 months on the 1st (or next business day)', 'libookin-auto-payments' ); ?></p>
					</div>
					
					<div class="info-item">
						<i class="fas fa-euro-sign"></i>
						<h4><?php esc_html_e( 'Minimum Amount', 'libookin-auto-payments' ); ?></h4>
						<p><?php esc_html_e( '€15 minimum required for payout processing', 'libookin-auto-payments' ); ?></p>
					</div>
					
					<div class="info-item">
						<i class="fas fa-university"></i>
						<h4><?php esc_html_e( 'Bank Transfer', 'libookin-auto-payments' ); ?></h4>
						<p><?php esc_html_e( 'Direct transfer to your bank account (1-2 business days)', 'libookin-auto-payments' ); ?></p>
					</div>
					
					<div class="info-item">
						<i class="fas fa-shield-alt"></i>
						<h4><?php esc_html_e( 'Security', 'libookin-auto-payments' ); ?></h4>
						<p><?php esc_html_e( 'Powered by Stripe Connect - bank-level security', 'libookin-auto-payments' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get vendor balance data
	 *
	 * @since 1.0.0
	 * @param int $vendor_id Vendor user ID.
	 * @return array Balance data.
	 */
	private function get_vendor_balance_data( $vendor_id ) {
		global $wpdb;

		// Get Stripe Connect account
		$stripe_account_id = get_user_meta( $vendor_id, 'stripe_connect_account_id', true );
		$available_balance = 0;

		if ( ! empty( $stripe_account_id ) ) {
			$stripe_manager = new Libookin_Stripe_Connect_Manager();
			$balance_result = $stripe_manager->get_account_balance( $stripe_account_id );
			
			if ( ! is_wp_error( $balance_result ) ) {
				$available_balance = $balance_result['available'];
			}
		}

		// Get pending royalties (less than 2 months old)
		$two_months_ago = gmdate( 'Y-m-d H:i:s', strtotime( 'last day of -3 months' ) );
		$pending_royalties = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(royalty_amount) FROM {$wpdb->prefix}libookin_royalties 
				WHERE vendor_id = %d AND payout_status = 'pending' AND created_at > %s",
				$vendor_id,
				$two_months_ago
			)
		);

		// Get total earned this year
		$year_start = gmdate( 'Y-01-01 00:00:00' );
		$total_year = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(royalty_amount) FROM {$wpdb->prefix}libookin_royalties 
				WHERE vendor_id = %d AND created_at >= %s",
				$vendor_id,
				$year_start
			)
		);

		// Get books sold count
		$books_sold = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}libookin_royalties 
				WHERE vendor_id = %d AND created_at >= %s",
				$vendor_id,
				$year_start
			)
		);

		return array(
			'available'        => floatval( $available_balance ),
			'pending'          => floatval( $pending_royalties ),
			'total_year'       => floatval( $total_year ),
			'books_sold'       => intval( $books_sold ),
			'next_payout_date' => $this->get_next_payout_date(),
		);
	}

	/**
	 * Get vendor sales data for charts
	 *
	 * @since 1.0.0
	 * @param int $vendor_id Vendor user ID.
	 * @return array Sales data.
	 */
	private function get_vendor_sales_data( $vendor_id ) {
		global $wpdb;

		$months = array();
		$royalties = array();
		$books_sold = array();

		// Get last 12 months data
		for ( $i = 11; $i >= 0; $i-- ) {
			$month_start = gmdate( 'Y-m-01 00:00:00', strtotime( "-$i months" ) );
			$month_end   = gmdate( 'Y-m-t 23:59:59', strtotime( "-$i months" ) );
			$month_label = gmdate( 'M Y', strtotime( "-$i months" ) );

			$months[] = $month_label;

			// Get royalties for this month
			$month_royalties = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(royalty_amount) FROM {$wpdb->prefix}libookin_royalties 
					WHERE vendor_id = %d AND created_at BETWEEN %s AND %s",
					$vendor_id,
					$month_start,
					$month_end
				)
			);

			// Get books sold for this month
			$month_books = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}libookin_royalties 
					WHERE vendor_id = %d AND created_at BETWEEN %s AND %s",
					$vendor_id,
					$month_start,
					$month_end
				)
			);

			$royalties[] = floatval( $month_royalties );
			$books_sold[] = intval( $month_books );

			// Define the start and end of the month exactly two months ago
			$current_date = Libookin_Auto_Payments::$current_date;
			$start_date = clone $current_date;
			$start_date->modify('first day of -3 months');
			$start_date->setTime(0, 0, 0);
			$end_date = clone $current_date;
			$end_date->modify('last day of -3 months');
			$end_date->setTime(23, 59, 59);

			// Get formatted timestamps
			$start = $start_date->format('Y-m-d H:i:s');
			$end   = $end_date->format('Y-m-d H:i:s');
			$eligible_payouts = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(royalty_amount) FROM {$wpdb->prefix}libookin_royalties 
					WHERE vendor_id = %d AND payout_status = 'pending' AND created_at BETWEEN %s AND %s",
					$vendor_id,
					$start,
					$end
				)
			);
		}

		return array(
			'labels'     => $months,
			'royalties'  => $royalties,
			'books_sold' => $books_sold,
			'eligible_payouts' => floatval( $eligible_payouts )
		);
	}

	/**
	 * Get vendor payout history
	 *
	 * @since 1.0.0
	 * @param int $vendor_id Vendor user ID.
	 * @return array Payout history.
	 */
	private function get_vendor_payout_history( $vendor_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}libookin_payouts 
				WHERE vendor_id = %d 
				ORDER BY created_at DESC 
				LIMIT 20",
				$vendor_id
			)
		);
	}

	/**
	 * Get next payout date
	 *
	 * @since 1.0.0
	 * @return string Next payout date.
	 */
	private function get_next_payout_date() {
		$next_month = new DateTime( 'first day of next month' );
		
		// If it falls on weekend, move to next Monday
		$day_of_week = intval( $next_month->format( 'N' ) );
		if ( $day_of_week >= 6 ) {
			$days_to_add = 8 - $day_of_week;
			$next_month->add( new DateInterval( "P{$days_to_add}D" ) );
		}

		return $next_month->format( 'F j, Y' );
	}

	/**
	 * Get bank arrival estimate
	 *
	 * @since 1.0.0
	 * @param string $payout_date Payout creation date.
	 * @return string Estimated arrival date.
	 */
	private function get_bank_arrival_estimate( $payout_date ) {
		$payout_time = strtotime( $payout_date );
		$arrival_time = $payout_time + ( 2 * DAY_IN_SECONDS ); // Add 2 business days
		return gmdate( 'Y-m-d', $arrival_time );
	}

	/**
	 * Format Stripe requirement for display
	 *
	 * @since 1.0.0
	 * @param string $requirement Stripe requirement key.
	 * @return string Formatted requirement.
	 */
	private function format_requirement( $requirement ) {
		$requirements_map = array(
			'individual.first_name'     => __( 'First name', 'libookin-auto-payments' ),
			'individual.last_name'      => __( 'Last name', 'libookin-auto-payments' ),
			'individual.dob.day'        => __( 'Date of birth', 'libookin-auto-payments' ),
			'individual.address.line1'  => __( 'Address', 'libookin-auto-payments' ),
			'individual.address.city'   => __( 'City', 'libookin-auto-payments' ),
			'individual.address.postal_code' => __( 'Postal code', 'libookin-auto-payments' ),
			'external_account'          => __( 'Bank account details', 'libookin-auto-payments' ),
			'individual.verification.document' => __( 'Identity document', 'libookin-auto-payments' ),
		);

		return $requirements_map[ $requirement ] ?? ucfirst( str_replace( array( '.', '_' ), ' ', $requirement ) );
	}

	/**
	 * Enqueue dashboard scripts
	 *
	 * @since 1.0.0
	 */
	public function enqueue_dashboard_scripts() {
		if ( ! is_user_logged_in() || ! current_user_can( 'dokandar' ) ) {
			return;
		}

		wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true );
		wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), '6.0.0' );
	}

	/**
	 * AJAX handler for getting vendor balance
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_vendor_balance() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		$vendor_id = get_current_user_id();
		$balance_data = $this->get_vendor_balance_data( $vendor_id );

		wp_send_json_success( $balance_data );
	}

	/**
	 * AJAX handler for getting vendor sales data
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_vendor_sales_data() {
		check_ajax_referer( 'libookin_auto_payments_nonce', 'nonce' );

		$vendor_id = get_current_user_id();
		$sales_data = $this->get_vendor_sales_data( $vendor_id );

		wp_send_json_success( $sales_data );
	}
}
