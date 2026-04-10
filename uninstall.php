<?php
/**
 * TrustScript Plugin Uninstall Handler
 * This file is responsible for cleaning up the database and options when the plugin is uninstalled. 
 * @package TrustScript
 * @version 1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$trustscript_delete_data = get_option( 'trustscript_delete_data_on_uninstall', false );

if ( ! $trustscript_delete_data ) {
	return;
}

global $wpdb;

$trustscript_prefix = $wpdb->prefix;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Intentional cleanup on uninstall, table names cannot be parameterized.
$wpdb->query( "DROP TABLE IF EXISTS {$trustscript_prefix}trustscript_order_registry" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Intentional cleanup on uninstall, table names cannot be parameterized.
$wpdb->query( "DROP TABLE IF EXISTS {$trustscript_prefix}trustscript_queue" );

$trustscript_options_to_delete = array(
	'trustscript_api_key',
	'trustscript_data_consent',
	'trustscript_delete_data_on_uninstall',
	'trustscript_reviews_enabled',
	'trustscript_review_categories',
	'trustscript_auto_publish',
	'trustscript_collect_rating',
	'trustscript_collect_photos',
	'trustscript_collect_videos',
	'trustscript_review_delay_hours',
	'trustscript_review_trigger_status',
	'trustscript_auto_sync_enabled',
	'trustscript_auto_sync_time',
	'trustscript_auto_sync_lookback',
	'trustscript_auto_sync_last_run',
	'trustscript_auto_sync_last_stats',
	'trustscript_review_keywords',
	'trustscript_base_url',
	'trustscript_user_plan',
	'trustscript_last_quota',
	'trustscript_review_voting_enabled',
	'trustscript_woocommerce_enabled',
	'trustscript_memberpress_enabled',
	'trustscript_enable_international_handling',
	'trustscript_international_delay_hours',
);

foreach ( $trustscript_options_to_delete as $trustscript_option_name ) {
	delete_option( $trustscript_option_name );
}

$trustscript_transients_to_delete = array(
	'trustscript_api_key_invalid_notice',
	'trustscript_quota_exceeded_notice',
	'trustscript_user_plan',
	'trustscript_base_url',
	'trustscript_last_quota',
);

foreach ( $trustscript_transients_to_delete as $trustscript_transient_name ) {
	delete_transient( $trustscript_transient_name );
}

$trustscript_timestamp_auto_sync = wp_next_scheduled( 'trustscript_auto_sync_orders' );
if ( $trustscript_timestamp_auto_sync ) {
	wp_unschedule_event( $trustscript_timestamp_auto_sync, 'trustscript_auto_sync_orders' );
}

wp_clear_scheduled_hook( 'trustscript_auto_sync_orders' );
wp_clear_scheduled_hook( 'trustscript_process_quota_queue' );