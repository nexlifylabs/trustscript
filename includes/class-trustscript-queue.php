<?php
/**
 * TrustScript Quota Queue - Manages a queue of orders that failed to send to TrustScript due
 * to quota limits or other retryable errors, with scheduled retries and admin management.
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
	 * Check if the queue table exists.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
	}

	/**
	 * Create or upgrade the queue table.
	 *
	 * Safe to call on every init — uses dbDelta for idempotency.
	 *
	 * @since 1.0.0
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
	 * Run schema migrations when the DB version is updated.
	 *
	 * @since 1.0.0
	 */
	public static function run_migrations() {
		global $wpdb;

		$table = esc_sql( self::get_table_name() );

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$table} ADD COLUMN scheduled_for datetime DEFAULT NULL AFTER queued_at"
			);
		}

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$table} ADD KEY idx_scheduled_for (scheduled_for)"
			);
		}
	}

	/**
	 * Add an order to the queue with a failure reason and optional delay.
	 *
	 * @since 1.0.0
	 * @param int    $order_id       WooCommerce order ID.
	 * @param string $service_id     Service slug e.g. 'woocommerce', 'memberpress'.
	 * @param string $failure_reason 'quota' | 'rate_limit' | 'network' | 'api_error' | 'delay'.
	 * @param int    $delay_seconds  Seconds from now to schedule processing. Default 0.
	 * @return bool True on insertion, false if already queued or provider inactive.
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
					$delay_seconds = 86400;
					$failure_reason = $last_error;
				} else {
					$delay_seconds = 300;
					$failure_reason = $last_error ?: 'api_error';
				}
			} else {
				return false;
			}
		}

		$scheduled_for = null;
		if ( $delay_seconds > 0 ) {
			$scheduled_for = wp_date( 'Y-m-d H:i:s', time() + $delay_seconds );
		}

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

		return (bool) $rows;
	}

	/**
	 * Remove an item from the queue by ID. Used for manual cleanup or if processing determines
	 * the item should no longer be retried.
	 *
	 * @since 1.0.0
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
	 * Mark a queue item as completed by ID. Used when processing succeeds in the cron.
	 *
	 * @since 1.0.0
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
	 * Mark a queue item as completed by order ID and service ID. Used when processing succeeds
	 * outside of the cron (e.g. manual retry from admin or successful webhook notification).
	 *
	 * @since 1.0.0
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
	 * Reset a queue item to pending by ID, clearing retry count and last attempt timestamp. 
	 * Used for manual retries from the admin.
	 *
	 * @since 1.0.0
	 * @param int $id
	 * @return bool
	 */
	public static function reset_to_pending( $id ) {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
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
	 * Register the cron job for processing the queue. Should be called on plugin activation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_cron_job() {
		if ( ! wp_next_scheduled( 'trustscript_process_queue_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'trustscript_process_queue_cron' );
		}
	}

	/**
	 * Hook callback to process the queue. Registered on 'trustscript_process_queue_cron' action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init_cron_hook() {
		add_action( 'trustscript_process_queue_cron', array( __CLASS__, 'process_queue_cron' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_custom_cron_intervals' ) );
	}

	/**
	 * Clear the cron job on plugin deactivation.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Cron callback to process the queue. Processes a batch of pending items that are ready (scheduled_for <= now).
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from internal method, cannot be parameterized.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) );
	}

	/**
	 * Count failed items.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function count_failed() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from internal method, cannot be parameterized.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'failed' ) );
	}

	/**
	 * Count completed items.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function count_completed() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from internal method, cannot be parameterized.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'completed' ) );
	}

	/**
	 * Count all items regardless of status.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Count pending items that are scheduled for a future time (scheduled_for > now).
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @param int    $page     1-based page number. Default 1.
	 * @param int    $per_page Items per page. Default 25.
	 * @param string $status   'pending' | 'failed' | '' for all. Default 'pending'.
	 * @return array {
	 *     @type array $items Queued items.
	 *     @type int   $total Total matching items.
	 * }
	 */
	public static function get_items( $page = 1, $per_page = 25, $status = 'pending' ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$table  = esc_sql( self::get_table_name() );
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

		if ( ! empty( $status ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
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
	 * Process a batch of pending queue items.
	 *
	 * Fetches up to $batch_size pending items and sends review requests via the
	 * appropriate service provider. Halts early on quota exhaustion or invalid API key.
	 * Items exceeding 5 retries are marked permanently failed.
	 *
	 * @since 1.0.0
	 * @param int  $batch_size        Max items to process in one batch. Default 20.
	 * @param bool $exclude_scheduled If true, skip items with a scheduled_for date. Default false.
	 * @return array {
	 *     @type int $processed Number of items successfully sent.
	 *     @type int $skipped   Number of items skipped or batch-halted.
	 *     @type int $failed    Number of items permanently failed (5 retries exhausted).
	 *     @type int $waiting   Number of pending items still awaiting their scheduled time.
	 * }
	 */
	public static function process_batch( $batch_size = 20, $exclude_scheduled = false ) {
		global $wpdb;

		$table   = esc_sql( self::get_table_name() );
		$results = array( 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0 );

		if ( $exclude_scheduled ) {
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
					// Hold for 24 hours to prevent auto-retry until quota resets.
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

			usleep( 100000 );
		}

		return $results;
	}
}
