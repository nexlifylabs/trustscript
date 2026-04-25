<?php
/**
 * Webhook handler for TrustScript requests.
 *
 * Processes review publishing, email resending, and customer opt-out actions.
 * Implements API key authentication, rate limiting, and token validation.
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
			add_action( 'woocommerce_review_comment_text', array( $this, 'render_review_media' ), 20 );
			add_filter( 'comment_class', array( $this, 'add_trustscript_comment_class' ), 10, 4 );
		}
	}

	public function register_routes() {
		register_rest_route( 'trustscript/v1', '/resend-email', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_resend_email' ),
			'permission_callback' => array( $this, 'verify_request' ),
			'args'                => array(
				'uniqueToken'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'productToken' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email_subject' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'email_html' => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
			),
		) );
		
		register_rest_route( 'trustscript/v1', '/publish-review', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_publish_review' ),
			'permission_callback' => array( $this, 'verify_request' ),
			'args'                => array(
				'uniqueToken'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'productToken' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'reviewText' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'rating' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'verificationHash' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Optional endpoint for handling opt-out requests, allowing customers to opt out. 
		register_rest_route( 'trustscript/v1', '/opt-out', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_opt_out' ),
			'permission_callback' => array( $this, 'verify_opt_out_request' ),
			'args'                => array(
				'emailHash' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $value ) {
						return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/', $value );
					},
				),
			),
		) );
	}

	/**
	 * Helper function to retrieve order meta for both HPOS and legacy orders, 
	 * ensuring compatibility across different WooCommerce versions.
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

	/**
	 * Insert a review comment with spam check bypass filter.
	 *
	 * Applies the trustscript_bypass_spam_check filter to allow bypassing spam/flood checks
	 * for webhook reviews. Defaults to true (bypasses checks) because these are authenticated
	 * webhook requests for verified purchases, not public user submissions.
	 *
	 * @param array $comment_data The comment data array.
	 * @return int|false Comment ID on success, false on failure.
	 */
	private function insert_review_comment( $comment_data ) {
		// Allow bypassing spam checks for webhook reviews, since these are authenticated requests for verified purchases.
		$bypass_spam = apply_filters( 'trustscript_bypass_spam_check', true, $comment_data );

		if ( $bypass_spam ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.wp_insert_comment
			return wp_insert_comment( $comment_data );
		} else {
			return wp_new_comment( wp_filter_comment( $comment_data ) );
		}
	}

	public function verify_request( $request ) {
		$api_key   = $request->get_header( 'X-API-Key' );
		$site_url  = $request->get_header( 'X-Site-URL' );
		$timestamp = $request->get_header( 'X-Webhook-Timestamp' );
		$signature = $request->get_header( 'X-Webhook-Signature' );

		if ( ! $api_key || ! $site_url ) {
			return new WP_Error(
				'unauthorized',
				__( 'Missing authentication headers (X-API-Key and X-Site-URL required)', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		if ( str_starts_with( $api_key, 'Bearer ' ) ) {
			$api_key = substr( $api_key, 7 );
		}

		$rate_limit_key = 'trustscript_rate_limit_' . hash( 'sha256', $api_key );

		if ( false === get_transient( $rate_limit_key ) ) {
			set_transient( $rate_limit_key, 1, 60 );
		} else {
			$request_count = intval( get_transient( $rate_limit_key ) );
			if ( $request_count >= 100 ) {
				return new WP_Error(
					'rate_limited',
					__( 'Too many requests. Rate limit: 100 requests per minute', 'trustscript' ),
					array( 'status' => 429 )
				);
			}
			set_transient( $rate_limit_key, $request_count + 1, 60 );
		}

		$stored_api_key = trustscript_get_api_key();

		if ( empty( $stored_api_key ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'No API key configured', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $stored_api_key, $api_key ) ) {
			return new WP_Error(
				'unauthorized',
				__( 'Invalid API key', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		$expires_at = get_option( 'trustscript_api_key_expires_at', null );
		if ( ! empty( $expires_at ) && time() > strtotime( $expires_at ) ) {
			return new WP_Error(
				'api_key_expired',
				__( 'API key has expired. Please generate a new API key in TrustScript dashboard.', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( get_site_url(), $site_url ) ) {
			return new WP_Error(
				'domain_mismatch',
				__( 'Domain mismatch: API key is not valid for this domain', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		$webhook_secret       = trustscript_get_webhook_secret();
		$signature_validation = trustscript_verify_webhook_signature( $request, $webhook_secret );

		if ( is_wp_error( $signature_validation ) ) {
			return $signature_validation;
		}

		return true;
	}

	public function handle_resend_email( $request ) {
		$data = $request->get_json_params();

		if ( empty( $data['uniqueToken'] ) && empty( $data['productToken'] ) ) {
			return new WP_Error(
				'missing_token',
				__( 'Missing uniqueToken or productToken', 'trustscript' ),
				array( 'status' => 400 )
			);
		}

		$has_unique_token  = ! empty( $data['uniqueToken'] ) && is_string( $data['uniqueToken'] );
		$has_product_token = ! empty( $data['productToken'] ) && is_string( $data['productToken'] );

		$api_key      = $request->get_header( 'X-API-Key' );
		$api_key_hash = hash( 'sha256', (string) $api_key );

		$review_data = array(
			'uniqueToken'   => isset( $data['uniqueToken'] )   ? sanitize_text_field( wp_unslash( $data['uniqueToken'] ) )   : '',
			'productToken'  => isset( $data['productToken'] )  ? sanitize_text_field( wp_unslash( $data['productToken'] ) )  : '',
			'id'            => isset( $data['id'] )            ? sanitize_text_field( wp_unslash( $data['id'] ) )            : '',
			'approval_url'  => isset( $data['approval_url'] )  ? esc_url_raw( $data['approval_url'] )                        : '',
			'email_subject' => isset( $data['email_subject'] ) ? sanitize_text_field( wp_unslash( $data['email_subject'] ) ) : '',
			'email_html'    => isset( $data['email_html'] )    ? wp_kses_post( $data['email_html'] )                         : '',
		);

		$service_manager  = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();

		$found_order    = null;
		$found_provider = null;
		$customer_email = null;

		if ( $has_product_token ) {
			$product_token = sanitize_text_field( $data['productToken'] );

			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $product_token ) ) {
				return new WP_Error(
					'invalid_token',
					__( 'Invalid productToken format', 'trustscript' ),
					array( 'status' => 400 )
				);
			}

			if ( TrustScript_Order_Registry::is_product_token_published( $product_token ) ) {
				return new WP_Error( 'token_already_used', __( 'This product token has already been used to publish a review', 'trustscript' ), array( 'status' => 409 ) );
			}

			$lookup = $this->find_order_by_product_token( $product_token );

			if ( $lookup ) {
				list( $order_id, $item_id, $product_id ) = $lookup;

				$service_type = $this->get_hpos_order_meta( $order_id, '_trustscript_service_type' );

				foreach ( $active_providers as $service_id => $provider ) {
					if ( ! empty( $service_type ) && $service_type === $service_id ) {
						$found_order    = $order_id;
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
				return new WP_Error(
					'invalid_token',
					__( 'Invalid uniqueToken format', 'trustscript' ),
					array( 'status' => 400 )
				);
			}

			foreach ( $active_providers as $service_id => $provider ) {
				$order_id = $this->find_order_by_token( $service_id, $unique_token, $api_key_hash );

				if ( $order_id ) {
					$found_order    = $order_id;
					$found_provider = $provider;
					$customer_email = $found_provider->get_customer_email( $order_id );
					break;
				}
			}
		}

		if ( ! $found_order || ! $found_provider ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found for this token, or API key mismatch', 'trustscript' ),
				array( 'status' => 404 )
			);
		}

		$sent = $found_provider->send_review_email( $found_order, $review_data );

		if ( ! $sent ) {
			return new WP_Error(
				'email_failed',
				__( 'Failed to send email', 'trustscript' ),
				array( 'status' => 500 )
			);
		}

		$email_hash = hash( 'sha256', strtolower( trim( (string) $customer_email ) ) );

		return rest_ensure_response( array(
			'success'   => true,
			'message'   => __( 'Reminder email sent successfully', 'trustscript' ),
			'emailHash' => substr( $email_hash, 0, 16 ),
			'service'   => $found_provider->get_service_name(),
		) );
	}

	public function handle_publish_review( $request ) {
		$data = $request->get_json_params();
		$has_unique_token  = ! empty( $data['uniqueToken'] ) && is_string( $data['uniqueToken'] );
		$has_product_token = ! empty( $data['productToken'] ) && is_string( $data['productToken'] );

		if ( ! $has_unique_token && ! $has_product_token ) {
			return new WP_Error( 'missing_token', __( 'Missing uniqueToken or productToken', 'trustscript' ), array( 'status' => 400 ) );
		}

		if ( ! isset( $data['reviewText'] ) ) {
			return new WP_Error( 'missing_review', __( 'Missing reviewText', 'trustscript' ), array( 'status' => 400 ) );
		}

		$api_key      = $request->get_header( 'X-API-Key' );
		$api_key_hash = hash( 'sha256', (string) $api_key );

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
				return new WP_Error( 'invalid_token', __( 'Invalid productToken format', 'trustscript' ), array( 'status' => 400 ) );
			}

			if ( TrustScript_Order_Registry::is_product_token_published( $product_token ) ) {
				return new WP_Error( 'token_already_used', __( 'This product token has already been used to publish a review', 'trustscript' ), array( 'status' => 409 ) );
			}

			$lookup = $this->find_order_by_product_token( $product_token );

			if ( ! $lookup ) {
				return new WP_Error( 'order_not_found', __( 'No order found for this product token', 'trustscript' ), array( 'status' => 404 ) );
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
				return new WP_Error( 'provider_not_found', __( 'WooCommerce provider not active', 'trustscript' ), array( 'status' => 500 ) );
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

			$comment_id = $this->insert_review_comment( $comment_data );

			if ( ! $comment_id ) {
				return new WP_Error( 'comment_failed', __( 'Failed to insert review comment for product #', 'trustscript' ) . $product_id, array( 'status' => 500 ) );
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

			// Clear any relevant caches to ensure the new review appears on the frontend immediately.
			// This includes WooCommerce product review counts and any custom caches used by TrustScript.
			if ( class_exists( 'TrustScript_Review_Renderer' ) ) {
				TrustScript_Review_Renderer::flush_stats_cache( $product_id );
			}
			if ( class_exists( 'TrustScript_Shop_Display' ) && method_exists( 'TrustScript_Shop_Display', 'clear_product_rating_cache' ) ) {
				TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
			}

			$status_text = $auto_publish ? __( 'published', 'trustscript' ) : __( 'submitted for moderation', 'trustscript' );
			return rest_ensure_response( array(
				'success'         => true,
				/* translators: %1$d: number of reviews published, %2$s: status text (published or submitted for moderation) */
				'message'         => sprintf( __( '%1$d review(s) %2$s successfully', 'trustscript' ), 1, $status_text ),
				'published_count' => 1,
				'auto_published'  => $auto_publish,
				'order_id'        => $order_id,
				'product_id'      => $product_id,
				'publishing_mode' => $publishing_mode,
			) );
		}

		$unique_token = sanitize_text_field( $data['uniqueToken'] );

		if ( ! preg_match( '/^[a-z0-9\-]{32,}$/i', $unique_token ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid token format', 'trustscript' ), array( 'status' => 400 ) );
		}

		// Check cache for duplicate token to avoid redundant database queries on retries.
		// Cache key includes API key hash to ensure separation between different API keys.
		$cache_key     = 'trustscript_duplicate_check_product_' . hash( 'sha256', $unique_token . '|' . $api_key_hash );
		$cached_result = get_transient( $cache_key );

		if ( false !== $cached_result ) {
			if ( 'exists' === $cached_result ) {
				return new WP_Error( 'token_already_used', __( 'This token has already been used to publish a review', 'trustscript' ), array( 'status' => 409 ) );
			}
		} else {
			// Query database for existing review with this token.
			$existing_review_count = get_comments( array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to check for duplicate reviews by unique token; results are transient-cached to minimize repeated queries
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
				// Cache positive result for 24 hours (token exists and won't change)
				set_transient( $cache_key, 'exists', DAY_IN_SECONDS );
				return new WP_Error( 'token_already_used', __( 'This token has already been used to publish a review', 'trustscript' ), array( 'status' => 409 ) );
			}
			// Cache negative result for 6 hours.
			set_transient( $cache_key, 'not_exists', 6 * HOUR_IN_SECONDS );
		}

		$service_manager  = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();

		$found_order    = null;
		$found_provider = null;
		$order_id       = null;

		foreach ( $active_providers as $service_id => $provider ) {
			$order_id = $this->find_order_by_token( $service_id, $unique_token, $api_key_hash );

			if ( $order_id ) {
				$found_order    = $order_id;
				$found_provider = $provider;
				break;
			}
		}

		if ( ! $found_order || ! $found_provider ) {
			return new WP_Error( 'order_not_found', __( 'Order not found for this token, or API key mismatch', 'trustscript' ), array( 'status' => 404 ) );
		}

		$product_ids = array();
		if ( method_exists( $found_provider, 'get_all_eligible_product_ids' ) ) {
			$product_ids = $found_provider->get_all_eligible_product_ids( $order_id );
		} else {
			$product_ids = $found_provider->get_product_ids( $order_id );
		}

		if ( empty( $product_ids ) ) {
			return new WP_Error( 'no_items', __( 'No eligible items found in order', 'trustscript' ), array( 'status' => 400 ) );
		}

		$items = array();
		foreach ( $product_ids as $product_id ) {
			$items[] = array( 'product_id' => $product_id );
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'no_items', __( 'No items found in order', 'trustscript' ), array( 'status' => 400 ) );
		}

		$published_count       = 0;
		$errors                = array();
		$publishing_mode       = 'direct';
		$published_product_ids = array();

		$customer_info  = $found_provider->get_customer_info( $order_id );
		$customer_email = $customer_info['email'];
		$customer_name  = $customer_info['name'];

		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? $item['product_id'] : ( is_object( $item ) ? $item->get_product_id() : 0 );

			if ( ! $product_id ) {
				continue;
			}

			// Check cache for duplicate token + product combination to avoid redundant database queries on retries.
			// Cache key includes API key hash to ensure separation between different API keys.
			$product_cache_key     = 'trustscript_duplicate_check_product_' . hash( 'sha256', $unique_token . '|' . $api_key_hash . '|' . $product_id );
			$cached_product_result = get_transient( $product_cache_key );

			if ( false !== $cached_product_result ) {
				if ( 'exists' === $cached_product_result ) {
					// Skip if token already used for this product.
					continue;
				}
			} else {
				// Query database for existing review with this token and product.
				// Note: _trustscript_review_token and _trustscript_api_key_hash should be indexed for optimal performance.
				$existing_reviews = get_comments( array(
					'post_id' => $product_id,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to check for duplicate reviews by unique token; results are transient-cached to minimize repeated queries
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

				if ( $existing_reviews > 0 ) {
					// Cache positive result for 24 hours
					set_transient( $product_cache_key, 'exists', DAY_IN_SECONDS );
					continue;
				}
				// Cache negative result for 6 hours
				set_transient( $product_cache_key, 'not_exists', 6 * HOUR_IN_SECONDS );
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

			$comment_id = $this->insert_review_comment( $comment_data );

			if ( ! $comment_id ) {
				$errors[] = 'Failed to insert comment for product #' . $product_id;
			} else {
				add_comment_meta( $comment_id, 'rating',                       $rating );
				add_comment_meta( $comment_id, '_trustscript_rating',          $rating );
				add_comment_meta( $comment_id, 'verified',                     1 );
				add_comment_meta( $comment_id, '_trustscript_review_token',    $unique_token );
				add_comment_meta( $comment_id, '_trustscript_order_id',        $order_id );
				add_comment_meta( $comment_id, '_trustscript_approved_at',     current_time( 'mysql' ) );
				add_comment_meta( $comment_id, '_trustscript_publishing_mode', $publishing_mode );
				add_comment_meta( $comment_id, '_trustscript_api_key_hash',    $api_key_hash );

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
				// Cache result to prevent redundant database queries on subsequent webhook retries for the same product.
				set_transient( $product_cache_key, 'exists', DAY_IN_SECONDS );
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

		// Flush caches for all affected products to ensure new reviews appear on frontend immediately.
		if ( ! empty( $published_product_ids ) ) {
			foreach ( array_unique( $published_product_ids ) as $prod_id ) {
				if ( class_exists( 'TrustScript_Review_Renderer' ) ) {
					TrustScript_Review_Renderer::flush_stats_cache( $prod_id );
				}
				// Clear product rating cache if the method exists, to ensure updated ratings are shown after review publication.
				if ( class_exists( 'TrustScript_Shop_Display' ) && method_exists( 'TrustScript_Shop_Display', 'clear_product_rating_cache' ) ) {
					TrustScript_Shop_Display::clear_product_rating_cache( $prod_id );
				}
			}
		}

		if ( $published_count > 0 ) {
			$status_text = $auto_publish ? __( 'published', 'trustscript' ) : __( 'submitted for moderation', 'trustscript' );
			$response    = array(
				'success'         => true,
				/* translators: %1$d: number of reviews published, %2$s: status text (published or submitted for moderation) */
				'message'         => sprintf( __( '%1$d review(s) %2$s successfully', 'trustscript' ), $published_count, $status_text ),
				'published_count' => $published_count,
				'auto_published'  => $auto_publish,
				'order_id'        => $order_id,
				'publishing_mode' => $publishing_mode,
			);

			if ( ! empty( $errors ) ) {
				$response['partial_errors'] = $errors;
			}
			return rest_ensure_response( $response );
		}

		// All products already published for this token; return 409 Conflict to ensure
		// webhook retries are treated as idempotent and do not create duplicate reviews.
		if ( empty( $errors ) ) {
			// Cache result to prevent redundant database queries on subsequent webhook retries.
			set_transient( $cache_key, 'exists', DAY_IN_SECONDS );
			return new WP_Error(
				'token_already_used',
				__( 'All reviews for this token have already been published', 'trustscript' ),
				array( 'status' => 409 )
			);
		}

		return new WP_Error(
			'no_reviews_created',
			/* translators: %s: list of error messages */
			sprintf( __( 'No reviews were created. Errors: %s', 'trustscript' ), implode( ', ', $errors ) ),
			array( 'status' => 500 )
		);
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

		$cache_key   = 'trustscript_pt_lookup_' . substr( hash( 'sha256', $product_token ), 0, 16 );
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			if ( 'not_found' === $cached_data ) {
				return false;
			}
			return $cached_data;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient caching implemented for both positive and negative results.
		$order_item_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
			 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			'_trustscript_product_token',
			$product_token
		) );

		if ( ! $order_item_id ) {
			set_transient( $cache_key, 'not_found', 3600 );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient caching implemented for both positive and negative results.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT oi.order_id, oim.meta_value AS product_id
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				  ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			 WHERE oi.order_item_id = %d LIMIT 1",
			$order_item_id
		) );

		if ( ! $row || ! $row->order_id ) {
			set_transient( $cache_key, 'not_found', 3600 );
			return false;
		}

		$result = array( intval( $row->order_id ), intval( $order_item_id ), intval( $row->product_id ) );
		
		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
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

		$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'webp', 'mp4' );
		$ext = strtolower( pathinfo( wp_parse_url( $remote_media_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_extensions, true ) ) {
			return $remote_media_url;
		}

		$filename  = sanitize_file_name( hash( 'sha256', $remote_media_url ) . '.' . $ext );
		$dest_path = $target_dir . $filename;
		$dest_url  = $target_url . $filename;

		if ( file_exists( $dest_path ) ) {
			return $dest_url;
		}

		if ( ! wp_http_validate_url( $remote_media_url ) ) {
			return $remote_media_url;
		}

		$size_limit = in_array( $ext, array( 'mp4' ), true ) ? 50 * MB_IN_BYTES : 5 * MB_IN_BYTES;
		$response = wp_remote_get( $remote_media_url, array(
			'timeout'             => 60,
			'sslverify'           => true,
			'limit_response_size' => $size_limit,
		) );

		if ( is_wp_error( $response ) ) {
			return $remote_media_url;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			return $remote_media_url;
		}

		// Validate content type against allowed MIME types to prevent downloading unexpected file types.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'video/mp4' );
		$mime_valid = false;
		foreach ( $allowed_mimes as $allowed_mime ) {
			if ( strpos( $content_type, $allowed_mime ) !== false ) {
				$mime_valid = true;
				break;
			}
		}
		if ( ! $mime_valid ) {
			return $remote_media_url;
		}

		$body = wp_remote_retrieve_body( $response );

		// Verify the actual file content matches the expected MIME type based on the extension.
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$detected_mime = finfo_buffer( $finfo, $body );
				finfo_close( $finfo );
				if ( ! in_array( $detected_mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
					return $remote_media_url;
				}
			}
		}

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




	/**
	 * Verify incoming opt-out requests and validate authentication headers.
	 *
	 * Validates the X-TrustScript-Api-Key and X-Site-URL headers, checks API key validity,
	 * verifies the webhook signature, and enforces rate limiting.
	 *
	 * @param WP_REST_Request $request The incoming REST request object.
	 * @return true|WP_Error True if valid, WP_Error on validation failure.
	 */
	public function verify_opt_out_request( $request ) {
		$api_key  = $request->get_header( 'X-TrustScript-Api-Key' );
		$site_url = $request->get_header( 'X-Site-URL' );

		if ( ! $api_key || ! $site_url ) {
			return new WP_Error( 'unauthorized', __( 'Missing X-TrustScript-Api-Key or X-Site-URL header', 'trustscript' ), array( 'status' => 401 ) );
		}

		$rate_limit_key = 'trustscript_rate_limit_opt_out_' . hash( 'sha256', $api_key );
		
		// Implement simple transient-based rate limiting: max 100 requests per minute per API key.
		if ( false === get_transient( $rate_limit_key ) ) {
			set_transient( $rate_limit_key, 1, 60 );
		} else {
			$request_count = intval( get_transient( $rate_limit_key ) );
			if ( $request_count >= 100 ) {
				return new WP_Error( 'rate_limited', __( 'Too many requests. Rate limit: 100 requests per minute', 'trustscript' ), array( 'status' => 429 ) );
			}
			set_transient( $rate_limit_key, $request_count + 1, 60 );
		}

		$stored_key = trustscript_get_api_key();

		if ( empty( $stored_key ) ) {
			return new WP_Error( 'unauthorized', __( 'No API key configured', 'trustscript' ), array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $stored_key, $api_key ) ) {
			return new WP_Error( 'unauthorized', __( 'Invalid API key', 'trustscript' ), array( 'status' => 401 ) );
		}

		$trustscript_api_expires_at = get_option( 'trustscript_api_key_expires_at', null );
		if ( ! empty( $trustscript_api_expires_at ) ) {
			$expires_timestamp = strtotime( $trustscript_api_expires_at );
			$current_timestamp = time();
			
			if ( $current_timestamp > $expires_timestamp ) {
				return new WP_Error( 'api_key_expired', __( 'API key has expired. Please generate a new API key in TrustScript dashboard.', 'trustscript' ), array( 'status' => 401 ) );
			}
		}

		if ( ! hash_equals( get_site_url(), $site_url ) ) {
			return new WP_Error( 'domain_mismatch', __( 'X-Site-URL does not match this site', 'trustscript' ), array( 'status' => 401 ) );
		}

		$webhook_secret = trustscript_get_webhook_secret();
		$signature_validation = trustscript_verify_webhook_signature( $request, $webhook_secret );
		if ( is_wp_error( $signature_validation ) ) {
			return $signature_validation;
		}

		return true;
	}

	/**
	 * Handle opt-out requests and record email hash opt-outs.
	 *
	 * Records opt-out preferences and backfills pending orders to ensure
	 * customers are excluded from review requests.
	 *
	 * @param WP_REST_Request $request The REST request containing the emailHash.
	 * @return WP_REST_Response|WP_Error Response with opt-out details or error.
	 */
	public function handle_opt_out( $request ) {
		$data       = $request->get_json_params();
		$email_hash = isset( $data['emailHash'] ) ? sanitize_text_field( $data['emailHash'] ) : '';

		if ( empty( $email_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $email_hash ) ) {
			return new WP_Error( 'invalid_hash', 'emailHash must be a 64-character lowercase hex string', array( 'status' => 400 ) );
		}

		$recorded   = TrustScript_Opt_Out::record_opt_out( $email_hash );
		$backfilled = TrustScript_Opt_Out::backfill_pending_orders( $email_hash );

		return rest_ensure_response( array(
			'success'    => true,
			'recorded'   => $recorded,
			'backfilled' => $backfilled,
		) );
	}

}