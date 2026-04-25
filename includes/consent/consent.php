<?php
/**
 * Handles the consent checkbox at WooCommerce checkout,
 * including dynamic show/hide based on billing country.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrustScript_Checkout_Consent {

    public function __construct() {
        if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_consent_checkbox' ), 10 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
    }

    /**
     * Enqueue JavaScript for dynamic consent checkbox handling.
     *
     * @since 1.0.0
     */
    public function enqueue_checkout_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        $js_file = TRUSTSCRIPT_PLUGIN_PATH . 'assets/js/trustscript-checkout-consent.js';
        $ver     = file_exists( $js_file ) ? filemtime( $js_file ) : TRUSTSCRIPT_VERSION;

        wp_enqueue_script(
            'trustscript-checkout-consent',
            TRUSTSCRIPT_PLUGIN_URL . 'assets/js/trustscript-checkout-consent.js',
            array( 'jquery' ),
            $ver,
            true
        );

        wp_localize_script(
            'trustscript-checkout-consent',
            'TrustScriptCheckout',
            array(
                'consent_mode'     => get_option( 'trustscript_consent_mode', 'auto' ),
                'eu_countries'     => $this->get_consent_countries(),
                'double_countries' => $this->get_double_optin_countries(),
                'nonce'            => wp_create_nonce( 'trustscript_consent' ),
            )
        );
    }

    /**
     * Render the consent checkbox before the checkout submit button.
     *
     * Always outputs the wrapper so JavaScript can show/hide it dynamically.
     *
     * @since 1.0.0
     */
    public function render_consent_checkbox() {
        if ( ! class_exists( 'TrustScript_Consent_Manager' )
          || ! class_exists( 'TrustScript_Privacy_Settings_Page' ) ) {
            return;
        }

        if ( 'disabled' === get_option( 'trustscript_consent_mode', 'auto' ) ) {
            return;
        }

        $country = $this->get_current_checkout_country();
        if ( empty( $country ) ) {
            $country = wc_get_base_location()['country'] ?? '';
        }

        $should_show_initially = true;
        if ( 'auto' === get_option( 'trustscript_consent_mode', 'auto' ) ) {
            if ( ! empty( $country ) && ! TrustScript_Consent_Manager::should_show_checkbox( $country ) ) {
                $should_show_initially = false;
            }
        }

        $consent_type  = TrustScript_Consent_Manager::get_consent_type_for_country( $country );
        $label         = TrustScript_Privacy_Settings_Page::get_checkbox_label();
        $display_style = $should_show_initially ? 'block' : 'none';

        ?>
        <div id="trustscript-consent-wrap" class="trustscript-consent-wrap" style="margin-bottom: 15px; display: <?php echo esc_attr( $display_style ); ?>;">
            <?php wp_nonce_field( 'trustscript_consent', 'trustscript_consent_nonce' ); ?>

            <label for="trustscript_review_consent" style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                <input
                    type="checkbox"
                    id="trustscript_review_consent"
                    name="trustscript_review_consent"
                    value="1"
                    style="margin-top: 3px; flex-shrink: 0; cursor: pointer;"
                >
                <span style="font-size: 0.9em; color: #555; flex: 1;">
                    <?php echo esc_html( $label ); ?>
                    <?php if ( function_exists( 'get_privacy_policy_url' ) ) : ?>
                        <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"
                           target="_blank"
                           style="font-size: 0.85em; margin-left: 4px;">
                            <?php esc_html_e( 'Privacy Policy', 'trustscript' ); ?>
                        </a>
                    <?php endif; ?>
                </span>
            </label>

            <!-- Hidden fields for backend processing -->
            <input type="hidden"
                   name="trustscript_consent_country"
                   id="trustscript_consent_country"
                   value="<?php echo esc_attr( $country ); ?>">
            <input type="hidden"
                   name="trustscript_consent_type"
                   id="trustscript_consent_type"
                   value="<?php echo esc_attr( $consent_type ); ?>">
        </div>
        <?php
    }

    /**
     * Get the customer's currently selected billing country at checkout.
     *
     * Returns the posted value if available, otherwise falls back to the customer object.
     *
     * @since 1.0.0
     * @return string ISO 3166-1 alpha-2 country code, or empty string.
     */
    private function get_current_checkout_country() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce AJAX checkout updates do not include a nonce in post_data
        if ( isset( $_POST['post_data'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
            parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
            if ( ! empty( $post_data['billing_country'] ) ) {
                return sanitize_text_field( $post_data['billing_country'] );
            }
        }

        if ( WC()->customer ) {
            $country = WC()->customer->get_billing_country();
            if ( ! empty( $country ) ) {
                return $country;
            }
        }

        return '';
    }

    /**
     * Get all countries requiring at least single opt-in.
     *
     * Used by JavaScript to show/hide the consent checkbox dynamically.
     *
     * @since 1.0.0
     * @return array List of ISO 3166-1 alpha-2 country codes.
     */
    private function get_consent_countries() {
        $single_countries = TrustScript_Privacy_Settings_Page::SINGLE_OPTIN_COUNTRIES;
        $custom           = (array) get_option( 'trustscript_custom_optin_countries', array() );
        $double           = TrustScript_Privacy_Settings_Page::DOUBLE_OPTIN_COUNTRIES;
        $custom_double    = (array) get_option( 'trustscript_custom_double_optin_countries', array() );

        return array_values( array_unique( array_merge( $single_countries, $custom, $double, $custom_double ) ) );
    }

    /**
     * Get all countries requiring double opt-in.
     *
     * Used by JavaScript to determine the email confirmation flow.
     *
     * @since 1.0.0
     * @return array List of ISO 3166-1 alpha-2 country codes.
     */
    private function get_double_optin_countries() {
        $double        = TrustScript_Privacy_Settings_Page::DOUBLE_OPTIN_COUNTRIES;
        $custom_double = (array) get_option( 'trustscript_custom_double_optin_countries', array() );

        return array_values( array_unique( array_merge( $double, $custom_double ) ) );
    }
}