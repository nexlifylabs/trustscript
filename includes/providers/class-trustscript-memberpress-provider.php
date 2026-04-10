<?php
/**
 * TrustScript MemberPress Service Provider
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_MemberPress_Provider extends TrustScript_Service_Provider {
	
	public function __construct() {
		$this->service_id = 'memberpress';
		$this->service_name = 'MemberPress';
		$this->service_icon = '👥';
		
		parent::__construct();
	}
	
	/**
	 * Detect if MemberPress is active
	 * 
	 * @return bool
	 */
	protected function detect_service() {
		$is_active = class_exists( 'MeprProduct' ) && class_exists( 'MeprTransaction' ); 
		return $is_active;
	}
	
	protected function register_hooks() {
		
		add_action( 'mepr-txn-status-complete', array( $this, 'handle_transaction_complete' ), 10, 2 );
		add_action( 'mepr-event-transaction-completed', array( $this, 'handle_transaction_event' ) );
		
		$is_enabled = get_option( 'trustscript_enable_service_memberpress', '1' ) === '1';
		$trigger_status = get_option( 'trustscript_trigger_status_memberpress', 'complete' );
	}
	
	/**
	 * Handle transaction completion
	 * 
	 * @param MeprTransaction $txn Transaction object
	 */
	public function handle_transaction_complete( $txn ) {
		
		if ( ! $txn || ! is_object( $txn ) ) {
			return;
		}
		
		$this->handle_status_change( $txn->id, 'complete' );
	}
	
	/**
	 * Handle transaction event
	 * 
	 * @param MeprEvent $event Event object
	 */
	public function handle_transaction_event( $event ) {
		
		if ( ! isset( $event->data ) || ! isset( $event->data->id ) ) {
			return;
		}
		
		$this->handle_status_change( $event->data->id, 'complete' );
	}
	
	/**
	 * Get all MemberPress transaction statuses
	 * 
	 * @return array
	 */
	public function get_available_statuses() {
		return array(
			'complete' => __( 'Complete', 'trustscript' ),
			'confirmed' => __( 'Confirmed', 'trustscript' ),
			'pending' => __( 'Pending', 'trustscript' ),
			'failed' => __( 'Failed', 'trustscript' ),
			'refunded' => __( 'Refunded', 'trustscript' ),
		);
	}
	
	/**
	 * Extract MemberPress transaction data
	 * 
	 * @param int $txn_id Transaction ID
	 * @return array|false
	 */
	public function extract_order_data( $txn_id ) {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return false;
		}
		
		$txn = new MeprTransaction( $txn_id );
		
		if ( ! $txn || ! $txn->id ) {
			return false;
		}
		
		$user = $txn->user();
		if ( ! $user || ! isset( $user->ID ) ) {
			return false;
		}
		
		$customer_name = $user->display_name;
		$customer_email = $user->user_email;
		
		$product = $txn->product();
		$service_name = $product ? $product->post_title : __( 'Membership', 'trustscript' );
		$service_description = $product && $product->post_excerpt ? wp_strip_all_tags( $product->post_excerpt ) : '';
		$product_id = $product ? $product->ID : 0;
		
		return array(
			'customer_name' => $customer_name,
			'customer_email' => $customer_email,
			'service_name' => $service_name,
			'service_description' => $service_description,
			'order_date' => $txn->created_at,
			'order_total' => $txn->total,
			'order_number' => $txn->trans_num,
			'product_id' => $product_id,
		);
	}
	
	/**
	 * Get customer email
	 * 
	 * @param int $txn_id Transaction ID
	 * @return string|false
	 */
	public function get_customer_email( $txn_id ) {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return false;
		}
		
		$txn = new MeprTransaction( $txn_id );
		$user = $txn->user();
		
		return ( $user && isset( $user->user_email ) ) ? $user->user_email : false;
	}
	
	/**
	 * Get customer name
	 * 
	 * @param int $txn_id Transaction ID
	 * @return string
	 */
	public function get_customer_name( $txn_id ) {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return '';
		}
		
		$txn = new MeprTransaction( $txn_id );
		$user = $txn->user();
		
		return ( $user && isset( $user->display_name ) ) ? $user->display_name : '';
	}
	
	/**
	 * Get service/membership name from specific transaction
	 * 
	 * @param int $txn_id Transaction ID
	 * @return string
	 */
	public function get_order_service_name( $txn_id ) {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return '';
		}
		
		$txn = new MeprTransaction( $txn_id );
		$product = $txn->product();
		
		return $product ? $product->post_title : __( 'Membership', 'trustscript' );
	}
	
	/**
	 * Get MemberPress-specific email placeholders
	 * 
	 * @param array $order_data Order data
	 * @param string $review_link Review link
	 * @return array Placeholders (universal + service-specific)
	 */
	protected function get_email_placeholders( $order_data, $review_link ) {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'class-trustscript-placeholder-mapper.php';
		
		return TrustScript_Placeholder_Mapper::map_placeholders(
			$order_data,
			'memberpress',
			$review_link
		);
	}
	
	/**
	 * Get default status
	 * 
	 * @return string
	 */
	public function get_default_status() {
		return 'complete';
	}

	/**
	 * Find MemberPress transaction by review token
	 * 
	 * @param string $unique_token Review token
	 * @return int|false Transaction ID or false if not found
	 */
	public function find_order_by_token( $unique_token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query to find transaction by token, caching not applicable
		$txn_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} 
			 WHERE meta_key = %s AND meta_value = %s
			 LIMIT 1",
			'_trustscript_review_token',
			$unique_token
		) );

		if ( $txn_id ) {
			return intval( $txn_id );
		}

		return false;
	}
}
