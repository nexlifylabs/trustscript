<?php
/**
 * TrustScript WooCommerce Service Provider 
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_WooCommerce_Provider extends TrustScript_Service_Provider {
	
	public function __construct() {
		$this->service_id = 'woocommerce';
		$this->service_name = 'WooCommerce';
		$this->service_icon = '🛒';
		
		parent::__construct();
	}
	
	/**
	 * Detect if WooCommerce is active
	 * 
	 * @return bool
	 */
	protected function detect_service() {
		return class_exists( 'WooCommerce' );
	}
	
	/**
	 * Register WooCommerce hooks
	 */
	protected function register_hooks() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_woocommerce_status_change' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_item_refund' ), 10, 2 );
	}
	
	/**
	 * Handle WooCommerce order status change
	 * 
	 * @param int $order_id Order ID
	 * @param string $old_status Old status
	 * @param string $new_status New status
	 * @param WC_Order $order Order object
	 */
	public function handle_woocommerce_status_change( $order_id, $old_status, $new_status, $order ) {
		$void_statuses = array( 'cancelled', 'refunded', 'failed' );

		if ( in_array( $new_status, $void_statuses, true ) ) {
			$this->maybe_void_review_request( $order_id, $new_status );
			return;
		}

		$this->handle_status_change( $order_id, $new_status, $old_status );
	}

	/**
	 * Handle a WooCommerce refund event at the item level.
	 * This is important for multi-product orders where only some items are refunded.
	 * 
	 * @param int $order_id  WooCommerce order ID
	 * @param int $refund_id WooCommerce refund ID
	 */
	public function handle_item_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order_token = $order->get_meta( '_trustscript_order_token' );
		if ( empty( $order_token ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		$api_key  = trustscript_get_api_key();
		$base_url = trustscript_get_base_url();

		if ( empty( $api_key ) || empty( $base_url ) ) {
			return;
		}

		foreach ( $refund->get_items() as $refund_item ) {
			$original_item_id = $refund_item->get_meta( '_refunded_item_id' );
			if ( ! $original_item_id ) {
				continue;
			}

			$product_token = wc_get_order_item_meta( $original_item_id, '_trustscript_product_token', true );
			if ( empty( $product_token ) ) {
				continue;
			}

			$original_item = $order->get_item( $original_item_id );
			$qty_original  = $original_item ? (int) $original_item->get_quantity() : 0;

			$qty_this_refund = (int) abs( $refund_item->get_quantity() );

			if ( $qty_original > 0 && $qty_this_refund < $qty_original ) {
				continue;
			}

			$payload = array(
				'productToken'  => $product_token,
				'sourceOrderId' => (string) $order_id,
				'reason'        => 'refunded',
				'source'        => 'woocommerce',
			);

			$response = wp_remote_post(
				trailingslashit( $base_url ) . 'api/review-requests/void',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'X-Site-URL'    => get_site_url(),
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				// Successfully voided — clear the product token so we don't re-use it
				wc_delete_order_item_meta( $original_item_id, '_trustscript_product_token' );
			}
		}
	}

	/**
	 * Notify TrustScript backend to void review requests for an order
	 * that has been cancelled, refunded, or failed.
	 *
	 * @param int    $order_id   WooCommerce order ID
	 * @param string $reason     'cancelled' | 'refunded' | 'failed'
	 */
	private function maybe_void_review_request( $order_id, $reason ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$review_token = $order->get_meta( '_trustscript_review_token' );
		$order_token = $order->get_meta( '_trustscript_order_token' );

		if ( empty( $review_token ) && empty( $order_token ) ) {
			return;
		}

		$api_key  = trustscript_get_api_key();
		$base_url = trustscript_get_base_url();

		if ( empty( $api_key ) || empty( $base_url ) ) {
			return;
		}

		$payload = array(
			'reason'        => $reason,
			'sourceOrderId' => (string) $order_id,
			'source'        => 'woocommerce',
		);

		if ( ! empty( $order_token ) ) {
			$payload['orderToken'] = $order_token;
		}
		if ( ! empty( $review_token ) ) {
			$payload['uniqueToken'] = $review_token;
		}

		$response = wp_remote_post(
			trailingslashit( $base_url ) . 'api/review-requests/void',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'X-Site-URL'    => get_site_url(),
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$order->update_meta_data( '_trustscript_review_voided', $reason );
			$order->update_meta_data( '_trustscript_review_voided_at', current_time( 'mysql' ) );
			$order->save();
		}
	}
	
	/**
	 * Check if order is international
	 * 
	 * @param int|WC_Order $order_id Order ID or order object
	 * @return bool True if order is international
	 * @since 1.0.0
	 */
	public function is_international_order( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		
		if ( ! $order ) {
			return false;
		}

		$store_location = wc_get_base_location();
		$store_country = $store_location['country'];

		if ( empty( $store_country ) ) {
			return false;
		}

		$shipping_country = $order->get_shipping_country();

		if ( empty( $shipping_country ) ) {
			$shipping_country = $order->get_billing_country();
		}

		$is_international = strtoupper( $shipping_country ) !== strtoupper( $store_country );

		return $is_international;
	}
	
	/**
	 * Get appropriate review request delay in hours based on order location
	 * 
	 * @param int|WC_Order $order_id Order ID or order object
	 * @return int Delay in hours
	 * @since 1.0.0
	 */
	public function get_review_request_delay_hours( $order_id ) {
		$intl_enabled = get_option( 'trustscript_enable_international_handling', false );
		$is_intl = $this->is_international_order( $order_id );

		if ( $intl_enabled && $is_intl ) {
			$intl_delay = get_option( 'trustscript_international_delay_hours', 336 );
			return (int) $intl_delay;
		}

		$domestic_delay = get_option( 'trustscript_review_delay_hours', 1 );
		return (int) $domestic_delay;
	}
	
	/**
	 * Get all WooCommerce order statuses
	 * 
	 * @return array
	 */
	public function get_available_statuses() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}
		
		$statuses = wc_get_order_statuses();
		
		$clean_statuses = array();
		foreach ( $statuses as $key => $label ) {
			$clean_key = str_replace( 'wc-', '', $key );
			$clean_statuses[ $clean_key ] = $label;
		}
		
		return $clean_statuses;
	}
	
	/**
	 * Extract ALL products from a WooCommerce order for the multi-product API payload.
	 * Filters out products from excluded categories, free products (if excluded), refunded items, and products below minimum value.
	 * 
	 * @param int|WC_Order $order_id Order ID or order object
	 * @return array Array of product arrays: [ productId, productName, productSku ]
	 */
	public function extract_all_products( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;

		if ( ! $order ) {
			return array();
		}

		// Get filtering settings
		$allowed_categories = (array) get_option( 'trustscript_review_categories', array() );
		// Ensure category IDs are integers and remove empty/invalid values
		$allowed_categories = array_filter( array_map( 'intval', $allowed_categories ) );
		$exclude_free = get_option( 'trustscript_woocommerce_exclude_free', '0' ) === '1';
		$min_order_value = (float) get_option( 'trustscript_woocommerce_min_value', 0 );

		$products = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			$qty_refunded = (int) abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$qty_remaining = $qty - $qty_refunded;

			if ( $qty_remaining <= 0 ) {
				continue; // Skip refunded items
			}

			// Check if product price is 0 (free) and we should exclude it
			if ( $exclude_free ) {
				$product_price = (float) $product->get_price();
				if ( $product_price <= 0 ) {
					continue; // Skip free products
				}
			}

			// Check category filtering (only applies if categories are set)
			if ( ! empty( $allowed_categories ) ) {
				// Category filter IS set: only include products IN the allowed categories
				$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
				
				// Guard against WP_Error (e.g., if product_cat taxonomy is unregistered)
				if ( is_wp_error( $product_categories ) ) {
					$product_categories = array();
				}
				
				// Ensure category IDs are integers for consistent comparison
				$product_categories = array_map( 'intval', $product_categories );
				
				$is_in_allowed_category = false;
				foreach ( $allowed_categories as $allowed_cat_id ) {
					if ( in_array( $allowed_cat_id, $product_categories, true ) ) {
						$is_in_allowed_category = true;
						break;
					}
				}

				// Skip this product if it's not in any allowed category
				if ( ! $is_in_allowed_category ) {
					continue;
				}
			}
			// else: NO category filter set, include ALL products (that pass other checks)

			// Check minimum value per product (line item total including tax)
			if ( $min_order_value > 0 ) {
				$item_total = (float) $item->get_total();
				$item_tax = (float) $item->get_total_tax();
				$item_value = $item_total + $item_tax;
				
				if ( $item_value < $min_order_value ) {
					continue; // Skip products below minimum value
				}
			}

			$image_id = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

			$products[] = array(
				'productId'       => $product->get_id(),
				'productName'     => $item->get_name(),
				'productSku'      => $product->get_sku(),
				'productImageUrl' => $image_url,
				'quantity'        => $qty_remaining,
			);
		}

		return $products;
	}

	/**
	 * Get IDs of all products in the order that pass the eligibility filters (category, free product exclusion, 
	 * min value, refunds). This is used for the "void" API call to identify which products' review requests 
	 * should be voided when an order is cancelled/refunded.
	 * 
	 * @param int|WC_Order $order_id Order ID or order object
	 * @return array Array of product IDs that pass all filters
	 */
	public function get_all_eligible_product_ids( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;

		if ( ! $order ) {
			return array();
		}

		$allowed_categories = (array) get_option( 'trustscript_review_categories', array() );
		$allowed_categories = array_filter( array_map( 'intval', $allowed_categories ) );
		$exclude_free = get_option( 'trustscript_woocommerce_exclude_free', '0' ) === '1';
		$min_order_value = (float) get_option( 'trustscript_woocommerce_min_value', 0 );

		$product_ids = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			$qty_refunded = (int) abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$qty_remaining = $qty - $qty_refunded;

			if ( $qty_remaining <= 0 ) {
				continue; 
			}

			if ( $exclude_free ) {
				$product_price = (float) $product->get_price();
				if ( $product_price <= 0 ) {
					continue; // Skip free products
				}
			}

			if ( ! empty( $allowed_categories ) ) {
				$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
				
				if ( is_wp_error( $product_categories ) ) {
					$product_categories = array();
				}
				
				$product_categories = array_map( 'intval', $product_categories );
				
				$is_in_allowed_category = false;
				foreach ( $allowed_categories as $allowed_cat_id ) {
					if ( in_array( $allowed_cat_id, $product_categories, true ) ) {
						$is_in_allowed_category = true;
						break;
					}
				}

				if ( ! $is_in_allowed_category ) {
					continue;
				}
			}

			if ( $min_order_value > 0 ) {
				$item_total = (float) $item->get_total();
				$item_tax = (float) $item->get_total_tax();
				$item_value = $item_total + $item_tax;
				
				if ( $item_value < $min_order_value ) {
					continue;
				}
			}

			$product_ids[] = $product->get_id();
		}

		return $product_ids;
	}

	/**
	 * Extract WooCommerce order data
	 * 
	 * @param int|WC_Order $order_id Order ID or order object
	 * @return array|false
	 */
	public function extract_order_data( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		
		if ( ! $order ) {
			return false;
		}
		
		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$customer_email = $order->get_billing_email();
		
		$items = $order->get_items();
		$product_name = '';
		$product_description = '';
		$product_id = 0;
		
		// Get filtering settings (must match extract_all_products logic)
		$allowed_categories = (array) get_option( 'trustscript_review_categories', array() );
		$allowed_categories = array_filter( array_map( 'intval', $allowed_categories ) );
		$exclude_free = get_option( 'trustscript_woocommerce_exclude_free', '0' ) === '1';
		$min_order_value = (float) get_option( 'trustscript_woocommerce_min_value', 0 );
		
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			
			// Check refunds
			$qty = (int) $item->get_quantity();
			$qty_refunded = (int) abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$qty_remaining = $qty - $qty_refunded;
			
			if ( $qty_remaining <= 0 ) {
				continue;
			}
			
			// Check free product exclusion
			if ( $exclude_free ) {
				$product_price = (float) $product->get_price();
				if ( $product_price <= 0 ) {
					continue;
				}
			}
			
			// Check category filtering
			if ( ! empty( $allowed_categories ) ) {
				$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
				
				if ( is_wp_error( $product_categories ) ) {
					$product_categories = array();
				}
				
				$product_categories = array_map( 'intval', $product_categories );
				if ( empty( array_intersect( $allowed_categories, $product_categories ) ) ) {
					continue;
				}
			}
			
			// Check minimum value per product
			if ( $min_order_value > 0 ) {
				$item_total = (float) $item->get_total();
				$item_tax = (float) $item->get_total_tax();
				$item_value = $item_total + $item_tax;
				
				if ( $item_value < $min_order_value ) {
					continue;
				}
			}
			
			if ( empty( $product_name ) ) {
				$product_name = $product->get_name();
				$product_id = $product->get_id();
				
				$description = $product->get_description();
				if ( empty( $description ) ) {
					$description = $product->get_short_description();
				}
				
				if ( ! empty( $description ) ) {
					$description = wp_strip_all_tags( $description );
					$product_description = $this->truncate_to_words( $description, 200 );
				}
			}
		}
		
		$product_image_url = '';
		// Use the product we already identified in the loop (guaranteed to be in allowed categories), not just the first item
		if ( ! empty( $product_id ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$image_url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
					$product_image_url = $image_url ?: wc_placeholder_img_src( 'woocommerce_thumbnail' );
				} else {
					$product_image_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
				}
			}
		}
		
		$order_date = $order->get_date_completed() ? $order->get_date_completed()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		
		return array(
			'customer_name' => $customer_name,
			'customer_email' => $customer_email,
			'service_name' => $product_name,
			'service_description' => $product_description,
			'order_date' => $order_date,
			'order_total' => $order->get_total(),
			'order_number' => $order->get_order_number(),
			'product_id' => $product_id,
			'product_image_url' => $product_image_url,
			'billing_country' => $order->get_billing_country(),
		);
	}

	/**
	 * Get customer email
	 */
	public function get_customer_email( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		return $order ? $order->get_billing_email() : false;
	}
	
	/**
	 * Get customer name
	 */
	public function get_customer_name( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		
		if ( ! $order ) {
			return '';
		}
		
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}
	
	/**
	 * Get product name from specific order
	 */
	public function get_order_service_name( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		
		if ( ! $order ) {
			return '';
		}
		
		$items = $order->get_items();
		
		if ( empty( $items ) ) {
			return '';
		}
		
		$first_item = reset( $items );
		$product = $first_item->get_product();
		
		return $product ? $product->get_name() : '';
	}
	
	/**
	 * Check if order should be processed (category filtering, min value, exclude free products) 
	 */
	public function should_process_order( $order_id ) {
		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		
		if ( ! $order ) {
			return false;
		}
		
		// Get filtering settings
		$allowed_categories = (array) get_option( 'trustscript_review_categories', array() );
		$allowed_categories = array_filter( array_map( 'intval', $allowed_categories ) ); // Ensure all are integers and remove empty values
		
		$min_order_value = (float) get_option( 'trustscript_woocommerce_min_value', 0 );
		$exclude_free = get_option( 'trustscript_woocommerce_exclude_free', '0' ) === '1';
		
		// Get all items and filter by criteria
		$items = $order->get_items();
		
		// If no items, can't process
		if ( empty( $items ) ) {
			return false;
		}
		
		$has_valid_product = false;
		$eligible_order_value = 0; // Track the value of products that will actually be included
		
		foreach ( $items as $item ) {
			$qty = (int) $item->get_quantity();
			$qty_refunded = (int) abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
			$qty_remaining = $qty - $qty_refunded;
			
			if ( $qty_remaining <= 0 ) {
				continue; // Skip refunded items
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			
			// Check if product price is 0 (free) and we should exclude it
			if ( $exclude_free ) {
				$product_price = (float) $product->get_price();
				if ( $product_price <= 0 ) {
					continue; // Skip free products
				}
			}
			
			// Check category filtering
			if ( ! empty( $allowed_categories ) ) {
				// Category filter IS set: only include products IN the allowed categories
				$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
				
				// Guard against WP_Error (e.g., if product_cat taxonomy is unregistered)
				if ( is_wp_error( $product_categories ) ) {
					$product_categories = array();
				}
				
				$product_categories = array_map( 'intval', $product_categories );
				
				// Check if this product is in ANY of the allowed categories
				$has_match = ! empty( array_intersect( $allowed_categories, $product_categories ) );

				// ONLY process products that ARE in the allowed categories
				if ( ! $has_match ) {
					continue;
				}
			}
			// If we reach here, this product passes all filters
			
			$has_valid_product = true;
			
			// Add this item's line total to eligible order value (after refunds)
			$item_total = (float) $item->get_total();
			$item_tax = (float) $item->get_total_tax();
			$eligible_order_value += ( $item_total + $item_tax );
		}
		
		// If no valid products found, reject the order
		if ( ! $has_valid_product ) {
			return false;
		}
		
		// Check minimum order value AFTER filtering products
		// Only check the value of products that will actually be included in review requests
		if ( $min_order_value > 0 && $eligible_order_value < $min_order_value ) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Truncate text to word limit
	 * 
	 * @param string $text Text to truncate
	 * @param int $word_limit Word limit
	 * @return string
	 */
	private function truncate_to_words( $text, $word_limit ) {
		$words = explode( ' ', $text );
		
		if ( count( $words ) <= $word_limit ) {
			return $text;
		}
		
		$truncated = array_slice( $words, 0, $word_limit );
		return implode( ' ', $truncated ) . '...';
	}
	
	/**
	 * Get WooCommerce-specific email placeholders
	 */
	protected function get_email_placeholders( $order_data, $review_link ) {
		return TrustScript_Placeholder_Mapper::map_placeholders(
			$order_data,
			'woocommerce',
			$review_link
		);
	}
		
	/**
	 * Get default status for WooCommerce
	 * 
	 * @return string
	 */
	public function get_default_status() {
		$statuses = $this->get_available_statuses();
		
		if ( isset( $statuses['delivered'] ) ) {
			return 'delivered';
		}
		
		return 'completed';
	}
}