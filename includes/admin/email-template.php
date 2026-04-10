<?php
/**
 * TrustScript Email Template Page Handler
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Email_Template_Page {
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin = TrustScript_Plugin_Admin::get_instance();
		$base_url = $admin->get_trustscript_base_url();
		$dashboard_url = trailingslashit( $base_url ) . 'dashboard/email-settings';
		$api_key = get_option( 'trustscript_api_key', '' );
		$user_plan = '';
		
		if ( ! empty( $api_key ) ) {
			$usage_url = trailingslashit( $base_url ) . 'api/usage';
			$response = wp_remote_get( $usage_url, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept' => 'application/json',
					'X-Site-URL' => get_site_url(),
				),
				'timeout' => 10,
			) );
			
			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					$body = wp_remote_retrieve_body( $response );
					$data = json_decode( $body, true );
					if ( ! empty( $data['plan'] ) ) {
						$user_plan = strtolower( $data['plan'] );
					}
				}
			}
		}
		
		$is_pro_or_business = in_array( $user_plan, array( 'pro', 'business' ), true );
		
		?>
		<div class="wrap">
			
			<h1 class="screen-reader-text"><?php esc_html_e( 'Email Template Management', 'trustscript' ); ?></h1>
			
			<?php if ( ! $is_pro_or_business ) : ?>
				<!-- Upgrade Notice for Free/Starter Users -->
				<div class="notice notice-warning" style="border-left-color: #f59e0b; background: #fef3c7; padding: 20px; margin-top: 20px;">
					<h2 style="margin-top: 0; color: #92400e;">
						<span class="dashicons dashicons-unlock" style="color: #f59e0b; font-size: 24px;"></span>
						<?php esc_html_e( 'Email Template Customization - Pro & Business Plans Only', 'trustscript' ); ?>
					</h2>
					<p style="font-size: 15px; line-height: 1.6; color: #78350f; margin: 12px 0;">
						<?php esc_html_e( 'Email template customization is available for Pro and Business plan users. Upgrade your plan to create and manage custom, compliance-checked email templates in your TrustScript dashboard.', 'trustscript' ); ?>
					</p>
					<p style="margin: 16px 0 0 0;">
						<a href="<?php echo esc_url( trailingslashit( $base_url ) . 'pricing' ); ?>" target="_blank" class="button button-primary" style="font-size: 16px; padding: 10px 24px; height: auto;">
							<?php esc_html_e( 'Upgrade Your Plan →', 'trustscript' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<!-- Dashboard Link for Pro/Business Users -->
				<div class="notice notice-info" style="border-left-color: #3b82f6; background: #eff6ff; padding: 20px; margin-top: 20px;">
					<h2 style="margin-top: 0; color: #1e40af;">
						<span class="dashicons dashicons-admin-settings" style="color: #3b82f6; font-size: 24px;"></span>
						<?php esc_html_e( 'Manage Your Email Templates', 'trustscript' ); ?>
					</h2>
					<p style="font-size: 15px; line-height: 1.6; color: #1e3a8a; margin: 12px 0;">
						<?php esc_html_e( 'Email templates are centrally managed in your TrustScript dashboard for all your platforms (WordPress, Wix, Shopify, API). Create custom, compliance-checked templates with built-in FTC & GDPR protection.', 'trustscript' ); ?>
					</p>
					<p style="margin: 16px 0 0 0;">
						<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" class="button button-primary button-hero" style="padding: 12px 32px; height: auto; line-height: 1.6; font-size: 16px;">
							<span class="dashicons dashicons-external" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Go to Email Template Dashboard →', 'trustscript' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
			
			<!-- Privacy & GDPR/FTC Compliance Notice -->
			<div class="trustscript-card" style="margin-top: 24px; background: #f0f9ff; border-left: 4px solid #3b82f6;">
				<h2 style="color: #1e40af; margin-top: 0;">
					<span class="dashicons dashicons-shield-alt" style="color: #3b82f6;"></span>
					<?php esc_html_e( 'Privacy & GDPR/FTC Compliance', 'trustscript' ); ?>
				</h2>
				<ul style="margin: 12px 0; color: #1e3a8a; line-height: 2;">
					<li><strong><?php esc_html_e( '🔒 No Customer Data Collection:', 'trustscript' ); ?></strong> <?php esc_html_e( 'TrustScript never collects, or stores customer personal information (names, emails, addresses, phone numbers, etc.).', 'trustscript' ); ?></li>
					<li><strong><?php esc_html_e( '🔐 Hashed Emails Only:', 'trustscript' ); ?></strong> <?php esc_html_e( 'We only store secure hashes of email addresses for opt-out purposes - the original email is never stored or accessible.', 'trustscript' ); ?></li>
					<li><strong><?php esc_html_e( '📊 Order IDs Only:', 'trustscript' ); ?></strong> <?php esc_html_e( 'We track order IDs to manage review requests and match approved reviews back to products.', 'trustscript' ); ?></li>
					<li><strong><?php esc_html_e( '✓ FTC & GDPR Compliant:', 'trustscript' ); ?></strong> <?php esc_html_e( 'All emails include mandatory GDPR footer, opt-out links, and honest feedback language - automatically enforced by the system.', 'trustscript' ); ?></li>
					<li><strong><?php esc_html_e( '✓ Automatic Privacy Footer:', 'trustscript' ); ?></strong> <?php esc_html_e( 'Privacy notice, opt-out links, and transactional disclosures are added to every email automatically and cannot be removed.', 'trustscript' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
