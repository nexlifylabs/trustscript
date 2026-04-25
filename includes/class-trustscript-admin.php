<?php
if (!defined('ABSPATH')) {
	exit;
}

class TrustScript_Plugin_Admin
{

	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Find WooCommerce order by review token
	 * 
	 * @param string $unique_token The review token to search for
	 * @return WC_Order|null Order object if token found on real order, null otherwise
	 */
	public static function find_order_by_review_token($unique_token)
	{
		if (empty($unique_token) || !is_string($unique_token)) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for cross-storage compatibility, caching handled by wc_get_order()
		$order_ids = wc_get_orders(array(
			'limit' => 1,
			'return' => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key' => '_trustscript_review_token',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => $unique_token,
		));

		if (!empty($order_ids)) {
			$order_id = $order_ids[0];

			$order = wc_get_order($order_id);
			if ($order) {
				return $order;
			}
		}

		$order_ids_by_order_token = wc_get_orders(array(
			'limit' => 1,
			'return' => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key' => '_trustscript_order_token',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => $unique_token,
		));

		if (!empty($order_ids_by_order_token)) {
			$order = wc_get_order($order_ids_by_order_token[0]);
			if ($order) {
				return $order;
			}
		}

		return null;
	}

	private function __construct()
	{
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_notices', array($this, 'display_api_key_invalid_notice'));
		add_action('admin_notices', array($this, 'display_quota_exceeded_notice'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_trustscript_delete_api_key', array($this, 'handle_delete_api_key'));
		add_action('wp_ajax_trustscript_save_review_settings', array($this, 'handle_save_review_settings'));
		add_action('wp_ajax_trustscript_sync_orders', array($this, 'handle_sync_orders'));
		add_action('wp_ajax_trustscript_save_service_settings', array($this, 'handle_save_service_settings'));
		add_action('wp_ajax_trustscript_save_optional_data_settings', array($this, 'handle_save_optional_data_settings'));
		add_action('wp_ajax_trustscript_dismiss_notice', array($this, 'handle_dismiss_notice'));
		add_action('wp_ajax_trustscript_save_uninstall_preference', array($this, 'handle_save_uninstall_preference'));
		add_action('wp_ajax_trustscript_save_privacy_settings',    array( new TrustScript_Privacy_Settings_Page(), 'handle_save_privacy_settings' ) );
		add_action('update_option_trustscript_api_key', array($this, 'on_api_key_updated'), 10, 2);
		add_action('admin_init', array($this, 'maybe_redirect_after_api_key_save'));
	}

	/**
	 * After saving an API key from the first-time setup page.
	 */
	public function maybe_redirect_after_api_key_save()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect logic only, no data processing.
		if (!isset($_GET['page']) || $_GET['page'] !== 'trustscript-settings') {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (empty($_GET['first-time']) || empty($_GET['settings-updated'])) {
			return;
		}
		if (empty(get_option('trustscript_api_key', ''))) {
			return;
		}
		wp_safe_redirect(admin_url('admin.php?page=trustscript-settings&settings-updated=true'));
		exit;
	}

	private function __clone()
	{
		// Singleton pattern - do not allow cloning
	}

	public function __wakeup()
	{
		throw new Exception('Cannot unserialize singleton');
	}

	public function add_admin_menu()
	{
		// Main Menu
		add_menu_page(
			__('TrustScript Settings', 'trustscript'),
			__('TrustScript', 'trustscript'),
			'manage_options',
			'trustscript-settings',
			array('TrustScript_Settings_Page', 'render'),
			'dashicons-star-filled',
			58
		);

		add_submenu_page(
			'trustscript-settings',
			__('Review Settings', 'trustscript'),
			__('Review Settings', 'trustscript'),
			'manage_options',
			'trustscript-reviews',
			array('TrustScript_Reviews_Page', 'render')
		);

		add_submenu_page(
			'trustscript-settings',
			__('Analytics', 'trustscript'),
			__('Analytics', 'trustscript'),
			'manage_options',
			'trustscript-review-requests',
			array('TrustScript_Review_Request_Page', 'render')
		);



		add_submenu_page(
			'trustscript-settings',
			__('Privacy & Compliance', 'trustscript'),
			__('Privacy & Compliance', 'trustscript'),
			'manage_options',
			'trustscript-privacy',
			array('TrustScript_Privacy_Settings_Page', 'render')
		);

		$queue_count = absint( TrustScript_Queue::count_pending() );
		$queue_label = __('Pending Queue', 'trustscript');
		if ($queue_count > 0) {
			$queue_label .= ' <span class="update-plugins count-' . $queue_count . '"><span class="plugin-count">' . $queue_count . '</span></span>';
		}
		add_submenu_page(
			'trustscript-settings',
			__('Pending Queue', 'trustscript'),
			$queue_label,
			'manage_options',
			'trustscript-queue',
			array('TrustScript_Pending_Queue_Page', 'render')
		);
	}

	/**
	 * Display API key invalid notice on TrustScript admin pages
	 */
	public function display_api_key_invalid_notice()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || !is_string($screen->id) || false === strpos($screen->id, 'trustscript')) {
			return;
		}

		$invalid_notice = get_transient('trustscript_api_key_invalid_notice');

		if (!$invalid_notice) {
			return;
		}

		?>
		<div class="notice notice-error is-dismissible trustscript-dismissible-notice" data-notice="api_key_invalid">
			<h3>🔑 <?php esc_html_e('API Key Invalid or Expired', 'trustscript'); ?></h3>
			<p><?php esc_html_e('Your TrustScript API key is no longer valid. This might be because:', 'trustscript'); ?></p>
			<ul>
				<li><?php esc_html_e('The key has expired (development keys expire after 24 hours)', 'trustscript'); ?></li>
				<li><?php esc_html_e('The key was deleted from your TrustScript dashboard', 'trustscript'); ?></li>
				<li><?php esc_html_e('Your account or key was revoked', 'trustscript'); ?></li>
			</ul>
			<p>
				<a href="<?php echo esc_url(admin_url('admin.php?page=trustscript-settings')); ?>"
					class="button button-primary">
					<?php esc_html_e('Go to Settings & Update Key', 'trustscript'); ?>
				</a>
				<a href="<?php echo esc_url( TRUSTSCRIPT_DASHBOARD_URL . '/api-keys' ); ?>" target="_blank" class="button">
					<?php esc_html_e('Generate New Key', 'trustscript'); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Display quota exceeded notice on TrustScript admin pages
	 */
	public function display_quota_exceeded_notice()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || !is_string($screen->id) || false === strpos($screen->id, 'trustscript')) {
			return;
		}

		$quota_info = get_transient('trustscript_quota_exceeded_notice');

		if (!$quota_info || !isset($quota_info['quotaExceeded']) || !$quota_info['quotaExceeded']) {
			return;
		}

		$current_plan = isset($quota_info['currentPlan']) ? sanitize_text_field($quota_info['currentPlan']) : 'unknown';
		$next_plan = isset($quota_info['nextPlan']) ? sanitize_text_field($quota_info['nextPlan']) : null;
		$next_limit = isset($quota_info['nextLimit']) ? intval($quota_info['nextLimit']) : null;
		$reset_date = isset($quota_info['resetDate']) ? sanitize_text_field($quota_info['resetDate']) : null;
		$plan_label = ucfirst(str_replace('_', ' ', $current_plan));

		$upgrade_message = sprintf(
			/* translators: %s: plan name */
			esc_html__('Monthly review limit reached for your %s plan.', 'trustscript'),
			esc_html($plan_label)
		);

		if ($next_plan && $next_limit) {
			$next_plan_label = ucfirst(str_replace('_', ' ', $next_plan));
			$upgrade_message .= sprintf(
				/* translators: %1$s: next plan name, %2$d: review limit */
				esc_html__(' Upgrade to %1$s for %2$d reviews per month.', 'trustscript'),
				esc_html($next_plan_label),
				intval($next_limit)
			);
		}

