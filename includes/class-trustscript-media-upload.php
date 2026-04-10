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

	public function verify_request($request) {
		$api_key = $request->get_header( 'Authorization' );
		$site_url = $request->get_header( 'X-Site-URL' );
		$review_token = $request->get_param( 'review_token' );
		
		if ( empty( $api_key ) && empty( $site_url ) ) {
			if ( ! empty( $review_token ) && preg_match( '/^[a-z0-9\-]{32,}$/i', $review_token ) ) {
				// Verify the token actually exists in the database before allowing upload.
				// Note: Current token lookup only searches WooCommerce orders. MemberPress customers must use API key authentication.
				if ( class_exists( 'TrustScript_Plugin_Admin' ) ) {
					$order = TrustScript_Plugin_Admin::find_order_by_review_token( $review_token );
					if ( $order ) {
						return true;
					}
				}
				return new WP_Error( 'invalid_token', 'Review token not recognized', array( 'status' => 401 ) );
			}
			return new WP_Error( 'unauthorized', 'Missing authentication headers', array( 'status' => 401 ) );
		}
		
		if ( ! $api_key || ! $site_url ) {
			return new WP_Error( 'unauthorized', 'Missing authentication headers', array( 'status' => 401 ) );
		}

		if ( strpos( $api_key, 'Bearer ' ) === 0 ) {
			$api_key = substr( $api_key, 7 );
		}

		$rate_limit_key = 'trustscript_media_limit_' . hash( 'sha256', $api_key );
		$request_count = intval( get_transient( $rate_limit_key ) );
		
		// phpcs:ignore WordPress.Security.EscapedData.OutputNotEscaped -- Rate limiting is best-effort; transient reads are not atomic and can allow burst in high concurrency scenarios, but acceptable per WordPress.org standards
		if ( $request_count >= 50 ) {
			return new WP_Error( 'rate_limited', 'Too many uploads. Rate limit: 50 per minute', array( 'status' => 429 ) );
		}
		
		set_transient( $rate_limit_key, $request_count + 1, 60 );

		$stored_api_key = get_option( 'trustscript_api_key', '' );
		
		if ( empty( $stored_api_key ) ) {
			return new WP_Error( 'unauthorized', 'No API key configured', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $stored_api_key, $api_key ) ) {
			return new WP_Error( 'unauthorized', 'Invalid API key', array( 'status' => 401 ) );
		}

		$current_site_url = get_site_url();
		if ( ! hash_equals( $current_site_url, $site_url ) ) {
			return new WP_Error( 'domain_mismatch', 'Domain mismatch', array( 'status' => 401 ) );
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
}