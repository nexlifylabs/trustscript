<?php
/**
 * TrustScript Block Checkout Consent — Block Checkout Integration
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrustScript_Block_Checkout_Consent {

    const STORE_API_NAMESPACE = 'trustscript';

    public static function init() {
        add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_schema' ) );
        add_action( 'init', array( __CLASS__, 'register_store_api_schema' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_block_scripts' ), 20 );

        add_action(
            'woocommerce_blocks_enqueue_checkout_block_scripts_after',
            array( __CLASS__, 'enqueue_block_scripts' )
        );

        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array( __CLASS__, 'capture_block_consent' ),
            10,
            2
        );
    }

    public static function register_store_api_schema() {
        static $registered = false;
        if ( $registered ) {
            return;
        }

        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        $registered = true;

        woocommerce_store_api_register_endpoint_data(
            array(
                'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
                'namespace'       => self::STORE_API_NAMESPACE,
                'schema_callback' => array( __CLASS__, 'get_store_api_schema' ),
                'schema_type'     => ARRAY_A,
            )
        );
    }

    /**
     * JSON Schema definition for TrustScript's extension data fields.
     *
     * @return array
     */
    public static function get_store_api_schema() {
        return array(
            'consent_given'   => array(
                'description' => __( 'Whether the customer opted in for a review request.', 'trustscript' ),
                'type'        => 'boolean',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => false,
                'default'     => false,
            ),
            'consent_country' => array(
                'description' => __( 'Customer billing country code at time of consent.', 'trustscript' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => false,
                'default'     => '',
            ),
            'consent_type'    => array(
                'description' => __( 'Consent type: single_optin, double_optin, or not_required.', 'trustscript' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => false,
                'default'     => 'not_required',
            ),
            'nonce'           => array(
                'description' => __( 'WordPress nonce for server-side verification.', 'trustscript' ),
                'type'        => 'string',
                'context'     => array( 'view', 'edit' ),
                'readonly'    => false,
                'default'     => '',
            ),
        );
    }

    /**
     * Enqueue the block checkout consent script.
     */
    public static function enqueue_block_scripts() {
        if ( wp_script_is( 'trustscript-block-checkout-consent', 'enqueued' ) ) {
            return;
        }

        if ( ! is_checkout() ) {
            return;
        }

        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        if ( ! class_exists( 'TrustScript_Privacy_Settings_Page' ) ) {
            return;
        }

        $js_rel = 'assets/js/trustscript-block-checkout-consent.js';
        $js_abs = TRUSTSCRIPT_PLUGIN_PATH . $js_rel;
        $ver    = file_exists( $js_abs ) ? filemtime( $js_abs ) : TRUSTSCRIPT_VERSION;

        $deps = array( 'wp-element', 'wp-data', 'wp-plugins', 'wc-settings', 'wc-blocks-data-store' );

        wp_enqueue_script(
            'trustscript-block-checkout-consent',
            TRUSTSCRIPT_PLUGIN_URL . $js_rel,
            $deps,
            $ver,
            true // Load in footer for better performance and to ensure dependencies are loaded
        );

        $config = array(
            'consent_mode'    => get_option( 'trustscript_consent_mode', 'auto' ),
            'eu_countries'    => self::get_consent_countries(),
            'double_countries' => self::get_double_optin_countries(),
            'nonce'           => wp_create_nonce( 'trustscript_consent' ),
            'label'           => TrustScript_Privacy_Settings_Page::get_checkbox_label(),
            'privacy_url'     => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
            'privacy_label'   => __( 'Privacy Policy', 'trustscript' ),
        );

        wp_add_inline_script(
            'trustscript-block-checkout-consent',
            'window.TrustScriptBlockCheckout = ' . wp_json_encode( $config ) . ';',
            'before'
        );
    }

    /**
     * Capture and process consent data from the Store API checkout request, saving it to order meta and logging events.
     *
     * @param WC_Order        $order   The newly created order.
     * @param WP_REST_Request $request The Store API checkout request.
     */
    public static function capture_block_consent( $order, $request ) {
        if ( ! class_exists( 'TrustScript_Consent_Manager' )
            || ! class_exists( 'TrustScript_Privacy_Settings_Page' ) ) {
            return;
        }

        $extensions = $request->get_param( 'extensions' );
        $ts_data    = isset( $extensions[ self::STORE_API_NAMESPACE ] )
            ? $extensions[ self::STORE_API_NAMESPACE ]
            : array();

        if ( empty( $ts_data ) ) {
            self::record_no_consent_required( $order );
            return;
        }

        $order_id        = $order->get_id();
        $nonce           = isset( $ts_data['nonce'] ) ? $ts_data['nonce'] : '';
        $consent_given   = ! empty( $ts_data['consent_given'] );
        $billing_country = sanitize_text_field(
            ! empty( $ts_data['consent_country'] ) ? $ts_data['consent_country'] : $order->get_billing_country()
        );
        $consent_type    = sanitize_text_field(
            ! empty( $ts_data['consent_type'] ) ? $ts_data['consent_type'] : 'not_required'
        );

        if ( ! wp_verify_nonce( $nonce, 'trustscript_consent' ) ) {
            self::record_no_consent_required( $order );
            return;
        }

        TrustScript_Consent_Manager::set_order_billing_country( $order_id, $billing_country );

        if ( 'not_required' === $consent_type ) {
            $consent_status = 'not_required';
        } elseif ( $consent_given ) {
            $consent_status = ( 'double_optin' === $consent_type ) ? 'pending' : 'confirmed';
        } else {
            $consent_status = 'declined';
        }

        TrustScript_Consent_Manager::set_order_consent_status( $order_id, $consent_status );

        if ( $consent_given ) {
            TrustScript_Consent_Manager::set_order_consent_given_at( $order_id, current_time( 'mysql' ) );

            if ( 'double_optin' === $consent_type ) {
                $token = TrustScript_Consent_Manager::generate_consent_token();
                TrustScript_Consent_Manager::set_order_consent_token( $order_id, $token );
            }
        }

        TrustScript_Consent_Manager::log_consent_event(
            $order_id,
            $consent_given ? 'checkout_consent_given' : 'checkout_consent_declined',
            $billing_country,
            $consent_type,
            hash( 'sha256', sanitize_text_field( wp_unslash( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) ) )

        );

        if ( 'double_optin' === $consent_type && $consent_given ) {
            TrustScript_Consent_Manager::send_confirmation_email( $order_id );
        }

        $order->update_meta_data( '_trustscript_consent_processed', 'yes' );
        $order->save();
    }

    /**
     * Mark the order as not requiring consent (no TrustScript payload received).
     *
     * @param WC_Order $order
     */
    private static function record_no_consent_required( $order ) {
        $order_id = $order->get_id();
        $country  = $order->get_billing_country();

        TrustScript_Consent_Manager::set_order_consent_status( $order_id, 'not_required' );
        TrustScript_Consent_Manager::set_order_billing_country( $order_id, $country );
        TrustScript_Consent_Manager::log_consent_event(
            $order_id,
            'checkout_consent_not_required',
            $country,
            'not_required',
            hash( 'sha256', sanitize_text_field( wp_unslash( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) ) )
        );

        $order->update_meta_data( '_trustscript_consent_processed', 'yes' );
        $order->save();
    }

    private static function get_consent_countries() {
        $single  = TrustScript_Privacy_Settings_Page::SINGLE_OPTIN_COUNTRIES;
        $custom  = (array) get_option( 'trustscript_custom_optin_countries', array() );
        $double  = TrustScript_Privacy_Settings_Page::DOUBLE_OPTIN_COUNTRIES;
        $cdouble = (array) get_option( 'trustscript_custom_double_optin_countries', array() );

        return array_values( array_unique( array_merge( $single, $custom, $double, $cdouble ) ) );
    }

    private static function get_double_optin_countries() {
        $double = TrustScript_Privacy_Settings_Page::DOUBLE_OPTIN_COUNTRIES;
        $custom = (array) get_option( 'trustscript_custom_double_optin_countries', array() );

        return array_values( array_unique( array_merge( $double, $custom ) ) );
    }
}

add_action(
    'plugins_loaded',
    function () {
        if ( class_exists( 'WooCommerce' ) ) {
            TrustScript_Block_Checkout_Consent::init();
        }
    },
    20
);