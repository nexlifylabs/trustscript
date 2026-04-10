<?php
/**
 * TrustScript Consent Form - This template renders a consent form for users 
 * to agree to data sharing terms before enabling the TrustScript integration.
 * 
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$trustscript_consent_given = get_option('trustscript_data_consent', false);
?>

<div class="trustscript-consent-wrapper">
	<label class="trustscript-consent-checkbox">
		<input type="checkbox" 
			   id="trustscript-consent-checkbox" 
			   name="trustscript_data_consent" 
			   value="1" 
			   <?php checked($trustscript_consent_given, '1'); ?>>
		<span class="trustscript-consent-label">
			<?php esc_html_e('I agree to the', 'trustscript'); ?> 
			<a href="#" class="trustscript-consent-link"><?php esc_html_e('data sharing terms', 'trustscript'); ?></a>
		</span>
	</label>
</div>

<div id="trustscript-consent-modal" class="trustscript-consent-modal-overlay">
	<div class="trustscript-consent-modal">
		<div class="trustscript-consent-modal-header">
			<h3>🔒 <?php esc_html_e('Data Sharing & Privacy Policy', 'trustscript'); ?></h3>
		</div>
		
		<div class="trustscript-consent-modal-body">
			<div class="trustscript-consent-section">
				<h4><?php esc_html_e('What Data We Share:', 'trustscript'); ?></h4>
				<ul class="trustscript-consent-list">
					<li><strong><?php esc_html_e('API Key', 'trustscript'); ?></strong> – <?php esc_html_e('Required for authentication (sent securely via HTTPS)', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Site URL', 'trustscript'); ?></strong> – <?php esc_html_e('Required to tie the integration to your store', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Order IDs', 'trustscript'); ?></strong> – <?php esc_html_e('Required for analytics and review verification (masked / never publicly exposed)', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Hashed Customer Email (SHA-256)', 'trustscript'); ?></strong> – <?php esc_html_e('Required for opt-out tracking and duplicate prevention (raw email never sent or stored)', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Product Names', 'trustscript'); ?></strong> – <?php esc_html_e('Optional (you can disable in review settings if privacy-sensitive, e.g., medical/adult products). However, product name is always required when order contain multiple products.', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Order Dates', 'trustscript'); ?></strong> – <?php esc_html_e('Suggested but optional (helps with timing review requests)', 'trustscript'); ?></li>
					<li><strong><?php esc_html_e('Review Content', 'trustscript'); ?></strong> – <?php esc_html_e('Only after customer approval (sent inbound via secure webhook)', 'trustscript'); ?></li>
				</ul>
			</div>

			<div class="trustscript-consent-highlight">
				<strong><?php esc_html_e('Important – What we NEVER collect:', 'trustscript'); ?></strong>
				<ul class="trustscript-consent-list">
					<li><?php esc_html_e('Raw customer emails', 'trustscript'); ?></li>
					<li><?php esc_html_e('Customer names', 'trustscript'); ?></li>
					<li><?php esc_html_e('Any other personal information', 'trustscript'); ?></li>
				</ul>
			</div>

			<div class="trustscript-consent-section">
				<p>
					<?php esc_html_e('This integration is privacy-first and GDPR/CCPA compliant. TrustScript does not sell data, use it for marketing, or train AI on your reviews without explicit permission. You can disable the integration or revoke access anytime by removing the API key.', 'trustscript'); ?>
				</p>
			</div>
		</div>
		
		<div class="trustscript-consent-modal-footer">
			<button type="button" id="trustscript-consent-decline" class="trustscript-btn trustscript-btn-secondary">
				<?php esc_html_e('Cancel', 'trustscript'); ?>
			</button>
			<button type="button" id="trustscript-consent-agree" class="trustscript-btn trustscript-btn-primary">
				<?php esc_html_e('I Agree', 'trustscript'); ?>
			</button>
		</div>
	</div>
</div>
