<?php
/**
 * TrustScript Order Status Manager
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Order_Status {

	const DELIVERED_STATUS_SLUGS = array(
		'wc-delivered',        
		'wc-isdelivered',      
		'wc-shipdelivered',    
		'wc-order-delivered',  
		'wc-fulfilled',        
		'wc-isfulfilled',      
		'wc-shipfulfilled',    
		'wc-order-fulfilled', 
	);

	public function __construct() {
		add_action( 'init', array( $this, 'register_delivered_status' ), 5 );
		add_filter( 'wc_order_statuses', array( $this, 'add_delivered_to_order_statuses' ), 10 );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'include_delivered_in_reports' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action_delivered' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action_delivered' ), 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action_delivered' ), 20 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action_delivered_hpos' ), 10, 3 );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action_delivered' ) );
		add_action( 'woocommerce_order_action_mark_delivered', array( $this, 'handle_order_action_delivered' ) );
	}

	public function register_delivered_status() {
		$existing_status = $this->get_existing_delivered_status();
		
		if ( $existing_status ) {
			return;
		}

		register_post_status( 'wc-delivered', array(
			'label'                     => _x( 'Delivered', 'Order status', 'trustscript' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Delivered <span class="count">(%s)</span>', 'Delivered <span class="count">(%s)</span>', 'trustscript' ),
		) );
	}

	public function add_delivered_to_order_statuses( $order_statuses ) {
		$new_order_statuses = array();

		foreach ( $order_statuses as $key => $status ) {
			$new_order_statuses[ $key ] = $status;
			
			if ( 'wc-completed' === $key ) {
				$delivered_status = $this->get_existing_delivered_status();
				if ( $delivered_status ) {
					$status_obj = get_post_status_object( $delivered_status );
					$new_order_statuses[ $delivered_status ] = $status_obj ? $status_obj->label : __( 'Delivered', 'trustscript' );
				} else {
					$new_order_statuses['wc-delivered'] = __( 'Delivered', 'trustscript' );
				}
			}
		}

		return $new_order_statuses;
	}

	public function include_delivered_in_reports( $statuses ) {
		$delivered_status = $this->get_existing_delivered_status();
		
		if ( $delivered_status ) {
			$statuses[] = str_replace( 'wc-', '', $delivered_status );
		} else {
			$statuses[] = 'delivered';
		}
		
		return $statuses;
	}

	public function add_bulk_action_delivered( $bulk_actions ) {
		$bulk_actions['mark_delivered'] = __( 'Change status to delivered', 'trustscript' );
		return $bulk_actions;
	}



	public function handle_bulk_action_delivered( $redirect_to, $action, $post_ids ) {
		if ( $action !== 'mark_delivered' ) {
			return $redirect_to;
		}

		$status_slug = self::get_delivered_status_name();
		$changed = 0;
		
		foreach ( $post_ids as $post_id ) {
			$order = wc_get_order( $post_id );
			
			if ( $order ) {
				$order->update_status( $status_slug, __( 'Order marked as delivered by bulk action.', 'trustscript' ), true );
				$changed++;
			}
		}

		$redirect_to = add_query_arg( array(
			'bulk_action' => 'marked_delivered',
			'changed'     => $changed,
		), $redirect_to );

		return $redirect_to;
	}

	public function handle_hpos_bulk_action_delivered( $redirect_url, $action, $order_ids ) {
		if ( $action !== 'mark_delivered' ) {
			return $redirect_url;
		}

		$status_slug = self::get_delivered_status_name();
		$changed = 0;
		
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( $order ) {
				$order->update_status( $status_slug, __( 'Order marked as delivered by bulk action.', 'trustscript' ), true );
				$changed++;
			}
		}

		$redirect_url = add_query_arg( array(
			'bulk_action' => 'marked_delivered',
			'changed'     => $changed,
		), $redirect_url );

		return $redirect_url;
	}

	public function handle_bulk_action_delivered_hpos( $redirect_url, $action, $order_ids ) {
		if ( $action !== 'mark_delivered' ) {
			return $redirect_url;
		}

		$changed = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_status( 'delivered', __( 'Order status changed to delivered via bulk action.', 'trustscript' ), true );
				$changed++;
			}
		}

		$redirect_url = add_query_arg( array(
			'bulk_action' => 'marked_delivered',
			'changed' => $changed,
		), $redirect_url );

		return $redirect_url;
	}

	public function add_order_action_delivered( $actions ) {
		$actions['mark_delivered'] = __( 'Mark as Delivered', 'trustscript' );
		return $actions;
	}

	public function handle_order_action_delivered( $order ) {
		$delivered_status = $this->get_existing_delivered_status();
		$status_slug = $delivered_status ? str_replace( 'wc-', '', $delivered_status ) : 'delivered';
		
		$order->update_status( $status_slug, __( 'Order marked as delivered.', 'trustscript' ), true );
	}

	public function get_existing_delivered_status() {
		global $wp_post_statuses;
		
		foreach ( self::DELIVERED_STATUS_SLUGS as $status_slug ) {
			if ( isset( $wp_post_statuses[ $status_slug ] ) ) {
				return $status_slug;
			}
		}
		
		return null;
	}

	public static function get_delivered_status_slug() {
		$instance = new self();
		$existing = $instance->get_existing_delivered_status();
		
		return $existing ? $existing : 'wc-delivered';
	}

	public static function get_delivered_status_name() {
		$slug = self::get_delivered_status_slug();
		return str_replace( 'wc-', '', $slug );
	}

	public static function is_order_delivered( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		
		if ( ! $order ) {
			return false;
		}
		
		$current_status = $order->get_status();
		$delivered_status = self::get_delivered_status_name();
		
		return $current_status === $delivered_status;
	}
}
