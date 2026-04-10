<?php
	/**
	 * TrustScript Service Provider Abstract Class
	 * @package TrustScript
	 * @since 1.0.0
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	abstract class TrustScript_Service_Provider {

		/**
		 * Service identifier
		 *
		 * @var string
		 */
		protected $service_id;

		/**
		 * Human-readable service name
		 *
		 * @var string
		 */
		protected $service_name;

		/**
		 * Service icon for UI
		 *
		 * @var string
		 */
		protected $service_icon;

		/**
		 * Whether this service is currently active/installed
		 *
		 * @var bool
		 */
		protected $is_active = false;

		/**
		 * Last API error code from review request
		 *
		 * @var string|null 'quota' | 'rate_limit' | 'network' | 'api_error' | 'api_key_invalid' | 'missing_order_token' | null
		 */
		protected $last_api_error = null;

		/**
		 * Constructor
		 */
		public function __construct() {
			$this->is_active = $this->detect_service();

			if ( $this->is_active ) {
				$this->register_hooks();
				$this->register_cron_handlers();
			}
		}

		/**
		 * Register cron handlers for scheduled review requests
		 *
		 * @return void
		 */
		private function register_cron_handlers() {
			$cron_hook = "trustscript_send_review_request_{$this->service_id}";
			add_action( $cron_hook, array( $this, 'handle_scheduled_review_request' ) );
		}

		/**
		 * Handle scheduled review request execution
		 *
		 * @param int $order_id Order/booking ID
		 * @return void
		 */
		public function handle_scheduled_review_request( $order_id ) {
			$this->send_review_request( $order_id );
		}

		/**
		 * Detect if this service is active/installed
		 *
		 * @return bool
		 */
		abstract protected function detect_service();

		/**
		 * Register WordPress hooks for this service
		 */
		abstract protected function register_hooks();

		/**
		 * Get available order statuses that can trigger a review request
		 *
		 * @return array Associative array of status_key => label
		 */
		abstract public function get_available_statuses();

		/**
		 * Extract order/booking data for review request
		 *
		 * @param int|object $order_id Order ID or order object
		 * @return array|false Order data or false if extraction fails
		 */
		abstract public function extract_order_data( $order_id );

		/**
		 * Get service name from specific order
		 *
		 * @param int|object $order_id Order ID or order object
		 * @return string Service name for the order
		 */
		abstract public function get_order_service_name( $order_id );

		/**
		 * Get email placeholders for this service
		 *
		 * @param array $order_data Order data
		 * @param string $review_link Review URL
		 * @return array Email placeholders
		 */
		abstract protected function get_email_placeholders( $order_data, $review_link );
		
		/**
		 * Check if order should be processed
		 *
		 * @param int|object $order_id Order ID or order object
		 * @return bool
		 */
		public function should_process_order( $order_id ) {
			return true;
		}

		/**
		 * Extract all products from an order
		 *
		 * @param int $order_id Order ID
		 * @return array Products array with token, ID, name, SKU, and optional imageUrl
		 */
		public function extract_all_products( $order_id ) {
			return array();
		}

		/**
		 * Get product image URL
		 *
		 * @param int $product_id Product ID
		 * @return string Product image URL or empty string
		 */
		public function get_product_image_url( $product_id ) {
			return '';
		}

		/**
		 * Generate deterministic product token
		 *
		 * @param int $order_id   Order ID
		 * @param int $product_id Product ID
		 * @return string SHA-256 token
		 */
		private function generate_product_token( $order_id, $product_id ) {
			$meta_key = '_trustscript_token_salt';
			$salt = $this->get_order_meta( $order_id, $meta_key );

			if ( empty( $salt ) ) {
				$salt = wp_generate_uuid4();
				$this->update_order_meta( $order_id, $meta_key, $salt );
			}

			return hash( 'sha256', $order_id . '|' . $product_id . '|' . $salt );
		}

		/**
		 * Get service identifier
		 *
		 * @return string
		 */
		public function get_service_id() {
			return $this->service_id;
		}

		/**
		 * Get service name
		 *
		 * @return string
		 */
		public function get_service_name() {
			return $this->service_name;
		}

		/**
		 * Get service icon
		 *
		 * @return string
		 */
		public function get_service_icon() {
			return $this->service_icon;
		}

		/**
		 * Get the last API error code
		 *
		 * @return string|null Error code or null
		 */
		public function get_last_api_error() {
			return $this->last_api_error;
		}

		/**
		 * Retry a review request
		 *
		 * @param int $order_id Order ID
		 * @return bool Success status
		 */
		public function retry_review_request( $order_id ) {
			return $this->send_review_request( $order_id );
		}

		/**
		 * Check if service is active
		 *
		 * @return bool
		 */
		public function is_active() {
			return $this->is_active;
		}

		/**
		 * Get default status for this service
		 *
		 * @return string
		 */
		public function get_default_status() {
			$statuses = $this->get_available_statuses();
			return ! empty( $statuses ) ? array_key_first( $statuses ) : '';
		}

		/**
		 * Handle status change and trigger review request
		 *
		 * @param int $order_id Order/booking ID
		 * @param string $new_status New status
		 * @param string $old_status Old status
		 * @param bool $force_resend Force reprocessing
		 * @return bool True if scheduled/sent, false if skipped
		 */
		public function handle_status_change( $order_id, $new_status, $old_status = '', $force_resend = false ) {
			$reviews_enabled = get_option( 'trustscript_reviews_enabled', false );
			
			if ( ! $reviews_enabled && ! $force_resend ) {
				return false;
			}

			$trigger_status = get_option( "trustscript_trigger_status_{$this->service_id}", '' );

			if ( empty( $trigger_status ) ) {
				return false;
			}

			if ( $new_status !== $trigger_status && ! $force_resend ) {
				return false;
			}

			$is_enabled = get_option( "trustscript_enable_service_{$this->service_id}", '1' ) === '1';
			
			if ( ! $is_enabled ) {
				return false;
			}

			if ( ! $force_resend ) {
				$meta_key = "_trustscript_processed_{$this->service_id}";
				$already_processed = get_post_meta( $order_id, $meta_key, true );

				if ( $already_processed ) {
					return false;
				}
			}

			$should_process = $this->should_process_order( $order_id );

			if ( ! $should_process ) {
				return false;
			}

			if ( method_exists( $this, 'get_review_request_delay_hours' ) ) {
				$delay_hours = $this->get_review_request_delay_hours( $order_id );
			} else {
				$delay_hours = get_option( 'trustscript_review_delay_hours', 1 );
			}
			
			$delay_seconds = (int) $delay_hours * HOUR_IN_SECONDS;

			if ( $force_resend ) {
				$delay_seconds = 0;
			}

			TrustScript_Queue::add(
				$order_id,
				$this->service_id,
				'delay',
				$delay_seconds
			);

			return true;
		}

		/**
		 * Get or generate email hash for customer
		 *
		 * @param int $order_id Order ID
		 * @param string $customer_email Customer email
		 * @param object|null $order Order object (optional)
		 * @return string Email hash
		 */
		private function get_or_generate_email_hash( $order_id, $customer_email, $order = null ) {
			$meta_key = '_trustscript_email_hash';
			if ( null === $order && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
			}

			$existing_hash = $order ? $order->get_meta( $meta_key ) : get_post_meta( $order_id, $meta_key, true );

			if ( ! empty( $existing_hash ) ) {
				return $existing_hash;
			}

			$email_hash = hash( 'sha256', strtolower( trim( $customer_email ) ) );

			if ( $order ) {
				$order->update_meta_data( $meta_key, $email_hash );
				$order->save_meta_data();
			} else {
				update_post_meta( $order_id, $meta_key, $email_hash );
			}

			return $email_hash;
		}

		private function get_order_meta( $order_id, $meta_key, $order = null ) {
			if ( null === $order && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
			}
			return $order ? $order->get_meta( $meta_key ) : get_post_meta( $order_id, $meta_key, true );
		}

		private function update_order_meta( $order_id, $meta_key, $meta_value, $order = null ) {
			if ( null === $order && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
			}
			if ( $order ) {
				$order->update_meta_data( $meta_key, $meta_value );
				$order->save_meta_data();
			} else {
				update_post_meta( $order_id, $meta_key, $meta_value );
			}
		}

		/**
		 * Handle API response status codes and common errors
		 *
		 * Centralizes response handling for both multi-product and single-product API calls.
		 * Returns true on success (caller should process $body), false on recoverable/permanent errors.
		 *
		 * @param int    $code     HTTP response code
		 * @param string $body     HTTP response body
		 * @param int    $order_id Order ID for queue/error tracking
		 * @return bool True if successful (HTTP 200-299), false on error
		 */
		private function handle_api_response( $code, $body, $order_id ) {
			if ( $code === 429 ) {
				$data = json_decode( $body, true );
				
				if ( isset( $data['quotaExceeded'] ) && $data['quotaExceeded'] === true ) {
					$this->last_api_error = 'quota';
					$this->store_quota_error( $order_id, $data );
					return false;
				}
				
				$retry_after = isset( $data['retryAfter'] ) ? intval( $data['retryAfter'] ) : 60;
				$this->last_api_error = 'rate_limit';
				TrustScript_Queue::add( $order_id, $this->service_id, 'rate_limit', $retry_after );
				return false;
			}
			
			if ( $code >= 200 && $code < 300 ) {
				delete_transient( 'trustscript_api_key_invalid_notice' );
				delete_transient( 'trustscript_quota_exceeded_notice' );
				return true;
			}
			
			if ( $code === 401 ) {
				$this->last_api_error = 'api_key_invalid';
				set_transient( 'trustscript_api_key_invalid_notice', array( 'timestamp' => current_time( 'mysql' ), 'order_id' => $order_id ), 24 * HOUR_IN_SECONDS );
				TrustScript_Queue::add( $order_id, $this->service_id, 'api_error' );
				return false;
			}
			
			$this->last_api_error = 'api_error';
			TrustScript_Queue::add( $order_id, $this->service_id, 'api_error' );
			return false;
		}

		/**
		 * Send review request to TrustScript API
		 *
		 * @param int $order_id Order/booking ID
		 * @return bool True if successfully sent, false otherwise
		 */
		protected function send_review_request( $order_id ) {

			$this->last_api_error = null;

			$order_data = $this->extract_order_data( $order_id );

			if ( ! $order_data ) {
				return false;
			}

			if ( empty( $order_data['customer_email'] ) ) {
				return false;
			}

			$api_key = get_option( 'trustscript_api_key', '' );
			$base_url = trailingslashit( trustscript_get_base_url() );

			if ( empty( $api_key ) || empty( $base_url ) ) {
				return false;
			}

			$review_request_url = $base_url . 'api/review-requests';
			$webhook_url = get_site_url() . '/wp-json/trustscript/v1/publish-review';

			$email_hash = $this->get_or_generate_email_hash( $order_id, $order_data['customer_email'] );

			$all_products = $this->extract_all_products( $order_id );

			if ( ! empty( $all_products ) && count( $all_products ) > 1 ) {
					$products_by_id = array();
					$products_payload = array();
					foreach ( $all_products as $product ) {
						$product_token = $this->generate_product_token( $order_id, $product['productId'] );
						$products_by_id[ $product['productId'] ] = $product_token;
						$product_payload = array(
							'productToken' => $product_token,
							'productId'    => (string) $product['productId'],
							'productName'  => $product['productName'],
							'productSku'   => isset( $product['productSku'] ) ? $product['productSku'] : '',
						);
						if ( isset( $product['productImageUrl'] ) && ! empty( $product['productImageUrl'] ) ) {
							$product_payload['productImageUrl'] = $product['productImageUrl'];
						}
						$products_payload[] = $product_payload;
					}

				$include_order_dates = get_option( 'trustscript_include_order_dates', '1' ) === '1';

				$multi_request_data = array(
					'orderNumber'   => (string) $order_data['order_number'],
					'source'        => $this->service_id,
					'webhookUrl'    => $webhook_url,
					'emailHash'     => $email_hash,
					'collectRating' => get_option( 'trustscript_collect_rating', true ),
					'collectPhotos' => get_option( 'trustscript_collect_photos', true ),
					'collectVideos' => get_option( 'trustscript_collect_videos', false ),
					'products'      => $products_payload,
				);

				if ( $include_order_dates ) {
					$multi_request_data['orderDate'] = $order_data['order_date'];
				}

				$response = wp_remote_post( $review_request_url, array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'X-Site-URL'    => get_site_url(),
					),
					'body'    => wp_json_encode( $multi_request_data ),
					'timeout' => 30,
					'sslverify' => true,
				) );

				if ( is_wp_error( $response ) ) {
					$this->last_api_error = 'network';
					TrustScript_Queue::add( $order_id, $this->service_id, 'network' );
					return false;
				}

				$code = wp_remote_retrieve_response_code( $response );
				$body = wp_remote_retrieve_body( $response );

				if ( ! $this->handle_api_response( $code, $body, $order_id ) ) {
					return false;
				}

				$data = json_decode( $body, true );

				if ( empty( $data['orderToken'] ) ) {
					$this->last_api_error = 'missing_order_token';
					TrustScript_Queue::add( $order_id, $this->service_id, 'api_error' );
					return false;
				}

				$order_token   = $data['orderToken'];
				$session_url   = isset( $data['session_url'] ) ? $data['session_url'] : '';
				$is_duplicate  = isset( $data['isDuplicate'] ) && $data['isDuplicate'];
				$customer_opted_out = isset( $data['customer_opted_out'] ) && $data['customer_opted_out'];

				$meta_key = "_trustscript_processed_{$this->service_id}";
				$this->update_order_meta( $order_id, $meta_key, '1' );
				$this->update_order_meta( $order_id, '_trustscript_order_token', $order_token );
				$this->update_order_meta( $order_id, '_trustscript_api_key_hash', hash( 'sha256', $api_key ) );
				$this->update_order_meta( $order_id, '_trustscript_review_sent_at', current_time( 'mysql' ) );
				$this->update_order_meta( $order_id, '_trustscript_service_type', $this->service_id );

				if ( function_exists( 'wc_get_order' ) ) {
					$order_obj = wc_get_order( $order_id );
					if ( $order_obj ) {
						foreach ( $order_obj->get_items() as $item_id => $item ) {
							$product_id = (int) $item->get_product_id();
							if ( isset( $products_by_id[ $product_id ] ) ) {
								$product_token = $products_by_id[ $product_id ];
								wc_update_order_item_meta( $item_id, '_trustscript_product_token', $product_token );
							}
						}
					}
				} 

				if ( $customer_opted_out ) {
					$this->update_order_meta( $order_id, '_trustscript_customer_opted_out', '1' );
					return true;
				}

				$send_email_now = isset( $data['sendEmailNow'] ) && $data['sendEmailNow'] === true;
				if ( $is_duplicate ) {
					$this->update_order_meta( $order_id, '_trustscript_email_sent', '1' );
				} elseif ( $send_email_now && ! empty( $session_url ) ) {
					$email_data = array(
						'approval_url' => $session_url,
						'opt_out_link' => isset( $data['opt_out_link'] ) ? $data['opt_out_link'] : '',
					);
					$email_sent = $this->send_review_email( $order_id, $email_data );
					if ( $email_sent ) {
						$this->update_order_meta( $order_id, '_trustscript_email_sent', '1' );
					} 
				} 

				return true;
			}
			$include_product_names = get_option( 'trustscript_include_product_names', '1' ) === '1';
			$include_order_dates = get_option( 'trustscript_include_order_dates', '1' ) === '1';

			$request_data = array(
				'collectRating' => get_option( 'trustscript_collect_rating', true ),
				'collectPhotos' => get_option( 'trustscript_collect_photos', true ),
				'collectVideos' => get_option( 'trustscript_collect_videos', false ),
				'source' => $this->service_id,
				'sourceOrderId' => (string) $order_id,
				'webhookUrl' => $webhook_url,
				'emailHash' => $email_hash,
			);

			if ( $include_product_names ) {
				$request_data['productName'] = $order_data['service_name'];
			}

			if ( $include_order_dates ) {
				$request_data['orderDate'] = $order_data['order_date'];
			}

			if ( ! empty( $order_data['product_id'] ) ) {
				$image_url = $this->get_product_image_url( $order_data['product_id'] );
				if ( ! empty( $image_url ) ) {
					$request_data['productImageUrl'] = $image_url;
				}
			}

			$response = wp_remote_post( $review_request_url, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
					'X-Site-URL' => get_site_url(),
				),
				'body' => wp_json_encode( $request_data ),
				'timeout' => 30,
				'sslverify' => true,
			) );

			if ( is_wp_error( $response ) ) {
				$this->last_api_error = 'network';
				TrustScript_Queue::add( $order_id, $this->service_id, 'network' );
				return false;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( ! $this->handle_api_response( $code, $body, $order_id ) ) {
				return false;
			}

			$data = json_decode( $body, true );

			if ( isset( $data['uniqueToken'] ) || isset( $data['reviewRequest']['uniqueToken'] ) ) {
					$is_duplicate = isset( $data['reviewRequest']['isDuplicate'] ) && $data['reviewRequest']['isDuplicate'];
					$unique_token = isset( $data['uniqueToken'] ) ? $data['uniqueToken'] : $data['reviewRequest']['uniqueToken'];
					$customer_opted_out = isset( $data['customer_opted_out'] ) && $data['customer_opted_out'];

					$meta_key = "_trustscript_processed_{$this->service_id}";
					$this->update_order_meta( $order_id, $meta_key, '1' );
					$this->update_order_meta( $order_id, '_trustscript_review_token', $unique_token );
					$this->update_order_meta( $order_id, '_trustscript_api_key_hash', hash( 'sha256', $api_key ) );
					$this->update_order_meta( $order_id, '_trustscript_review_sent_at', current_time( 'mysql' ) );
					$this->update_order_meta( $order_id, '_trustscript_service_type', $this->service_id );

					if ( $customer_opted_out ) {
						$this->update_order_meta( $order_id, '_trustscript_customer_opted_out', '1' );
						$this->update_order_meta( $order_id, '_trustscript_opt_out_message', isset( $data['opt_out_message'] ) ? $data['opt_out_message'] : 'Customer has opted out from receiving review request emails.' );
						return true;
					}

					$send_email_now = isset( $data['sendEmailNow'] ) && $data['sendEmailNow'] === true;
					$existing_email_sent = $is_duplicate && isset( $data['reviewRequest']['existingEmailSent'] ) &&
						$data['reviewRequest']['existingEmailSent'];

					if ( $existing_email_sent ) {
						$this->update_order_meta( $order_id, '_trustscript_email_sent', '1' );
					} elseif ( $send_email_now ) {
						$email_sent = $this->send_review_email( $order_id, $data );
						if ( $email_sent ) {
							$this->update_order_meta( $order_id, '_trustscript_email_sent', '1' );
						}
					}

					return true;
				} else {
					return false;
				}
		}

		/**
		 * Send review request email to customer
		 *
		 * @param int $order_id Order ID
		 * @param array $review_data Review request data (contains 'uniqueToken', optionally 'approval_url', 'email_subject', 'email_html')
		 * @return bool True if email sent successfully, false otherwise
		 */
		public function send_review_email( $order_id, $review_data ) {
			$order_data = $this->extract_order_data( $order_id );

			if ( ! $order_data ) {
				return false;
			}

			if ( empty( $order_data['customer_email'] ) ) {
				return false;
			}

			if ( ! empty( $review_data['email_html'] ) && ! empty( $review_data['email_subject'] ) ) {
				$email_subject = $review_data['email_subject'];
				$email_body = $review_data['email_html'];
			} else {
				if ( empty( $review_data['approval_url'] ) ) {
					return false;
				}

				$review_link = $review_data['approval_url'];

				$all_products = $this->extract_all_products( $order_id );
				$is_multi_product = ! empty( $all_products ) && count( $all_products ) > 1;

				$template = $this->get_email_template( $order_data, $review_link, $review_data, $all_products, $is_multi_product );

				if ( $template === false ) {
					return false;
				}

				$email_subject = $template['subject'];
				$email_body = $template['body'];
			}

			$from_email = get_option( 'admin_email' );
			$from_name = get_bloginfo( 'name' );

			$unique_id = isset( $review_data['uniqueToken'] ) ? $review_data['uniqueToken'] : uniqid();
			$site_domain = wp_parse_url( get_site_url(), PHP_URL_HOST );
			
			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . wp_specialchars_decode( $from_name, ENT_QUOTES ) . ' <' . $from_email . '>',
				'Reply-To: ' . $from_email,
				'X-Entity-Ref-ID: <' . $unique_id . '@' . $site_domain . '>',
				'X-Mailer: TrustScript/1.0 (Transactional)',
			);

			$sent = wp_mail(
				$order_data['customer_email'],
				$email_subject,
				$email_body,
				$headers
			);

			return $sent;
		}

		/**
		 * Fetch email template from TrustScript API and replace placeholders
		 *
		 * @param array $order_data Order data (first product)
		 * @param string $review_link Review link URL
		 * @param array $review_data Review data array
		 * @param array $all_products All products from extract_all_products() (optional, for multi-product)
		 * @param bool $is_multi_product Whether this is a multi-product order (optional)
		 * @return array|false Array with 'subject' and 'body' keys if successful, false on failure
		 */
		protected function get_email_template( $order_data, $review_link, $review_data = array(), $all_products = array(), $is_multi_product = false ) {
			$api_key = get_option( 'trustscript_api_key', '' );

			$base_url = trustscript_get_base_url();

			if ( empty( $api_key ) || empty( $base_url ) ) {
				return false;
			}

			$custom_template_url = trailingslashit( $base_url ) . 'api/email-templates/service?serviceType=' . urlencode( $this->service_id );

			$custom_response = wp_remote_get( $custom_template_url, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept' => 'application/json',
					'X-Site-URL' => get_site_url(),
				),
				'timeout' => 10,
				'sslverify' => true,
			) );

			$use_custom_template = false;
			$custom_subject = '';
			$custom_body = '';

			if ( ! is_wp_error( $custom_response ) && wp_remote_retrieve_response_code( $custom_response ) === 200 ) {
				$custom_data = json_decode( wp_remote_retrieve_body( $custom_response ), true );

				if ( isset( $custom_data['templates'][ $this->service_id ] ) ) {
					$service_template = $custom_data['templates'][ $this->service_id ];

					if ( $service_template['active'] === 'custom' && ! empty( $service_template['custom'] ) ) {
						$use_custom_template = true;
						$custom_subject = $service_template['custom']['subject'];
						$custom_body = $service_template['custom']['body'];
					}
				}
			}

			if ( ! $use_custom_template ) {
				if ( ! empty( $all_products ) ) {
					$template_type = count( $all_products ) > 1 ? 'wordpress-multi' : 'wordpress-single';
				} else {
					$template_type = 'default';
				}
				$template_url = trailingslashit( $base_url ) . 'api/email-templates?service_type=' . urlencode( $this->service_id ) . '&template_type=' . urlencode( $template_type );

				$response = wp_remote_get( $template_url, array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept' => 'application/json',
						'X-Site-URL' => get_site_url(),
					),
					'timeout' => 10,
					'sslverify' => true,
				) );

				if ( is_wp_error( $response ) ) {
					return false;
				}

				$code = wp_remote_retrieve_response_code( $response );

				if ( $code !== 200 ) {
					return false;
				}

				$body_data = wp_remote_retrieve_body( $response );
				$template_data = json_decode( $body_data, true );

				if ( empty( $template_data['subject'] ) || empty( $template_data['body'] ) ) {
					return false;
				}

				$custom_subject = $template_data['subject'];
				$custom_body = $template_data['body'];
			}

			$placeholders = $this->get_email_placeholders( $order_data, $review_link );

			if ( ! empty( $review_data['opt_out_link'] ) ) {
				$placeholders['opt_out_link'] = $review_data['opt_out_link'];
			}

			$products_html = '';
			if ( ! empty( $all_products ) ) {
				require_once dirname( TRUSTSCRIPT_PLUGIN_FILE ) . '/includes/class-trustscript-placeholder-mapper.php';
				$products_for_email = TrustScript_Placeholder_Mapper::build_products_for_email( $all_products, $this );
				$products_html = self::generate_products_html_for_email( $products_for_email, $this->service_id, $order_data );
			}

			$subject = $custom_subject;
			foreach ( $placeholders as $placeholder => $value ) {
				$subject = str_replace( '{' . $placeholder . '}', $value, $subject );
			}

			$body = $custom_body;
			foreach ( $placeholders as $placeholder => $value ) {
				$body = str_replace( '{' . $placeholder . '}', $value, $body );
			}

			$products_html_with_replaced_placeholders = $products_html;
			if ( ! empty( $products_html_with_replaced_placeholders ) ) {
				foreach ( $placeholders as $placeholder => $value ) {
					$products_html_with_replaced_placeholders = str_replace( '{' . $placeholder . '}', $value, $products_html_with_replaced_placeholders );
				}
			}

			if ( ! empty( $products_html_with_replaced_placeholders ) ) {
				if ( strpos( $body, '<!-- MULTI_PRODUCT_ITEMS -->' ) !== false ) {
					$body = str_replace( '<!-- MULTI_PRODUCT_ITEMS -->', $products_html_with_replaced_placeholders, $body );
				} else {
					$fallback_block = '<div style="font-family:Arial,sans-serif;padding:16px 0;">' . $products_html_with_replaced_placeholders . '</div>';

					if ( stripos( $body, '</body>' ) !== false ) {
						$body = preg_replace( '/<\/body>/i', $fallback_block . '</body>', $body, 1 );
					} else {
						$body .= $fallback_block;
					}
				}
			}

			return array(
				'subject' => $subject,
				'body' => $body,
			);
		}

		/**
		 * Generate HTML for products list to be injected into multi-product email template
		 *
		 * @param array $products_for_email Products array from build_products_for_email()
		 * @param string $service_id Service identifier for styling
		 * @return string HTML for products list
		 */
		private static function generate_products_html_for_email( $products_for_email, $service_id = 'woocommerce', $order_data = array() ) {
			if ( empty( $products_for_email ) ) {
				return '';
			}

			$html = '';

			foreach ( $products_for_email as $product ) {
				$name = isset( $product['name'] ) ? esc_html( $product['name'] ) : 'Product';
				$image_url = isset( $product['image'] ) ? esc_url( $product['image'] ) : '';

				$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:0;">';
				$html .= '<tr>';
				$html .= '<td width="80" valign="top" style="padding-right:12px;border-bottom:1px solid #e0e0e0;padding-bottom:12px;">';

				if ( ! empty( $image_url ) ) {
					$html .= '<img src="' . $image_url . '" alt="' . $name . '" width="80" height="80" style="display:block;width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #e0e0e0;">';
				} else {
					$html .= '<div style="width:80px;height:80px;background-color:#e0e0e0;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#999;font-size:11px;font-weight:bold;">Product</div>';
				}

				$html .= '</td>';
				$html .= '<td valign="middle" style="border-bottom:1px solid #e0e0e0;padding-bottom:12px;">';
				$html .= '<p style="margin:0 0 3px 0;font-size:12px;color:#4a90e2;font-weight:bold;letter-spacing:0.5px;text-transform:uppercase;">Your Purchase</p>';
				$html .= '<p style="margin:0 0 4px;font-size:14px;font-weight:bold;color:#1a1a1a;">' . $name . '</p>';
				$html .= '</td>';
				$html .= '</tr>';
				$html .= '</table>';
			}

			return $html;
		}
		
		/**
		 * Get customer email for an order (from order data extraction)
		 * 
		 * @param int $order_id Order ID
		 * @return string Customer email
		 */
		public function get_customer_email( $order_id ) {
			$order_data = $this->extract_order_data( $order_id );
			return $order_data ? $order_data['customer_email'] : '';
		}
		
		/**
		 * Get customer name for an order (from order data extraction)
		 * 
		 * @param int $order_id Order ID
		 * @return string Customer name
		 */
		public function get_customer_name( $order_id ) {
			$order_data = $this->extract_order_data( $order_id );
			return $order_data ? $order_data['customer_name'] : '';
		}
		
		/**
		 * Get customer email and name for an order in a single extraction
		 * 
		 * Optimized to call extract_order_data once instead of calling both
		 * get_customer_email() and get_customer_name() separately.
		 * 
		 * @param int $order_id Order ID
		 * @return array Array with 'email' and 'name' keys (both empty strings if extraction fails)
		 */
		public function get_customer_info( $order_id ) {
			$order_data = $this->extract_order_data( $order_id );
			return array(
				'email' => $order_data ? $order_data['customer_email'] : '',
				'name'  => $order_data ? $order_data['customer_name'] : '',
			);
		}
		
		/**
		 * Get product IDs from an order (from order data extraction)
		 * 
		 * @param int $order_id Order ID
		 * @return array Product IDs
		 */
		public function get_product_ids( $order_id ) {
			$order_data = $this->extract_order_data( $order_id );
			
			if ( ! $order_data || empty( $order_data['product_id'] ) ) {
				return array();
			}
			
			return array( $order_data['product_id'] );
		}
		
		/**
		 * Save order meta data
		 * 
		 * @param int $order_id Order ID
		 * @param string $meta_key Meta key
		 * @param mixed $meta_value Meta value
		 */
		public function save_order_meta( $order_id, $meta_key, $meta_value ) {
			$this->update_order_meta( $order_id, $meta_key, $meta_value );
		}

		/**
		 * Find order ID by review token
		 * 
		 * NOTE: Default implementation queries WooCommerce only via wc_get_orders().
		 * Non-WooCommerce service providers MUST override this method with their own
		 * implementation to query their respective database tables.
		 * 
		 * @param string $unique_token Unique review token
		 * @return int|false Order/transaction ID if found, false otherwise
		 */
		public function find_order_by_token( $unique_token ) {
			if ( function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders( array(
					'limit'      => 1,
					'return'     => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => '_trustscript_review_token',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => $unique_token,
				) );

				if ( ! empty( $orders ) ) {
					return $orders[0];
				}
			}

			return false;
		}

		/**
		 * Store quota error details and add order to retry queue
		 *
		 * @param int   $order_id The ID of the order that triggered the quota error.
		 * @param array $error_data An associative array containing details about the quota error, such as 'currentPlan', 'nextPlan', 'nextLimit', and 'resetDate'.
		 * @return void 
		 */
		private function store_quota_error( $order_id, $error_data ) {
			$quota_info = array(
				'quotaExceeded' => true,
				'currentPlan'   => isset( $error_data['currentPlan'] ) ? $error_data['currentPlan'] : 'unknown',
				'nextPlan'      => isset( $error_data['nextPlan'] )    ? $error_data['nextPlan']    : null,
				'nextLimit'     => isset( $error_data['nextLimit'] )   ? $error_data['nextLimit']   : null,
				'resetDate'     => isset( $error_data['resetDate'] )   ? $error_data['resetDate']   : null,
				'timestamp'     => current_time( 'mysql' ),
			);

			set_transient( 'trustscript_quota_exceeded_notice', $quota_info, DAY_IN_SECONDS );

			TrustScript_Queue::add( $order_id, $this->service_id, 'quota' );
		}
	}