<?php
/**
 * Universal Placeholder Mapper
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Placeholder_Mapper {
	
	public static function map_placeholders( $service_data, $service_type, $review_link, $products = array() ) {
		// Get physical address based on country and setting
		$physical_address = self::get_physical_address_for_email( $service_data );
		
		$placeholders = array(
			'customer_name' => $service_data['customer_name'] ?? '',
			'customer_email' => $service_data['customer_email'] ?? '',
			'store_name' => get_bloginfo( 'name' ),
			'store_url' => home_url(),
			'review_link' => $review_link,
			'item_name' => $service_data['service_name'] ?? '',
			'item_description' => $service_data['service_description'] ?? '',
			'item_image' => self::get_item_image( $service_data ),
			'purchase_id' => $service_data['order_number'] ?? '',
			'purchase_date' => isset( $service_data['order_date'] ) ? wp_date( 'F j, Y', strtotime( $service_data['order_date'] ) ) : '',
			'purchase_total' => self::format_price( $service_data['order_total'] ?? 0, $service_type ),
			'physical_address' => $physical_address,
		);
		
		$service_specific = self::get_service_specific_placeholders( $service_data, $service_type );
		
		return array_merge( $placeholders, $service_specific );
	}

	/**
	 * Build products array for multi-product email templates
	 * Returns structured data that can be passed to email templates via options.products
	 *
	 * @param array $all_products Array of product data from extract_all_products()
	 * @param object $service_provider Service provider instance with get_product_image_url()
	 * @return array Array of products formatted for email template
	 */
	public static function build_products_for_email( $all_products, $service_provider = null ) {
		if ( empty( $all_products ) ) {
			return array();
		}

		$products_for_email = array();

		foreach ( $all_products as $product ) {
			$product_image = '';

			if ( ! empty( $product['productImageUrl'] ) ) {
				$product_image = self::make_email_safe_url( $product['productImageUrl'] );
			} elseif ( ! empty( $product['productId'] ) && $service_provider && method_exists( $service_provider, 'get_product_image_url' ) ) {
				$image_url = $service_provider->get_product_image_url( $product['productId'] );
				if ( ! empty( $image_url ) ) {
					$product_image = self::make_email_safe_url( $image_url );
				}
			}

			$products_for_email[] = array(
				'name' => $product['productName'] ?? '',
				'image' => $product_image ?: '',
			);
		}

		return $products_for_email;
	}
	
	private static function get_service_specific_placeholders( $service_data, $service_type ) {
		switch ( $service_type ) {
			case 'woocommerce':
				return array(
					'product_name' => $service_data['service_name'] ?? '',
					'product_description' => $service_data['service_description'] ?? '',
					'product_image' => self::get_item_image( $service_data ),
					'order_number' => $service_data['order_number'] ?? '',
					'order_date' => isset( $service_data['order_date'] ) ? wp_date( 'F j, Y', strtotime( $service_data['order_date'] ) ) : '',
					'order_total' => isset( $service_data['order_total'] ) && function_exists( 'wc_price' ) ? wc_price( $service_data['order_total'] ) : '$' . number_format( $service_data['order_total'] ?? 0, 2 ),
				);
				
			case 'memberpress':
				return array(
					'membership_name' => $service_data['service_name'] ?? '',
					'membership_description' => $service_data['service_description'] ?? '',
					'transaction_number' => $service_data['order_number'] ?? '',
					'transaction_total' => '$' . number_format( $service_data['order_total'] ?? 0, 2 ),
					'subscription_date' => isset( $service_data['order_date'] ) ? wp_date( 'F j, Y', strtotime( $service_data['order_date'] ) ) : '',
				);
				
			default:
				return array();
		}
	}
	
	private static function get_item_image( $service_data ) {
		$image_url = '';
		
		if ( ! empty( $service_data['product_image_url'] ) ) {
			$image_url = $service_data['product_image_url'];
		} elseif ( ! empty( $service_data['membership_image_url'] ) ) {
			$image_url = $service_data['membership_image_url'];
		} elseif ( ! empty( $service_data['service_image_url'] ) ) {
			$image_url = $service_data['service_image_url'];
		}
		
		if ( empty( $image_url ) ) {
			return self::get_svg_placeholder();
		}
		
		return self::make_email_safe_url( $image_url );
	}
	
	private static function format_price( $price, $service_type ) {
		if ( $service_type === 'woocommerce' && function_exists( 'wc_price' ) ) {
			return wc_price( $price );
		}
		
		return '$' . number_format( $price, 2 );
	}
	
	private static function make_email_safe_url( $url ) {
		return esc_url( $url );
	}
	
	private static function get_svg_placeholder() {
		$svg = sprintf(
			'<svg width="%d" height="%d" xmlns="http://www.w3.org/2000/svg">' .
			'<rect width="%d" height="%d" fill="%s" stroke="%s"/>' .
			'<text x="%d" y="%d" text-anchor="middle" font-size="%d" fill="%s">%s</text>' .
			'</svg>',
			80, 80, 80, 80, '#f0f0f0', '#e0e0e0', 40, 45, 10, '#666', esc_html__( 'Image', 'trustscript' )
		);

		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}
	
	public static function get_universal_placeholders() {
		return array(
			'customer_name' => __( 'Customer name', 'trustscript' ),
			'customer_email' => __( 'Customer email', 'trustscript' ),
			'store_name' => __( 'Store name', 'trustscript' ),
			'store_url' => __( 'Store URL', 'trustscript' ),
			'review_link' => __( 'Review link', 'trustscript' ),
			'item_name' => __( 'Item/Product/Service name', 'trustscript' ),
			'item_description' => __( 'Item description', 'trustscript' ),
			'item_image' => __( 'Item image', 'trustscript' ),
			'purchase_id' => __( 'Order/Transaction/Booking ID', 'trustscript' ),
			'purchase_date' => __( 'Purchase date', 'trustscript' ),
			'purchase_total' => __( 'Purchase total', 'trustscript' ),
			'physical_address' => __( 'Physical mailing address', 'trustscript' ),
		);
	}
	
	public static function get_service_placeholders( $service_type ) {
		switch ( $service_type ) {
			case 'woocommerce':
				return array(
					'product_name' => __( 'Product name', 'trustscript' ),
					'product_description' => __( 'Product description', 'trustscript' ),
					'product_image' => __( 'Product image', 'trustscript' ),
					'order_number' => __( 'Order number', 'trustscript' ),
					'order_date' => __( 'Order date', 'trustscript' ),
					'order_total' => __( 'Order total', 'trustscript' ),
				);
				
			case 'memberpress':
				return array(
					'membership_name' => __( 'Membership name', 'trustscript' ),
					'membership_description' => __( 'Membership description', 'trustscript' ),
					'transaction_number' => __( 'Transaction number', 'trustscript' ),
					'transaction_total' => __( 'Transaction total', 'trustscript' ),
					'subscription_date' => __( 'Subscription date', 'trustscript' ),
				);
				

			default:
				return array();
		}
	}

	/**
	 * Determine if physical address should be included in email based on country and settings.
	 * 
	 * Always includes for US (CAN-SPAM requirement).
	 * For other countries, includes only if explicitly enabled.
	 *
	 * @param array $service_data Service/order data with 'billing_country' key
	 * @return string Physical address or empty string
	 */
	private static function get_physical_address_for_email( $service_data ) {
		$country = $service_data['billing_country'] ?? '';
		$include_address = false;

		// Always include for US customers (CAN-SPAM requirement)
		if ( $country === 'US' ) {
			$include_address = true;
		} else {
			// For other countries, check if explicitly enabled
			$require_address = get_option( 'trustscript_require_physical_address', '0' );
			$include_address = $require_address === '1';
		}

		if ( ! $include_address ) {
			return '';
		}

		// Get the physical address from settings
		if ( class_exists( 'TrustScript_Privacy_Settings_Page' ) ) {
			$address = TrustScript_Privacy_Settings_Page::get_physical_address();
		} else {
			$address = get_option( 'trustscript_physical_address', '' );
		}

		return $address;
	}
}
