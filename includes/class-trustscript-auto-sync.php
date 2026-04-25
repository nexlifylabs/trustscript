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
	 * Cron hook name used to schedule and identify the auto-sync event.
	 */
	const CRON_HOOK = 'trustscript_auto_sync_orders';
	
	/** Number of orders per batch. */
	private $batch_size = 50;
	/** Seconds to pause between batches to avoid rate-limit and timeout issues. */
	private $batch_delay = 2;
	/** Maximum allowed PHP execution time in seconds for a sync run. */
	private $max_execution_time = 300;
	/** Maximum API calls allowed per sync run before backing off. */
	private $api_rate_limit = 100;

	/**
	 * Register cron, quota queue, and settings-change hooks.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_auto_sync' ) );
		add_action( 'trustscript_process_quota_queue', array( $this, 'process_quota_queue' ) );
		add_action( 'update_option_trustscript_auto_sync_enabled', array( $this, 'reschedule_cron' ), 10, 2 );
		add_action( 'update_option_trustscript_auto_sync_time', array( $this, 'reschedule_cron' ), 10, 2 );
	}

	/**
	 * Schedule the daily auto-sync cron event based on the configured run time.
	 *
	 * Does nothing if auto-sync is disabled. Unschedules any existing event before
	 * registering a fresh one.
	 *
	 * @since 1.0.0
	 */
	public static function schedule_cron() {
		if ( ! get_option( 'trustscript_auto_sync_enabled', false ) ) {
			return;
		}

		$sync_time = get_option( 'trustscript_auto_sync_time', '02:00' );
		
		$next_run = self::calculate_next_run( $sync_time );
		
		self::unschedule_cron();
		
		wp_schedule_event( $next_run, 'daily', self::CRON_HOOK );
	}

	/**
	 * Remove the scheduled auto-sync cron event if one exists.
	 *
	 * @since 1.0.0
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Reschedule the cron event when a relevant setting is updated.
	 *
	 * @since 1.0.0
	 * @param mixed $old_value Previous option value (unused).
	 * @param mixed $new_value New option value (unused).
	 */
	public function reschedule_cron( $old_value, $new_value ) {
		self::schedule_cron();
	}

	/**
	 * Calculate the next Unix timestamp for a given HH:MM time string.
	 *
	 * Returns a timestamp for today if the time has not yet passed, otherwise
	 * returns tomorrow's timestamp at the same time.
	 *
	 * @since 1.0.0
	 * @param string $time_string Time in HH:MM format. Defaults to 02:00 on parse failure.
	 * @return int Unix timestamp of the next scheduled run.
	 */
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

	/**
	 * Execute the auto-sync routine: publish approved reviews, sync orders, and drain the quota queue.
	 *
	 * Exits early if auto-sync is disabled.
	 *
	 * @since 1.0.0
	 */
	public function run_auto_sync() {

		if ( ! get_option( 'trustscript_auto_sync_enabled', false ) ) {
			return;
		}

		$published = $this->publish_approved_reviews();
		$this->sync_orders_to_trustscript();
		$this->process_quota_queue();
	}

	/**
	 * Drain the quota queue in batches until empty or a blocking condition is reached.
	 *
	 * A blocking condition is when a batch produces zero processed items and at least
	 * one skipped item, indicating quota exhaustion or rate-limiting.
	 *
	 * @since 1.0.0
	 * @return array Aggregated counts: { processed, skipped, failed }.
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

	/**
	 * Poll the TrustScript API for approved reviews and publish them as WordPress comments.
	 *
	 * Skips reviews where the customer has opted out, or where the order has
	 * already been published to the registry.
	 *
	 * @since 1.0.0
	 * @return int|void Number of reviews published, or void on early exit.
	 */
	private function publish_approved_reviews() {
		$api_key = trustscript_get_api_key();
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

	/**
	 * Validate and publish a single review payload as a WordPress comment.
	 *
	 * Runs a chain of guards in order:
	 * - `uniqueToken` present and a string
	 * - Project status is active
	 * - Review text is non-empty after sanitisation
	 * - Source service is in the allow-list (`woocommerce`, `memberpress`)
	 * - Token-to-order ID ownership verified via meta lookup
	 * - HMAC verification hash matches the stored hash
	 * - WooCommerce order is not cancelled, refunded, or failed
	 *
	 * On success, marks the order in the registry and persists publishing
	 * metadata to order/post meta.
	 *
	 * @since 1.0.0
	 * @param array $review Decoded review payload from the TrustScript API.
	 * @return bool True if the review was published, false if any guard failed.
	 */
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
			$order = null;
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

	/**
	 * Publish a review for every product in a WooCommerce order.
	 *
	 * Skips products that already have a comment with the same unique token.
	 * Notifies the TrustScript API after at least one comment is inserted.
	 *
	 * @since 1.0.0
	 * @param int         $order_id        WooCommerce order ID.
	 * @param string      $review_text     Sanitised review body.
	 * @param int         $rating          Star rating (1–5).
	 * @param string      $unique_token    TrustScript unique review token.
	 * @param string      $comment_date    Comment date in site timezone (Y-m-d H:i:s).
	 * @param string      $comment_date_gmt Comment date in UTC (Y-m-d H:i:s).
	 * @param array       $review          Full decoded review payload from the API.
	 * @param WC_Order|null $order         Pre-loaded order object, or null to fetch fresh.
	 * @return bool True if at least one product review was inserted.
	 */
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

	/**
	 * Publish a review against the membership product linked to a MemberPress transaction.
	 *
	 * Skips insertion if a comment with the same unique token already exists.
	 * Notifies the TrustScript API on successful insertion.
	 *
	 * @since 1.0.0
	 * @param int    $txn_id           MemberPress transaction ID.
	 * @param string $review_text      Sanitised review body.
	 * @param int    $rating           Star rating (1–5).
	 * @param string $unique_token     TrustScript unique review token.
	 * @param string $comment_date     Comment date in site timezone (Y-m-d H:i:s).
	 * @param string $comment_date_gmt Comment date in UTC (Y-m-d H:i:s).
	 * @param array  $review           Full decoded review payload from the API.
	 * @return bool True if the review comment was inserted successfully.
	 */
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

	/**
	 * Push recent orders from all active service providers to the TrustScript API.
	 *
	 * Iterates each active provider, syncing orders from the last 2 days to
	 * cover any missed during downtime. Stops early if the remaining execution
	 * time drops below 30 seconds. Pauses 500ms between providers to reduce
	 * server load. Persists run timestamp and aggregate stats to options on
	 * completion.
	 *
	 * @since 1.0.0
	 */
	private function sync_orders_to_trustscript() {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky -- Required for long-running sync operations
			set_time_limit( $this->max_execution_time );
		}
		$start_time = time();
		$lookback_days = 2;
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

	/**
	 * Notify the TrustScript API that a review has been published on this site.
	 *
	 * Sends a POST request with the unique token and publishing metadata.
	 * Silently returns false on any HTTP error or non-2xx response.
	 *
	 * @since 1.0.0
	 * @param string $unique_token TrustScript unique review token.
	 * @return bool True if the API accepted the notification, false otherwise.
	 */
	private function notify_trustscript_published( $unique_token ) {
		$api_key = trustscript_get_api_key();
		
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

	/**
	 * Get the Unix timestamp of the next scheduled auto-sync run.
	 *
	 * @since 1.0.0
	 * @return int|false Timestamp of the next run, or false if not scheduled.
	 */
	public static function get_next_run() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		return $timestamp ? $timestamp : false;
	}

	/**
	 * Check that a URL is structurally valid and uses an HTTP/HTTPS scheme.
	 *
	 * Does not make a network request. Used before sending outbound API calls
	 * to avoid wasted requests to malformed or non-HTTP URLs.
	 *
	 * @since 1.0.0
	 * @param string $url URL to validate.
	 * @return bool True if the URL is well-formed with an http or https scheme.
	 */
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
	
	/**
	 * Get the aggregate stats from the most recent auto-sync run.
	 *
	 * @since 1.0.0
	 * @return array { processed: int, skipped: int, errors: int, total: int }
	 */
	public static function get_last_run_stats() {
		return get_option( 'trustscript_auto_sync_last_stats', array(
			'processed' => 0,
			'skipped' => 0,
			'errors' => 0,
			'total' => 0,
		) );
	}

	/**
	 * Get the Unix timestamp of the most recent completed auto-sync run.
	 *
	 * @since 1.0.0
	 * @return int Unix timestamp, or 0 if the sync has never run.
	 */
	public static function get_last_run_time() {
		return get_option( 'trustscript_auto_sync_last_run', 0 );
	}
}