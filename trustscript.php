<?php
/**
 * Plugin Name: TrustScript
 * Description: Automated review collection for WooCommerce — verified, visual, AI-assisted, and 100% privacy compliant. No PII. No manual work.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Tested up to: 6.9
 * Requires PHP: 8.0
 * Plugin URI: https://nexlifylabs.com
 * Author: NexlifyLabs
 * Author URI: https://nexlifylabs.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trustscript
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

define('TRUSTSCRIPT_PLUGIN_FILE', __FILE__);
define('TRUSTSCRIPT_VERSION', '1.0.0');
define('TRUSTSCRIPT_PLUGIN_URL', plugin_dir_url(__FILE__));
/**
 * Default endpoint for TrustScript API key verification.
 */
define('TRUSTSCRIPT_VERIFY_ENDPOINT', 'https://nexlifylabs.com/api/verify-api-key');

require_once plugin_dir_path(__FILE__) . 'includes/trustscript-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/pricing-config.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-placeholder-mapper.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-service-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-sync-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-analytics-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/branding.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/review-setting.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/email-template.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/review-request.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/pending-queue.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-webhook.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-settings-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-media-upload.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-auto-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-immutability.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-order-status.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-compatibility.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-review-voting.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-order-registry.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-queue.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-review-query.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-review-renderer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-woocommerce-reviews.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-shop-display.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-date-formatter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-frontend-reviews-base.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-memberpress-reviews.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-trustscript-memberpress-reviews-metabox.php';

if (file_exists(plugin_dir_path(__FILE__) . 'includes/integration/elementor/class-trustscript-elementor.php')) {
	require_once plugin_dir_path(__FILE__) . 'includes/integration/elementor/class-trustscript-elementor.php';
}

function trustscript_plugin_init()
{
	$service_manager = TrustScript_Service_Manager::get_instance();
	$woocommerce_active = class_exists('WooCommerce');

	if (!$service_manager->has_active_services()) {
		add_action('admin_notices', 'trustscript_no_services_notice');
	}

	if ($woocommerce_active) {
		new TrustScript_Compatibility();
		new TrustScript_Order_Status();
		new TrustScript_WooCommerce_Reviews();
	}

	if (is_admin()) {
		TrustScript_Plugin_Admin::get_instance();
		new TrustScript_Review_Request_Page();
		new TrustScript_Pending_Queue_Page();
	}

	new TrustScript_Webhook();
	new TrustScript_Media_Upload();
	new TrustScript_Settings_Sync();
	new TrustScript_Immutability();
	new TrustScript_Review_Voting();
	new TrustScript_Auto_Sync();

	TrustScript_Review_Renderer::boot();

	TrustScript_Queue::init_cron_hook();

	if (!is_admin() && class_exists('WooCommerce')) {
		new TrustScript_Shop_Display();
	}

}
add_action('plugins_loaded', 'trustscript_plugin_init');

function trustscript_process_scheduled_queue()
{
	$lock_key = 'trustscript_queue_fallback_lock';
	if (get_transient($lock_key)) {
		return;
	}

	if (!TrustScript_Queue::table_exists()) {
		return;
	}

	$ready_count = TrustScript_Queue::count_ready();

	if ($ready_count === 0) {
		return;
	}

	set_transient($lock_key, 1, 60);

	TrustScript_Queue::process_batch(10, false);
}
add_action('wp_loaded', 'trustscript_process_scheduled_queue', 99);

function trustscript_plugin_activate()
{
	TrustScript_Order_Registry::create_table();
	TrustScript_Queue::create_table();

	TrustScript_Queue::register_cron_job();
	TrustScript_Auto_Sync::schedule_cron();

	if (!get_option('trustscript_api_key')) {
		set_transient('trustscript_activation_redirect', true, 30);
	}
}

register_activation_hook(TRUSTSCRIPT_PLUGIN_FILE, 'trustscript_plugin_activate');

function trustscript_plugin_deactivate()
{
	// Clear the 6-hourly queue processing cron job on plugin deactivation
	TrustScript_Queue::unregister_cron_job();
	TrustScript_Auto_Sync::unschedule_cron();
}

register_deactivation_hook(TRUSTSCRIPT_PLUGIN_FILE, 'trustscript_plugin_deactivate');

function trustscript_activation_redirect()
{
	if (!get_transient('trustscript_activation_redirect')) {
		return;
	}

	delete_transient('trustscript_activation_redirect');

	if (isset($_GET['activate-multi'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe redirect on plugin activation.
		return;
	}

	wp_safe_redirect(admin_url('admin.php?page=trustscript-settings&first-time=1'));
	exit;
}

add_action('admin_init', 'trustscript_activation_redirect');

function trustscript_no_services_notice()
{
	?>
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e('TrustScript', 'trustscript'); ?>:</strong>
			<?php esc_html_e('No supported services detected. TrustScript works with WooCommerce, MemberPress, and more.', 'trustscript'); ?>
		</p>
		<p>
			<?php esc_html_e('Install and activate a supported plugin to start collecting reviews.', 'trustscript'); ?>
			<a href="https://nexlifylabs.com/docs/supported-platforms"
				target="_blank"><?php esc_html_e('View Supported Platforms', 'trustscript'); ?></a>
		</p>
	</div>
	<?php
}

function trustscript_plugin_action_links($links)
{
	$custom_links = array(
		'<a href="https://nexlifylabs.com/pricing" target="_blank" rel="noopener noreferrer" style="color: #10b981; font-weight: bold;">'
		. esc_html__('Go Pro', 'trustscript') . '</a>',

		'<a href="' . esc_url(admin_url('admin.php?page=trustscript-settings')) . '">'
		. esc_html__('Settings', 'trustscript') . '</a>',

		'<a href="https://nexlifylabs.com/docs" target="_blank" rel="noopener noreferrer">'
		. esc_html__('Docs', 'trustscript') . '</a>',

		'<a href="https://nexlifylabs.com/support" target="_blank" rel="noopener noreferrer">'
		. esc_html__('Support', 'trustscript') . '</a>',
	);

	return array_merge($custom_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(TRUSTSCRIPT_PLUGIN_FILE), 'trustscript_plugin_action_links');