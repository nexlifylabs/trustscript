<?php
/**
 * TrustScript Settings Sync
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Settings_Sync {
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'trustscript/v1', '/settings/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_settings_update' ),
			'permission_callback' => array( $this, 'verify_request' ),
		) );

		register_rest_route( 'trustscript/v1', '/plan/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_sync' ),
			'permission_callback' => array( $this, 'verify_request' ),
		) );
	}

	public function verify_request( $request ) {
		$api_key_header = $request->get_header( 'X-TrustScript-Api-Key' );
		$stored_api_key = get_option( 'trustscript_api_key', '' );

		if ( empty( $stored_api_key ) ) {
			return new WP_Error( 'no_api_key', 'API key not configured', array( 'status' => 500 ) );
		}

		if ( empty( $api_key_header ) ) {
			return new WP_Error( 'missing_api_key', 'API key required', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $stored_api_key, $api_key_header ) ) {
			return new WP_Error( 'invalid_api_key', 'Invalid API key', array( 'status' => 401 ) );
		}

		return true;
	}

	public function handle_settings_update( $request ) {

		$params = $request->get_json_params();
		
		if ( ! isset( $params['emailSendMode'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error' => 'Missing emailSendMode parameter',
				),
				400
			);
		}

		$email_send_mode = sanitize_text_field( $params['emailSendMode'] );
		$timestamp = isset( $params['timestamp'] ) ? sanitize_text_field( $params['timestamp'] ) : current_time( 'mysql' );

		if ( ! in_array( $email_send_mode, array( 'auto', 'manual' ) ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error' => 'Invalid emailSendMode value. Must be "auto" or "manual"',
				),
				400
			);
		}

		$current_timestamp = get_option( 'trustscript_email_send_mode_updated_at', '' );
		
		if ( ! empty( $current_timestamp ) && ! empty( $timestamp ) ) {
			$current_time = strtotime( $current_timestamp );
			$new_time = strtotime( $timestamp );
			
			if ( $new_time < $current_time ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'error' => 'Update rejected: older than current setting',
						'current_mode' => get_option( 'trustscript_email_send_mode', 'auto' ),
						'current_timestamp' => $current_timestamp,
					),
					409
				);
			}
		}

		update_option( 'trustscript_email_send_mode', $email_send_mode );
		update_option( 'trustscript_email_send_mode_updated_at', $timestamp );

		delete_transient( 'trustscript_api_key_invalid_notice' );

		return new WP_REST_Response(
			array(
				'success'       => true,
				'emailSendMode' => $email_send_mode,
				'timestamp'     => $timestamp,
				'message'       => 'Settings synced successfully',
			),
			200
		);
	}

	/**
	 * Handles plan change and quota reset events from the TrustScript backend. 
	 * This endpoint is called by a webhook whenever a user's subscription plan 
	 * changes (e.g., upgrade, downgrade) or when their usage quota is reset at 
	 * the start of a new billing cycle. The handler performs several actions to 
	 * ensure the WordPress site reflects the new plan status:
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_plan_sync( $request ) {
		$params    = $request->get_json_params();
		$new_plan  = isset( $params['plan'] )      ? sanitize_text_field( $params['plan'] )      : '';
		$event     = isset( $params['event'] )     ? sanitize_text_field( $params['event'] )     : 'upgrade';
		$reset_date = isset( $params['resetDate'] ) ? sanitize_text_field( $params['resetDate'] ) : '';

		delete_transient( 'trustscript_quota_exceeded_notice' );
		delete_transient( 'trustscript_api_key_invalid_notice' );
		delete_transient( 'trustscript_user_plan' );

		if ( ! empty( $new_plan ) ) {
			update_option( 'trustscript_current_plan', $new_plan );
		}

		if ( in_array( $event, array( 'upgrade', 'reset' ), true ) ) {
			$pending = TrustScript_Queue::count_pending();
			if ( $pending > 0 ) {
				if ( ! wp_next_scheduled( 'trustscript_process_quota_queue' ) ) {
					wp_schedule_single_event( time(), 'trustscript_process_quota_queue' );
				}
			}
		}

		$final_pending = TrustScript_Queue::count_pending();

		return new WP_REST_Response(
			array(
				'success'      => true,
				'event'        => $event,
				'plan'         => $new_plan,
				'noticeCleared' => true,
				'pendingQueue' => $final_pending,
				'message'      => 'Plan sync processed successfully',
			),
			200
		);
	}
}

new TrustScript_Settings_Sync();
