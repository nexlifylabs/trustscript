<?php
/**
 * Media Upload Handler
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class TrustScript_Media_Upload {
	
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes() {
		register_rest_route('trustscript/v1', '/upload-media', array(
			'methods' => 'POST',
			'callback' => array($this, 'handle_upload'),
			'permission_callback' => array($this, 'verify_request'),
		));
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

		$rate_limit_key = 'trustscript_media_limit_' . hash( 'sha256', $api_key );

		if ( false === get_transient( $rate_limit_key ) ) {
			set_transient( $rate_limit_key, 1, 60 );
		} else {
			$request_count = intval( get_transient( $rate_limit_key ) );
			// phpcs:ignore WordPress.Security.EscapedData.OutputNotEscaped
			if ( $request_count >= 50 ) {
				return new WP_Error(
					'rate_limited',
					__( 'Too many uploads. Rate limit: 50 per minute', 'trustscript' ),
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

	public function handle_upload($request) {
		$review_token = $request->get_param('review_token');
		
		if (empty($review_token)) {
			return new WP_Error('missing_token', 'Review token is required', array('status' => 400));
		}

		$review_token = sanitize_text_field($review_token);
		if ( ! preg_match( '/^[a-z0-9\-]{32,}$/i', $review_token ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token format', array( 'status' => 400 ) );
		}

		$api_key = $request->get_header( 'X-API-Key' );
		if ( str_starts_with( $api_key, 'Bearer ' ) ) {
			$api_key = substr( $api_key, 7 );
		}
		$api_key_hash = hash( 'sha256', $api_key );

		$token_valid = $this->verify_token_from_review_request( $review_token, $api_key_hash );
		if ( ! $token_valid ) {
			return new WP_Error(
				'invalid_token',
				__( 'Review token not found or not associated with this API key', 'trustscript' ),
				array( 'status' => 403 )
			);
		}

		$files = $_FILES; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST API endpoint, authentication handled via API key validation
		$uploaded_urls = array();

		$received_photo_count = 0;
		$received_video_count = 0;
		if ( isset( $files['photos'] ) ) {
			$received_photo_count = is_array( $files['photos']['name'] ) ? count( $files['photos']['name'] ) : 1;
		}
		if ( isset( $files['videos'] ) ) {
			$received_video_count = is_array( $files['videos']['name'] ) ? count( $files['videos']['name'] ) : 1;
		}
		
		$total_size = 0;
		$max_total_size = 50 * MB_IN_BYTES;

		$upload_dir = wp_upload_dir();
		$trustscript_dir = $upload_dir['basedir'] . '/trustscript-reviews';
		
		if (!file_exists($trustscript_dir)) {
			wp_mkdir_p($trustscript_dir);
		}

		if (isset($files['photos'])) {
			$photos = $files['photos'];
			
			if (is_array($photos['name'])) {
				$file_count = count($photos['name']);
				for ($i = 0; $i < $file_count; $i++) {
					if ($photos['error'][$i] === UPLOAD_ERR_OK) {
						$total_size += $photos['size'][$i];
						if ($total_size > $max_total_size) {
							return new WP_Error('upload_size_exceeded', 'Total upload size exceeds 50MB', array('status' => 413));
						}
						
						$file_data = array(
							'name' => $photos['name'][$i],
							'type' => $photos['type'][$i],
							'tmp_name' => $photos['tmp_name'][$i],
							'error' => $photos['error'][$i],
							'size' => $photos['size'][$i],
						);
						$uploaded_url = $this->upload_file($file_data, 'photo', $review_token);
						if ($uploaded_url) {
							$uploaded_urls[] = $uploaded_url;
						}
					}
				}
			} else {
				if ($photos['error'] === UPLOAD_ERR_OK) {
					$total_size += $photos['size'];
					if ($total_size > $max_total_size) {
						return new WP_Error('upload_size_exceeded', 'Total upload size exceeds 50MB', array('status' => 413));
					}
					
					$uploaded_url = $this->upload_file($photos, 'photo', $review_token);
					if ($uploaded_url) {
						$uploaded_urls[] = $uploaded_url;
					}
				}
			}
		}

		if (isset($files['videos'])) {
			$videos = $files['videos'];
			
			if (is_array($videos['name'])) {
				$file_count = count($videos['name']);
				for ($i = 0; $i < $file_count; $i++) {
					if ($videos['error'][$i] === UPLOAD_ERR_OK) {
						$total_size += $videos['size'][$i];
						if ($total_size > $max_total_size) {
							return new WP_Error('upload_size_exceeded', 'Total upload size exceeds 50MB', array('status' => 413));
						}
						
						$file_data = array(
							'name' => $videos['name'][$i],
							'type' => $videos['type'][$i],
							'tmp_name' => $videos['tmp_name'][$i],
							'error' => $videos['error'][$i],
							'size' => $videos['size'][$i],
						);
						$uploaded_url = $this->upload_file($file_data, 'video', $review_token);
						if ($uploaded_url) {
							$uploaded_urls[] = $uploaded_url;
						}
					}
				}
			} else {
				if ($videos['error'] === UPLOAD_ERR_OK) {
					$total_size += $videos['size'];
					if ($total_size > $max_total_size) {
						return new WP_Error('upload_size_exceeded', 'Total upload size exceeds 50MB', array('status' => 413));
					}
					
					$uploaded_url = $this->upload_file($videos, 'video', $review_token);
					if ($uploaded_url) {
						$uploaded_urls[] = $uploaded_url;
					}
				}
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'urls'    => $uploaded_urls,
			'count'   => count( $uploaded_urls ),
		) );
	}

	private function upload_file( $file, $type, $review_token ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$allowed_image_types = array( 'image/jpeg', 'image/png', 'image/webp' );
		$allowed_video_types = array( 'video/mp4', 'video/webm', 'video/quicktime' );

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $check['type'] ) ) {
			return false;
		}
		$file_type = $check['type'];

		if ( 'photo' === $type && ! in_array( $file_type, $allowed_image_types, true ) ) {
			return false;
		}

		if ( 'video' === $type && ! in_array( $file_type, $allowed_video_types, true ) ) {
			return false;
		}

		if ( 'photo' === $type && $file['size'] > 5 * MB_IN_BYTES ) {
			return false;
		}

		if ( 'video' === $type && $file['size'] > 50 * MB_IN_BYTES ) {
			return false;
		}

		$upload_overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
				'mp4'      => 'video/mp4',
				'webm'     => 'video/webm',
				'mov'      => 'video/quicktime',
			),
		);

		add_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );
		$uploaded_file = wp_handle_upload( $file, $upload_overrides );
		remove_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );

		if ( isset( $uploaded_file['error'] ) ) {
			return false;
		}

		if ( empty( $uploaded_file['file'] ) ) {
			return false;
		}

		$file_path = $uploaded_file['file'];
		$file_url  = $uploaded_file['url'];

		$wp_filetype = wp_check_filetype( basename( $file_path ), null );

		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => sprintf( 'TrustScript Review %s - %s', ucfirst( $type ), $review_token ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path );

		if ( ! $attach_id ) {
			return false;
		}

		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $file_url;
	}

	public function custom_upload_dir($dirs) {
		$dirs['subdir'] = '/trustscript-reviews';
		$dirs['path'] = $dirs['basedir'] . '/trustscript-reviews';
		$dirs['url'] = $dirs['baseurl'] . '/trustscript-reviews';
		return $dirs;
	}

	/**
	 * Verify that the review token exists in order meta
	 *
	 * @param string $token Review token (uniqueToken or productToken)
	 * @param string $api_key_hash SHA256 hash of API key
	 * @return bool True if token is valid, false otherwise
	 */
	private function verify_token_from_review_request( $token, $api_key_hash ) {
		// Check if token exists as uniqueToken in order meta
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_trustscript_review_token',
						'value' => $token,
					),
					array(
						'key'   => '_trustscript_api_key_hash',
						'value' => $api_key_hash,
					),
				),
			) );

			if ( ! empty( $orders ) ) {
				return true;
			}

			// Check if token exists as orderToken in order meta
			$orders = wc_get_orders( array(
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_trustscript_order_token',
						'value' => $token,
					),
					array(
						'key'   => '_trustscript_api_key_hash',
						'value' => $api_key_hash,
					),
				),
			) );

			if ( ! empty( $orders ) ) {
				return true;
			}
		}

		// Check if token exists as productToken in order item meta
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$order_item_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
			 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			'_trustscript_product_token',
			$token
		) );

		if ( $order_item_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$order_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items
				 WHERE order_item_id = %d LIMIT 1",
				$order_item_id
			) );

			if ( $order_id && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$stored_hash = $order->get_meta( '_trustscript_api_key_hash' );
					if ( hash_equals( $stored_hash, $api_key_hash ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}