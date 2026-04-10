<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Settings_Page {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'trustscript' ) );
		}
		
		$pricing_tiers = trustscript_get_pricing_tiers();
		$api_keys_url = trustscript_get_api_keys_url();
		$app_url = trustscript_get_app_url();
		
		$is_first_time = isset( $_GET['first-time'] ) && $_GET['first-time'] == '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display logic only, no data processing.
		$has_api_key = ! empty( get_option( 'trustscript_api_key', '' ) );
		
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'TrustScript Settings', 'trustscript' ); ?></h1>
			<div class="trustscript-settings-wrap">

			<?php if ( $is_first_time || ! $has_api_key ): ?>
			<div class="trustscript-ob-header">
				<div class="trustscript-ob-brand">
					<div class="trustscript-ob-logo-mark">TS</div>
					<div>
						<h1 class="trustscript-ob-title"><?php esc_html_e( 'TrustScript', 'trustscript' ); ?></h1>
						<p class="trustscript-ob-tagline"><?php esc_html_e( 'AI-powered review collection for WooCommerce &amp; MemberPress', 'trustscript' ); ?></p>
					</div>
				</div>
				<span class="trustscript-connection-badge trustscript-badge-disconnected">
					<span class="trustscript-badge-dot"></span>
					<?php esc_html_e( 'Not Connected', 'trustscript' ); ?>
				</span>
			</div>

			<div class="trustscript-ob-card trustscript-ob-wizard">
				<h2 class="trustscript-ob-section-title"><?php esc_html_e( 'Get Up &amp; Running in 3 Steps', 'trustscript' ); ?></h2>
				<div class="trustscript-steps">

					<div class="trustscript-step">
						<div class="trustscript-step-left">
							<div class="trustscript-step-circle">1</div>
							<div class="trustscript-step-line"></div>
						</div>
						<div class="trustscript-step-body">
							<strong><?php esc_html_e( 'Create Your Free Account', 'trustscript' ); ?></strong>
							<p><?php esc_html_e( 'Sign up on TrustScript — no credit card required.', 'trustscript' ); ?></p>
							<a href="<?php echo esc_url( $api_keys_url ); ?>" target="_blank" class="button button-primary trustscript-step-btn">
								<?php esc_html_e( 'Create Free Account →', 'trustscript' ); ?>
							</a>
						</div>
					</div>

					<div class="trustscript-step">
						<div class="trustscript-step-left">
							<div class="trustscript-step-circle">2</div>
							<div class="trustscript-step-line"></div>
						</div>
						<div class="trustscript-step-body">
							<strong><?php esc_html_e( 'Generate an API Key', 'trustscript' ); ?></strong>
							<p>
								<?php
								printf(
									/* translators: %s is the site URL wrapped in <code> */
									esc_html__( 'In your dashboard → API Keys, generate a new key for: %s', 'trustscript' ),
									'<code>' . esc_html( get_site_url() ) . '</code>'
								);
								?>
							</p>
							<a href="<?php echo esc_url( $api_keys_url ); ?>" target="_blank" class="button trustscript-step-btn">
								<?php esc_html_e( 'Open API Keys Dashboard →', 'trustscript' ); ?>
							</a>
						</div>
					</div>

					<div class="trustscript-step trustscript-step-last">
						<div class="trustscript-step-left">
							<div class="trustscript-step-circle trustscript-step-circle-highlight">3</div>
						</div>
						<div class="trustscript-step-body">
							<strong><?php esc_html_e( 'Paste Your Key Below &amp; Connect', 'trustscript' ); ?></strong>
							<p><?php esc_html_e( 'Copy the key (it looks like TSK-XXXX-XXXX-XXXX) and paste it in the form just below, then click Save &amp; Connect.', 'trustscript' ); ?></p>
							<a href="#trustscript-api-form" class="button trustscript-step-btn trustscript-scroll-to">
								<?php esc_html_e( '↓ Jump to API Key Field', 'trustscript' ); ?>
							</a>
						</div>
					</div>

				</div>
			</div>

			<div class="trustscript-ob-card">
				<h2 class="trustscript-ob-section-title">💎 <?php esc_html_e( 'Choose Your Plan', 'trustscript' ); ?></h2>
				<p class="trustscript-ob-section-sub"><?php esc_html_e( 'Start free. Upgrade anytime. No lock-in.', 'trustscript' ); ?></p>
				<div class="trustscript-pricing-grid">
					<?php foreach ( $pricing_tiers as $tier_key => $tier ): ?>
					<div class="trustscript-pricing-card <?php echo $tier_key === 'pro' ? 'trustscript-pricing-featured' : ''; ?>">
						<?php if ( ! empty( $tier['badge'] ) ): ?>
							<div class="trustscript-pricing-badge"><?php echo esc_html( $tier['badge'] ); ?></div>
						<?php elseif ( $tier_key === 'pro' ): ?>
							<div class="trustscript-pricing-badge trustscript-badge-popular"><?php esc_html_e( 'Most Popular', 'trustscript' ); ?></div>
						<?php else: ?>
							<div class="trustscript-pricing-badge trustscript-pricing-badge-spacer"></div>
						<?php endif; ?>
						<div class="trustscript-pricing-name"><?php echo esc_html( $tier['name'] ); ?></div>
						<div class="trustscript-pricing-price">
							<?php echo esc_html( $tier['price'] ); ?>
							<span class="trustscript-pricing-period"><?php echo esc_html( $tier['period'] ); ?></span>
						</div>
						<div class="trustscript-pricing-volume">
							<strong><?php echo intval( $tier['requests'] ); ?></strong>
							<span><?php esc_html_e( 'reviews / month', 'trustscript' ); ?></span>
						</div>
						<ul class="trustscript-pricing-features">
							<?php foreach ( array_slice( $tier['features'], 0, 5 ) as $feature ): ?>
								<li><?php echo esc_html( $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( ! empty( $tier['button_url'] ) ): ?>
							<a href="<?php echo esc_url( $tier['button_url'] ); ?>" target="_blank"
							   class="button <?php echo $tier_key === 'pro' ? 'button-primary' : ''; ?> trustscript-pricing-cta">
								<?php echo esc_html( $tier['button_text'] ); ?>
							</a>
						<?php else: ?>
							<span class="trustscript-pricing-cta-free"><?php esc_html_e( 'Free Forever', 'trustscript' ); ?></span>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
				<p class="trustscript-pricing-footnote">
					<?php esc_html_e( 'All plans include AI-powered review rewriting and email support.', 'trustscript' ); ?>
				</p>
			</div>

			<div id="trustscript-api-form" class="trustscript-form-anchor-label">
				<span>⚙️ <?php esc_html_e( 'Step 3 — Paste Your API Key to Connect', 'trustscript' ); ?></span>
			</div>

			<?php else: ?>

			<div class="trustscript-connected-banner">
				<div class="trustscript-connected-left">
					<span class="trustscript-connection-badge trustscript-badge-connected">
						<span class="trustscript-badge-dot"></span>
						<?php esc_html_e( 'Connected to TrustScript', 'trustscript' ); ?>
					</span>
					<h1 class="trustscript-connected-heading"><?php esc_html_e( 'TrustScript Settings', 'trustscript' ); ?></h1>
				</div>
				<div class="trustscript-connected-links">
					<a href="<?php echo esc_url( $app_url . '/dashboard' ); ?>" target="_blank" class="button">
						<?php esc_html_e( 'Open Dashboard', 'trustscript' ); ?>
					</a>
					<a href="<?php echo esc_url( 'https://nexlifylabs.com/pricing' ); ?>" target="_blank" class="button button-primary trustscript-upgrade-btn">
						⭐ <?php esc_html_e( 'Upgrade Plan', 'trustscript' ); ?>
					</a>
				</div>
			</div>

			<?php endif; ?>

			<div class="trustscript-ob-card <?php echo ! $has_api_key ? 'trustscript-api-key-card-highlight' : ''; ?>">

				<div class="trustscript-api-card-header">
				<h2 class="trustscript-ob-section-title">
						<?php if ( $has_api_key ): ?>
							<span class="dashicons dashicons-yes-alt trustscript-icon-connected"></span>
							<?php esc_html_e( 'API Configuration', 'trustscript' ); ?>
						<?php else: ?>
							🔑 <?php esc_html_e( 'Connect Your API Key', 'trustscript' ); ?>
						<?php endif; ?>
					</h2>
					<?php if ( ! $has_api_key ): ?>
					<a href="<?php echo esc_url( $api_keys_url ); ?>" target="_blank" class="trustscript-get-key-link">
						<?php esc_html_e( "Don't have a key? Get one free →", 'trustscript' ); ?>
					</a>
					<?php endif; ?>
				</div>

				<form method="post" action="options.php" class="trustscript-api-form">
					<?php
					settings_fields( 'trustscript_options' );
					do_settings_sections( 'trustscript_options' );
					?>

					<?php if ( ! $has_api_key ): ?>
					<div id="trustscript-api-key-inline-error" class="trustscript-api-key-inline-error"></div>

					<?php
					$settings_errors = get_settings_errors( 'trustscript_api_key' );
					foreach ( $settings_errors as $error ) {
						if ( $error['type'] === 'error' ) {
							?>
							<div class="trustscript-api-key-error">
								<span class="trustscript-api-key-error-icon">⚠️</span>
								<div class="trustscript-api-key-error-body">
									<strong><?php esc_html_e( 'API Key Save Failed', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
								<button type="button" class="trustscript-api-key-error-dismiss" aria-label="Dismiss">×</button>
							</div>
							<?php
						} elseif ( $error['type'] === 'success' ) {
							?>
							<div class="trustscript-api-key-success">
								<span class="trustscript-api-key-success-icon">✅</span>
								<div class="trustscript-api-key-success-body">
									<strong><?php esc_html_e( 'Connected Successfully', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
							</div>
							<?php
						} elseif ( $error['type'] === 'warning' ) {
							?>
							<div class="trustscript-api-key-warning">
								<span class="trustscript-api-key-warning-icon">⚠️</span>
								<div class="trustscript-api-key-warning-body">
									<strong><?php esc_html_e( 'Connected — Quota Limit Reached', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
							</div>
							<?php
						}
					}
					?>

					<div class="trustscript-api-key-input-row">
						<input
							type="password"
							id="trustscript_api_key"
							name="trustscript_api_key"
							value="<?php echo isset( $_POST['trustscript_api_key'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['trustscript_api_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by settings_fields() in form ?>"
							class="trustscript-api-key-input"
							placeholder="TSK-XXXX-XXXX-XXXX"
							autocomplete="off"
							spellcheck="false"
							required
						/>
						<button type="submit" class="button button-primary trustscript-connect-btn">
							<?php esc_html_e( 'Save &amp; Connect', 'trustscript' ); ?>
						</button>
					</div>
					<p class="trustscript-api-key-hint">
						<?php
						printf(
							/* translators: %1$s dashboard link open, %2$s close */
							esc_html__( 'Your key is tied to this domain. It looks like %1$s. Generate one from your %2$sTrustScript Dashboard%3$s.', 'trustscript' ),
							'<code>TSK-XXXX-XXXX-XXXX</code>',
							'<a href="' . esc_url( $api_keys_url ) . '" target="_blank">',
							'</a>'
						);
						?>
					</p>
					<div class="trustscript-info-box trustscript-info-box-spaced">
						<strong>🔐 <?php esc_html_e( 'Security Note:', 'trustscript' ); ?></strong>
						<p><?php esc_html_e( 'Your API key is masked as you type for security. It will never be displayed in plain text or source code.', 'trustscript' ); ?></p>
					</div>
					<div class="trustscript-info-box trustscript-info-box-spaced">
						<strong>⚠️ 📝 <?php esc_html_e( 'Important:', 'trustscript' ); ?></strong>
						<p><?php esc_html_e( 'You must provide a valid API key to use TrustScript. The form submission will be blocked until you paste a key.', 'trustscript' ); ?></p>
					</div>

					<?php else: ?>
					<div id="trustscript-api-key-inline-error" class="trustscript-api-key-inline-error"></div>
					<?php
					$settings_errors = get_settings_errors( 'trustscript_api_key' );
					foreach ( $settings_errors as $error ) {
						if ( $error['type'] === 'error' ) {
							?>
							<div class="trustscript-api-key-error">
								<span class="trustscript-api-key-error-icon">⚠️</span>
								<div class="trustscript-api-key-error-body">
									<strong><?php esc_html_e( 'API Key Save Failed', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
								<button type="button" class="trustscript-api-key-error-dismiss" aria-label="Dismiss">×</button>
							</div>
							<?php
						} elseif ( $error['type'] === 'success' ) {
							?>
							<div class="trustscript-api-key-success">
								<span class="trustscript-api-key-success-icon">✅</span>
								<div class="trustscript-api-key-success-body">
									<strong><?php esc_html_e( 'Connected Successfully', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
							</div>
							<?php
						} elseif ( $error['type'] === 'warning' ) {
							?>
							<div class="trustscript-api-key-warning">
								<span class="trustscript-api-key-warning-icon">⚠️</span>
								<div class="trustscript-api-key-warning-body">
									<strong><?php esc_html_e( 'Connected — Quota Limit Reached', 'trustscript' ); ?></strong>
									<p><?php echo wp_kses_post( $error['message'] ); ?></p>
								</div>
							</div>
							<?php
						}
					}
					?>

					<table class="form-table trustscript-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'API Key', 'trustscript' ); ?></th>
							<td>
								<div id="trustscript-api-key-display" data-api-key-container>
									<span class="trustscript-key-masked">
										<span class="dashicons dashicons-lock trustscript-lock-icon"></span>
										<code>TSK — •••• — •••• — ••••</code>
									</span>
									<button type="button" class="button trustscript-btn-replace" data-action="edit-api-key">
										<?php esc_html_e( 'Replace Key', 'trustscript' ); ?>
									</button>
									<button type="button" class="button button-link-delete trustscript-btn-delete" data-action="delete-api-key">
										<?php esc_html_e( 'Delete', 'trustscript' ); ?>
									</button>
								</div>
								<p class="description">
									<?php esc_html_e( 'Click "Replace Key" to paste a new key. Leave the field blank to keep the current key unchanged.', 'trustscript' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'TrustScript Server', 'trustscript' ); ?></th>
							<td>
								<code class="trustscript-code-pill">https://nexlifylabs.com</code>
								<p class="description"><?php esc_html_e( 'Your site is connected to the TrustScript server at nexlifylabs.com', 'trustscript' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook Endpoint', 'trustscript' ); ?></th>
							<td>
								<code class="trustscript-code-pill"><?php echo esc_html( get_site_url() ); ?></code>
								<p class="description"><?php esc_html_e( 'Webhooks from TrustScript will be delivered to this URL.', 'trustscript' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Changes', 'trustscript' ) ); ?>
					<?php endif; ?>

					<?php include plugin_dir_path( __FILE__ ) . '../consent-form-template.php'; ?>
				</form>
			</div>
			<div class="trustscript-ob-card">
				<h2 class="trustscript-ob-section-title">🗑️ <?php esc_html_e( 'Plugin Uninstall & Data', 'trustscript' ); ?></h2>
				<p><?php esc_html_e( 'Choose how TrustScript should handle your data if you decide to uninstall the plugin.', 'trustscript' ); ?></p>
				
				<form id="trustscript-uninstall-form" class="trustscript-uninstall-form">
					<table class="form-table trustscript-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Delete Data on Uninstall', 'trustscript' ); ?></th>
							<td>
								<div class="trustscript-checkbox-wrapper">
									<label for="trustscript_delete_data_on_uninstall">
										<input 
											type="checkbox" 
											id="trustscript_delete_data_on_uninstall" 
											name="delete_data" 
											value="1"
											<?php checked( get_option( 'trustscript_delete_data_on_uninstall', false ), true ); ?>
										/>
										<span><?php esc_html_e( 'Completely remove all TrustScript data when I uninstall the plugin', 'trustscript' ); ?></span>
									</label>
								</div>
								<p class="description">
									<?php esc_html_e( 'If unchecked (recommended), your TrustScript data will be preserved when you uninstall the plugin. This lets you reinstall later without losing your settings and review history.', 'trustscript' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<div class="trustscript-alert trustscript-alert-warning">
						<strong>⚠️ <?php esc_html_e( 'Important:', 'trustscript' ); ?></strong>
						<p class="trustscript-alert-content">
							<?php esc_html_e( 'If you enable this option, the following data will be permanently deleted when you uninstall the plugin:', 'trustscript' ); ?>
						</p>
						<ul class="trustscript-alert-list">
							<li><?php esc_html_e( 'All TrustScript settings and configuration', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'API key and connection information', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'Review collection history and pending queue items', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'Database tables (order registry, queue, and related data)', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'Scheduled automation tasks (auto-sync, quota checks)', 'trustscript' ); ?></li>
						</ul>
					</div>

					<button type="submit" class="button button-secondary" id="trustscript-uninstall-save-btn">
						<?php esc_html_e( 'Save Preference', 'trustscript' ); ?>
					</button>
				</form>
			</div>

			<details class="trustscript-ob-card trustscript-privacy-accordion">
				<summary class="trustscript-privacy-summary">
					<span>🔒 <?php esc_html_e( 'Privacy-First Architecture (GDPR Compliant)', 'trustscript' ); ?></span>
					<span class="trustscript-accordion-chevron">▼</span>
				</summary>
				<div class="trustscript-privacy-body">
					<p class="trustscript-privacy-lead">
						<?php esc_html_e( 'TrustScript follows privacy-by-design principles. We do NOT store customer names or email addresses — only Order IDs for merchant analytics.', 'trustscript' ); ?>
					</p>
					<ul class="trustscript-privacy-list">
						<li><?php esc_html_e( '✓ Customer emails are hashed (SHA-256) for opt-out checking only, then immediately discarded', 'trustscript' ); ?></li>
						<li><?php esc_html_e( '✓ No customer PII (personally identifiable information) is stored in our database', 'trustscript' ); ?></li>
						<li><?php esc_html_e( '✓ Reviews are published as "Verified Buyer" — no customer names displayed', 'trustscript' ); ?></li>
						<li><?php esc_html_e( '✓ Full compliance with GDPR Article 5 (data minimization) and Article 25 (privacy by design)', 'trustscript' ); ?></li>
					</ul>
				</div>
			</details>

			<div class="trustscript-support-footer">
				<a href="<?php echo esc_url( $app_url . '/docs/wordpress' ); ?>" target="_blank" class="trustscript-support-link">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( 'Documentation', 'trustscript' ); ?>
				</a>
				<a href="<?php echo esc_url( $app_url . '/dashboard/support' ); ?>" target="_blank" class="trustscript-support-link">
					<span class="dashicons dashicons-sos"></span>
					<?php esc_html_e( 'Support', 'trustscript' ); ?>
				</a>
				<a href="<?php echo esc_url( $api_keys_url ); ?>" target="_blank" class="trustscript-support-link">
					<span class="dashicons dashicons-admin-network"></span>
					<?php esc_html_e( 'Manage API Keys', 'trustscript' ); ?>
				</a>
				<a href="https://nexlifylabs.com/pricing" target="_blank" class="trustscript-support-link trustscript-support-upgrade">
					<span class="dashicons dashicons-star-filled"></span>
					<?php esc_html_e( 'Upgrade Plan', 'trustscript' ); ?>
				</a>
			</div>

			<div id="trustscript-delete-modal" class="trustscript-modal-overlay" data-modal="delete-api-key">
				<div class="trustscript-modal">
					<div class="trustscript-modal-header">
						<h3><?php esc_html_e( 'Delete API Key?', 'trustscript' ); ?></h3>
						<button type="button" class="trustscript-modal-close" data-action="close-modal">&times;</button>
					</div>
					<div class="trustscript-modal-body">
						<div class="trustscript-modal-icon">
							<span class="dashicons dashicons-warning"></span>
						</div>
						<p><?php esc_html_e( 'Are you sure you want to delete your API key? You will need to enter a new API key to use TrustScript.', 'trustscript' ); ?></p>
					</div>
					<div class="trustscript-modal-footer">
						<button type="button" class="button trustscript-modal-cancel" data-action="cancel-modal"><?php esc_html_e( 'Cancel', 'trustscript' ); ?></button>
						<button type="button" id="trustscript-confirm-delete" class="button button-primary trustscript-button-danger" data-action="confirm-delete"><?php esc_html_e( 'Delete API Key', 'trustscript' ); ?></button>
					</div>
				</div>
			</div>

			</div>
		</div>
		<?php
	}
}
