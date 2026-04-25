<?php
/**
 * TrustScript Opt-Out Manager
 *
 * Handles local storage of customer opt-outs for review requests, including database
 * table management and backfilling existing orders when a new opt-out is recorded.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Opt_Out {

	const TABLE_SUFFIX = 'trustscript_optouts';
	const DB_VERSION   = '1.0';

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function table_exists() {
		global $wpdb;
		$table = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create the opt-out table
	 */
	public static function create_table() {
		global $wpdb;

		if ( get_option( 'trustscript_optout_db_version', '' ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email_hash VARCHAR(64)         NOT NULL COMMENT 'SHA-256 hex of lowercase-trimmed customer email',
			created_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uq_email_hash (email_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'trustscript_optout_db_version', self::DB_VERSION );
	}

	/**
	 * Check if a given email hash is in the opt-out table, indicating the customer has opted out of review requests.
	 *
	 * @param string $email_hash SHA-256 hex string (64 chars).
	 * @return bool
	 */
	public static function is_opted_out( $email_hash ) {
		global $wpdb;

		if ( empty( $email_hash ) || strlen( $email_hash ) !== 64 ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE email_hash = %s LIMIT 1",
				$email_hash
			)
		);

		return $count > 0;
	}

	/**
	 * Record a new opt-out by inserting the email hash into the database. Uses INSERT IGNORE to prevent duplicates.
	 *
	 * @param string $email_hash SHA-256 hex string (64 chars).
	 * @return bool True on first insert, false if already present or on error.
	 */
	public static function record_opt_out( $email_hash ) {
		global $wpdb;

		if ( empty( $email_hash ) || strlen( $email_hash ) !== 64 ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$table} (email_hash, created_at) VALUES (%s, %s)",
				$email_hash,
				current_time( 'mysql', true )
			)
		);

		return (bool) $rows;
	}

	/**
	 * Backfill existing orders with the opt-out status for a given email hash.
	 * This is used when a customer opts out via the email link, and we want to
	 * retroactively mark all their past orders as opted out for review requests.
	 * 
	 * @param string $email_hash SHA-256 hex string (64 chars).
	 * @return int Number of orders updated with the opt-out status.
	 */
	public static function backfill_pending_orders( $email_hash ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$total_updated = 0;
		$batch_size = 500;
		$offset = 0;
		$has_more = true;

		while ( $has_more ) {
			$order_ids = wc_get_orders( array(
				'status'     => 'any',
				'return'     => 'ids',
				'limit'      => $batch_size,
				'offset'     => $offset,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => '_trustscript_email_hash',
						'value'   => $email_hash,
						'compare' => '=',
					),
					array(
						'key'     => '_trustscript_customer_opted_out',
						'compare' => 'NOT EXISTS',
					),
				),
			) );

			if ( empty( $order_ids ) ) {
				$has_more = false;
				break;
			}

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				$order->update_meta_data( '_trustscript_customer_opted_out', '1' );
				$order->update_meta_data( '_trustscript_opt_out_message', 'Customer opted out via TrustScript email link.' );
				$order->save_meta_data();
				$total_updated++;
			}

			if ( count( $order_ids ) < $batch_size ) {
				$has_more = false;
			} else {
				$offset += $batch_size;
			}
		}

		return $total_updated;
	}
}