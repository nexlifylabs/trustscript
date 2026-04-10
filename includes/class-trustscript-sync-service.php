<?php
/**
 * TrustScript Sync Service
 * This class handles the synchronization of orders from various service providers (e.g., WooCommerce, MemberPress)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Sync_Service {

	public function sync_service_orders( $provider, $service_id, $days ) {

		$registry_published_ids = TrustScript_Order_Registry::get_published_order_ids( $service_id );

		$existing_orders = $this->fetch_existing_orders_from_trustscript( $service_id, $days );
		$api_existing_ids = array();

		if ( is_array( $existing_orders ) ) {
			foreach ( $existing_orders as $order ) {
				if ( isset( $order['sourceOrderId'], $order['source'] ) && $order['source'] === $service_id ) {
					$api_existing_ids[] = (string) $order['sourceOrderId'];

					if ( ! in_array( (string) $order['sourceOrderId'], $registry_published_ids, true ) ) {
						TrustScript_Order_Registry::mark_published( $service_id, $order['sourceOrderId'], null, null, 'api_backfill' );
						$registry_published_ids[] = (string) $order['sourceOrderId'];
					}
				}
			}
		}

		$all_published_ids = array_unique( array_merge( $registry_published_ids, $api_existing_ids ) );
		$processed = 0;
		$skipped   = 0;
		$total     = 0;

		$sync_trigger = ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'auto_sync' : 'manual_sync';

		if ( $service_id === 'woocommerce' && function_exists( 'wc_get_orders' ) ) {
			$trigger_status = get_option( 'trustscript_review_trigger_status', 'delivered' );
			$wc_status      = ( $trigger_status === 'delivered' ) ? 'delivered' : 'completed';
			$per_page = 50;
			$page     = 1;

			do {
				$args = array(
					'status' => $wc_status,
					'return' => 'ids',
					'limit'  => $per_page,
					'paged'  => $page,
				);

				if ( $days !== 'all' ) {
					$args['date_created'] = '>' . gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
				}

				$order_ids = wc_get_orders( $args );
				$total    += count( $order_ids );

				foreach ( $order_ids as $order_id ) {

					if ( in_array( (string) $order_id, $all_published_ids, true ) ) {
						$skipped++;
						continue;
					}

					$order          = wc_get_order( $order_id );
					$existing_token = $order ? $order->get_meta( '_trustscript_review_token' ) : '';

					if ( empty( $existing_token ) && $order ) {
						$existing_token = $order->get_meta( '_trustscript_order_token' );
					}

					if ( empty( $existing_token ) ) {
						$sent = $provider->handle_status_change( $order_id, $wc_status, '', true );
						if ( $sent ) {
							$processed++;
						}
					} else {
						TrustScript_Order_Registry::mark_published( $service_id, $order_id, null, null, $sync_trigger );
						$all_published_ids[] = (string) $order_id;
						$skipped++;
					}
				}

				$page++;
			} while ( count( $order_ids ) === $per_page );

		}
		elseif ( $service_id === 'memberpress' && class_exists( 'MeprTransaction' ) ) {
			global $wpdb;
			$table = esc_sql( $wpdb->prefix . 'mepr_transactions' );

			$trigger_status = get_option( 'trustscript_trigger_status_memberpress', 'complete' );

			$per_page = 50;
			$offset   = 0;

			do {
				if ( $days !== 'all' ) {
					$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
					$query = $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql()
						"SELECT mmt.id FROM {$table} mmt WHERE mmt.status = %s AND mmt.created_at >= %s ORDER BY mmt.id DESC LIMIT %d OFFSET %d",
						$trigger_status,
						$date,
						$per_page,
						$offset
					);
				} else {
					$query = $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql()
						"SELECT mmt.id FROM {$table} mmt WHERE mmt.status = %s ORDER BY mmt.id DESC LIMIT %d OFFSET %d",
						$trigger_status,
						$per_page,
						$offset
					);
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above
				$transaction_ids = $wpdb->get_col( $query );
				$total          += count( $transaction_ids );

				foreach ( $transaction_ids as $txn_id ) {

					if ( in_array( (string) $txn_id, $all_published_ids, true ) ) {
						$skipped++;
						continue;
					}

					$existing_token = get_post_meta( $txn_id, '_trustscript_review_token', true );

					if ( empty( $existing_token ) ) {
						$sent = $provider->handle_status_change( $txn_id, $trigger_status, '', true );
						if ( $sent ) {
							$processed++;
						}
					} else {
						TrustScript_Order_Registry::mark_published( $service_id, $txn_id, null, null, $sync_trigger );
						$all_published_ids[] = (string) $txn_id;
						$skipped++;
					}
				}

				$offset += $per_page;
			} while ( count( $transaction_ids ) === $per_page );

		}
		
		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'total'     => $total,
		);
	}

	/**
	 * Fetch existing orders for the given service from the TrustScript API, 
	 * within the specified lookback period. This is used to avoid duplicates 
	 * when syncing.
	 *
	 * @param string $service_id The service ID
	 * @param int|string $days Number of days to look back, or 'all'
	 * @return array|false Array of existing orders, or false on failure
	 */
	private function fetch_existing_orders_from_trustscript( $service_id, $days ) {
		$api_key = get_option( 'trustscript_api_key', '' );
		$base_url = trustscript_get_base_url();
		
		if ( empty( $api_key ) || empty( $base_url ) ) {
			return false;
		}
		
		$wordpress_orders_url = add_query_arg( array(
			'source' => rawurlencode( $service_id ),
			'days'   => $days !== 'all' ? intval( $days ) : 'all',
		), trailingslashit( $base_url ) . 'api/wordpress-orders' );
		
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'X-Site-URL' => get_site_url(),
			),
			'timeout' => 15,
		);
		
		$response = wp_remote_get( $wordpress_orders_url, $args );
		
		if ( is_wp_error( $response ) ) {
			return false;
		}
		
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		if ( $code !== 200 ) {
			return false;
		}
		
		$data = json_decode( $body, true );
		
		if ( ! isset( $data['orders'] ) || ! is_array( $data['orders'] ) ) {
			return false;
		}
		
		return $data['orders'];
	}
}