		if ($reset_date) {
			try {
				$reset_datetime = new DateTime($reset_date, new DateTimeZone('UTC'));
				$formatted_date = $reset_datetime->format('F j, Y');
				$upgrade_message .= sprintf(
					/* translators: %s: reset date */
					esc_html__(' Or wait until %s for your limit to reset.', 'trustscript'),
					esc_html($formatted_date)
				);
			} catch (Exception $e) {
				$upgrade_message .= sprintf(
					/* translators: %s: reset date */
					esc_html__(' Or wait until %s for your limit to reset.', 'trustscript'),
					esc_html($reset_date)
				);
			}
		}

		?>
		<div class="notice notice-warning is-dismissible trustscript-dismissible-notice" data-notice="quota_exceeded">
			<h3>📊 <?php esc_html_e('Review Quota Limit Reached', 'trustscript'); ?></h3>
			<p><?php echo wp_kses_post($upgrade_message); ?></p>
			<p>
				<a href="<?php echo esc_url( TRUSTSCRIPT_PRICING_URL ); ?>" target="_blank" class="button button-primary">
					<?php esc_html_e('Upgrade Your Plan', 'trustscript'); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function register_settings()
	{
		register_setting('trustscript_options', 'trustscript_api_key', array(
			'sanitize_callback' => array($this, 'sanitize_api_key'),
		));
		register_setting('trustscript_options', 'trustscript_webhook_secret', array(
			'type' => 'string',
			'sanitize_callback' => array($this, 'sanitize_webhook_secret'),
			'default' => '',
		));
		register_setting('trustscript_options', 'trustscript_data_consent', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => '',
		));
	}

	/**
	 * Sanitize checkbox value
	 */
	public function sanitize_checkbox($value)
	{
		return !empty($value) ? true : false;
	}

	/**
	 * Validate and sanitize the webhook secret when it's saved.
	 */
	public function sanitize_webhook_secret($value)
	{
		$value = sanitize_text_field($value);
		if (empty($value)) {
			$existing = get_option('trustscript_webhook_secret', '');
			if (!empty($existing)) {
				return $existing;
			}
			return '';
		}

		if (!preg_match('/^TSS-[A-F0-9-]+$/i', $value)) {
			// Check if this is an encrypted blob round-tripping through the settings form
			$decrypted = trustscript_decrypt_data($value);
			if (!empty($decrypted) && preg_match('/^TSS-[A-F0-9-]+$/i', $decrypted)) {
				return $value;
			}
			
			add_settings_error(
				'trustscript_webhook_secret',
				'invalid_format',
				esc_html__('Invalid webhook secret format. Webhook secrets should look like: TSS-XXXX-XXXX-XXXX', 'trustscript')
			);
			return '';
		}

		$encrypted = trustscript_encrypt_data( $value );
		
		if (empty($encrypted)) {
			add_settings_error(
				'trustscript_webhook_secret',
				'encryption_failed',
				esc_html__('Failed to encrypt webhook secret. Please try again.', 'trustscript')
			);
			return '';
		}
		
		return $encrypted;
	}

	/**
	 * Validate and sanitize the API key when it's saved.
	 */

	public function sanitize_api_key($value)	{
		$value = sanitize_text_field($value);

		if (empty($value)) {
			$existing = get_option('trustscript_api_key', '');
			if (!empty($existing)) {
				return $existing;
			}
			delete_transient('trustscript_base_url');
			return '';
		}

		if (!preg_match('/^TSK-[A-F0-9-]+$/i', $value)) {
			// Check if this is an encrypted blob round-tripping through the settings form
			$decrypted = trustscript_decrypt_data($value);
			if (!empty($decrypted) && preg_match('/^TSK-[A-F0-9-]+$/i', $decrypted)) {
				return $value;
			}
			
			add_settings_error(
				'trustscript_api_key',
				'invalid_format',
				esc_html__('Invalid API key format. API keys should look like: TSK-XXXX-XXXX-XXXX', 'trustscript')
			);
			return '';
		}

		$site_url = get_site_url();
		$site_url_normalized = rtrim($site_url, '/');

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by settings_fields() / options.php handler.
		$post_consent = isset($_POST['trustscript_data_consent']) ? sanitize_text_field(wp_unslash($_POST['trustscript_data_consent'])) : '';
		$data_consent = !empty($post_consent) ? $post_consent : get_option('trustscript_data_consent', '');
		if (empty($data_consent)) {
			add_settings_error(
				'trustscript_api_key',
				'consent_required',
				esc_html__('You must agree to data sharing before verifying your API key.', 'trustscript')
			);
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by settings_fields() / options.php handler.
		$post_webhook_secret = isset($_POST['trustscript_webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['trustscript_webhook_secret'])) : '';
		$webhook_secret = !empty($post_webhook_secret) ? $post_webhook_secret : get_option('trustscript_webhook_secret', '');

		if (empty($webhook_secret)) {
			add_settings_error(
				'trustscript_api_key',
				'webhook_secret_required',
				esc_html__('Webhook secret is required. Please generate both an API key and webhook secret from the TrustScript dashboard.', 'trustscript')
			);
			return '';
		}

		if (!preg_match('/^TSS-[A-F0-9-]+$/i', $webhook_secret)) {
			add_settings_error(
				'trustscript_api_key',
				'invalid_webhook_secret_format',
				esc_html__('Invalid webhook secret format. Webhook secrets should look like: TSS-XXXX-XXXX-XXXX', 'trustscript')
			);
			return '';
		}

		$verify_url = apply_filters('trustscript_verify_endpoint', TRUSTSCRIPT_VERIFY_ENDPOINT);

		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode(array(
				'apiKey' => $value,
				'domain' => $site_url_normalized,
			)),
			'timeout' => 15,
			'sslverify' => true,
		);

		$response = wp_remote_post($verify_url, $args);

		if (is_wp_error($response)) {
			add_settings_error(
				'trustscript_api_key',
				'verify_failed',
				sprintf(
					/* translators: %s: error message */
					esc_html__('Could not verify your API key with TrustScript: %s. Please check your internet connection and try again.', 'trustscript'),
					esc_html($response->get_error_message())
				)
			);
			return '';
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if ($code < 200 || $code >= 300 || !$data || empty($data['valid'])) {
			$message = 'API key verification failed (HTTP ' . $code . ').';
			if ($data && !empty($data['message'])) {
				$message = $data['message'];
			}
			add_settings_error('trustscript_api_key', 'verify_failed', esc_html($message));
			return '';
		}

		if (!empty($data['apiUrl'])) {
			update_option('trustscript_base_url', $data['apiUrl']);


			set_transient('trustscript_base_url', $data['apiUrl'], 3600);
		}

		delete_transient('trustscript_user_plan');
		delete_transient('trustscript_api_key_invalid_notice');
		delete_transient('trustscript_quota_exceeded_notice');

		$current_errors = get_settings_errors('trustscript_api_key');
		$already_added = false;
		foreach ($current_errors as $err) {
			if ($err['code'] === 'api_key_verified') {
				$already_added = true;
				break;
			}
		}

		if (!$already_added) {
			$success_message = esc_html__('API key verified successfully! Your site is now connected to TrustScript.', 'trustscript');

			if (isset($data['quota']) && is_array($data['quota'])) {
				$quota = $data['quota'];
				$limit = isset($quota['limit']) ? intval($quota['limit']) : 0;
				$used = isset($quota['used']) ? intval($quota['used']) : 0;
				$remaining = isset($quota['remaining']) ? intval($quota['remaining']) : 0;
				$reset = isset($quota['resetDate']) ? sanitize_text_field($quota['resetDate']) : '';
				$exceeded = isset($quota['isExceeded']) ? boolval($quota['isExceeded']) : false;
				$plan = isset($quota['plan']) ? sanitize_text_field($quota['plan']) : '';
				$next_plan = isset($quota['nextPlan']) ? sanitize_text_field($quota['nextPlan']) : '';
				$next_limit = isset($quota['nextLimit']) ? intval($quota['nextLimit']) : 0;

				// Cache the plan for internal use
				if (!empty($plan)) {
					set_transient('trustscript_user_plan', $plan, WEEK_IN_SECONDS);
				}

				if ($exceeded) {
					$plan_label = !empty($plan) ? ucfirst(str_replace('_', ' ', $plan)) : 'unknown';
					$success_message = sprintf(
						/* translators: %s: plan name */
						esc_html__('Monthly review limit reached for your %s plan.', 'trustscript'),
						esc_html($plan_label)
					);

					if (!empty($next_plan) && $next_limit > 0) {
						$next_plan_label = ucfirst(str_replace('_', ' ', $next_plan));
						$success_message .= sprintf(
							/* translators: %1$s: next plan name, %2$d: review limit */
							esc_html__(' Upgrade to %1$s for %2$d reviews per month.', 'trustscript'),
							esc_html($next_plan_label),
							intval($next_limit)
						);
					}

					if (!empty($reset)) {
						try {
							$reset_datetime = new DateTime($reset, new DateTimeZone('UTC'));
							$formatted_date = $reset_datetime->format('F j, Y');
							$success_message .= sprintf(
								/* translators: %s: reset date */
								esc_html__(' Or wait until %s for your limit to reset.', 'trustscript'),
								esc_html($formatted_date)
							);
						} catch (Exception $e) {
							$success_message .= sprintf(
								/* translators: %s: reset date */
								esc_html__(' Or wait until %s for your limit to reset.', 'trustscript'),
								esc_html($reset)
							);
						}
					}
					$notice_type = 'warning';
				} else {
					$formatted_reset = $reset;
					if (!empty($reset)) {
						try {
							$reset_datetime = new DateTime($reset, new DateTimeZone('UTC'));
							$formatted_reset = $reset_datetime->format('F j, Y');
						} catch (Exception $e) {
							$formatted_reset = $reset;
						}
					}
					$success_message .= sprintf(
						/* translators: 1: remaining reviews, 2: total limit, 3: reset date */
						esc_html__(' You have %1$d/%2$d reviews remaining this month (resets on %3$s).', 'trustscript'),
						$remaining,
						$limit,
						$formatted_reset
					);
					$notice_type = 'success';
				}

				set_transient(
					'trustscript_last_quota',
					array(
						'limit' => $limit,
						'used' => $used,
						'remaining' => $remaining,
						'isExceeded' => $exceeded,
						'resetDate' => $reset,
						'plan' => $plan,
						'nextPlan' => $next_plan,
						'nextLimit' => $next_limit,
						'timestamp' => time(),
					),
					3600
				);
			} else {
				$notice_type = 'success';
			}

			add_settings_error(
				'trustscript_api_key',
				'api_key_verified',
				$success_message,
				$notice_type
			);
		}

		$encrypted = trustscript_encrypt_data( $value );
		
		if (empty($encrypted)) {
			add_settings_error(
				'trustscript_api_key',
				'encryption_failed',
				esc_html__('Failed to encrypt API key. Please try again.', 'trustscript')
			);
			return '';
		}
		
		return $encrypted;
	}

	/**
	 * Handle API key updates: if the key changed and we have pending queue items, attempt to auto-process the queue with the new key. This allows merchants to fix their connection issues by simply updating/pasting a valid API key, without needing to manually trigger a separate "process queue" action after reconnecting.
	 * 
	 * @param mixed $old_value The old option value
	 * @param mixed $new_value The new option value
	 * @return void
	 */
	public function on_api_key_updated($old_value, $new_value)
	{
		if ($old_value === $new_value) {
			return;
		}

		if (empty($new_value)) {
			return;
		}

		$pending_count = TrustScript_Queue::count_pending();
		if ($pending_count === 0) {
			return;
		}

		do_action('trustscript_process_quota_queue');
	}

	/**
	 * Get TrustScript base URL
	 */
	public function get_trustscript_base_url()
	{
		return trustscript_get_base_url();
	}

	public function enqueue_assets($hook)
	{
		if (!is_string($hook) || false === strpos($hook, 'trustscript')) {
			return;
		}

		// Enqueue CSS & JS & Media Library
		$base_url = plugin_dir_url(__DIR__);
		$base_dir = plugin_dir_path(__DIR__);
		$admin_css_ver = file_exists($base_dir . 'assets/css/trustscript-admin.css') ? filemtime($base_dir . 'assets/css/trustscript-admin.css') : '0.2.0';
		$admin_notices_css_ver = file_exists($base_dir . 'assets/css/trustscript-admin-notices.css') ? filemtime($base_dir . 'assets/css/trustscript-admin-notices.css') : '0.2.0';
		$admin_js_ver = file_exists($base_dir . 'assets/js/admin.js') ? filemtime($base_dir . 'assets/js/admin.js') : '0.2.0';
		wp_enqueue_style('trustscript-admin-css', $base_url . 'assets/css/trustscript-admin.css', array(), $admin_css_ver);
		wp_enqueue_style('trustscript-admin-notices', $base_url . 'assets/css/trustscript-admin-notices.css', array(), $admin_notices_css_ver);
		wp_enqueue_script('trustscript-admin-js', $base_url . 'assets/js/admin.js', array('jquery'), $admin_js_ver, true);

		if (strpos($hook, 'trustscript-reviews') !== false) {
			$reviews_js_ver = file_exists($base_dir . 'assets/js/reviews.js') ? filemtime($base_dir . 'assets/js/reviews.js') : '0.2.0';
			wp_enqueue_script('trustscript-reviews-js', $base_url . 'assets/js/reviews.js', array('jquery'), $reviews_js_ver, true);
		}

		if (strpos($hook, 'trustscript-review-requests') !== false) {
			$review_requests_js_ver = file_exists($base_dir . 'assets/js/review-requests.js') ? filemtime($base_dir . 'assets/js/review-requests.js') : '0.2.0';
			wp_enqueue_script('trustscript-review-requests-js', $base_url . 'assets/js/review-requests.js', array('jquery'), $review_requests_js_ver, true);
		}



		// Localize the script with settings and translation strings.
		wp_localize_script('trustscript-admin-js', 'TrustscriptAdmin', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('trustscript_admin'),

			'save_review_nonce' => wp_create_nonce('trustscript_save_review'),
			'site_url' => get_site_url(),
			'i18n' => array(
				'noActivity' => __('No recent activity', 'trustscript'),
				'configureSettings' => __('Start by configuring review settings', 'trustscript'),
				'reviewsGenerated' => __('Reviews Generated', 'trustscript'),
				'loadFailed' => __('Failed to load analytics data. Please check your API connection in Settings.', 'trustscript'),
				'refreshing' => __('Refreshing...', 'trustscript'),
				'refreshButton' => __('Refresh Analytics', 'trustscript'),
				'saving' => __('Saving...', 'trustscript'),
				'saveButton' => __('Save Review Settings', 'trustscript'),
				'saveSuccess' => __('Settings saved successfully!', 'trustscript'),
				'saveFailed' => __('Failed to save', 'trustscript'),
				'networkError' => __('Network error', 'trustscript'),
				'syncing' => __('Syncing...', 'trustscript'),
				'syncButton' => __('Sync Completed Orders', 'trustscript'),
				'syncConfirm' => __('This will create review requests for all completed orders in the selected time range. Continue?', 'trustscript'),
				'syncFailed' => __('Failed to sync', 'trustscript'),
				'delayDelivered' => __('Short delay recommended (1-2 days) - customers already have the product.', 'trustscript'),
				'delayCompleted' => __('Longer delay recommended to ensure product delivery before review request. International orders use this same delay by default. To use a different delay for international orders, enable "Handle international orders differently" below.', 'trustscript'),
				'confirmClear' => __('Are you sure? This will remove the item from the queue.', 'trustscript'),
				'confirmClearQueue' => __('Remove this order from the queue? This will not cancel the order, but the review request will NOT be retried.', 'trustscript'),
				'apiKeyRequired' => __('Please paste your TrustScript API key (TSK-XXXX-XXXX-XXXX).', 'trustscript'),
				'apiKeyRequiredTitle' => __('API Key Required', 'trustscript'),
				'invalidFormat' => __('Invalid format. Your key should look like: TSK-XXXX-XXXX-XXXX. Copy it from your TrustScript dashboard.', 'trustscript'),
				'invalidFormatTitle' => __('Invalid API Key Format', 'trustscript'),
				'deleting' => __('Deleting...', 'trustscript'),
				'failedToDelete' => __('Failed to delete API key', 'trustscript'),
				'unknownError' => __('Unknown error', 'trustscript'),
				'queuedForProcessing' => __('Queued for processing...', 'trustscript'),
				'processingBackground' => __('Processing your orders in the background — please check back in a few minutes.', 'trustscript'),
				'allCategories' => __('All categories', 'trustscript'),
				'oneCategory' => __('1 category', 'trustscript'),
				/* translators: %d: number of categories */
				'nCategories' => __('%d categories', 'trustscript'),
				'cancel' => __('Cancel', 'trustscript'),
				'retrying' => __('Retrying...', 'trustscript'),
				'clearing' => __('Clearing...', 'trustscript'),
				'retry' => __('Retry', 'trustscript'),
				'clear' => __('Clear', 'trustscript'),
				'processQueueNow' => __('Process Queue Now', 'trustscript'),
				'savePreference' => __('Save Preference', 'trustscript'),
				'retryFailed' => __('Failed to retry', 'trustscript'),
				'clearFailed' => __('Failed to clear', 'trustscript'),
				'processQueueFailed' => __('Failed to process queue', 'trustscript'),
				'savePreferenceFailed' => __('Failed to save preference', 'trustscript'),
				'pasteApiKeyPlaceholder' => __('Paste new API key to replace current key…', 'trustscript'),
				'saveChanges' => __('Save Changes', 'trustscript'),
				'syncBreakdown' => __('Breakdown:', 'trustscript'),
				'syncReviewsPublished' => __('review(s) published', 'trustscript'),
				'syncOrdersSent' => __('new order(s) sent to TrustScript', 'trustscript'),
				'syncOrdersSkipped' => __('order(s) already published (skipped)', 'trustscript'),
			),
		));
	}

	/**
	 * Handle AJAX request to dismiss quota exceeded or API key invalid notice
	 */
	public function handle_dismiss_notice()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Permission denied', 'trustscript')));
		}

		$notice = isset($_POST['notice']) ? sanitize_key($_POST['notice']) : '';

		$allowed = array(
			'quota_exceeded' => 'trustscript_quota_exceeded_notice',
			'api_key_invalid' => 'trustscript_api_key_invalid_notice',
		);

		if (!isset($allowed[$notice])) {
			wp_send_json_error(array('message' => esc_html__('Unknown notice', 'trustscript')));
		}

		delete_transient($allowed[$notice]);
		wp_send_json_success();
	}

	/**
	 * Save uninstall preference via AJAX
	 */
	public function handle_save_uninstall_preference()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Permission denied', 'trustscript')));
		}

		$delete_data = isset($_POST['delete_data']) ? $this->sanitize_checkbox(sanitize_text_field(wp_unslash($_POST['delete_data']))) : false;
		update_option('trustscript_delete_data_on_uninstall', $delete_data);

		$status = $delete_data ? __('enabled', 'trustscript') : __('disabled', 'trustscript');
		wp_send_json_success(array(
			/* translators: %s is the status of data deletion (enabled or disabled) */
			'message' => sprintf(__('Data deletion on uninstall %s', 'trustscript'), $status),
			'delete_data' => $delete_data,
		));
	}

	public function handle_delete_api_key()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Unauthorized', 'trustscript')), 401);
		}

		delete_option('trustscript_api_key');
		delete_option('trustscript_webhook_secret');
		delete_transient('trustscript_user_plan');

		wp_send_json_success(array('message' => esc_html__('API key deleted successfully', 'trustscript')));
	}

	/**
	 * Handle AJAX request to save review settings.
	 *
	 * @since 1.0.0
	 */
	public function handle_save_review_settings()
	{
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (!$nonce || !wp_verify_nonce($nonce, 'trustscript_save_review')) {
			wp_send_json_error(array(
				'message' => esc_html__('Security check failed', 'trustscript'),
			));
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array(
				'message' => esc_html__('Unauthorized', 'trustscript'),
			));
		}

		// Boolean values 
		$enabled = isset($_POST['enabled'])
			&& 'true' === sanitize_text_field(wp_unslash($_POST['enabled']));

		$auto_publish = isset($_POST['auto_publish'])
			&& 'true' === sanitize_text_field(wp_unslash($_POST['auto_publish']));

		$enable_voting = isset($_POST['enable_voting'])
			&& 'true' === sanitize_text_field(wp_unslash($_POST['enable_voting']));

		$auto_sync_enabled = isset($_POST['auto_sync_enabled'])
			&& 'true' === sanitize_text_field(wp_unslash($_POST['auto_sync_enabled']));

		$enable_international_handling = isset($_POST['enable_international_handling'])
			&& 'true' === sanitize_text_field(wp_unslash($_POST['enable_international_handling']));

		// Integer values
		$delay_hours = isset($_POST['delay_hours'])
			? absint(wp_unslash($_POST['delay_hours']))
			: 1;

		$auto_sync_lookback = isset($_POST['auto_sync_lookback'])
			? absint(wp_unslash($_POST['auto_sync_lookback']))
			: 2;

		$international_delay_hours = isset($_POST['international_delay_hours'])
			? absint(wp_unslash($_POST['international_delay_hours']))
			: 336;

		// Trigger status (string value)
		$trigger_status = isset($_POST['trigger_status'])
			? sanitize_text_field(wp_unslash($_POST['trigger_status']))
			: 'delivered';

		// Time value (HH:MM)
		$auto_sync_time = isset($_POST['auto_sync_time'])
			? sanitize_text_field(wp_unslash($_POST['auto_sync_time']))
			: '02:00';

		// Validate time format (basic check for HH:MM)
		if (!preg_match('/^\d{2}:\d{2}$/', $auto_sync_time)) {
			$auto_sync_time = '02:00';
		}

		// Categories - ensure it's an array of valid category IDs, and remove child categories if their parent is also selected to avoid redundancy
		$categories = array();
		if (isset($_POST['categories']) && is_array($_POST['categories'])) {
			$all_valid_categories = get_terms(array(
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'fields' => 'id=>parent',
			));

			if (!is_wp_error($all_valid_categories) && !empty($all_valid_categories)) {
				$requested_categories = array_map('absint', (array) wp_unslash($_POST['categories']));
				$requested_categories = array_unique($requested_categories);
				
				$filtered_categories = array();
				foreach ($requested_categories as $cat_id) {
					$should_include = true;
					
					foreach ($requested_categories as $other_cat_id) {
						if ($other_cat_id !== $cat_id && $this->is_category_ancestor($other_cat_id, $cat_id, $all_valid_categories)) {
							$should_include = false;
							break;
						}
					}
					
					if ($should_include) {
						$filtered_categories[] = $cat_id;
					}
				}
				
				$categories = array_values($filtered_categories);
			}
		}

		// MemberPress settings
		$memberpress_memberships = array();
		if (isset($_POST['trustscript_memberpress_memberships']) && is_array($_POST['trustscript_memberpress_memberships'])) {
			$memberpress_memberships = array_map('absint', wp_unslash($_POST['trustscript_memberpress_memberships']));
		}

		$memberpress_delay_days = 0;
		if (isset($_POST['trustscript_memberpress_delay_days'])) {
			$memberpress_delay_days = absint(wp_unslash($_POST['trustscript_memberpress_delay_days']));
			if ($memberpress_delay_days > 90) {
				$memberpress_delay_days = 0;
			}
		}

		// WooCommerce settings
		$woocommerce_min_value = 0;
		if (isset($_POST['trustscript_woocommerce_min_value'])) {
			$woocommerce_min_value = floatval(wp_unslash($_POST['trustscript_woocommerce_min_value']));
			$woocommerce_min_value = max(0, $woocommerce_min_value);
		}

		$woocommerce_exclude_free = '0';
		if (isset($_POST['trustscript_woocommerce_exclude_free'])) {
			$woocommerce_exclude_free = '1' === sanitize_text_field(wp_unslash($_POST['trustscript_woocommerce_exclude_free']))
				? '1'
				: '0';
		}

		// Keywords
		$keywords = array();
		if (isset($_POST['trustscript_review_keywords']) && is_array($_POST['trustscript_review_keywords'])) {
			$keywords = array_filter(
				array_map('sanitize_text_field', wp_unslash($_POST['trustscript_review_keywords']))
			);
		}

		// Validation and sanitization
		if ($delay_hours > 2160) {
			$delay_hours = 0;
		}
		if ($international_delay_hours > 2160) {
			$international_delay_hours = 336;
		}
		if ($auto_sync_lookback < 1 || $auto_sync_lookback > 2) {
			$auto_sync_lookback = 2;
		}

		if (!in_array($trigger_status, array('delivered', 'completed'), true)) {
			$trigger_status = 'delivered';
		}

		// Save options
		update_option('trustscript_reviews_enabled', $enabled);
		update_option('trustscript_review_categories', $categories);
		update_option('trustscript_memberpress_memberships', $memberpress_memberships);
		update_option('trustscript_memberpress_delay_days', $memberpress_delay_days);
		update_option('trustscript_woocommerce_min_value', $woocommerce_min_value);
		update_option('trustscript_woocommerce_exclude_free', $woocommerce_exclude_free);
		update_option('trustscript_auto_publish', $auto_publish);
		update_option('trustscript_enable_voting', $enable_voting);
		update_option('trustscript_review_delay_hours', $delay_hours);
		update_option('trustscript_review_trigger_status', $trigger_status);
		update_option('trustscript_auto_sync_enabled', $auto_sync_enabled);
		update_option('trustscript_auto_sync_time', $auto_sync_time);
		update_option('trustscript_auto_sync_lookback', $auto_sync_lookback);
		update_option('trustscript_enable_international_handling', $enable_international_handling);
		update_option('trustscript_international_delay_hours', $international_delay_hours);
		update_option('trustscript_review_keywords', $keywords);

		// Schedule or unschedule auto-sync cron job based on the setting
		if (class_exists('TrustScript_Auto_Sync')) {
			if ($auto_sync_enabled) {
				TrustScript_Auto_Sync::schedule_cron();
			} else {
				TrustScript_Auto_Sync::unschedule_cron();
			}
		}

		$response = array(
			'message' => esc_html__('Review settings saved successfully', 'trustscript'),
			'settings' => array(
				'enabled' => $enabled,
				'categories' => $categories,
				'auto_publish' => $auto_publish,
				'enable_voting' => $enable_voting,
				'delay_hours' => $delay_hours,
				'trigger_status' => $trigger_status,
				'auto_sync_enabled' => $auto_sync_enabled,
				'auto_sync_time' => $auto_sync_time,
				'auto_sync_lookback' => $auto_sync_lookback,
				'enable_international_handling' => $enable_international_handling,
				'international_delay_hours' => $international_delay_hours,
				'keywords' => $keywords,
			),
		);

		wp_send_json_success($response);
	}

	/**
	 * Syncing existing completed orders and approved reviews
	 */
	public function handle_sync_orders()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Unauthorized', 'trustscript')), 401);
			return;
		}

		$service_manager = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();

		if (empty($active_providers)) {
			wp_send_json_error(array('message' => esc_html__('No active service providers detected. Please enable at least one service (WooCommerce or MemberPress).', 'trustscript')), 400);
		}

		$days = isset($_POST['days']) ? sanitize_text_field(wp_unslash($_POST['days'])) : '2';

		if ($days !== 'all') {
			$days = max(1, min(2, intval($days)));
		} else {
			$days = 2; // Max lookback is 2 days to avoid performance issues
		}

		$reviews_published = $this->fetch_and_publish_approved_reviews();

		if (!class_exists('TrustScript_Sync_Service')) {
			wp_send_json_error(array('message' => esc_html__('Sync service not available', 'trustscript')), 500);
		}

		$sync_service = new TrustScript_Sync_Service();
		$orders_synced = 0;
		$orders_skipped = 0;
		$orders_total = 0;

		foreach ($active_providers as $service_id => $provider) {
			try {
				$is_enabled = get_option("trustscript_enable_service_{$service_id}", '1') === '1';

				if (!$is_enabled) {
					continue;
				}

				$trigger_status = get_option("trustscript_trigger_status_{$service_id}", '');
				if (empty($trigger_status)) {
					continue;
				}

				$result = $sync_service->sync_service_orders($provider, $service_id, $days);
				$orders_synced += $result['processed'] ?? 0;
				$orders_skipped += $result['skipped'] ?? 0;
				$orders_total += $result['total'] ?? 0;
			} catch (Exception $e) {
				// Fall silently.. 
			}
		}

		$total_processed = $reviews_published + $orders_synced;

		if ($total_processed === 0) {
			$status_message = __('No new data to sync', 'trustscript');
			if ($orders_skipped > 0) {
				$status_message .= sprintf(
					/* translators: %d: number of orders that were already published */
					__(' (but %d order(s) were already published and skipped)', 'trustscript'),
					$orders_skipped
				);
			}
			wp_send_json_success(array(
				'message' => $status_message,
				'processed' => 0,
				'reviews_published' => 0,
				'orders_synced' => 0,
				'orders_skipped' => $orders_skipped,
				'orders_total' => $orders_total,
			));
			return;
		}

		$message = sprintf(
			/* translators: %1$d: reviews published, %2$d: orders synced */
			__('Sync complete! Reviews published: %1$d, New orders sent: %2$d', 'trustscript'),
			$reviews_published,
			$orders_synced
		);

		if ($orders_skipped > 0) {
			$message .= sprintf(
				/* translators: %d: orders that were already published and skipped */
				__(', %d order(s) already published (skipped re-sync)', 'trustscript'),
				$orders_skipped
			);
		}

		wp_send_json_success(array(
			'message' => $message,
			'processed' => $total_processed,
			'reviews_published' => $reviews_published,
			'orders_synced' => $orders_synced,
			'orders_skipped' => $orders_skipped,
			'orders_total' => $orders_total,
		));
	}

	/**
	 * Fetch approved reviews from TrustScript API and publish them as WordPress comments
	 */
	private function fetch_and_publish_approved_reviews()
	{
		$result = trustscript_api_request('GET', 'api/wordpress-orders/sync');

		if (is_wp_error($result)) {
			return 0;
		}

		$data = $result['data'];

		if (!isset($data['orders']) || !is_array($data['orders'])) {
			return 0;
		}

		$approved_reviews = array_filter($data['orders'], function ($order) {
			return isset($order['status']) && $order['status'] === 'approved';
		});

		if (empty($approved_reviews)) {
			return 0;
		}

		$published_count = 0;

		foreach ($approved_reviews as $review) {
			if ($this->publish_single_review($review)) {
				$published_count++;
			}
		}

		return $published_count;
	}

	private function publish_single_review($review)
	{
		if (empty($review['uniqueToken']) || !is_string($review['uniqueToken'])) {
			return false;
		}

		if (isset($review['projectStatus']['status']) && $review['projectStatus']['status'] !== 'active') {
			return false;
		}

		$review_text = sanitize_textarea_field($review['finalText'] ?? $review['reviewText'] ?? '');
		if (empty($review_text)) {
			return false;
		}

		$unique_token = sanitize_text_field($review['uniqueToken']);
		$rating = isset($review['rating']) ? intval($review['rating']) : 5;

		$comment_date = current_time('mysql');
		$comment_date_gmt = current_time('mysql', true);

		if (isset($review['approvedAt']) && !empty($review['approvedAt'])) {
			$approved_timestamp = strtotime($review['approvedAt']);
			if ($approved_timestamp) {
				$comment_date = wp_date('Y-m-d H:i:s', $approved_timestamp);
				$comment_date_gmt = wp_date('Y-m-d H:i:s', $approved_timestamp, new DateTimeZone('UTC'));
			}
		}

		$order = self::find_order_by_review_token($unique_token);

		if (!$order) {
			return false;
		}

		$order_id = $order->get_id();

		$service_id = $order->get_meta('_trustscript_service_type');
		if (empty($service_id)) {
			$service_id = 'woocommerce';
		}

		$is_published = TrustScript_Order_Registry::is_published($service_id, $order_id);
		if ($is_published) {
			return false;
		}

		$items = $order->get_items();

		if (empty($items)) {
			return false;
		}

		$stored_hash = $order->get_meta('_trustscript_verification_hash');
		$incoming_hash = !empty($review['verificationHash']) ? sanitize_text_field($review['verificationHash']) : '';

		if (!empty($stored_hash) && !hash_equals($stored_hash, $incoming_hash)) {
			return false;
		}

		$order->update_meta_data('_trustscript_review_token', $unique_token);
		$order->update_meta_data('_trustscript_review_published', 'yes');
		$order->update_meta_data('_trustscript_review_published_at', current_time('mysql'));
		$order->update_meta_data('_trustscript_publishing_mode', 'manual_sync');
		$order->save();

		$customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		$customer_email = $order->get_billing_email();

		$published_count = 0;

		foreach ($items as $item) {
			$product_id = $item->get_product_id();

			if (!$product_id) {
				continue;
			}

			$existing_reviews = get_comments(array(
				'post_id' => $product_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- we need to query by meta key
				'meta_key' => '_trustscript_review_token',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- we need to query by meta value
				'meta_value' => $unique_token,
				'count' => true,
			));

			if ($existing_reviews > 0) {
				continue;
			}

			$review_data = array(
				'comment_post_ID' => $product_id,
				'comment_content' => $review_text,
				'comment_author' => $customer_name,
				'comment_author_email' => $customer_email,
				'comment_approved' => 1,
				'comment_type' => 'review',
				'user_id' => 0,
				'comment_date' => $comment_date,
				'comment_date_gmt' => $comment_date_gmt,
			);

			$comment_id = wp_insert_comment($review_data, true);

			if (is_wp_error($comment_id)) {
				continue;
			}

			update_comment_meta($comment_id, 'rating', $rating);
			update_comment_meta($comment_id, '_trustscript_review_token', $unique_token);
			update_comment_meta($comment_id, 'verified', 1);

			if (isset($review['verificationHash']) && !empty($review['verificationHash'])) {
				update_comment_meta($comment_id, '_trustscript_verification_hash', sanitize_text_field($review['verificationHash']));
			}

			$published_count++;
		}

		if ($published_count > 0) {
			TrustScript_Order_Registry::mark_published($service_id, $order_id, null, null, 'manual_sync');
			$this->notify_trustscript_published($unique_token);
			return true;
		}

		return false;
	}

	private function notify_trustscript_published($unique_token)
	{
		$result = trustscript_api_request('POST', 'api/wordpress-orders/admin-notification', array(
			'uniqueToken' => $unique_token,
			'publishingStatus' => 'published',
			'publishedAt' => current_time('mysql', true),
			'publishingMode' => 'manual_sync',
		));

		if (is_wp_error($result)) {
			return false;
		}

		return true;
	}


	/**
	 * Handle AJAX request to save service settings
	 */
	public function handle_save_service_settings()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Permission denied', 'trustscript')));
		}

		$service_manager = TrustScript_Service_Manager::get_instance();
		$active_services = $service_manager->get_active_providers();

		if (empty($active_services)) {
			wp_send_json_error(array('message' => esc_html__('No services detected', 'trustscript')));
		}

		foreach ($active_services as $service_id => $provider) {
			$enabled_key = 'trustscript_enable_service_' . $service_id;
			$is_enabled = isset($_POST[$enabled_key]) && '1' === sanitize_text_field(wp_unslash($_POST[$enabled_key]));
			update_option($enabled_key, $is_enabled ? '1' : '0');

			$trigger_key = 'trustscript_trigger_status_' . $service_id;
			if (isset($_POST[$trigger_key])) {
				$trigger_value = sanitize_text_field(wp_unslash($_POST[$trigger_key]));
				$allowed_statuses = array_keys($provider->get_available_statuses());
				if (in_array($trigger_value, $allowed_statuses, true)) {
					update_option($trigger_key, $trigger_value);
				}
			}
		}

		wp_send_json_success(array(
			'message' => esc_html__('Service settings saved successfully!', 'trustscript'),
			'services_updated' => count($active_services),
		));
	}

	/**
	 * Render the service detection and configuration UI in the admin dashboard
	 */
	public function render_service_detection_ui()
	{
		$service_manager = TrustScript_Service_Manager::get_instance();
		$active_services = $service_manager->get_active_providers();

		if (empty($active_services)) {
			?>
			<div class="notice notice-warning" style="border-left-color: #f59e0b; background: #fffbeb; padding: 16px;">
				<p style="margin: 0 0 8px 0; font-weight: 600; color: #92400e;">
					<strong>🔍<?php esc_html_e('No Supported Services Detected', 'trustscript'); ?></strong>
				</p>
				<p style="margin: 0 0 8px 0; font-size: 14px; color: #b45309;">
					<?php esc_html_e('TrustScript works with WooCommerce, MemberPress, and more.', 'trustscript'); ?>
				</p>
				<p style="margin: 0; font-size: 13px; color: #d97706;">
					<?php esc_html_e('Install and activate a supported plugin to start collecting reviews.', 'trustscript'); ?>
					<a href="<?php echo esc_url(trustscript_get_app_url() . '/docs/wordpress/supported-platforms'); ?>"
						target="_blank" style="color: #ea580c; text-decoration: underline;">
						<?php esc_html_e('View Supported Platforms', 'trustscript'); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="trustscript-card" style="margin-top: 24px;">
			<h2>
				<strong>🔌<?php esc_html_e('Detected Services', 'trustscript'); ?></strong>
			</h2>
			<p class="description" style="margin-bottom: 20px;">
				<?php esc_html_e('TrustScript has detected the following services on your site. Configure trigger statuses for each service to automatically collect reviews.', 'trustscript'); ?>
			</p>

			<div class="trustscript-services-grid">
				<?php foreach ($active_services as $service_id => $provider):
					$service_name = $provider->get_service_name();
					$service_icon = $this->get_service_icon($service_id);
					$all_statuses = $provider->get_available_statuses();
					$current_trigger = get_option('trustscript_trigger_status_' . $service_id, '');
					$is_enabled = get_option('trustscript_enable_service_' . $service_id, '0') === '1';

					if (empty($current_trigger) && !empty($all_statuses)) {
						$current_trigger = array_key_first($all_statuses);
					}
					?>
					<div class="trustscript-service-card <?php echo $is_enabled ? 'active' : 'inactive'; ?>"
						data-service-id="<?php echo esc_attr($service_id); ?>">
						<div class="trustscript-service-header">
							<div class="trustscript-service-title">
								<span class="trustscript-service-icon"><?php echo esc_html($service_icon); ?></span>
								<h3><?php echo esc_html($service_name); ?></h3>
							</div>
							<label class="trustscript-toggle">
								<input type="checkbox" name="trustscript_enable_service_<?php echo esc_attr($service_id); ?>"
									value="1" <?php checked($is_enabled, true); ?> class="trustscript-service-toggle"
									data-service-id="<?php echo esc_attr($service_id); ?>" />
								<span class="trustscript-toggle-slider"></span>
							</label>
						</div>

						<div
							class="trustscript-service-body<?php echo !$is_enabled ? ' trustscript-service-body-disabled' : ''; ?>">
							<div class="trustscript-service-setting">
								<label for="trustscript_trigger_status_<?php echo esc_attr($service_id); ?>">
									<strong><?php esc_html_e('Trigger Status:', 'trustscript'); ?></strong>
									<span
										class="description"><?php esc_html_e('Send review request when order reaches this status', 'trustscript'); ?></span>
								</label>
								<select name="trustscript_trigger_status_<?php echo esc_attr($service_id); ?>"
									id="trustscript_trigger_status_<?php echo esc_attr($service_id); ?>"
									class="trustscript-service-trigger" data-service-id="<?php echo esc_attr($service_id); ?>">
									<?php foreach ($all_statuses as $status_key => $status_label): ?>
										<option value="<?php echo esc_attr($status_key); ?>" <?php selected($current_trigger, $status_key); ?>>
											<?php echo esc_html($status_label); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="trustscript-service-stats">
								<small class="description">
									<?php
									printf(
										/* translators: %s is the number of available statuses */
										esc_html__('%d status options available', 'trustscript'),
										count($all_statuses)
									);
									?>
								</small>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div style="margin-top: 20px;">
				<button type="button" id="trustscript-save-service-settings" class="button button-primary button-large">
					<?php esc_html_e('Save Service Settings', 'trustscript'); ?>
				</button>
				<span class="spinner" style="float: none; margin: 4px 10px 0;"></span>
				<span class="trustscript-save-message" style="margin-left: 10px;"></span>
			</div>
		</div>

		<div class="trustscript-card" style="margin-top: 24px;">
			<h2>
				<strong>🔒<?php esc_html_e('Optional Data Collection', 'trustscript'); ?></strong>
			</h2>
			<p class="description" style="margin-bottom: 20px;">
				<?php esc_html_e('Enhance review requests with additional context. You can disable these options for privacy-sensitive products (e.g., medical, adult content).', 'trustscript'); ?>
			</p>

			<div
				style="background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(249, 250, 251, 0.98) 100%); border: 2px solid var(--trustscript-gray-200); border-radius: var(--trustscript-radius); padding: 20px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);">
				<div class="trustscript-form-group" style="margin-bottom: 24px;">
					<label class="trustscript-checkbox-label"
						style="display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; transition: all 0.2s ease; cursor: pointer;">
						<input type="checkbox" id="trustscript-include-product-names" name="trustscript_include_product_names"
							value="1" <?php checked(get_option('trustscript_include_product_names', '1'), '1'); ?>
							class="trustscript-optional-data-toggle"
							style="width: 18px; height: 18px; cursor: pointer; margin: 0;">
						<div>
							<strong style="color: var(--trustscript-gray-900);">📦
								<?php esc_html_e('Include Product Names', 'trustscript'); ?></strong>
							<p class="description"
								style="margin: 4px 0 0 0; color: var(--trustscript-gray-600); line-height: 1.6;">
								<?php esc_html_e('Show specific product names in review requests (e.g., "Blue Wireless Headphones"). If disabled, customers will see generic text like "your recent purchase" instead. Disable this for privacy-sensitive products.', 'trustscript'); ?>
							</p>

							<div
								style="margin-top: 8px; padding: 10px 12px; background: #f0f9ff; border-left: 3px solid #0284c7; border-radius: 4px;">
								<p style="margin: 0; font-size: 13px; color: #0c4a6e; line-height: 1.5;">
									<strong>💡 <?php esc_html_e('Why this matters:', 'trustscript'); ?></strong><br>
									<?php esc_html_e('Specific product names help customers write better, more relevant reviews. However, for sensitive items (health, personal care, gifts), you may want to keep purchases private.', 'trustscript'); ?>
								</p>
							</div>
						</div>
					</label>
				</div>

				<div class="trustscript-form-group" style="margin-bottom: 0;">
					<label class="trustscript-checkbox-label"
						style="display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; transition: all 0.2s ease; cursor: pointer;">
						<input type="checkbox" id="trustscript-include-order-dates" name="trustscript_include_order_dates"
							value="1" <?php checked(get_option('trustscript_include_order_dates', '1'), '1'); ?>
							class="trustscript-optional-data-toggle"
							style="width: 18px; height: 18px; cursor: pointer; margin: 0;">
						<div>
							<strong
								style="color: var(--trustscript-gray-900);">📅<?php esc_html_e('Include Order Dates', 'trustscript'); ?></strong>
							<p class="description" style="margin: 4px 0 0 0; color: var(--trustscript-gray-600);">
								<?php esc_html_e('Share the purchase date with customers. This helps with timing review requests and adds context to their feedback.', 'trustscript'); ?>
							</p>
						</div>
					</label>
				</div>
			</div>

			<div style="margin-top: 20px;">
				<button type="button" id="trustscript-save-optional-data-settings" class="button button-primary button-large">
					<?php esc_html_e('Save Privacy Settings', 'trustscript'); ?>
				</button>
				<span class="spinner" style="float: none; margin: 4px 10px 0;"></span>
				<span class="trustscript-optional-data-save-message" style="margin-left: 10px;"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Get icon for services
	 */
	private function get_service_icon($service_id)
	{
		$icons = array(
			'woocommerce' => '🛒',
			'memberpress' => '👥',
		);

		return isset($icons[$service_id]) ? $icons[$service_id] : '⚙️';
	}

	public function render_service_specific_settings($service_id, $provider, $categories = array())
	{
		switch ($service_id) {
			case 'woocommerce':
				$this->render_woocommerce_settings($categories);
				break;
			case 'memberpress':
				$this->render_memberpress_settings();
				break;
			default:
				echo '<p class="description">' . esc_html__('No additional filters available for this service. All orders/bookings will be processed.', 'trustscript') . '</p>';
				break;
		}
	}

	/**
	 * Build a recursive category tree supporting unlimited nesting levels
	 */
	private function build_recursive_category_tree($categories_by_id, $parent_id = 0)
	{
		$tree = array();

		foreach ($categories_by_id as $term_id => $term) {
			if ((int) $term->parent === (int) $parent_id) {
				$children = $this->build_recursive_category_tree($categories_by_id, $term_id);
				
				$total_count = $term->count;
				if (!empty($children)) {
					foreach ($children as $child) {
						$total_count += $child['term']->count;
					}
				}
				
				$tree[$term_id] = array(
					'term' => $term,
					'children' => $children,
					'total_count' => $total_count,
				);
			}
		}

		return $tree;
	}

	/**
	 * Filter category tree to only show categories with products or children with products
	 */
	private function filter_category_tree($tree)
	{
		$filtered = array();

		foreach ($tree as $term_id => $node) {
			$filtered_children = $this->filter_category_tree($node['children']);
			if ($node['term']->count > 0 || !empty($filtered_children)) {
				$node['children'] = $filtered_children;
				$filtered[$term_id] = $node;
			}
		}

		return $filtered;
	}

	/**
	 * Check if a category is an ancestor of another category
	 * 
	 * @param int $potential_ancestor Category ID to check
	 * @param int $category_id Category ID to check against
	 * @param array $categories_hierarchy Map of category_id => parent_id
	 * @return bool True if potential_ancestor is an ancestor of category_id
	 */
	private function is_category_ancestor($potential_ancestor, $category_id, $categories_hierarchy)
	{
		if ($potential_ancestor === $category_id) {
			return false;
		}

		$current_id = $category_id;
		while ($current_id > 0 && isset($categories_hierarchy[$current_id])) {
			$parent_id = $categories_hierarchy[$current_id];
			if ($parent_id === 0) {
				return false;
			}
			if ($parent_id === $potential_ancestor) {
				return true;
			}
			$current_id = $parent_id;
		}
		return false;
	}

	/**
	 * Recursively render category tree with proper nesting and expand/collapse support
	 */
	private function render_category_tree_recursive($tree, $selected_categories, $depth = 0)
	{
		foreach ($tree as $term_id => $node) {
			$term = $node['term'];
			$children = $node['children'];
			$is_checked = in_array($term->term_id, (array) $selected_categories);
			$has_children = !empty($children);
			$indent_style = $depth > 0 ? 'margin-left: ' . ($depth * 24) . 'px;' : '';
			?>

			<!-- Category Level -->
			<label class="trustscript-checkbox-label trustscript-parent-category" 
				data-parent-id="<?php echo esc_attr($term->term_id); ?>" style="<?php echo esc_attr($indent_style); ?>">
				<input type="checkbox" name="trustscript_review_categories[]"
					value="<?php echo esc_attr($term->term_id); ?>" class="trustscript-category-checkbox trustscript-parent-checkbox"
					data-category-name="<?php echo esc_attr(strtolower($term->name)); ?>"
					data-has-children="<?php echo $has_children ? '1' : '0'; ?>"
					data-depth="<?php echo esc_attr($depth); ?>" <?php checked($is_checked); ?>>
				<span>
					<?php echo esc_html($term->name); ?>
					<span class="trustscript-product-categories-label-count">
						(<?php echo esc_html($node['total_count']); ?>)
					</span>
				</span>
				<?php if ($has_children): ?>
					<span class="trustscript-expand-toggle">▶</span>
				<?php endif; ?>
			</label>

			<!-- Nested Children -->
			<?php if ($has_children): ?>
				<div class="trustscript-subcategories" data-parent-id="<?php echo esc_attr($term->term_id); ?>" data-depth="<?php echo esc_attr($depth); ?>">
					<?php $this->render_category_tree_recursive($children, $selected_categories, $depth + 1); ?>
				</div>
			<?php endif; ?>
			<?php
		}
	}

	private function render_woocommerce_settings($categories = array())
	{
		$wc_categories = get_terms(array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		));

		$category_tree = array();
		$categories_by_id = array();

		if (!is_wp_error($wc_categories) && !empty($wc_categories)) {
			foreach ($wc_categories as $term) {
				$categories_by_id[$term->term_id] = $term;
			}

			$category_tree = $this->build_recursive_category_tree($categories_by_id, 0);
			$category_tree = $this->filter_category_tree($category_tree);
		}

		$min_order_value = get_option('trustscript_woocommerce_min_value', '0');
		$exclude_free = get_option('trustscript_woocommerce_exclude_free', '0');

		?>
		<div class="trustscript-service-settings-section">
			<h3>📦<?php esc_html_e('Product Category Filtering', 'trustscript'); ?></h3>
			<p class="description">
				<?php esc_html_e('Select specific product categories to collect reviews for. Leave all unchecked to include all products.', 'trustscript'); ?>
				<br><em><?php esc_html_e('Note: When you select a parent category, all products in that category tree are included. Child categories should not be selected alongside their parent.', 'trustscript'); ?></em>
			</p>

			<?php if (!empty($category_tree)): ?>
				<div class="trustscript-category-search-toolbar">
					<input type="text" id="trustscript-category-search" class="trustscript-category-search-input"
						placeholder="<?php esc_attr_e('Search categories...', 'trustscript'); ?>">
					<button type="button" id="trustscript-select-all-categories"
						class="button button-secondary trustscript-category-button">
						✓ <?php esc_html_e('Select All', 'trustscript'); ?>
					</button>
					<button type="button" id="trustscript-deselect-all-categories"
						class="button button-secondary trustscript-category-button">
						✕ <?php esc_html_e('Deselect All', 'trustscript'); ?>
					</button>
					<span id="trustscript-category-count" class="trustscript-category-count">
						<?php
						/* translators: %d: number of categories */
						printf(esc_html__('%d categories', 'trustscript'), count($category_tree));
						?>
					</span>
				</div>

				<div class="trustscript-product-categories-list">
					<?php $this->render_category_tree_recursive($category_tree, $categories, 0); ?>
				</div>
			<?php else: ?>
				<p class="description"><?php esc_html_e('No product categories found.', 'trustscript'); ?></p>
			<?php endif; ?>
			<hr style="margin: 24px 0;">

			<h3>💰<?php esc_html_e('Order Value Filtering', 'trustscript'); ?></h3>
			<div class="trustscript-form-group">
				<label for="trustscript_woocommerce_min_value">
					<?php
					$currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
					/* translators: %s: store currency symbol */
					printf(esc_html__('Minimum Order Value (%s):', 'trustscript'), esc_html($currency_symbol));
					?>
				</label>
				<input type="number" id="trustscript_woocommerce_min_value" name="trustscript_woocommerce_min_value"
					value="<?php echo esc_attr($min_order_value); ?>" min="0" step="0.01" class="trustscript-form-input"
					style="max-width: 200px;">
				<p class="description">
					<?php esc_html_e('Only send review requests for orders above this amount. Set to 0 to disable.', 'trustscript'); ?>
				</p>
			</div>

			<div class="trustscript-form-group">
				<label class="trustscript-checkbox-label">
					<input type="checkbox" id="trustscript_woocommerce_exclude_free" name="trustscript_woocommerce_exclude_free"
						value="1" <?php checked($exclude_free, '1'); ?>>
					<?php
					$currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
					/* translators: %s: store currency symbol */
					printf(esc_html__('Exclude free products and %s0 orders', 'trustscript'), esc_html($currency_symbol));
					?>
				</label>
			</div>
		</div>
		<?php
	}

	private function render_memberpress_settings()
	{
		if (!class_exists('MeprProduct')) {
			echo '<p class="description">' . esc_html__('MemberPress is not properly loaded.', 'trustscript') . '</p>';
			return;
		}

		$memberships = \MeprProduct::get_all();
		$selected_memberships = (array) get_option('trustscript_memberpress_memberships', array());
		$delay_days = get_option('trustscript_memberpress_delay_days', '0');

		?>
		<div class="trustscript-service-settings-section">
			<h3>👥<?php esc_html_e('Membership Tier Filtering', 'trustscript'); ?></h3>
			<p class="description">
				<?php esc_html_e('Select which membership tiers should trigger review requests. Leave all unchecked to include all memberships.', 'trustscript'); ?>
			</p>

			<?php if (!empty($memberships)): ?>
				<div class="trustscript-product-categories-list">
					<?php foreach ($memberships as $membership): ?>
						<label class="trustscript-checkbox-label">
							<input type="checkbox" name="trustscript_memberpress_memberships[]"
								value="<?php echo esc_attr($membership->ID); ?>" <?php checked(in_array($membership->ID, $selected_memberships)); ?>>
							<?php echo esc_html($membership->post_title); ?>
						</label>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<p class="description"><?php esc_html_e('No memberships found.', 'trustscript'); ?></p>
			<?php endif; ?>

			<hr style="margin: 24px 0;">

			<h3>⏰<?php esc_html_e('Review Request Timing', 'trustscript'); ?></h3>
			<div class="trustscript-form-group">
				<label for="trustscript_memberpress_delay_days">
					<?php esc_html_e('Send review request after:', 'trustscript'); ?>
				</label>
				<select id="trustscript_memberpress_delay_days" name="trustscript_memberpress_delay_days"
					class="trustscript-form-input" style="max-width: 200px;">
					<option value="0" <?php selected($delay_days, '0'); ?>>
						<?php esc_html_e('Immediately', 'trustscript'); ?>
					</option>
					<option value="7" <?php selected($delay_days, '7'); ?>><?php esc_html_e('7 days', 'trustscript'); ?>
					</option>
					<option value="14" <?php selected($delay_days, '14'); ?>><?php esc_html_e('14 days', 'trustscript'); ?>
					</option>
					<option value="30" <?php selected($delay_days, '30'); ?>><?php esc_html_e('30 days', 'trustscript'); ?>
					</option>
				</select>
				<p class="description">
					<?php esc_html_e('Allow members time to experience your content before requesting a review.', 'trustscript'); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to save optional data collection settings (product names, order dates, etc.)
	 */
	public function handle_save_optional_data_settings()
	{
		check_ajax_referer('trustscript_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => esc_html__('Permission denied', 'trustscript')));
		}

		$include_product_names = isset($_POST['trustscript_include_product_names'])
			&& '1' === sanitize_text_field(wp_unslash($_POST['trustscript_include_product_names']));

		$include_order_dates = isset($_POST['trustscript_include_order_dates'])
			&& '1' === sanitize_text_field(wp_unslash($_POST['trustscript_include_order_dates']));

		update_option('trustscript_include_product_names', $include_product_names ? '1' : '0');
		update_option('trustscript_include_order_dates', $include_order_dates ? '1' : '0');

		wp_send_json_success(array(
			'message' => esc_html__('Privacy settings saved successfully!', 'trustscript'),
			'include_product_names' => $include_product_names,
			'include_order_dates' => $include_order_dates,
		));
	}
}