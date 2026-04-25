<?php
/**
 * Handles privacy and compliance settings including consent mode,
 * opt-in country lists, checkbox label, and CAN-SPAM address injection.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Privacy_Settings_Page {

	/**
     * Countries requiring double opt-in (checkbox + confirmation email).
     *
     * @since 1.0.0
     */
	const DOUBLE_OPTIN_COUNTRIES = array( 'DE', 'AT' );

	/**
     * Countries requiring single opt-in (EU/UK GDPR).
     *
     * @since 1.0.0
     */
	const SINGLE_OPTIN_COUNTRIES = array(
		'FR', 'IT', 'ES', 'NL', 'BE', 'PL', 'SE', 'DK', 'FI',
		'NO', 'PT', 'IE', 'CZ', 'HU', 'RO', 'GR', 'BG', 'HR',
		'SK', 'SI', 'LV', 'LT', 'EE', 'LU', 'MT', 'CY', 'GB',
	);

	const OPT_PHYSICAL_ADDRESS        = 'trustscript_physical_address';
	const OPT_CONSENT_MODE            = 'trustscript_consent_mode';
	const OPT_CUSTOM_OPTIN_COUNTRIES  = 'trustscript_custom_optin_countries';
	const OPT_CUSTOM_DOUBLE_COUNTRIES = 'trustscript_custom_double_optin_countries';
	const OPT_CHECKBOX_LABEL          = 'trustscript_consent_checkbox_label';

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'trustscript' ) );
		}

		$address        = self::get_physical_address();
		$consent_mode   = get_option( self::OPT_CONSENT_MODE, 'auto' );
		$checkbox_label = get_option(
			self::OPT_CHECKBOX_LABEL,
			__( 'Send me a one-time review request after my order is delivered. No personal data is stored.', 'trustscript' )
		);

		$custom_optin        = (array) get_option( self::OPT_CUSTOM_OPTIN_COUNTRIES, array() );
		$custom_double_optin = (array) get_option( self::OPT_CUSTOM_DOUBLE_COUNTRIES, array() );

		$all_countries = function_exists( 'WC' ) && WC()->countries
			? WC()->countries->get_countries()
			: array();

		$effective_single = array_unique( array_merge( self::SINGLE_OPTIN_COUNTRIES, $custom_optin ) );
		$effective_double = array_unique( array_merge( self::DOUBLE_OPTIN_COUNTRIES, $custom_double_optin ) );

		?>
		<div class="wrap trustscript-privacy-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Privacy & Compliance', 'trustscript' ); ?></h1>

			<div class="trustscript-connected-banner trustscript-privacy-mb-24">
				<div class="trustscript-connected-left">
					<h1 class="trustscript-connected-heading">
						🔒 <?php esc_html_e( 'Privacy & Compliance', 'trustscript' ); ?>
					</h1>
				</div>
			</div>

			<form id="trustscript-privacy-form">
				<?php wp_nonce_field( 'trustscript_admin', 'trustscript_privacy_nonce' ); ?>

				<div class="trustscript-ob-card trustscript-privacy-mb-24">
					<h2 class="trustscript-ob-section-title">
						📮 <?php esc_html_e( 'Physical Mailing Address', 'trustscript' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Required under CAN-SPAM for customers in the United States. This address is passed to the TrustScript server and injected into the footer of every review request email via the {physical_address} template placeholder.', 'trustscript' ); ?>
					</p>

					<div class="trustscript-form-group trustscript-privacy-mt-16">
						<label for="ts_physical_address">
							<strong><?php esc_html_e( 'Business Address', 'trustscript' ); ?></strong>
						</label>
						<textarea
							id="ts_physical_address"
							name="physical_address"
							rows="3"
							class="large-text trustscript-privacy-mt-6"
							placeholder="<?php esc_attr_e( 'e.g. Acme Inc, 123 Main St, Springfield, IL 62701, USA', 'trustscript' ); ?>"
						><?php echo esc_textarea( $address ); ?></textarea>
						<p class="description trustscript-privacy-mt-6">
							<?php esc_html_e( 'Keep this on a single line or use commas to separate parts. It will appear exactly as typed in the email footer.', 'trustscript' ); ?>
						</p>
					</div>

					<?php if ( empty( $address ) ) : ?>
						<div class="trustscript-alert trustscript-alert-warning trustscript-privacy-mt-12">
							<strong>⚠️ <?php esc_html_e( 'No address saved yet.', 'trustscript' ); ?></strong>
							<p class="trustscript-alert-content">
								<?php esc_html_e( 'Review request emails sent to US customers will be missing the required CAN-SPAM footer address until you save one here.', 'trustscript' ); ?>
							</p>
						</div>
					<?php endif; ?>

					<div class="trustscript-form-group trustscript-privacy-mt-16">
						<label class="trustscript-privacy-d-flex-center">
							<input type="checkbox" name="require_physical_address" value="1"
								<?php checked( get_option( 'trustscript_require_physical_address', '0' ), '1' ); ?>>
							<strong><?php esc_html_e( 'Require address for all countries', 'trustscript' ); ?></strong>
						</label>
						<p class="description trustscript-privacy-mt-6">
							<?php esc_html_e( 'When enabled, the physical address will be included in all review emails regardless of customer country. When disabled (recommended), the address is only included for US customers (CAN-SPAM requirement) and other countries where you explicitly require it.', 'trustscript' ); ?>
						</p>
					</div>
				</div>

				<div class="trustscript-ob-card trustscript-privacy-mb-24">
					<h2 class="trustscript-ob-section-title">
						✅ <?php esc_html_e( 'Consent Mode', 'trustscript' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Controls when a consent checkbox appears at checkout. In Auto mode, TrustScript detects the billing country and applies the correct flow — no checkbox for US customers, single opt-in for EU/UK, double opt-in for Germany and Austria.', 'trustscript' ); ?>
					</p>

					<fieldset class="trustscript-privacy-mt-16">
						<label class="trustscript-privacy-radio-item">
							<input type="radio" name="consent_mode" value="auto"
								<?php checked( $consent_mode, 'auto' ); ?>>
							&nbsp;<strong><?php esc_html_e( 'Auto (Recommended)', 'trustscript' ); ?></strong>
							&nbsp;<span class="description"><?php esc_html_e( '— TrustScript detects the billing country and applies the correct consent flow automatically.', 'trustscript' ); ?></span>
						</label>
						<label class="trustscript-privacy-radio-item">
							<input type="radio" name="consent_mode" value="always_show"
								<?php checked( $consent_mode, 'always_show' ); ?>>
							&nbsp;<strong><?php esc_html_e( 'Always Show Checkbox', 'trustscript' ); ?></strong>
							&nbsp;<span class="description"><?php esc_html_e( '— Show a consent checkbox at checkout for all customers regardless of country. Use this if your store sells globally and you prefer uniform treatment.', 'trustscript' ); ?></span>
						</label>
						<label class="trustscript-privacy-radio-item-last">
							<input type="radio" name="consent_mode" value="disabled"
								<?php checked( $consent_mode, 'disabled' ); ?>>
							&nbsp;<strong><?php esc_html_e( 'Disabled — Opt-Out Link Only', 'trustscript' ); ?></strong>
							&nbsp;<span class="description"><?php esc_html_e( '— Never show a consent checkbox. Use only if your store exclusively serves jurisdictions where prior consent is not required (e.g. US-only stores).', 'trustscript' ); ?></span>
						</label>
					</fieldset>
				</div>

				<div class="trustscript-ob-card trustscript-privacy-mb-24">
					<h2 class="trustscript-ob-section-title">
						🏷️ <?php esc_html_e( 'Consent Checkbox Label', 'trustscript' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'The text shown next to the opt-in checkbox at checkout. Keep this neutral — do not mention discounts, incentives, or ratings. The opt-out link is appended automatically by TrustScript.', 'trustscript' ); ?>
					</p>
					<div class="trustscript-form-group trustscript-privacy-mt-16">
						<textarea
							name="consent_checkbox_label"
							rows="2"
							class="large-text"
						><?php echo esc_textarea( $checkbox_label ); ?></textarea>
					</div>
				</div>

				<div class="trustscript-ob-card trustscript-privacy-mb-24">
					<h2 class="trustscript-ob-section-title">
						🌍 <?php esc_html_e( 'Custom Country Rules', 'trustscript' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'By default, TrustScript uses built-in rules for EU/UK/DE/AT countries. Use these pickers to add extra countries to each tier — useful if your legal team requires stricter treatment for specific markets.', 'trustscript' ); ?>
					</p>

					<?php if ( ! empty( $all_countries ) ) : ?>

						<div class="trustscript-privacy-builtin-box">
							<strong><?php esc_html_e( 'Built-in coverage (always active):', 'trustscript' ); ?></strong>
							<div class="trustscript-privacy-builtin-box-inner">
								<div>
									<span class="trustscript-privacy-badge-double"><?php esc_html_e( 'Double opt-in:', 'trustscript' ); ?></span>
									<span class="trustscript-privacy-mono"> <?php echo esc_html( implode( ', ', self::DOUBLE_OPTIN_COUNTRIES ) ); ?></span>
								</div>
								<div>
									<span class="trustscript-privacy-badge-single"><?php esc_html_e( 'Single opt-in:', 'trustscript' ); ?></span>
									<span class="trustscript-privacy-mono"> <?php echo esc_html( implode( ', ', self::SINGLE_OPTIN_COUNTRIES ) ); ?></span>
								</div>
							</div>
						</div>

						<div class="trustscript-privacy-mb-24">
							<h3 class="trustscript-privacy-heading-sm">
								<?php esc_html_e( 'Additional Single Opt-In Countries', 'trustscript' ); ?>
							</h3>
							<p class="description trustscript-privacy-desc-sm">
								<?php esc_html_e( 'Customers from these countries will see an unchecked consent checkbox at checkout. No review is sent if they do not tick it.', 'trustscript' ); ?>
							</p>
							<?php self::render_country_picker(
								'custom_optin_countries[]',
								'ts-optin-picker',
								$all_countries,
								$custom_optin,
								self::SINGLE_OPTIN_COUNTRIES 
							); ?>
						</div>

						<div>
							<h3 class="trustscript-privacy-heading-sm">
								<?php esc_html_e( 'Additional Double Opt-In Countries', 'trustscript' ); ?>
							</h3>
							<p class="description trustscript-privacy-desc-sm">
								<?php esc_html_e( 'Customers from these countries must tick the checkbox AND click a confirmation link in a follow-up email before a review request is sent.', 'trustscript' ); ?>
							</p>
							<?php self::render_country_picker(
								'custom_double_optin_countries[]',
								'ts-double-optin-picker',
								$all_countries,
								$custom_double_optin,
								self::DOUBLE_OPTIN_COUNTRIES
							); ?>
						</div>

					<?php else : ?>
						<p class="description"><?php esc_html_e( 'WooCommerce country list unavailable. Activate WooCommerce to use this feature.', 'trustscript' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="trustscript-privacy-mb-40">
					<button type="submit" id="trustscript-privacy-save-btn" class="button button-primary trustscript-privacy-min-140">
						<?php esc_html_e( 'Save Changes', 'trustscript' ); ?>
					</button>
					<span id="trustscript-privacy-save-msg" class="trustscript-privacy-ml-14" style="display:none;"></span>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_country_picker( $input_name, $picker_id, $all_countries, $selected, $builtin_covered ) {
		?>
		<div id="<?php echo esc_attr( $picker_id ); ?>" class="trustscript-country-picker-container">

			<!-- Toolbar -->
			<div class="trustscript-country-toolbar">
				<input type="text" placeholder="<?php esc_attr_e( 'Search countries…', 'trustscript' ); ?>"
					class="trustscript-country-search-input">
				<button type="button" class="button button-secondary trustscript-country-select-all">
					✓ <?php esc_html_e( 'Select All', 'trustscript' ); ?>
				</button>
				<button type="button" class="button button-secondary trustscript-country-deselect-all">
					✕ <?php esc_html_e( 'Deselect All', 'trustscript' ); ?>
				</button>
			</div>

			<div class="trustscript-country-grid">
				<?php foreach ( $all_countries as $code => $name ) :
					$is_builtin  = in_array( $code, $builtin_covered, true );
					$is_checked  = $is_builtin || in_array( $code, $selected, true );
					$label_class = $is_builtin ? 'trustscript-country-label-disabled' : 'trustscript-country-label';
					$title_attr  = $is_builtin
						? esc_attr__( 'Already covered by built-in rules — cannot be unchecked', 'trustscript' )
						: '';
				?>
					<label class="<?php echo esc_attr( $label_class ); ?>"
						<?php if ( $title_attr ) echo 'title="' . esc_attr( $title_attr ) . '"'; ?>>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $input_name ); ?>"
							value="<?php echo esc_attr( $code ); ?>"
							<?php checked( $is_checked ); ?>
							<?php disabled( $is_builtin ); ?>
						>
						<span><?php echo esc_html( $code ); ?> &mdash; <?php echo esc_html( $name ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function handle_save_privacy_settings() {
		check_ajax_referer( 'trustscript_admin', 'trustscript_privacy_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'trustscript' ) ), 401 );
		}

		$address = isset( $_POST['physical_address'] )
			? sanitize_textarea_field( wp_unslash( $_POST['physical_address'] ) )
			: '';
		update_option( self::OPT_PHYSICAL_ADDRESS, $address );

		$require_address = isset( $_POST['require_physical_address'] )
			? '1'
			: '0';
		update_option( 'trustscript_require_physical_address', $require_address );

		$allowed_modes = array( 'auto', 'always_show', 'disabled' );
		$consent_mode  = isset( $_POST['consent_mode'] )
			? sanitize_key( wp_unslash( $_POST['consent_mode'] ) )
			: 'auto';
		if ( ! in_array( $consent_mode, $allowed_modes, true ) ) {
			$consent_mode = 'auto';
		}
		update_option( self::OPT_CONSENT_MODE, $consent_mode );

		$label = isset( $_POST['consent_checkbox_label'] )
			? sanitize_textarea_field( wp_unslash( $_POST['consent_checkbox_label'] ) )
			: '';
		update_option( self::OPT_CHECKBOX_LABEL, $label );

		$raw_optin        = isset( $_POST['custom_optin_countries'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['custom_optin_countries'] ) )
			: array();
		$raw_double_optin = isset( $_POST['custom_double_optin_countries'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['custom_double_optin_countries'] ) )
			: array();

		$valid_countries = function_exists( 'WC' ) && WC()->countries
			? array_keys( WC()->countries->get_countries() )
			: array();

		$sanitise_countries = function ( $raw, $exclude ) use ( $valid_countries ) {
			$out = array();
			foreach ( $raw as $code ) {
				$code = strtoupper( sanitize_text_field( $code ) );
				if ( strlen( $code ) === 2
					&& ( empty( $valid_countries ) || in_array( $code, $valid_countries, true ) )
					&& ! in_array( $code, $exclude, true )
				) {
					$out[] = $code;
				}
			}
			return array_values( array_unique( $out ) );
		};

		$custom_optin        = $sanitise_countries( $raw_optin,        self::SINGLE_OPTIN_COUNTRIES );
		$custom_double_optin = $sanitise_countries( $raw_double_optin, self::DOUBLE_OPTIN_COUNTRIES );

		update_option( self::OPT_CUSTOM_OPTIN_COUNTRIES,  $custom_optin );
		update_option( self::OPT_CUSTOM_DOUBLE_COUNTRIES, $custom_double_optin );

		wp_send_json_success( array(
			'message' => esc_html__( 'Privacy settings saved.', 'trustscript' ),
		) );
	}

	public static function get_physical_address() {
		$saved = get_option( self::OPT_PHYSICAL_ADDRESS, '' );
		if ( ! empty( $saved ) ) {
			return $saved;
		}

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$store_name    = get_bloginfo( 'name' );
			$base_address  = WC()->countries->get_base_address();
			$base_address2 = WC()->countries->get_base_address_2();
			$base_city     = WC()->countries->get_base_city();
			$base_state    = WC()->countries->get_base_state();
			$base_postcode = WC()->countries->get_base_postcode();
			$base_country  = WC()->countries->get_base_country();

			$parts = array_filter( array(
				$store_name,
				$base_address,
				$base_address2,
				$base_city,
				$base_state,
				$base_postcode,
				$base_country,
			) );
			return implode( ', ', $parts );
		}

		return '';
	}

	/**
	 * Determine what type of consent is needed for a billing country code.
	 *
	 * Returns one of: 'double_optin' | 'single_optin' | 'not_required'
	 *
	 * @param  string $country_code  ISO 3166-1 alpha-2 billing country code.
	 * @return string
	 */
	public static function get_consent_type_for_country( $country_code ) {
		$consent_mode = get_option( self::OPT_CONSENT_MODE, 'auto' );

		if ( 'disabled' === $consent_mode ) {
			return 'not_required';
		}

		if ( 'always_show' === $consent_mode ) {
			return 'single_optin';
		}

		$code = strtoupper( $country_code );

		$double_countries = array_unique( array_merge(
			self::DOUBLE_OPTIN_COUNTRIES,
			(array) get_option( self::OPT_CUSTOM_DOUBLE_COUNTRIES, array() )
		) );

		$single_countries = array_unique( array_merge(
			self::SINGLE_OPTIN_COUNTRIES,
			(array) get_option( self::OPT_CUSTOM_OPTIN_COUNTRIES, array() )
		) );

		if ( in_array( $code, $double_countries, true ) ) {
			return 'double_optin';
		}
		if ( in_array( $code, $single_countries, true ) ) {
			return 'single_optin';
		}

		return 'not_required';
	}

	/**
	 * Get the checkout consent checkbox label text.
	 *
	 * @return string
	 */
	public static function get_checkbox_label() {
		return get_option(
			self::OPT_CHECKBOX_LABEL,
			__( 'I agree to receive a one-time review request after my order is delivered.', 'trustscript' )
		);
	}

	/**
	 * Get the double opt-in confirmation email subject.
	 * Used by Consent Manager when sending confirmation emails.
	 *
	 * @return string
	 */
	public static function get_confirmation_email_subject( $order_id = '' ) {
		if ( $order_id ) {
			/* translators: %s: Order ID */
			return sprintf( __( 'Confirm your review request for order #%s', 'trustscript' ), $order_id );
		}
		return __( 'Confirm your review request', 'trustscript' );
	}
}