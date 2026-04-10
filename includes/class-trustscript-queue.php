<?php
/**
 * TrustScript Quota Queue
 * This class manages a queue of orders that have failed to 
 * send review requests due to quota limits or other issues.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Queue {

	const TABLE_SUFFIX = 'trustscript_queue';
	const DB_VERSION   = '1.0';

	/**
	 * Return the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Check if the table exists in the database.
	 */
	public static function table_exists() {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// Safe: SHOW TABLES is introspection and doesn't execute user data. Table prefix is set by WordPress.
		// Caching is unnecessary: introspection queries are fast and caching could become stale if tables are manually added/dropped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
	}

	/**
	 * Create (or upgrade) the queue table.
	 * Safe to call on every init — uses dbDelta for idempotency.
	 */
	public static function create_table() {
		global $wpdb;

		$installed = get_option( 'trustscript_queue_db_version', '' );
		if ( $installed === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id              bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id        bigint(20)  UNSIGNED NOT NULL,
			service_id      varchar(50)          NOT NULL,
			failure_reason  varchar(20)          NOT NULL DEFAULT 'quota',
			retry_count     tinyint(3)  UNSIGNED NOT NULL DEFAULT 0,
			queued_at       datetime             NOT NULL,
			scheduled_for   datetime                      DEFAULT NULL,
			last_attempt_at datetime                      DEFAULT NULL,
			status          varchar(20)          NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			UNIQUE KEY   order_service (order_id, service_id),
			KEY          idx_status (status),
			KEY          idx_scheduled_for (scheduled_for),
			KEY          idx_queued_at (queued_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::run_migrations();

		update_option( 'trustscript_queue_db_version', self::DB_VERSION );
	}

	/**
	 * Run any necessary migrations when the DB version is updated.
	 *
	 * @return void
	 */
	public static function run_migrations() {
		global $wpdb;

		$table = esc_sql( self::get_table_name() );

		// Safe: INFORMATION_SCHEMA introspection to check existing columns before migration.
		// Caching not needed: schema checks only run during initialization.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$table
			),
			ARRAY_A
		);

		$column_names = wp_list_pluck( $columns, 'COLUMN_NAME' );

		if ( ! in_array( 'scheduled_for', $column_names, true ) ) {
			// Safe: ALTER TABLE with computed table name from get_table_name() (WordPress prefix + suffix).
			// Table name is not user input and is safe for direct interpolation.
			// Schema changes are necessary during plugin initialization/migration.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$table} ADD COLUMN scheduled_for datetime DEFAULT NULL AFTER queued_at" 
			);
		}

		// Safe: INFORMATION_SCHEMA introspection to check existing indexes before migration.
		// Caching not needed: schema checks only run during initialization.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$table
			),
			ARRAY_A
		);

		$index_names = wp_list_pluck( $indexes, 'INDEX_NAME' );

		if ( ! in_array( 'idx_scheduled_for', $index_names, true ) ) {
			// Safe: ALTER TABLE with computed table name from get_table_name() (WordPress prefix + suffix).
			// Table name is not user input and is safe for direct interpolation.
			// Schema changes are necessary during plugin initialization/migration.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$table} ADD KEY idx_scheduled_for (scheduled_for)"
			);
		}
	}

	/**
	 * Add an order to the queue with a specified failure reason and optional delay.
	 * 
	 * @param int      $order_id
	 * @param string   $service_id         e.g. 'woocommerce', 'memberpress'
	 * @param string   $failure_reason     'quota' | 'rate_limit' | 'network' | 'api_error' | 'delay'
	 * @param int      $delay_seconds      How many seconds from now should this be processed? (optional)
	 * @return bool    True on a new insertion, false if already queued.
	 */
	public static function add( $order_id, $service_id, $failure_reason = 'quota', $delay_seconds = 0 ) {
		global $wpdb;

		$table = esc_sql( self::get_table_name() );
		
		if ( (int) $delay_seconds === 0 && 'delay' === $failure_reason ) {
			$service_manager = TrustScript_Service_Manager::get_instance();
			$providers       = $service_manager->get_active_providers();
			
			if ( isset( $providers[ $service_id ] ) ) {
				$provider = $providers[ $service_id ];
				$success  = $provider->retry_review_request( $order_id );
				
				if ( $success ) {
					return true;
				}
				
				$last_error = $provider->get_last_api_error();
				
				if ( 'quota' === $last_error || 'api_key_invalid' === $last_error ) {
					// Safety net: queue with 24-hour delay. Backend webhook should notify sooner,
					// but this ensures self-healing if the webhook notification is missed.
					$delay_seconds = 86400;
				} else {
					// Temporary error (network, timeout): retry sooner.
					$delay_seconds = 300;
				}
			} else {
				// Provider not found: hard block. Backend will always reject the request
				// until the provider (e.g. WooCommerce, MemberPress) is reinstalled/activated.
				return false;
			}
		}
		
		$scheduled_for = null;
		if ( $delay_seconds > 0 ) {
			$delay_timestamp = time() + $delay_seconds;
			$scheduled_for = wp_date( 'Y-m-d H:i:s', $delay_timestamp );
		}
		// Safe: INSERT IGNORE with prepared values for order_id, service_id, failure_reason, and scheduled_for.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$table}
					(order_id, service_id, failure_reason, retry_count, queued_at, scheduled_for, status)
				 VALUES (%d, %s, %s, 0, %s, %s, 'pending')",
				absint( $order_id ),
				sanitize_key( $service_id ),
				sanitize_key( $failure_reason ),
				current_time( 'mysql' ),
				$scheduled_for
			)
		);

		if ( $rows ) {
			$log_msg = '[TrustScript Queue] Enqueued order #' . $order_id .
				' service=' . $service_id .
				' reason=' . $failure_reason;
			
			if ( $delay_seconds > 0 ) {
				$log_msg .= ' scheduled_for=' . $scheduled_for;
			}
		}

		return (bool) $rows;
	}

	/**
	 * Remove an item from the queue by ID.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function remove( $id ) {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Mark a queue item as completed by ID.
	 *
	 * @param int $id Queue item ID
	 * @return bool
	 */
	public static function mark_completed( $id ) {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			$table,
			array( 'status' => 'completed', 'last_attempt_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a queue item as completed by order ID and service ID.
	 *
	 * @param int    $order_id
	 * @param string $service_id
	 * @return bool
	 */
	public static function mark_completed_by_order( $order_id, $service_id ) {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			$table,
			array( 'status' => 'completed', 'last_attempt_at' => current_time( 'mysql' ) ),
			array(
				'order_id'   => absint( $order_id ),
				'service_id' => sanitize_key( $service_id ),
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Reset a queue item to pending for retry by ID.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function reset_to_pending( $id ) {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// Safe: Raw UPDATE query to properly set last_attempt_at to SQL NULL (not empty string).
		// Using raw query because wpdb->update() converts PHP null to empty string with %s format.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s, retry_count = %d, last_attempt_at = NULL WHERE id = %d",
				'pending',
				0,
				absint( $id )
			)
		);
	}


	/**
	 * Register the hourly cron job for automated queue processing.
	 * Called during plugin activation.
	 *
	 * @return void
	 */
	public static function register_cron_job() {
		if ( ! wp_next_scheduled( 'trustscript_process_queue_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'trustscript_process_queue_cron' );
		}
	}

	/**
	 * Hook the cron callback.
	 * Must be called on every page load (e.g., in plugins_loaded).
	 *
	 * @return void
	 */
	public static function init_cron_hook() {
		add_action( 'trustscript_process_queue_cron', array( __CLASS__, 'process_queue_cron' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_intervals' ) );
	}

	/**
	 * Clear the cron job on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unregister_cron_job() {
		$timestamp = wp_next_scheduled( 'trustscript_process_queue_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'trustscript_process_queue_cron' );
		}
	}

	/**
	 * Custom cron interval of every 6 hours for processing the queue.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_custom_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['every_6_hours'] ) ) {
			$schedules['every_6_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 hours', 'trustscript' ),
			);
		}
		return $schedules;
	}

	/**
	 * Process the queue via WordPress cron.
	 *
	 * Callback triggered by the hourly WordPress cron schedule. Processes a batch of pending
	 * queue items whose scheduled_for time has arrived. Implements a transient-based rate limit
	 * of once per minute to prevent concurrent execution and database overhead.
	 *
	 * @return bool True if items were processed, false if skipped due to rate limit or empty queue.
	 */
	public static function process_queue_cron() {
		global $wpdb;

		$table = esc_sql( self::get_table_name() );

		if ( ! self::table_exists() ) {
			return false;
		}

		$lock_key = 'trustscript_queue_processing_lock';
		$lock = get_transient( $lock_key );
		
		if ( $lock ) {
			return false;
		}
		
		set_transient( $lock_key, 1, 60 );

		// Safe: COUNT query with prepared statement for scheduled_for timestamp comparison.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ready_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} 
				 WHERE status = 'pending' 
				 AND (scheduled_for IS NULL OR scheduled_for <= %s)",
				current_time( 'mysql' )
			)
		);

		if ( $ready_count === 0 ) {
			return false;
		}

		$results = self::process_batch( 20, false );

		if ( isset( $results['processed'] ) && $results['processed'] > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Count pending items.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// Safe: Simple COUNT query with static WHERE clause. Table name from get_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Count failed items.
	 *
	 * @return int
	 */
	public static function count_failed() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// Safe: Simple COUNT query with static WHERE clause. Table name from get_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
	}

	/**
	 * Count completed items.
	 *
	 * @return int
	 */
	public static function count_completed() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// Safe: Simple COUNT query with static WHERE clause. Table name from get_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
	}

	/**
	 * Count all items regardless of status.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// Safe: Simple COUNT query with no WHERE clause. Table name from get_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Count pending items that are scheduled for a future time (scheduled_for > now).
	 *
	 * @return int
	 */
	public static function count_scheduled() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} 
				 WHERE status = 'pending' 
				 AND scheduled_for IS NOT NULL 
				 AND scheduled_for > %s",
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Count pending items that are ready to be processed (scheduled_for IS NULL OR scheduled_for <= now).
	 *
	 * @return int
	 */
	public static function count_ready() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} 
				 WHERE status = 'pending' 
				 AND (scheduled_for IS NULL OR scheduled_for <= %s)",
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Get queue items with pagination and optional status filter.
	 *
	 * @param int    $page       1-based page number.
	 * @param int    $per_page
	 * @param string $status     'pending' | 'failed' | '' (all)
	 * @return array { items: array, total: int }
	 */
	public static function get_items( $page = 1, $per_page = 25, $status = 'pending' ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$table = esc_sql( self::get_table_name() );
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

		if ( ! empty( $status ) ) {
			// Safe: COUNT query with prepared status parameter. 
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
			// Safe: SELECT query with prepared status parameter and LIMIT/OFFSET.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = %s ORDER BY queued_at ASC LIMIT %d OFFSET %d",
					$status,
					(int) $per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// Safe: Simple COUNT query with no WHERE clause. Table name from get_table_name().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} ORDER BY queued_at ASC LIMIT %d OFFSET %d",
					(int) $per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Process a batch of pending queue items by calling the appropriate service provider.
	 *
	 * Retrieves up to $batch_size pending items from the queue and attempts to send review
	 * requests via the appropriate service provider. Only processes items where the scheduled_for
	 * time has arrived (or is NULL for immediate items). Halts processing early if the API
	 * provider responds with quota exhausted or invalid API key to avoid unnecessary API calls
	 * when the account has hit its limits.
	 *
	 * Items are processed in order of queued_at (FIFO). Each attempt is stamped with the
	 * current timestamp and retry count before calling the API. Successfully published items
	 * are marked completed. Failed items are retried up to 5 times; items exceeding max
	 * retries are marked as permanently failed.
	 *
	 * @param int  $batch_size          Max items to attempt to process in one batch. Default 20.
	 * @param bool $exclude_scheduled   If true, skip scheduled items (where scheduled_for IS NOT NULL).
	 *                                  If false (default), process all ready items including those
	 *                                  whose scheduled time has arrived. Default false.
	 * @return array {
	 *     @type int $processed Number of items successfully published to the API.
	 *     @type int $skipped   Number of items skipped (registry gate, provider inactive, or batch halted).
	 *     @type int $failed    Number of items marked as permanently failed (5 retries exhausted).
	 *     @type int $waiting   Number of pending items still waiting for their scheduled time.
	 * }
	 */
	public static function process_batch( $batch_size = 20, $exclude_scheduled = false ) {
		global $wpdb;

		$table = esc_sql( self::get_table_name() );
		$results = array( 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0 );

		if ( $exclude_scheduled ) {
			// Safe: SELECT query with hardcoded WHERE clause and prepared LIMIT parameter.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} 
					 WHERE status = 'pending' AND scheduled_for IS NULL
					 ORDER BY queued_at ASC
					 LIMIT %d",
					$batch_size
				),
				ARRAY_A
			);
		} else {
			// Safe: SELECT query with prepared scheduled_for timestamp and LIMIT parameters.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} 
					 WHERE status = 'pending' 
					 AND (scheduled_for IS NULL OR scheduled_for <= %s)
					 ORDER BY queued_at ASC 
					 LIMIT %d",
					current_time( 'mysql' ),
					$batch_size
				),
				ARRAY_A
			);
		}

		if ( empty( $items ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$waiting_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} 
					 WHERE status = 'pending' 
					 AND scheduled_for > %s",
					current_time( 'mysql' )
				)
			);
			
			if ( $waiting_count > 0 ) {
				$results['waiting'] = $waiting_count;
			} 

			return $results;
		}

		$service_manager = TrustScript_Service_Manager::get_instance();
		$providers       = $service_manager->get_active_providers();

		foreach ( $items as $item ) {
			$id          = (int) $item['id'];
			$order_id    = (int) $item['order_id'];
			$service_id  = $item['service_id'];
			$retry_count = (int) $item['retry_count'] + 1;

			if ( TrustScript_Order_Registry::is_published( $service_id, $order_id ) ) {
				self::mark_completed( $id );
				$results['skipped']++;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'last_attempt_at' => current_time( 'mysql' ),
					'retry_count'     => $retry_count,
				),
				array( 'id' => $id ),
				array( '%s', '%d' ),
				array( '%d' )
			);

			if ( ! isset( $providers[ $service_id ] ) ) {
				$results['skipped']++;
				continue;
			}

			$provider = $providers[ $service_id ];
			$success  = $provider->retry_review_request( $order_id );

			if ( $success ) {
				self::mark_completed( $id );
				$results['processed']++;
			} else {
				$last_error = $provider->get_last_api_error();

				if ( 'quota' === $last_error ) {
					// Hold this item; don't retry until server notifies quota is reset.
					// Set scheduled_for to 24 hours in the future to prevent auto-retry.
					$hold_until = wp_date( 'Y-m-d H:i:s', time() + 86400 );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array( 'scheduled_for' => $hold_until ),
						array( 'id' => $id ),
						array( '%s' ),
						array( '%d' )
					);
					$results['skipped']++;
					break;
				}

				if ( 'api_key_invalid' === $last_error ) {
					$results['skipped']++;
					break;
				}

				if ( $retry_count >= 5 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array( 'status' => 'failed' ),
						array( 'id' => $id ),
						array( '%s' ),
						array( '%d' )
					);
					$results['failed']++;
				} else {
					$results['skipped']++;
				}
			}

			usleep( 250000 );
		}

		return $results;
	}
}
