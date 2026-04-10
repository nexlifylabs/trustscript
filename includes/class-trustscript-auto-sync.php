<?php
/**
 * TrustScript Auto-Sync Scheduler
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Auto_Sync {
	
	/**
	 * Cron hook name
	 */
	const CRON_HOOK = 'trustscript_auto_sync_orders';
	
	// Batch processing parameters sending requests in batches of 50 with a 2 second delay between batches to avoid hitting API rate limits or PHP execution timeouts. These can be adjusted as needed.
	private $batch_size = 50;
	private $batch_delay = 2;
	private $max_execution_time = 300;
	private $api_rate_limit = 100;

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_auto_sync' ) );

		// This action can be triggered manually from the backend when an admin upgrades 
		// their plan or when we receive a webhook indicating that the quota has been reset. 
		// It can also be scheduled to run periodically if desired.
		add_action( 'trustscript_process_quota_queue', array( $this, 'process_quota_queue' ) );

		add_action( 'update_option_trustscript_auto_sync_enabled', array( $this, 'reschedule_cron' ), 10, 2 );
		add_action( 'update_option_trustscript_auto_sync_time', array( $this, 'reschedule_cron' ), 10, 2 );
	}

	public static function schedule_cron() {
		if ( ! get_option( 'trustscript_auto_sync_enabled', false ) ) {
			return;
		}

		$sync_time = get_option( 'trustscript_auto_sync_time', '02:00' );
		
		$next_run = self::calculate_next_run( $sync_time );
		
		self::unschedule_cron();
		
		wp_schedule_event( $next_run, 'daily', self::CRON_HOOK );
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public function reschedule_cron( $old_value, $new_value ) {
		self::schedule_cron();
	}

	private static function calculate_next_run( $time_string ) {
		$timezone = wp_timezone();
		$now = new DateTime( 'now', $timezone );
		
		$parts = explode( ':', $time_string );
		if ( count( $parts ) < 2 ) {
			$parts = array( '02', '00' );
		}
		list( $hour, $minute ) = $parts;
		
		$target = new DateTime( 'today', $timezone );
		$target->setTime( (int) $hour, (int) $minute, 0 );
		
		if ( $target <= $now ) {
			$target->modify( '+1 day' );
		}
		
		return $target->getTimestamp();
	}

	public function run_auto_sync() {

		if ( ! get_option( 'trustscript_auto_sync_enabled', false ) ) {
			return;
		}

		$published = $this->publish_approved_reviews();
		$this->sync_orders_to_trustscript();
		$this->process_quota_queue();
	}

	/**
	 * Drains the quota queue, processing items until the queue is empty or we hit an API rate limit / quota exhaustion condition. 
	 * This is intended to be triggered manually from the backend when an admin upgrades their plan or when we receive a webhook 
	 * indicating that the quota has been reset. It can also be scheduled to run periodically if desired.
	 *
	 * @return array Aggregated { processed, skipped, failed } counts.
	 */
	public function process_quota_queue() {
		$pending = TrustScript_Queue::count_pending();

		if ( $pending === 0 ) {
			return array( 'processed' => 0, 'skipped' => 0, 'failed' => 0 );
		}

		$totals     = array( 'processed' => 0, 'skipped' => 0, 'failed' => 0 );
		$batch_size = 20;
		$max_passes = (int) ceil( $pending / $batch_size ) + 1;
		$passes     = 0;

		do {
			$result = TrustScript_Queue::process_batch( $batch_size );

			$totals['processed'] += $result['processed'];
			$totals['skipped']   += $result['skipped'];
			$totals['failed']    += $result['failed'];

			$passes++;

			if ( $result['processed'] === 0 && $result['skipped'] > 0 ) {
				break;
			}

			$still_pending = TrustScript_Queue::count_pending();

		} while ( $still_pending > 0 && $passes < $max_passes );

		return $totals;
	}

	private function publish_approved_reviews() {
		$api_key = get_option( 'trustscript_api_key', '' );
		$base_url = trustscript_get_base_url();
		
		if ( empty( $api_key ) || empty( $base_url ) ) {
			return;
		}

		$wordpress_orders_url = trailingslashit( $base_url ) . 'api/wordpress-orders/sync';
		
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept' => 'application/json',
				'X-Site-URL' => get_site_url(),
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $wordpress_orders_url, $args );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return;
		}
		
		$data = json_decode( $body, true );
		
		if ( ! isset( $data['orders'] ) || ! is_array( $data['orders'] ) ) {
			return;
		}

		$approved_reviews = array_filter( $data['orders'], function( $order ) {
			return isset( $order['status'] ) && $order['status'] === 'approved';
		} );

		if ( empty( $approved_reviews ) ) {
			return;
		}

		$published_count = 0;

		foreach ( $approved_reviews as $review ) {
			if ( isset( $review['isOptedOut'] ) && $review['isOptedOut'] ) {
				continue;
			}
			
			$unique_token = sanitize_text_field( $review['uniqueToken'] ?? '' );
			if ( ! empty( $unique_token ) && ! empty( $review['sourceOrderId'] ) ) {
				$source_service = sanitize_text_field( $review['source'] ?? 'woocommerce' );
				if ( TrustScript_Order_Registry::is_published( $source_service, $review['sourceOrderId'] ) ) {
					continue;
				}
			}
			
			if ( $this->publish_review( $review ) ) {
				$published_count++;
			}
		}

		return $published_count;
	}

	private function publish_review( $review ) {
		$unique_token = $review['uniqueToken'] ?? 'unknown';
		
		if ( empty( $review['uniqueToken'] ) || ! is_string( $review['uniqueToken'] ) ) {
			return false;
		}

		if ( isset( $review['projectStatus']['status'] ) && $review['projectStatus']['status'] !== 'active' ) {
			return false;
		}

		$review_text = sanitize_textarea_field( $review['finalText'] ?? $review['reviewText'] ?? '' );
		if ( empty( $review_text ) ) {
			return false;
		}

		$unique_token = sanitize_text_field( $review['uniqueToken'] );
		$rating = isset( $review['rating'] ) ? max( 1, min( 5, intval( $review['rating'] ) ) ) : 5;
		$source_service = sanitize_text_field( $review['source'] ?? 'woocommerce' );
		$source_order_id = isset( $review['sourceOrderId'] ) ? sanitize_text_field( $review['sourceOrderId'] ) : '';
		$comment_date = current_time( 'mysql' );
		$comment_date_gmt = current_time( 'mysql', true );
		
		if ( isset( $review['approvedAt'] ) && ! empty( $review['approvedAt'] ) ) {
			$approved_timestamp = strtotime( $review['approvedAt'] );
			if ( $approved_timestamp ) {
				$comment_date = wp_date( 'Y-m-d H:i:s', $approved_timestamp );
				$comment_date_gmt = wp_date( 'Y-m-d H:i:s', $approved_timestamp, new DateTimeZone( 'UTC' ) );
			}
		}

		$valid_services = array( 'woocommerce', 'memberpress' );
		if ( ! in_array( $source_service, $valid_services, true ) ) {
			return false;
		}
		
		if ( $source_service === 'woocommerce' && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => '_trustscript_review_token',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $unique_token,
				'return'     => 'ids',
			) );
			$found_id = ! empty( $orders ) ? (int) $orders[0] : 0;
		} else {
			$posts = get_posts( array(
				'post_type'      => 'any',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => '_trustscript_review_token',
						'value' => $unique_token,
					),
				),
				'fields'         => 'ids',
			) );
			$found_id = ! empty( $posts ) ? (int) $posts[0] : 0;
		}
		
		if ( $found_id !== (int) $source_order_id ) {
			return false;
		}
				
		if ( $source_service === 'woocommerce' && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $source_order_id );
			$stored_hash = $order ? $order->get_meta( '_trustscript_verification_hash' ) : '';
		} else {
			$stored_hash = get_post_meta( $source_order_id, '_trustscript_verification_hash', true );
		}

		$incoming_hash = ! empty( $review['verificationHash'] ) ? sanitize_text_field( $review['verificationHash'] ) : '';

		if ( ! empty( $stored_hash ) && ! hash_equals( $stored_hash, $incoming_hash ) ) {
			return false;
		}

		if ( $source_service === 'woocommerce' && function_exists( 'wc_get_order' ) ) {
			if ( $order ) {
				$order_status = $order->get_status();
				if ( in_array( $order_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
					return false;
				}
			}
		} 

		$result = false;
		if ( $source_service === 'woocommerce' ) {
			$result = $this->publish_woocommerce_review( $source_order_id, $review_text, $rating, $unique_token, $comment_date, $comment_date_gmt, $review, $order ?? null );
		} elseif ( $source_service === 'memberpress' ) {
			$result = $this->publish_memberpress_review( $source_order_id, $review_text, $rating, $unique_token, $comment_date, $comment_date_gmt, $review );
		}

		if ( $result ) {
			TrustScript_Order_Registry::mark_published( $source_service, $source_order_id, null, null, 'auto_sync' );
			
			if ( $source_service === 'woocommerce' && function_exists( 'wc_get_order' ) ) {
				if ( ! $order ) {
					$order = wc_get_order( $source_order_id );
				}
				if ( $order ) {
					$order->update_meta_data( '_trustscript_review_published', 'yes' );
					$order->update_meta_data( '_trustscript_review_published_at', current_time( 'mysql' ) );
					$order->update_meta_data( '_trustscript_publishing_mode', 'auto_sync_polling' );
					$order->save();
				}
			} else {
				update_post_meta( $source_order_id, '_trustscript_review_published', 'yes' );
				update_post_meta( $source_order_id, '_trustscript_review_published_at', current_time( 'mysql' ) );
				update_post_meta( $source_order_id, '_trustscript_publishing_mode', 'auto_sync_polling' );
			}
		}

		return $result;
	}

	private function publish_woocommerce_review( $order_id, $review_text, $rating, $unique_token, $comment_date, $comment_date_gmt, $review, $order = null ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return false;
		}

		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$customer_email = $order->get_billing_email();

		$published_count = 0;
		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			if ( ! $product_id ) {
				continue;
			}

			$existing = get_comments( array(
				'post_id' => $product_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key' => '_trustscript_review_token',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $unique_token,
				'count' => true,
			) );

			if ( $existing > 0 ) {
				continue;
			}

			$comment_id = wp_insert_comment( array(
				'comment_post_ID' => $product_id,
				'comment_content' => $review_text,
				'comment_author' => $customer_name,
				'comment_author_email' => $customer_email,
				'comment_approved' => 1,
				'comment_type' => 'review',
				'user_id' => 0,
				'comment_date' => $comment_date,
				'comment_date_gmt' => $comment_date_gmt,
			), true );

			if ( ! is_wp_error( $comment_id ) ) {
				update_comment_meta( $comment_id, 'rating', $rating );
				update_comment_meta( $comment_id, '_trustscript_review_token', $unique_token );
				update_comment_meta( $comment_id, 'verified', 1 );
				if ( ! empty( $review['verificationHash'] ) ) {
					update_comment_meta( $comment_id, '_trustscript_verification_hash', sanitize_text_field( $review['verificationHash'] ) );
				}
				$published_count++;
			}
		}

		if ( $published_count > 0 ) {
			$this->notify_trustscript_published( $unique_token );
			return true;
		}
		return false;
	}

	private function publish_memberpress_review( $txn_id, $review_text, $rating, $unique_token, $comment_date, $comment_date_gmt, $review ) {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return false;
		}

		$txn = new MeprTransaction( $txn_id );
		if ( ! $txn->id ) {
			return false;
		}

		$membership_id = $txn->product_id;
		if ( ! $membership_id ) {
			return false;
		}

		$user = $txn->user();
		$customer_name = $user ? $user->display_name : 'Verified Member';
		$customer_email = $user ? $user->user_email : '';

		$existing = get_comments( array(
			'post_id' => $membership_id,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key' => '_trustscript_review_token',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => $unique_token,
			'count' => true,
		) );

		if ( $existing > 0 ) {
			return false;
		}

		$comment_id = wp_insert_comment( array(
			'comment_post_ID' => $membership_id,
			'comment_content' => $review_text,
			'comment_author' => $customer_name,
			'comment_author_email' => $customer_email,
			'comment_approved' => 1,
			'comment_type' => 'review',
			'user_id' => 0,
			'comment_date' => $comment_date,
			'comment_date_gmt' => $comment_date_gmt,
		), true );

		if ( ! is_wp_error( $comment_id ) ) {
			update_comment_meta( $comment_id, 'rating', $rating );
			update_comment_meta( $comment_id, '_trustscript_review_token', $unique_token );
			update_comment_meta( $comment_id, 'verified', 1 );
			if ( ! empty( $review['verificationHash'] ) ) {
				update_comment_meta( $comment_id, '_trustscript_verification_hash', sanitize_text_field( $review['verificationHash'] ) );
			}
			$this->notify_trustscript_published( $unique_token );
			return true;
		}
		return false;
	}

	private function sync_orders_to_trustscript() {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky -- Required for long-running sync operations
			set_time_limit( $this->max_execution_time );
		}
		$start_time = time();

		$lookback_days = get_option( 'trustscript_auto_sync_lookback', 30 );
		$lookback_days = max( 1, min( 365, intval( $lookback_days ) ) );
		
		$service_manager = TrustScript_Service_Manager::get_instance();
		$active_providers = $service_manager->get_active_providers();
		
		if ( empty( $active_providers ) ) {
			return;
		}

		$total_processed = 0;
		$total_skipped = 0;
		$total_errors = 0;
		$total_orders = 0;
		$batches_processed = 0;
		$provider_count = count( $active_providers );
		$sync_service = new TrustScript_Sync_Service();

		foreach ( $active_providers as $service_id => $provider ) {
			try {
				if ( ( time() - $start_time ) > ( $this->max_execution_time - 30 ) ) {
					break;
				}
				
				$result = $sync_service->sync_service_orders( $provider, $service_id, $lookback_days );
				
				if ( is_array( $result ) ) {
					$total_processed += $result['processed'] ?? 0;
					$total_skipped += $result['skipped'] ?? 0;
					$total_errors += $result['errors'] ?? 0;
					$total_orders += $result['total'] ?? 0;
				} else {
					$total_processed += (int) $result;
				}
				
				$batches_processed++;
				
				if ( $batches_processed < $provider_count ) {
					usleep( 500000 );
				}
				
			} catch ( Exception $e ) {
				$total_errors++;
			}
		}

		update_option( 'trustscript_auto_sync_last_run', time() );
		update_option( 'trustscript_auto_sync_last_stats', array(
			'processed' => $total_processed,
			'skipped' => $total_skipped,
			'errors' => $total_errors,
			'total' => $total_orders,
		) );
	}

	private function notify_trustscript_published( $unique_token ) {
		$api_key = get_option( 'trustscript_api_key', '' );
		
		if ( empty( $api_key ) ) {
			return;
		}

		$base_url = trustscript_get_base_url();

		$callback_url = trailingslashit( $base_url ) . 'api/wordpress-orders/admin-notification';
		
		if ( ! $this->validate_webhook_url( $callback_url ) ) {
			return;
		}
		
		$payload = array(
			'uniqueToken' => $unique_token,
			'publishingStatus' => 'published',
			'publishedAt' => current_time( 'mysql', true ),
			'publishingMode' => 'manual_sync',
		);

		$start_time = microtime( true );

		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
				'X-Site-URL' => get_site_url(),
			),
			'body' => wp_json_encode( $payload ),
			'timeout' => 10,
		);

		$response = wp_remote_post( $callback_url, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}
		
		return true;
	}

	public static function get_next_run() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		return $timestamp ? $timestamp : false;
	}

	private function validate_webhook_url( $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		$parsed_url = wp_parse_url( $url );
		if ( ! $parsed_url || empty( $parsed_url['host'] ) ) {
			return false;
		}

		if ( ! in_array( $parsed_url['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}

		return true;
	}
	
	public static function get_last_run_stats() {
		return get_option( 'trustscript_auto_sync_last_stats', array(
			'processed' => 0,
			'skipped' => 0,
			'errors' => 0,
			'total' => 0,
		) );
	}

	public static function get_last_run_time() {
		return get_option( 'trustscript_auto_sync_last_run', 0 );
	}
}