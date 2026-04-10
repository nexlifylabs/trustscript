<?php
/**
 * Helper functions for TrustScript plugin - API requests, URL building, etc.
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get TrustScript base URL
 * 
 * Returns the base URL for TrustScript API requests, which can be overridden by a plugin setting.
 * 
 * @return string Base URL without trailing slash
 */
function trustscript_get_base_url() {
	$cached_url = get_transient( 'trustscript_base_url' );
	if ( $cached_url ) {
		return rtrim( $cached_url, '/' );
	}

	$db_url = get_option( 'trustscript_base_url', '' );
	if ( ! empty( $db_url ) ) {
		set_transient( 'trustscript_base_url', $db_url, MONTH_IN_SECONDS );
		return rtrim( $db_url, '/' );
	}

	return 'https://nexlifylabs.com';
}

/**
 * Get TrustScript app URL
 * 
 * Returns the base URL for the TrustScript web application.
 * Currently identical to trustscript_get_base_url(), but kept as a separate function
 * to allow for future divergence if the app URL and API URL need to differ.
 * 
 * @return string App URL without trailing slash
 */
function trustscript_get_app_url() {
	return trustscript_get_base_url();
}


/**
 * Make an authenticated API request to the TrustScript backend.
 * 
 * @param string $method HTTP method (GET, POST, etc.)
 * @param string $endpoint API endpoint path (e.g. '/api/verify-api-key')
 * @param array $body Optional request body for POST requests
 * @param int $timeout Optional timeout in seconds (default 15)
 * @return array|WP_Error Decoded response data on success, WP_Error on failure
 */
function trustscript_api_request( $method, $endpoint, $body = array(), $timeout = 15 ) {
	$api_key = get_option( 'trustscript_api_key', '' );
	
	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', __( 'Please set your API key in settings.', 'trustscript' ), array( 'status' => 400 ) );
	}
	
	$base_url = trustscript_get_base_url();
	$url      = trailingslashit( $base_url ) . ltrim( $endpoint, '/' );
	
	$args = array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $api_key,
			'Accept'        => 'application/json',
			'X-Site-URL'    => get_site_url(),
		),
		'timeout' => $timeout,
	);
	
	if ( strtoupper( $method ) === 'POST' && ! empty( $body ) ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body'] = wp_json_encode( $body );
	}
	
	$response = strtoupper( $method ) === 'POST' 
		? wp_remote_post( $url, $args ) 
		: wp_remote_get( $url, $args );
	
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	
	if ( $code < 200 || $code >= 300 ) {
		$message = __( 'API request failed', 'trustscript' );
		
		if ( $code === 401 ) {
			$message = __( 'API key authentication failed', 'trustscript' );
		} elseif ( $code === 403 ) {
			$message = __( 'Access denied', 'trustscript' );
		} elseif ( $code === 404 ) {
			$message = __( 'Resource not found', 'trustscript' );
		} elseif ( $code === 429 ) {
			$message = __( 'Rate limit exceeded', 'trustscript' );
		}
		
		return new WP_Error( 'api_error', $message, array( 'status' => $code, 'body' => $body ) );
	}
	
	$data = json_decode( $body, true );
	
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'invalid_json', __( 'Invalid JSON response from API', 'trustscript' ), array( 'status' => 500 ) );
	}
	
	return array( 'data' => $data, 'code' => $code );
}

/**
 * Validate webhook source origin
 * 
 * Ensures the webhook is coming from the configured TrustScript base URL.
 * Uses constant-time comparison to prevent timing attacks.
 *
 * @param WP_REST_Request $request The REST request object
 * @return true|WP_Error True if source is valid, WP_Error otherwise
 */
function trustscript_validate_webhook_source( $request ) {
	// Get the webhook source header (X-Webhook-Source) or Origin header
	$webhook_source = $request->get_header( 'X-Webhook-Source' );
	$origin = $request->get_header( 'Origin' );
	
	// Use X-Webhook-Source if provided (explicit source header), fallback to Origin
	$source = ! empty( $webhook_source ) ? $webhook_source : $origin;
	
	if ( empty( $source ) ) {
		// If no explicit source header is provided, validation is optional
		// The API key validation should be sufficient
		return true;
	}
	
	// Get the configured TrustScript base URL
	$allowed_base_url = trustscript_get_base_url();
	
	// Normalize URLs for comparison (remove trailing slashes and schema)
	$source_normalized = rtrim( $source, '/' );
	$allowed_normalized = rtrim( $allowed_base_url, '/' );
	
	// Parse and extract domain from source
	$source_parsed = wp_parse_url( $source_normalized );
	$allowed_parsed = wp_parse_url( $allowed_normalized );
	
	if ( ! $source_parsed || ! $allowed_parsed ) {
		return new WP_Error(
			'invalid_webhook_source',
			'Invalid webhook source format',
			array( 'status' => 401 )
		);
	}
	
	$source_host = isset( $source_parsed['host'] ) ? $source_parsed['host'] : '';
	$allowed_host = isset( $allowed_parsed['host'] ) ? $allowed_parsed['host'] : '';
	
	// Use hash_equals for constant-time comparison to prevent timing attacks
	if ( empty( $source_host ) || ! hash_equals( $allowed_host, $source_host ) ) {
		return new WP_Error(
			'unauthorized_webhook_source',
			'Webhook source is not authorized. Expected: ' . $allowed_host,
			array( 'status' => 401 )
		);
	}
	
	return true;
}