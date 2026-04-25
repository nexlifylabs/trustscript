<?php
/**
 * TrustScript Order Consent Capture
 * Captures and processes consent decisions during the WooCommerce checkout process,
 * saving them to order meta and logging events for audit purposes.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Order_Consent_Capture {

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'capture_consent_on_order_created' ), 10, 1 );
	}

	/**
	 * Capture consent decision when order is created.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 */
	public static function capture_consent_on_order_created( $order ) {
		// Nonce is optional -- only present when the checkout form includes the TrustScript consent block.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce presence is checked on the next line; absence is a valid non-WC flow.
		if ( ! isset( $_POST['trustscript_consent_nonce'] ) ) {
			self::record_no_consent_required( $order );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is read here solely to pass it to wp_verify_nonce() on the next line.
		$nonce = sanitize_text_field( wp_unslash( $_POST['trustscript_consent_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'trustscript_consent' ) ) {
			self::record_no_consent_required( $order );
			return;
		}

		// Nonce verified above via wp_verify_nonce( $nonce, 'trustscript_consent' ).
		$order_id = $order->get_id();

		$billing_country = isset( $_POST['trustscript_consent_country'] )
			? sanitize_text_field( wp_unslash( $_POST['trustscript_consent_country'] ) )
			: '';

		$consent_type = isset( $_POST['trustscript_consent_type'] )
			? sanitize_text_field( wp_unslash( $_POST['trustscript_consent_type'] ) )
			: 'not_required';

		$consent_given = isset( $_POST['trustscript_review_consent'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['trustscript_review_consent'] ) );

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

		// REMOTE_ADDR is the client's IP as set by the server at the TCP level (not a user-submitted
		// form value). It is hashed for the audit trail only - never stored or output raw.
		// Note: behind a proxy, this will be the proxy IP; HTTP_X_FORWARDED_FOR is not used here
		// as it is user-spoofable.
		$ip_hash = hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );

		TrustScript_Consent_Manager::log_consent_event(
			$order_id,
			$consent_given ? 'checkout_consent_given' : 'checkout_consent_declined',
			$billing_country,
			$consent_type,
			$ip_hash
		);

		// If double opt-in and consent given, send confirmation email immediately.
		if ( 'double_optin' === $consent_type && $consent_given ) {
			TrustScript_Consent_Manager::send_confirmation_email( $order_id );
		}

		// Mark order metadata to indicate consent has been processed.
		$order->update_meta_data( '_trustscript_consent_processed', 'yes' );
		$order->save();
	}

	/**
	 * Record that consent is not required for this order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 */
	private static function record_no_consent_required( $order ) {
		$order_id = $order->get_id();

		TrustScript_Consent_Manager::set_order_consent_status( $order_id, 'not_required' );

		$country = $order->get_billing_country();
		TrustScript_Consent_Manager::set_order_billing_country( $order_id, $country );

		TrustScript_Consent_Manager::log_consent_event(
			$order_id,
			'checkout_consent_not_required',
			$country,
			'not_required',
			hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) )
		);

		// Mark as processed
		$order->update_meta_data( '_trustscript_consent_processed', 'yes' );
		$order->save();
	}
}

// Initialize the consent capture integration if WooCommerce is active.
add_action( 'plugins_loaded', array( 'TrustScript_Order_Consent_Capture', 'init' ) );
