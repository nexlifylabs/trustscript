<?php
/**
 * Analytics Page Handler
 *
 * @package TrustScript
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Analytics_Page {

	public function __construct() {
  		add_action( 'wp_ajax_trustscript_fetch_usage', array( $this, 'handle_fetch_usage' ) );
  		add_action( 'wp_ajax_trustscript_fetch_usage_history', array( $this, 'handle_fetch_usage_history' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_trustscript-analytics' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'trustscript-analytics',
			plugin_dir_url( TRUSTSCRIPT_PLUGIN_FILE ) . 'assets/js/analytics.js',
			array( 'jquery' ),
			TRUSTSCRIPT_VERSION,
			true
		);

		wp_localize_script(
			'trustscript-analytics',
			'TrustscriptAnalytics',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'trustscript_admin' ),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'TrustScript Analytics', 'trustscript' ); ?></h1>
			
			<div class="trustscript-card trustscript-mb-24">
				<h2><?php esc_html_e( 'Welcome to TrustScript', 'trustscript' ); ?></h2>
				<p><?php esc_html_e( 'Transform your WooCommerce customer reviews with AI-powered content generation. TrustScript automatically creates authentic, engaging review options that help customers express their genuine experiences-making it easier to gather valuable feedback.', 'trustscript' ); ?></p>
			</div>

			<div id="trustscript-stats-grid" class="trustscript-grid trustscript-mb-24">
				<div class="trustscript-stat-card trustscript-stat-card-primary">
					<div class="trustscript-stat-value" id="stat-total-requests">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Review Requests Sent', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-success">
					<div class="trustscript-stat-value" id="stat-approved">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Approved Reviews', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-warning">
					<div class="trustscript-stat-value" id="stat-pending">-</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Pending Reviews', 'trustscript' ); ?></div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-purple">
					<div class="trustscript-stat-value" id="stat-conversion">-%</div>
					<div class="trustscript-stat-label"><?php esc_html_e( 'Conversion Rate', 'trustscript' ); ?></div>
				</div>
			</div>

			<div class="trustscript-workflow-grid">
				<div class="trustscript-card">
					<h2><?php esc_html_e( 'How It Works', 'trustscript' ); ?></h2>
					<div class="trustscript-how-it-works">
						<div class="trustscript-step">
							<div class="trustscript-step-number">01</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Order Delivered', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'When order is marked as "Delivered", TrustScript sends review request email to customer (configured by admin).', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">02</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Customer Clicks Link', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Customer opens email and clicks the secure review link to access the review page.', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">03</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Write Review', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Customer types their honest review in their own words - anything from a few words to detailed feedback.', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">04</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Select Tone & Variant', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Customer chooses writing tone (Professional, Casual, Enthusiastic). AI generates 3 enhanced versions.', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">05</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Choose Version', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Customer picks their favorite: original text or one of the AI-enhanced versions. Can edit before submitting.', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">06</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Auto-Publish', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Once approved, review automatically publishes to your WooCommerce product page via webhook or daily sync.', 'trustscript' ); ?></p>
							</div>
						</div>
						<div class="trustscript-step">
							<div class="trustscript-step-number">07</div>
							<div class="trustscript-step-content">
								<h3><?php esc_html_e( 'Cryptographic Verification', 'trustscript' ); ?></h3>
								<p><?php esc_html_e( 'Each review gets a unique SHA-256 hash for authenticity verification. Customers can verify at nexlifylabs.com/verify-review.', 'trustscript' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="trustscript-card">
					<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
						<h2 style="margin: 0;"><?php esc_html_e( 'Recent Activity (Last 10)', 'trustscript' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=trustscript-review-requests' ) ); ?>" class="trustscript-btn trustscript-btn-secondary trustscript-btn-sm">
							<?php esc_html_e( 'View All Review Requests →', 'trustscript' ); ?>
						</a>
					</div>
					<div id="trustscript-recent-activity" class="trustscript-activity-feed">
						<div class="trustscript-activity-item">
							<div class="trustscript-activity-dot"></div>
							<div class="trustscript-activity-content">
								<div class="trustscript-activity-title"><?php esc_html_e( 'Loading activity...', 'trustscript' ); ?></div>
								<div class="trustscript-activity-time"><?php esc_html_e( 'Please wait', 'trustscript' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="trustscript-action-buttons" data-section="action-buttons">
				<button id="trustscript-refresh-analytics" class="trustscript-btn trustscript-btn-primary" data-action="refresh-analytics">
					<?php esc_html_e( 'Refresh Analytics', 'trustscript' ); ?>
				</button>
				<a href="https://nexlifylabs.com/pricing" target="_blank" class="trustscript-btn trustscript-btn-secondary trustscript-button-spacing">
					<?php esc_html_e( 'Upgrade Plan', 'trustscript' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=trustscript-reviews' ) ); ?>" class="trustscript-btn trustscript-btn-secondary trustscript-button-spacing">
					<?php esc_html_e( 'Review Settings', 'trustscript' ); ?>
				</a>
			</div>
			
			<div id="trustscript-analytics-error" style="display:none;margin-top:16px;" data-section="analytics-error"></div>
		</div>
		<?php
	}

	public function handle_fetch_usage() {
		check_ajax_referer( 'trustscript_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$api_key  = get_option( 'trustscript_api_key', '' );
		$base_url = trailingslashit( get_option( 'trustscript_base_url', '' ) );

		if ( empty( $base_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'API settings not configured' ), 400 );
		}

		$usage_url = $base_url . 'api/review-requests/stats';

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'X-Site-URL'    => get_site_url(),
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $usage_url, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( json_decode( $body, true ) );
		}
		
		$error_message = 'Failed to fetch usage data';
		if ( $code === 401 ) {
			$error_message = 'API key authentication failed';
		} elseif ( $code === 429 ) {
			$error_message = 'Rate limit exceeded';
		} elseif ( $code === 403 ) {
			$error_message = 'Access denied';
		} elseif ( $code === 404 ) {
			$error_message = 'Resource not found';
		}
		
		wp_send_json_error( array( 'message' => $error_message ), $code );
	}

	public function handle_fetch_usage_history() {
		check_ajax_referer( 'trustscript_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$api_key  = get_option( 'trustscript_api_key', '' );
		$base_url = trailingslashit( get_option( 'trustscript_base_url', '' ) );

		if ( empty( $base_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'API settings not configured' ), 400 );
		}

		$usage_url = $base_url . 'api/review-requests/stats';

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'X-Site-URL'    => get_site_url(),
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $usage_url, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $body, true );
			wp_send_json_success( $data['dailyActivity'] ?? $data );
		}
		
		$error_message = 'Failed to fetch usage history';
		if ( $code === 401 ) {
			$error_message = 'API key authentication failed';
		} elseif ( $code === 429 ) {
			$error_message = 'Rate limit exceeded';
		} elseif ( $code === 403 ) {
			$error_message = 'Access denied';
		} elseif ( $code === 404 ) {
			$error_message = 'Resource not found';
		}
		
		wp_send_json_error( array( 'message' => $error_message ), $code );
	}
}
