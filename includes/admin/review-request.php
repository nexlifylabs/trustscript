<?php
/**
 * Review Requests Management Page
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Review_Request_Page {
	
	public function __construct() {
		add_action( 'wp_ajax_trustscript_fetch_review_requests', array( $this, 'handle_fetch_requests' ) );
	}
	
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Review Requests', 'trustscript' ); ?></h1>
			
			<!-- Compliance Notice -->
			<h2><?php esc_html_e( 'Review Request', 'trustscript' ); ?></h2> 
			<div class="trustscript-alert trustscript-alert-info trustscript-compliance-notice">
				<strong>
					<strong>📊<?php esc_html_e( 'Quick Operations Dashboard', 'trustscript' ); ?></strong>
				</strong>
				<p><?php esc_html_e( 'Manage review requests for this store. For complete report, and cross-platform analytics, visit your TrustScript dashboard.', 'trustscript' ); ?></p>
				<p class="trustscript-compliance-link">
					<a href="<?php echo esc_url( trailingslashit( trustscript_get_base_url() ) . 'dashboard/wordpress-orders' ); ?>" 
					   target="_blank" 
					   class="trustscript-btn trustscript-btn-primary trustscript-btn-sm">
						<?php esc_html_e( 'Open TrustScript Compliance Dashboard →', 'trustscript' ); ?>
					</a>
				</p>
			</div>

			<!-- Stats Cards -->
			<div id="review-requests-stats" class="trustscript-grid trustscript-stats-grid">
				<div class="trustscript-stat-card trustscript-stat-card-primary">
					<div class="trustscript-stat-value" id="rr-stat-total">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Total Requests', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-warning">
					<div class="trustscript-stat-value" id="rr-stat-pending">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Pending', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-success">
					<div class="trustscript-stat-value" id="rr-stat-approved">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Approved', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-purple">
					<div class="trustscript-stat-value" id="rr-stat-conversion">-%</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Conversion Rate', 'trustscript' ); ?></div>
				</div>
			</div>

			<!-- Review Requests Table -->
			<div class="trustscript-card">
				<h2><?php esc_html_e( 'All Review Requests', 'trustscript' ); ?></h2>
				
				<!-- Search and Filters -->
				<div class="trustscript-filters trustscript-filters-section">
					<div class="trustscript-grid trustscript-filters-grid">
						<div>
							<label for="rr-search" class="trustscript-filter-label">
								<?php esc_html_e( 'Search', 'trustscript' ); ?>
							</label>
							<input 
								type="text" 
								id="rr-search" 
								class="trustscript-form-input trustscript-filter-input" 
								placeholder="<?php esc_attr_e( 'Product name or order ID...', 'trustscript' ); ?>"
							>
						</div>
						<div>
							<label for="rr-status-filter" class="trustscript-filter-label">
								<?php esc_html_e( 'Status', 'trustscript' ); ?>
							</label>
							<select id="rr-status-filter" class="trustscript-form-input trustscript-filter-input">
								<option value=""><?php esc_html_e( 'All Statuses', 'trustscript' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'trustscript' ); ?></option>
								<option value="approved"><?php esc_html_e( 'Approved', 'trustscript' ); ?></option>
							</select>
						</div>
						<div>
							<label for="rr-date-filter" class="trustscript-filter-label">
								<?php esc_html_e( 'Date Range', 'trustscript' ); ?>
							</label>
							<select id="rr-date-filter" class="trustscript-form-input trustscript-filter-input">
								<option value=""><?php esc_html_e( 'All Time', 'trustscript' ); ?></option>
								<option value="7"><?php esc_html_e( 'Last 7 Days', 'trustscript' ); ?></option>
								<option value="30"><?php esc_html_e( 'Last 30 Days', 'trustscript' ); ?></option>
								<option value="90"><?php esc_html_e( 'Last 90 Days', 'trustscript' ); ?></option>
							</select>
						</div>
						<div class="trustscript-filter-button-container">
							<button type="button" id="rr-apply-filters" class="trustscript-btn trustscript-btn-primary trustscript-filter-button">
								<?php esc_html_e( 'Apply Filters', 'trustscript' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div id="review-requests-loading" class="trustscript-requests-loading">
					<p><?php esc_html_e( 'Loading review requests...', 'trustscript' ); ?></p>
				</div>
				<div id="review-requests-list" class="trustscript-requests-list">
					<div id="rr-results-info" class="trustscript-results-info">
						<!-- Showing X-Y of Z results -->
					</div>
					<table class="trustscript-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order ID', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Product', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Status', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Project', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Date', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'trustscript' ); ?></th>
							</tr>
						</thead>
						<tbody id="review-requests-tbody">
							<!-- Populated by JavaScript -->
						</tbody>
					</table>
					
					<!-- Pagination -->
					<div id="rr-pagination" class="trustscript-pagination">
						<div id="rr-pagination-info" class="trustscript-pagination-info">
							<!-- Page X of Y -->
						</div>
						<div id="rr-pagination-buttons">
							<!-- Pagination buttons -->
						</div>
					</div>
				</div>
				<div id="review-requests-empty" class="trustscript-requests-empty">
					<p><?php esc_html_e( 'No review requests found. They will appear here after orders are completed.', 'trustscript' ); ?></p>
				</div>
			</div>

			<div style="margin-top: 16px;">
				<button type="button" id="refresh-review-requests" class="trustscript-btn trustscript-btn-primary">
					<?php esc_html_e( 'Refresh', 'trustscript' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	public function handle_fetch_requests() {
		check_ajax_referer( 'trustscript_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}

		$orders_result = trustscript_api_request( 'GET', 'api/wordpress-orders' );
		$stats_result  = trustscript_api_request( 'GET', 'api/review-requests/stats' );

		if ( is_wp_error( $orders_result ) ) {
			wp_send_json_error( array( 'message' => $orders_result->get_error_message() ), $orders_result->get_error_data()['status'] ?? 500 );
		}

		$orders_data = $orders_result['data'];

		$stats_data = array(
			'total'          => 0,
			'pending'        => 0,
			'approved'       => 0,
			'conversionRate' => 0,
		);

		if ( ! is_wp_error( $stats_result ) ) {
			$stats_parsed = $stats_result['data'];
			$stats_data = array(
				'total'          => $stats_parsed['totalRequests'] ?? 0,
				'pending'        => $stats_parsed['pendingReviews'] ?? 0,
				'approved'       => $stats_parsed['approvedReviews'] ?? 0,
				'conversionRate' => $stats_parsed['conversionRate'] ?? 0,
			);
		}

		$base_url = trustscript_get_base_url();
		$requests = array();

		if ( isset( $orders_data['orders'] ) && is_array( $orders_data['orders'] ) ) {
			foreach ( $orders_data['orders'] as $order ) {
				$created_at    = $order['createdAt'] ?? null;
				$date_formatted = $created_at ? wp_date( 'M j, Y g:i A', strtotime( $created_at ) ) : 'N/A';
				$project_name  = isset( $order['project']['name'] ) ? $order['project']['name'] : null;
				$dashboard_url = trailingslashit( $base_url ) . 'dashboard/wordpress-orders';

				$requests[] = array(
					'id'            => $order['id'] ?? null,
					'uniqueToken'   => $order['uniqueToken'] ?? null,
					'productName'   => $order['productName'] ?? 'Product',
					'sourceOrderId' => $order['sourceOrderId'] ?? null,
					'source'        => $order['source'] ?? 'wordpress',
					'status'        => strtolower( $order['status'] ?? 'pending' ),
					'date'          => $date_formatted,
					'dateObj'       => $created_at,
					'projectName'   => $project_name,
					'dashboardUrl'  => $dashboard_url,
				);
			}
		}

		wp_send_json_success( array(
			'stats'    => $stats_data,
			'requests' => $requests,
		) );
	}
}
