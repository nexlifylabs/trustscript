/**
 * TrustScript Checkout Consent - Dynamic Country Detection
 *
 * Handles showing/hiding the consent checkbox based on selected billing country
 * and updates the consent type (single vs double opt-in) dynamically.
 *
 * @package TrustScript
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        var checkoutData    = window.TrustScriptCheckout || {};
        var consentMode     = checkoutData.consent_mode || 'auto';
        var euCountries     = checkoutData.eu_countries || [];
        var doubleCountries = checkoutData.double_countries || [];

        function shouldShowCheckbox( country ) {
            if ( consentMode === 'disabled' ) {
                return false;
            }
            if ( consentMode === 'always_show' ) {
                return true;
            }
            return euCountries.indexOf( country.toUpperCase() ) !== -1;
        }

        function getConsentType( country ) {
            country = country.toUpperCase();
            if ( doubleCountries.indexOf( country ) !== -1 ) {
                return 'double_optin';
            }
            if ( euCountries.indexOf( country ) !== -1 ) {
                return 'single_optin';
            }
            if ( consentMode === 'always_show' ) {
                return 'single_optin';
            }
            return 'not_required';
        }

        function updateConsentCheckbox() {
            var $countryField = $( '#billing_country' );
            var country       = $countryField.val() || $countryField.attr( 'value' ) || '';

            if ( ! country ) {
                country = $( 'input[name="billing_country"]' ).val() || '';
            }

            var $wrap = $( '#trustscript-consent-wrap' );

            if ( $wrap.length === 0 ) {
                return;
            }

            var $checkbox     = $( '#trustscript_review_consent' );
            var $countryInput = $( '#trustscript_consent_country' );
            var $typeInput    = $( '#trustscript_consent_type' );

            if ( shouldShowCheckbox( country ) ) {
                $wrap.slideDown( 200 );
                if ( $countryInput.length > 0 ) {
                    $countryInput.val( country );
                }
                if ( $typeInput.length > 0 ) {
                    $typeInput.val( getConsentType( country ) );
                }
            } else {
                $wrap.slideUp( 200 );
                if ( $checkbox.length > 0 ) {
                    $checkbox.prop( 'checked', false );
                }
            }
        }

        updateConsentCheckbox();

        $( document.body ).on( 'updated_checkout', function () {
            updateConsentCheckbox();
        });

        $( document ).on( 'change', '#billing_country', function () {
            updateConsentCheckbox();
        });

        $( document ).on( 'select2:change', '#billing_country', function () {
            updateConsentCheckbox();
        });

        $( document ).on( 'change', '#billing_state, #billing_postcode', function () {
            setTimeout( updateConsentCheckbox, 150 );
        });

        var lastCountry = '';
        var pollInterval = setInterval(function () {
            var currentCountry = $( '#billing_country' ).val() || '';
            if ( currentCountry !== lastCountry ) {
                lastCountry = currentCountry;
                updateConsentCheckbox();
            }
        }, 500 );

        // Clear polling once WC checkout event system confirms it's working
        $( document.body ).one( 'updated_checkout', function () {
            clearInterval( pollInterval );
        });
    });
})(jQuery);