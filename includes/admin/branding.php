<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TrustScript Branding Page Handler
 */
class TrustScript_Branding_Page {

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$pricing_url = trustscript_get_pricing_url();
		
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'TrustScript Branding', 'trustscript' ); ?></h1>
			
			<div id="trustscript-branding-loading" class="trustscript-card" data-section="branding-loading">
				<p><?php esc_html_e( 'Loading your account information...', 'trustscript' ); ?></p>
			</div>

			<div id="trustscript-branding-error" class="trustscript-card trustscript-branding-error" style="display:none;" data-section="branding-error">
				<h2><?php esc_html_e( '⚠️ Unable to Verify Plan', 'trustscript' ); ?></h2>
				<p id="trustscript-branding-error-message" data-error-message></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=trustscript-settings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Check API Settings', 'trustscript' ); ?>
					</a>
					<button type="button" id="trustscript-refresh-plan" class="button" style="margin-left: 10px;" data-action="refresh-plan">
						<?php esc_html_e( 'Refresh Plan Information', 'trustscript' ); ?>
					</button>
				</p>
			</div>

			<div id="trustscript-branding-upgrade" class="trustscript-card trustscript-branding-upgrade" style="display:none;" data-section="branding-upgrade">
				<h2>
					<strong>💎<?php esc_html_e( 'Upgrade to Pro or Business Plan', 'trustscript' ); ?></strong>
				</h2>
				<p>
					<?php esc_html_e( 'Custom branding is available for Pro and Business plan users. Customize your review pages with your own logo, colors, and domain to create a professional branded experience.', 'trustscript' ); ?>
				</p>
				<ul class="trustscript-branding-features">
					<li>
						<span class="trustscript-check">✓</span>
						<?php esc_html_e( 'Custom logo and business name', 'trustscript' ); ?>
					</li>
					<li>
						<span class="trustscript-check">✓</span>
						<?php esc_html_e( 'Custom subdomain or domain', 'trustscript' ); ?>
					</li>
					<li>
						<span class="trustscript-check">✓</span>
						<?php esc_html_e( 'Custom brand colors', 'trustscript' ); ?>
					</li>
					<li>
						<span class="trustscript-check">✓</span>
						<?php esc_html_e( 'White-label review pages', 'trustscript' ); ?>
					</li>
					<li>
						<span class="trustscript-check">✓</span>
						<?php esc_html_e( 'Pro: 500 reviews/month | Business: 2,000 reviews/month', 'trustscript' ); ?>
					</li>
				</ul>
				<p>
					<a href="<?php echo esc_url( $pricing_url ); ?>" target="_blank" class="button trustscript-branding-upgrade-btn">
						<?php esc_html_e( 'View Plans & Upgrade →', 'trustscript' ); ?>
					</a>
					<button type="button" id="trustscript-refresh-plan-from-upgrade" class="button" style="margin-left: 10px;" data-action="refresh-plan">
						<?php esc_html_e( 'Refresh Plan Information', 'trustscript' ); ?>
					</button>
				</p>
			</div>

			<div id="trustscript-branding-container" style="display:none;" data-section="branding-container">
				<div class="trustscript-card trustscript-mb-24">
					<h2><?php esc_html_e( '✨ Your White-Label Branding', 'trustscript' ); ?></h2>
					<p><?php esc_html_e( 'Customize how your review pages appear to customers. Changes sync automatically to TrustScript.', 'trustscript' ); ?></p>
				</div>

				<div class="trustscript-card">
					<form id="trustscript-branding-form" onsubmit="return false;" data-form="branding">
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Business Name', 'trustscript' ); ?></th>
								<td>
									<input type="text" id="trustscript-brand-name" name="brand[name]" class="regular-text" data-field="brand-name" />
									<p class="description"><?php esc_html_e( 'Your business name shown on review pages', 'trustscript' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Logo', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-logo-upload" data-section="logo-upload">
										<input type="url" id="trustscript-brand-logo" name="brand[logo]" class="regular-text" placeholder="Logo URL" readonly data-field="brand-logo" />
										<button type="button" id="trustscript-select-logo" class="button" data-action="select-logo"><?php esc_html_e( 'Upload Logo', 'trustscript' ); ?></button>
										<button type="button" id="trustscript-remove-logo" class="button" style="display:none;" data-action="remove-logo"><?php esc_html_e( 'Remove', 'trustscript' ); ?></button>
									</div>
									<div id="trustscript-logo-preview" class="trustscript-logo-preview" style="display:none;" data-preview="logo">
										<img src="" alt="Logo preview" />
									</div>
									<p class="description"><?php esc_html_e( 'Upload your logo (PNG, JPG, or SVG recommended). Max 5MB.', 'trustscript' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Custom Subdomain', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-domain-input" data-section="domain-input">
										<input type="text" id="trustscript-brand-domain" name="brand[customDomain]" class="regular-text" placeholder="yourbrand" data-field="brand-domain" />
										<span id="trustscript-domain-status" data-status="domain"></span>
									</div>
									<p class="description">
										<?php esc_html_e( 'Your custom subdomain (e.g., "yourbrand" for yourbrand.nexlifylabs.com). Checks availability in real-time.', 'trustscript' ); ?>
									</p>
								</td>
							</tr>
							<tr style="background-color: #f5f5f5; border-top: 2px solid #ddd;">
								<td colspan="2">
									<h3 style="margin: 12px 0 8px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( '🎨 Brand Colors', 'trustscript' ); ?></h3>
									<p style="margin: 0; font-size: 12px; color: #666;"><?php esc_html_e( 'Customize the colors of your review pages to match your brand identity', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Title & Sub heading', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[primaryColor]" class="trustscript-color-input" data-field="primaryColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#0091FF" data-field="primaryColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Title & Sub heading color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Accent Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[accentColor]" class="trustscript-color-input" data-field="accentColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#0091FF" data-field="accentColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Secondary accent color for interactive elements', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Page Background', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[backgroundColor]" class="trustscript-color-input" data-field="backgroundColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#f9fafb" data-field="backgroundColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Main page background color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Header Background', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[headerBackgroundColor]" class="trustscript-color-input" data-field="headerBackgroundColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#0091FF" data-field="headerBackgroundColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Header section background color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Card Background', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[cardBackgroundColor]" class="trustscript-color-input" data-field="cardBackgroundColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#ffffff" data-field="cardBackgroundColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Card and content area background color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Text Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[textColor]" class="trustscript-color-input" data-field="textColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#1f2937" data-field="textColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Primary text color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Secondary Text Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[secondaryTextColor]" class="trustscript-color-input" data-field="secondaryTextColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#6b7280" data-field="secondaryTextColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Secondary/dimmed text color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Border Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[borderColor]" class="trustscript-color-input" data-field="borderColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#e5e7eb" data-field="borderColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Border and divider color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Button Background', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[buttonBackgroundColor]" class="trustscript-color-input" data-field="buttonBackgroundColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#0091FF" data-field="buttonBackgroundColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Button background color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Button Text', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[buttonTextColor]" class="trustscript-color-input" data-field="buttonTextColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#ffffff" data-field="buttonTextColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Button text color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Button Border', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[buttonBorderColor]" class="trustscript-color-input" data-field="buttonBorderColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#0091FF" data-field="buttonBorderColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Button border color', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Success Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[successColor]" class="trustscript-color-input" data-field="successColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#10b981" data-field="successColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Color for success messages and indicators', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Error Color', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[errorColor]" class="trustscript-color-input" data-field="errorColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#ef4444" data-field="errorColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Color for error messages and alerts', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Star Color (Filled)', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[starColor]" class="trustscript-color-input" data-field="starColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#fbbf24" data-field="starColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Color for filled rating stars', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Star Color (Empty)', 'trustscript' ); ?></th>
								<td>
									<div class="trustscript-color-picker">
										<input type="color" name="brand[starEmptyColor]" class="trustscript-color-input" data-field="starEmptyColor" />
										<input type="text" class="regular-text trustscript-color-hex" placeholder="#d1d5db" data-field="starEmptyColor-hex" maxlength="7" />
									</div>
									<p class="description"><?php esc_html_e( 'Color for empty rating stars', 'trustscript' ); ?></p>
								</td>
							</tr>

							<tr style="background-color: #f5f5f5; border-top: 2px solid #ddd;">
								<td colspan="2">
									<h3 style="margin: 12px 0 8px 0; font-size: 14px; font-weight: 600;"><?php esc_html_e( '⚙️ Layout & Display', 'trustscript' ); ?></h3>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Display Logo Only', 'trustscript' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="brand[displayLogoOnly]" value="1" data-field="displayLogoOnly" />
										<?php esc_html_e( 'Show only the logo without business name', 'trustscript' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Display Name Only', 'trustscript' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="brand[displayNameOnly]" value="1" data-field="displayNameOnly" />
										<?php esc_html_e( 'Show only the business name without logo', 'trustscript' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Remove Footer', 'trustscript' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="brand[removeFooter]" value="1" data-field="removeFooter" />
										<?php esc_html_e( 'Remove footer text from review pages', 'trustscript' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Footer Text', 'trustscript' ); ?></th>
								<td>
									<textarea id="trustscript-brand-footer" name="brand[footer]" rows="3" class="large-text" placeholder="Powered by YourBrand" data-field="brand-footer"></textarea>
									<p class="description"><?php esc_html_e( 'Custom footer text shown on review pages (optional)', 'trustscript' ); ?></p>
								</td>
							</tr>
						</table>

						<p class="submit">
							<button type="button" id="trustscript-branding-save" class="button button-primary" data-action="save-branding">
								<?php esc_html_e( 'Save Changes', 'trustscript' ); ?>
							</button>
							<span id="trustscript-branding-save-status" data-status="branding-save"></span>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
