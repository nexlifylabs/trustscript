/**
 * TrustScript Block Checkout Consent
 * @description Insert a billing-country-aware consent checkbox into WC Blocks checkout and pushes
 * consent state into the store for use by the TrustScript tag.
 *
 * @since 1.0.0
 * @package TrustScript
 */
(function () {
    'use strict';

    if ( ! window.TrustScriptBlockCheckout ) {
        return;
    }

    var cfg             = window.TrustScriptBlockCheckout;
    var consentMode     = cfg.consent_mode    || 'auto';
    var euCountries     = cfg.eu_countries    || [];
    var doubleCountries = cfg.double_countries || [];
    var label           = cfg.label           || '';
    var privacyUrl      = cfg.privacy_url     || '';
    var privacyLabel    = cfg.privacy_label   || 'Privacy Policy';
    var nonce           = cfg.nonce           || '';
    var CHECKOUT_STORE_KEY = 'wc/store/checkout';
    var CART_STORE_KEY     = 'wc/store/cart';
    var state = { given: false, country: '', type: 'not_required' };
    var lastCountry = null;
    var storeUnsubscribe = null;
    var lastLoggedCountry = null;
    var lastLoggedStoreStatus = null;
    var countryChangeTimeout = null;
    var pushTimeout = null;

    function shouldShowCheckbox( country ) {
        if ( consentMode === 'disabled'    ) { return false; }
        if ( consentMode === 'always_show' ) { return true;  }
        return euCountries.indexOf( ( country || '' ).toUpperCase() ) !== -1;
    }

    function getConsentType( country ) {
        var c = ( country || '' ).toUpperCase();
        if ( doubleCountries.indexOf( c ) !== -1 ) { return 'double_optin'; }
        if ( euCountries.indexOf( c )    !== -1 ) { return 'single_optin'; }
        if ( consentMode === 'always_show' )       { return 'single_optin'; }
        return 'not_required';
    }

    function pushToStore() {
        if ( pushTimeout ) {
            clearTimeout( pushTimeout );
        }
        pushTimeout = setTimeout( function() {
            try {
                if ( ! window.wp || ! window.wp.data ) { return; }
                var storeDispatch = window.wp.data.dispatch( CHECKOUT_STORE_KEY );
                if ( ! storeDispatch ) { return; }

                var payload = {
                    consent_given:   state.given,
                    consent_country: state.country,
                    consent_type:    state.type,
                    nonce:           nonce
                };

                if ( typeof storeDispatch.setExtensionData === 'function' ) {
                    storeDispatch.setExtensionData( 'trustscript', payload );
                }
            } catch ( e ) {
                // Store not available - will retry on next subscription or DOM event
            }
        }, 50 );
    }

    function getBillingCountryFromStore() {
        var domCountry = getCountryFromDOM();
        var storeCountry = null;

        try {
            if ( ! window.wp || ! window.wp.data ) {
                if ( domCountry !== lastLoggedStoreStatus ) {
                    lastLoggedStoreStatus = domCountry;
                }
                return domCountry;
            }
            var cartSelect = window.wp.data.select( CART_STORE_KEY );
            if ( ! cartSelect ) {
                if ( domCountry !== lastLoggedStoreStatus ) {
                    lastLoggedStoreStatus = domCountry;
                }
                return domCountry;
            }

            if ( typeof cartSelect.getBillingAddress === 'function' ) {
                var addr = cartSelect.getBillingAddress();
                if ( addr && typeof addr.country === 'string' ) {
                    storeCountry = addr.country;
                }
            }

            if ( storeCountry === null && typeof cartSelect.getCustomerData === 'function' ) {
                var customer = cartSelect.getCustomerData();
                if ( customer ) {
                    if ( customer.billing_address && typeof customer.billing_address.country === 'string' ) {
                        storeCountry = customer.billing_address.country;
                    }
                    else if ( customer.billingAddress && typeof customer.billingAddress.country === 'string' ) {
                        storeCountry = customer.billingAddress.country;
                    }
                    else if ( customer.billingData && typeof customer.billingData.country === 'string' ) {
                        storeCountry = customer.billingData.country;
                    }
                    else if ( customer.billing_data && typeof customer.billing_data.country === 'string' ) {
                        storeCountry = customer.billing_data.country;
                    }
                }
            }
        } catch ( e ) {
            // Store not available - will retry on next subscription
        }

            if ( storeCountry === null ) {
            if ( domCountry !== lastLoggedStoreStatus ) {
                lastLoggedStoreStatus = domCountry;
            }
            return domCountry;
        }

        if ( domCountry && domCountry !== storeCountry ) {
            if ( domCountry !== lastLoggedStoreStatus ) {
                lastLoggedStoreStatus = domCountry;
            }
            return domCountry;
        }

        if ( storeCountry !== lastLoggedCountry ) {
            lastLoggedCountry = storeCountry;
        }
        return storeCountry;
    }

    function getCountryFromDOM() {
        var sel = findCountrySelect();
        if ( ! sel ) { return ''; }

        var code = ( sel.value || '' ).toUpperCase();
        if ( /^[A-Z]{2}$/.test( code ) ) { return code; }

        var opt = sel.options && sel.options[ sel.selectedIndex ];
        if ( opt ) {
            var dv = ( opt.getAttribute( 'data-value' ) || '' ).toUpperCase();
            if ( /^[A-Z]{2}$/.test( dv ) ) { return dv; }

            var ov = ( opt.value || '' ).toUpperCase();
            if ( /^[A-Z]{2}$/.test( ov ) ) { return ov; }

            var match = ( opt.textContent || '' ).match( /\(([A-Z]{2})\)/ );
            if ( match ) { return match[1]; }
        }

        return '';
    }

    function findCountrySelect() {
        var selectors = [
            '.wc-block-components-country-input select',
            '#billing-country',
            'select[autocomplete="billing country"]',
            'select[name="billing_country"]'
        ];
        for ( var i = 0; i < selectors.length; i++ ) {
            var el = document.querySelector( selectors[i] );
            if ( el ) { return el; }
        }
        return null;
    }

    function findInsertionPoint() {
        var selectors = [
            '.wp-block-woocommerce-checkout-terms-block',
            '.wc-block-checkout__terms',
            '.wc-block-checkout__terms--with-separator',
            '.wc-block-checkout__add-ons',
            '.wp-block-woocommerce-checkout-actions-block',
            '.wc-block-checkout__actions',
            '.wc-block-components-checkout-place-order-button',
            '.wc-block-checkout__submit',
            '.wp-block-woocommerce-checkout-payment-block',
            '[data-block-name="woocommerce/checkout-payment-block"]',
            '.wc-block-checkout__payment-method',
            '.wc-block-checkout__main',
            '.wc-block-checkout__form',
            '.wp-block-woocommerce-checkout',
            '.wc-block-checkout'
        ];

        var broadContainers = [
            '.wc-block-checkout__main',
            '.wc-block-checkout__form',
            '.wp-block-woocommerce-checkout',
            '.wc-block-checkout'
        ];

        for ( var i = 0; i < selectors.length; i++ ) {
            var el = document.querySelector( selectors[i] );
            if ( ! el ) { continue; }
            if ( broadContainers.indexOf( selectors[i] ) !== -1 ) {
                return el.lastElementChild || el;
            }
            return el;
        }
        return null;
    }

    function escHTML( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;'  )
            .replace( />/g, '&gt;'  )
            .replace( /"/g, '&quot;' );
    }

    function buildHTML( show ) {
        var ppLink = privacyUrl
            ? '<a href="' + escHTML( privacyUrl ) + '" target="_blank" rel="noopener noreferrer">' + escHTML( privacyLabel ) + '</a>'
            : '';
        return (
            '<div id="trustscript-consent-wrapper" style="display:' + ( show ? 'block' : 'none' ) + ';margin:12px 0;">' +
                '<label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:14px;line-height:1.4;">' +
                    '<input type="checkbox" id="trustscript-consent-checkbox" style="margin-top:2px;flex-shrink:0;" />' +
                    '<span>' + escHTML( label ) + ( ppLink ? ' ' + ppLink : '' ) + '</span>' +
                '</label>' +
            '</div>'
        );
    }

    function tryInject() {
        if ( document.getElementById( 'trustscript-consent-wrapper' ) ) {
            return true;
        }

        var target = findInsertionPoint();
        if ( ! target ) {
            return false;
        }

        var country = getBillingCountryFromStore();
        var show    = shouldShowCheckbox( country );

        state.country = country;
        state.type    = getConsentType( country );
        state.given   = false;
        lastCountry   = country.toUpperCase();

        var tmp = document.createElement( 'div' );
        tmp.innerHTML = buildHTML( show );
        var wrapper = tmp.firstChild;
        target.parentNode.insertBefore( wrapper, target );

        var cb = document.getElementById( 'trustscript-consent-checkbox' );
        if ( cb ) {
            cb.addEventListener( 'change', function () {
                state.given = this.checked;
                pushToStore();
            } );
        }

        pushToStore();
        return true;
    }

    function onCountryChange( country ) {
        country = ( country || '' ).toUpperCase();

        if ( country === ( lastCountry || '' ).toUpperCase() ) { return; }

        clearTimeout( countryChangeTimeout );
        countryChangeTimeout = setTimeout( function () {
            var currentCountry = getBillingCountryFromStore();
            currentCountry = ( currentCountry || '' ).toUpperCase();

            if ( currentCountry === ( lastCountry || '' ).toUpperCase() ) { return; }
            lastCountry = currentCountry;
            state.country = currentCountry;
            state.type    = getConsentType( currentCountry );

            var wrapper = document.getElementById( 'trustscript-consent-wrapper' );
            var show    = shouldShowCheckbox( currentCountry );

            if ( ! wrapper ) {
                tryInject();
                return;
            }

            wrapper.style.display = show ? 'block' : 'none';

            if ( ! show ) {
                state.given = false;
                var cb = document.getElementById( 'trustscript-consent-checkbox' );
                if ( cb ) { cb.checked = false; }
            }

            pushToStore();
        }, 150 ); 
    }

    function subscribeToCartStore() {
        if ( ! window.wp || ! window.wp.data ) { return; }

        if ( typeof storeUnsubscribe === 'function' ) {
            storeUnsubscribe();
            storeUnsubscribe = null;
        }

        try {
            storeUnsubscribe = window.wp.data.subscribe( function () {
                var country = getBillingCountryFromStore();
                onCountryChange( country );
            } );
        } catch ( e ) {
            // Store subscription unavailable.
        }
    }

    function attachCountryListener() {
        var sel = findCountrySelect();
        if ( ! sel || sel._tsListenerAttached ) { return; }
        sel._tsListenerAttached = true;

        sel.addEventListener( 'change', function () {
            onCountryChange( this.value );
        } );

        if ( typeof jQuery !== 'undefined' ) {
            jQuery( sel ).on( 'select2:change', function () {
                onCountryChange( this.value );
            } );
        }
    }

    function init() {
        tryInject();
        subscribeToCartStore();
        attachCountryListener();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () {
            init();
        } );
    } else {
        setTimeout( function () {
            init();
        }, 0 );
    } // end else

    setTimeout( function () {
        if ( ! document.getElementById( 'trustscript-consent-wrapper' ) ) {
            init();
        }
    }, 1000 );

    setInterval( function () {
        if ( ! document.getElementById( 'trustscript-consent-wrapper' ) ) {
            if ( findInsertionPoint() ) {
                tryInject();
            }
        }
        attachCountryListener();
    }, 1000 );

    window.addEventListener( 'beforeunload', function () {
        if ( typeof storeUnsubscribe === 'function' ) {
            storeUnsubscribe();
        }
    } );

}());