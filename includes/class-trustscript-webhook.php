<?php
/**
 * TrustScript Webhook Handler - Handles incoming webhook requests from TrustScript
 * for actions like resending review emails and publishing reviews directly from the 
 * TrustScript dashboard.Includes security checks for API key authentication, rate 
 * limiting, and token validation to ensure only authorized requests are processed.
 * Integrates with WooCommerce orders to identify the correct customer and send emails 
 * or publish reviews accordingly. Supports both legacy uniqueToken and new productToken 
 * for flexible review management.
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Webhook {
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_review_meta', array( $this, 'add_verification_badge_inline' ), 10 );
			add_action( 'woocommerce_review_comment_text', array( $this, 'render_review_media' ), 20 );
			add_filter( 'comment_class', array( $this, 'add_trustscript_comment_class' ), 10, 4 );
		}
	}

	public function register_routes() {
		register_rest_route( 'trustscript/v1', '/resend-email', array(
			'methods' => 'POST',
			'callback' => array( $this, 'handle_resend_email' ),
			'permission_callback' => array( $this, 'verify_request' ),
		) );

		register_rest_route( 'trustscript/v1', '/publish-review', array(
			'methods' => 'POST',
			'callback' => array( $this, 'handle_publish_review' ),
			'permission_callback' => array( $this, 'verify_request' ),
		) );
	}

	/**
	 * Helper function to get order meta in a way that is compatible with both HPOS and traditional orders.
	 *
	 * @param int $order_id Order ID
	 * @param string $meta_key Metadata key
	 * @return mixed Metadata value or empty string if not found
	 */
	private function get_hpos_order_meta( $order_id, $meta_key ) {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return $order->get_meta( $meta_key );
			}
		}
		return get_post_meta( $order_id, $meta_key, true );
	}

	public function verify_request( $request ) {
		// First, validate the source of the webhook to ensure it's coming from a trusted origin. 
		$source_validation = trustscript_validate_webhook_source( $request );
		if ( is_wp_error( $source_validation ) ) {
			return $source_validation;
		}

		$api_key = $request->get_header( 'Authorization' );
		$site_url = $request->get_header( 'X-Site-URL' );
		
		if ( ! $api_key || ! $site_url ) {
			return new WP_Error( 'unauthorized', 'Missing authentication headers (Authorization and X-Site-URL required)', array( 'status' => 401 ) );
		}

		if ( strpos( $api_key, 'Bearer ' ) === 0 ) {
			$api_key = substr( $api_key, 7 );
		}

		$rate_limit_key = 'trustscript_rate_limit_' . hash( 'sha256', $api_key );
		
		// Implement a simple rate limiting mechanism to prevent abuse of the webhook endpoint.
		// This limits each API key to 100 requests per minute. Legitimate clients should not hit 
		// this limit under normal usage.
		if ( false === get_transient( $rate_limit_key ) ) {
			set_transient( $rate_limit_key, 1, 60 );
		} else {
			$request_count = intval( get_transient( $rate_limit_key ) );
			if ( $request_count >= 100 ) {
				return new WP_Error( 'rate_limited', 'Too many requests. Rate limit: 100 requests per minute', array( 'status' => 429 ) );
			}
			set_transient( $rate_limit_key, $request_count + 1, 60 );
		}

		$stored_api_key = get_option( 'trustscript_api_key', '' );
		
		if ( empty( $stored_api_key ) ) {
			return new WP_Error( 'unauthorized', 'No API key configured', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $stored_api_key, $api_key ) ) {
			return new WP_Error( 'unauthorized', 'Invalid API key', array( 'status' => 401 ) );
		}

		$trustscript_api_expires_at = get_option( 'trustscript_api_key_expires_at', null );
		if ( ! empty( $trustscript_api_expires_at ) ) {
			$expires_timestamp = strtotime( $trustscript_api_expires_at );
			$current_timestamp = time();
			
			if ( $current_timestamp > $expires_timestamp ) {
				return new WP_Error( 'api_key_expired', 'API key has expired. Please generate a new API key in TrustScript dashboard.', array( 'status' => 401 ) );
			}
		}

		$current_site_url = get_site_url();
		if ( ! hash_equals( $current_site_url, $site_url ) ) {
			return new WP_Error( 'domain_mismatch', 'Domain mismatch: API key is not valid for this domain', array( 'status' => 401 ) );
		}
		
		return true;
	}

	public function handle_resend_email( $request ) {
		$data = $request->get_json_params();
		
		if ( empty( $data['uniqueToken'] ) && empty( $data['productToken'] ) ) {
			return new WP_Error( 'missing_token', 'Missing uniqueToken or productToken', array( 'status' => 400 ) );
		}

		$has_unique_token  = ! empty( $data['uniqueToken'] ) && is_string( $data['uniqueToken'] );
		$has_product_token = ! empty( $data['productToken'] ) && is_string( $data['productToken'] );
		
		$api_key = $request->get_header( 'Authorization' );
		if ( strpos( $api_key, 'Bearer ' ) === 0 ) {
			$api_key = substr( $api_key, 7 );
		}
		$api_key_hash = hash( 'sha256', $api_key );
		
		$review_data = array(
			'uniqueToken' => isset( $data['uniqueToken'] ) ? sanitize_text_field( wp_unslash( $data['uniqueToken'] ) ) : '',
			'productToken' => isset( $data['productToken'] ) ? sanitize_text_field( wp_unslash( $data['productToken'] ) ) : '',
			'id' => isset( $data['id'] ) ? sanitize_text_field( wp_unslash( $data['id'] ) ) : '',
			'approval_url' => isset( $data['approval_url'] ) ? esc_url_raw( $data['approval_url'] ) : '',
			'email_subject' => isset( $data['email_subject'] ) ? sanitize_text_field( wp_unslash( $data['email_subject'] ) ) : '',
			'email_html' => isset( $data['email_html'] ) ? wp_kses_post( $data['email_html'] ) : '',
		);
		
		$service_manager = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();
		
		$found_order = null;
		$found_provider = null;
		$customer_email = null;
		
		if ( $has_product_token ) {
			$product_token = sanitize_text_field( $data['productToken'] );

			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $product_token ) ) {
				return new WP_Error( 'invalid_token', 'Invalid productToken format', array( 'status' => 400 ) );
			}

			$lookup = $this->find_order_by_product_token( $product_token );

			if ( $lookup ) {
				list( $order_id, $item_id, $product_id ) = $lookup;
				
				$service_type = $this->get_hpos_order_meta( $order_id, '_trustscript_service_type' );
				
				foreach ( $active_providers as $service_id => $provider ) {
					if ( ! empty( $service_type ) && $service_type === $service_id ) {
						$found_order = $order_id;
						$found_provider = $provider;
						$customer_email = $found_provider->get_customer_email( $order_id );
						break;
					}
				}
				
				if ( ! $found_provider && isset( $active_providers['woocommerce'] ) ) {
					$found_order    = $order_id;
					$found_provider = $active_providers['woocommerce'];
					$customer_email = $found_provider->get_customer_email( $order_id );
				}
			} 
		}
		
		if ( ! $found_order && $has_unique_token ) {
			$unique_token = sanitize_text_field( wp_unslash( $data['uniqueToken'] ) );

			if ( ! preg_match( '/^[a-z0-9\-]{32,}$/i', $unique_token ) ) {
				return new WP_Error( 'invalid_token', 'Invalid uniqueToken format', array( 'status' => 400 ) );
			}

			foreach ( $active_providers as $service_id => $provider ) {
				$order_id = $this->find_order_by_token( $service_id, $unique_token, $api_key_hash );
				
				if ( $order_id ) {
					$found_order = $order_id;
					$found_provider = $provider;
					$customer_email = $found_provider->get_customer_email( $order_id );
					break;
				} 
			}
		}
		
		if ( ! $found_order || ! $found_provider ) {
			return new WP_Error( 'order_not_found', 'Order not found for this token, or API key mismatch', array( 'status' => 404 ) );
		}
		
		$sent = $found_provider->send_review_email( $found_order, $review_data );
		
		if ( $sent ) {
			$email_hash = hash( 'sha256', strtolower( trim( $customer_email ) ) );
			
			return array(
				'success' => true,
				'message' => 'Reminder email sent successfully',
				'emailHash' => substr( $email_hash, 0, 16 ),
				'service' => $found_provider->get_service_name(),
			);
		} else {
			return new WP_Error( 'email_failed', 'Failed to send email', array( 'status' => 500 ) );
		}
	}

	public function handle_publish_review( $request ) {

		$data = $request->get_json_params();
		$has_unique_token  = ! empty( $data['uniqueToken'] ) && is_string( $data['uniqueToken'] );
		$has_product_token = ! empty( $data['productToken'] ) && is_string( $data['productToken'] );

		if ( ! $has_unique_token && ! $has_product_token ) {
			return new WP_Error( 'missing_token', 'Missing uniqueToken or productToken', array( 'status' => 400 ) );
		}

		if ( ! isset( $data['reviewText'] ) ) {
			return new WP_Error( 'missing_review', 'Missing reviewText', array( 'status' => 400 ) );
		}

		$api_key = $request->get_header( 'Authorization' );
		if ( strpos( $api_key, 'Bearer ' ) === 0 ) {
			$api_key = substr( $api_key, 7 );
		}
		$api_key_hash = hash( 'sha256', $api_key );

		$timestamp_header = $request->get_header( 'X-Webhook-Timestamp' );
		if ( $timestamp_header ) {
			$request_time    = intval( $timestamp_header );
			$current_time    = time();
			$max_age_seconds = 3600;

			if ( $request_time <= 0 ) {
				return new WP_Error( 'invalid_timestamp', 'Invalid timestamp format', array( 'status' => 400 ) );
			}
			if ( $request_time > 4102444800 ) {
				$request_time = intval( $request_time / 1000 );
			}
			$time_diff = abs( $current_time - $request_time );
			if ( $time_diff > $max_age_seconds ) {
				return new WP_Error( 'token_expired', 'Token has expired (max age: 60 minutes)', array( 'status' => 401 ) );
			}
		}

		$review_text = sanitize_textarea_field( wp_unslash( $data['reviewText'] ) );
		$rating      = isset( $data['rating'] ) ? max( 1, min( 5, intval( $data['rating'] ) ) ) : 5;

		$media_urls = array();
		if ( isset( $data['mediaUrls'] ) && is_array( $data['mediaUrls'] ) ) {
			foreach ( $data['mediaUrls'] as $url ) {
				$url = sanitize_text_field( wp_unslash( $url ) );
				if ( ! empty( $url ) ) {
					$local_url = $this->localise_media_url( $url );
					if ( ! empty( $local_url ) ) {
						$media_urls[] = $local_url;
					}
				}
			}
		}

		$auto_publish    = get_option( 'trustscript_auto_publish', false );
		$publishing_mode = 'direct';

		if ( $has_product_token ) {
			$product_token = sanitize_text_field( $data['productToken'] );

			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $product_token ) ) {
				return new WP_Error( 'invalid_token', 'Invalid productToken format', array( 'status' => 400 ) );
			}

			if ( TrustScript_Order_Registry::is_product_token_published( $product_token ) ) {
				return new WP_Error( 'token_already_used', 'This product token has already been used to publish a review', array( 'status' => 409 ) );
			}

			$lookup = $this->find_order_by_product_token( $product_token );

			if ( ! $lookup ) {
				return new WP_Error( 'order_not_found', 'No order found for this product token', array( 'status' => 404 ) );
			}

			list( $order_id, $item_id, $product_id ) = $lookup;

			$registry_service_id = null;
			$found_provider      = null;
			$service_manager     = TrustScript_Service_Manager::get_instance();
			$active_providers    = $service_manager->get_active_providers();

			foreach ( $active_providers as $sid => $prov ) {
				if ( $sid === 'woocommerce' ) {
					$found_provider      = $prov;
					$registry_service_id = $sid;
					break;
				}
			}

			if ( ! $found_provider ) {
				return new WP_Error( 'provider_not_found', 'WooCommerce provider not active', array( 'status' => 500 ) );
			}

			$customer_info = $found_provider->get_customer_info( $order_id );

			$comment_data = array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => $customer_info['name'],
				'comment_author_email' => $customer_info['email'],
				'comment_content'      => $review_text,
				'comment_type'         => 'review',
				'comment_parent'       => 0,
				'user_id'              => 0,
				'comment_approved'     => $auto_publish ? 1 : 0,
			);

			$comment_id = wp_insert_comment( $comment_data );

			if ( ! $comment_id ) {
				return new WP_Error( 'comment_failed', 'Failed to insert review comment for product #' . $product_id, array( 'status' => 500 ) );
			}

			add_comment_meta( $comment_id, 'rating',                       $rating );
			add_comment_meta( $comment_id, '_trustscript_rating',          $rating );
			add_comment_meta( $comment_id, 'verified',                     1 );
			add_comment_meta( $comment_id, '_trustscript_review_token',    $product_token );
			add_comment_meta( $comment_id, '_trustscript_product_token',   $product_token );
			add_comment_meta( $comment_id, '_trustscript_order_id',        $order_id );
			add_comment_meta( $comment_id, '_trustscript_approved_at',     current_time( 'mysql' ) );
			add_comment_meta( $comment_id, '_trustscript_publishing_mode', $publishing_mode );
			add_comment_meta( $comment_id, '_trustscript_api_key_hash',    $api_key_hash );

			if ( ! empty( $data['verificationHash'] ) ) {
				add_comment_meta( $comment_id, '_trustscript_verification_hash', sanitize_text_field( $data['verificationHash'] ) );
			}
			if ( ! empty( $media_urls ) ) {
				add_comment_meta( $comment_id, '_trustscript_media_urls', wp_json_encode( $media_urls ) );
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}

			TrustScript_Order_Registry::mark_published(
				$registry_service_id,
				$order_id,
				$product_id,
				$product_token,
				'webhook'
			);

			$found_provider->save_order_meta( $order_id, '_trustscript_review_published', 'yes' );
			$found_provider->save_order_meta( $order_id, '_trustscript_review_published_at', current_time( 'mysql' ) );
			$found_provider->save_order_meta( $order_id, '_trustscript_publishing_mode', $publishing_mode );

			// Flush relevant caches to ensure the new review appears on the frontend 
			// immediately, especially important for direct publishing.
			if ( class_exists( 'TrustScript_Review_Renderer' ) ) {
				TrustScript_Review_Renderer::flush_stats_cache( $product_id );
			}
			if ( class_exists( 'TrustScript_Shop_Display' ) && method_exists( 'TrustScript_Shop_Display', 'clear_product_rating_cache' ) ) {
				TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
			}

			$status_text = $auto_publish ? 'published' : 'submitted for moderation';
			return array(
				'success'         => true,
				'message'         => '1 review ' . $status_text . ' successfully',
				'published_count' => 1,
				'auto_published'  => $auto_publish,
				'order_id'        => $order_id,
				'product_id'      => $product_id,
				'publishing_mode' => $publishing_mode,
			);
		}
		$unique_token = sanitize_text_field( $data['uniqueToken'] );

		if ( ! preg_match( '/^[a-z0-9\-]{32,}$/i', $unique_token ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token format', array( 'status' => 400 ) );
		}

		$existing_review_count = get_comments( array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to check for duplicate reviews by unique token
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'   => '_trustscript_review_token',
					'value' => $unique_token,
				),
				array(
					'key'   => '_trustscript_api_key_hash',
					'value' => $api_key_hash,
				),
			),
			'count' => true,
		) );

		if ( $existing_review_count > 0 ) {
			return new WP_Error( 'token_already_used', 'This token has already been used to publish a review', array( 'status' => 409 ) );
		}

		$service_manager  = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();

		$found_order    = null;
		$found_provider = null;
		$order_id       = null;
		
		foreach ( $active_providers as $service_id => $provider ) {
			$order_id = $this->find_order_by_token( $service_id, $unique_token, $api_key_hash );
			
			if ( $order_id ) {
				$found_order = $order_id;
				$found_provider = $provider;
				break;
			}
		}
		
		if ( ! $found_order || ! $found_provider ) {
			return new WP_Error( 'order_not_found', 'Order not found for this token, or API key mismatch', array( 'status' => 404 ) );
		}
		
		$product_ids = array();
		if ( method_exists( $found_provider, 'get_all_eligible_product_ids' ) ) {
			$product_ids = $found_provider->get_all_eligible_product_ids( $order_id );
		} else {
			$product_ids = $found_provider->get_product_ids( $order_id );
		}
		
		if ( empty( $product_ids ) ) {
			return new WP_Error( 'no_items', 'No eligible items found in order', array( 'status' => 400 ) );
		}
		
		$items = array();
		foreach ( $product_ids as $product_id ) {
			$items[] = array( 'product_id' => $product_id );
		}
		
		if ( empty( $items ) ) {
			return new WP_Error( 'no_items', 'No items found in order', array( 'status' => 400 ) );
		}

		$published_count = 0;
		$errors = array();
		$publishing_mode = 'direct';
		$published_product_ids = array();
		
		$customer_info = $found_provider->get_customer_info( $order_id );
		$customer_email = $customer_info['email'];
		$customer_name = $customer_info['name'];

		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? $item['product_id'] : ( is_object( $item ) ? $item->get_product_id() : 0 );
			
			if ( ! $product_id ) {
				continue;
			}

			$existing_reviews = get_comments( array(
				'post_id' => $product_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to check for duplicate reviews by unique token
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => '_trustscript_review_token',
						'value' => $unique_token,
					),
					array(
						'key' => '_trustscript_api_key_hash',
						'value' => $api_key_hash,
					),
				),
				'count' => true,
			) );

			if ( $existing_reviews > 0 ) {
				continue;
			}

			$review_data = array(
				'product_id'     => $product_id,
				'order_id'       => $order_id,
				'customer_name'  => $customer_name,
				'customer_email' => $customer_email,
				'review_text'    => $review_text,
				'rating'         => $rating,
				'unique_token'   => $unique_token,
				'user_id'        => 0,
			);

			$comment_data = array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => $review_data['customer_name'],
				'comment_author_email' => $review_data['customer_email'],
				'comment_content'      => $review_data['review_text'],
				'comment_type'         => 'review',
				'comment_parent'       => 0,
				'user_id'              => $review_data['user_id'] ?? 0,
				'comment_approved'     => $auto_publish ? 1 : 0,
			);

			$comment_id = wp_insert_comment( $comment_data );

			if ( ! $comment_id ) {
				$error_msg = 'Failed to insert comment for product #' . $product_id;
				$errors[] = $error_msg;
			} else {
				add_comment_meta( $comment_id, 'rating', $rating );
				add_comment_meta( $comment_id, '_trustscript_rating', $rating );
				add_comment_meta( $comment_id, 'verified', 1 );
				add_comment_meta( $comment_id, '_trustscript_review_token', $unique_token );
				add_comment_meta( $comment_id, '_trustscript_order_id', $order_id );
				add_comment_meta( $comment_id, '_trustscript_approved_at', current_time( 'mysql' ) );
				add_comment_meta( $comment_id, '_trustscript_publishing_mode', $publishing_mode );
				add_comment_meta( $comment_id, '_trustscript_api_key_hash', $api_key_hash );
				
				if ( isset( $data['verificationHash'] ) && ! empty( $data['verificationHash'] ) ) {
					add_comment_meta( $comment_id, '_trustscript_verification_hash', sanitize_text_field( $data['verificationHash'] ) );
				}
				
				if ( ! empty( $media_urls ) ) {
					add_comment_meta( $comment_id, '_trustscript_media_urls', wp_json_encode( $media_urls ) );
				}

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}

				$published_count++;
				$published_product_ids[] = $product_id;
			}
		}

		$registry_service_id = null;
		foreach ( $active_providers as $sid => $prov ) {
			if ( $prov === $found_provider ) {
				$registry_service_id = $sid;
				break;
			}
		}
		if ( $registry_service_id ) {
			TrustScript_Order_Registry::mark_published( $registry_service_id, $order_id, null, null, 'webhook' );
		}

		$found_provider->save_order_meta( $order_id, '_trustscript_review_published', 'yes' );
		$found_provider->save_order_meta( $order_id, '_trustscript_review_published_at', current_time( 'mysql' ) );
		$found_provider->save_order_meta( $order_id, '_trustscript_publishing_mode', $publishing_mode );

		// After processing all items, flush caches for all affected products to ensure the new reviews are visible on the frontend immediately.
		if ( ! empty( $published_product_ids ) ) {
			foreach ( array_unique( $published_product_ids ) as $prod_id ) {
				if ( class_exists( 'TrustScript_Review_Renderer' ) ) {
					TrustScript_Review_Renderer::flush_stats_cache( $prod_id );
				}
				// Also clear WooCommerce product rating cache if available
				if ( class_exists( 'TrustScript_Shop_Display' ) && method_exists( 'TrustScript_Shop_Display', 'clear_product_rating_cache' ) ) {
					TrustScript_Shop_Display::clear_product_rating_cache( $prod_id );
				}
			}
		}

		if ( $published_count > 0 ) {
			$status_text = $auto_publish ? 'published' : 'submitted for moderation';

			$response = array(
				'success' => true,
				'message' => $published_count . ' review(s) ' . $status_text . ' successfully',
				'published_count' => $published_count,
				'auto_published' => $auto_publish,
				'order_id' => $order_id,
				'publishing_mode' => $publishing_mode,
			);

			if ( ! empty( $errors ) ) {
				$response['partial_errors'] = $errors;
			}
			return $response;
		} else {
			return new WP_Error(
				'no_reviews_created',
				'No reviews were created. Errors: ' . implode( ', ', $errors ),
				array( 'status' => 500 )
			);
		}
	}
	
	/**
	 * Find an order by looking up the product token in order item meta, then retrieving 
	 * the associated order and product IDs.
	 *
	 * @param string $product_token SHA256 hash stored in _trustscript_product_token item meta.
	 * @return array|false [ order_id, item_id, product_id ] or false if not found.
	 */
	private function find_order_by_product_token( $product_token ) {
		global $wpdb;

		$cache_key = 'trustscript_product_token_invalid_' . substr( hash( 'sha256', $product_token ), 0, 16 );
		if ( get_transient( $cache_key ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_item_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
			 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			'_trustscript_product_token',
			$product_token
		) );

		if ( ! $order_item_id ) {
			set_transient( $cache_key, true, 3600 );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT oi.order_id, oim.meta_value AS product_id
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				  ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			 WHERE oi.order_item_id = %d LIMIT 1",
			$order_item_id
		) );

		if ( ! $row || ! $row->order_id ) {
			// Cache negative result to prevent repeated database hits for invalid tokens.
			set_transient( $cache_key, true, 3600 );
			return false;
		}

		// Return order ID, item ID, and product ID as integers for consistency. 
		return array( intval( $row->order_id ), intval( $order_item_id ), intval( $row->product_id ) );
	}

	private function find_order_by_token( $service_id, $unique_token, $api_key_hash ) {
		
		$service_manager = TrustScript_Service_Manager::get_instance();
		$provider = $service_manager->get_provider( $service_id );

		if ( ! $provider ) {
			return false;
		}

		$order_id = $provider->find_order_by_token( $unique_token );

		if ( $order_id ) {
			
			if ( $service_id === 'woocommerce' && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->update_meta_data( '_trustscript_api_key_hash', $api_key_hash );
					$order->save();
				}
			} else {
				$provider->save_order_meta( $order_id, '_trustscript_api_key_hash', $api_key_hash );
			}

			return $order_id;
		}

		return false;
	}

	
	public function add_verification_badge_inline( $comment ) {
		$verification_hash = get_comment_meta( $comment->comment_ID, '_trustscript_verification_hash', true );
		
		if ( empty( $verification_hash ) ) {
			return;
		}
		
		$base_url = trustscript_get_base_url();
		$verify_url = $base_url . '/verify-review';
		
		?>
		<span class="trustscript-verification-badge-inline">
			<button type="button"
				class="trustscript-verify-link"
				title="<?php esc_attr_e( 'Click to view verification hash', 'trustscript' ); ?>"
				data-hash="<?php echo esc_attr( $verification_hash ); ?>"
				data-verify-url="<?php echo esc_url( $verify_url ); ?>">
				<svg class="trustscript-shield-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
					<path d="m9 12 2 2 4-4"/>
				</svg>
				<span class="trustscript-verify-text"><?php esc_html_e( 'Verified Purchase', 'trustscript' ); ?></span>
			</button>
		</span>
		<?php
	}

	/**
	 * Download external media (e.g. from TrustScript) and save locally in 
	 * the WordPress uploads directory, returning the local URL.
	 *
	 * @param string $remote_media_url TrustScript External media URL.
	 * @return string Local WordPress URL, or original URL on failure.
	 */
	private function localise_media_url( $remote_media_url ) {
		$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $remote_media_url, PHP_URL_HOST );

		if ( $url_host === $site_host ) {
			return $remote_media_url;
		}

		$upload_dir  = wp_upload_dir();
		$target_dir  = $upload_dir['basedir'] . '/trustscript-reviews/';
		$target_url  = $upload_dir['baseurl'] . '/trustscript-reviews/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		// Only allow certain media types for security and compatibility reasons.
		$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'webp', 'mp4' );
		$ext = strtolower( pathinfo( wp_parse_url( $remote_media_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_extensions, true ) ) {
			return $remote_media_url;
		}

		// Generate a unique filename based on the URL hash to avoid collisions and ensure consistent naming.
		$filename  = sanitize_file_name( hash( 'sha256', $remote_media_url ) . '.' . $ext );
		$dest_path = $target_dir . $filename;
		$dest_url  = $target_url . $filename;

		if ( file_exists( $dest_path ) ) {
			return $dest_url;
		}

		$response = wp_remote_get( $remote_media_url, array(
			'timeout'   => 30,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) {
			return $remote_media_url;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			return $remote_media_url;
		}

		$body = wp_remote_retrieve_body( $response );

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$written = $wp_filesystem->put_contents( $dest_path, $body, FS_CHMOD_FILE );

		if ( false === $written ) {
			return $remote_media_url;
		}

		return $dest_url;
	}

	/**
	 * Render review media gallery for WooCommerce native reviews.
	 *
	 * @param WP_Comment $comment Comment object passed by WooCommerce.
	 */
	public function render_review_media( $comment ) {
		$media_urls_json = get_comment_meta( $comment->comment_ID, '_trustscript_media_urls', true );
		if ( empty( $media_urls_json ) ) {
			return;
		}

		$media_urls = json_decode( $media_urls_json, true );
		if ( ! is_array( $media_urls ) || empty( $media_urls ) ) {
			return;
		}

		echo '<div class="trustscript-review-media-gallery">';
		foreach ( $media_urls as $url ) {
			$url = esc_url( $url );
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( preg_match( '/\.(jpg|jpeg|png|webp)$/i', $path ) ) {
				echo '<div class="trustscript-media-item">';
				echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Review photo', 'trustscript' ) . '" class="trustscript-review-photo">';
				echo '</div>';
			} elseif ( preg_match( '/\.(mp4)$/i', $path ) ) {
				echo '<div class="trustscript-media-item">';
				echo '<video class="trustscript-review-video" controls preload="metadata">';
				echo '<source src="' . esc_url( $url ) . '" type="video/mp4">';
				echo esc_html__( 'Your browser does not support the video tag.', 'trustscript' );
				echo '</video>';
				echo '</div>';
			}
		}
		echo '</div>';
	}
	
	public function add_trustscript_comment_class( $classes, $class, $comment_id, $comment ) {
		$verification_hash = get_comment_meta( $comment_id, '_trustscript_verification_hash', true );
		
		if ( ! empty( $verification_hash ) ) {
			$classes[] = 'trustscript-hide-wc-verified';
		}
		
		return $classes;
	}


}