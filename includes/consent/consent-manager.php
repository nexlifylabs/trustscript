<?php
/**
 * Handles consent tracking, type detection, token generation,
 * confirmation emails, and review request permission gating.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Consent_Manager {

	/**
     * Consent log table slug (without prefix).
     *
     * @since 1.0.0
     */
	const CONSENT_LOG_TABLE_SLUG = 'trustscript_consent_log';

	/**
	 * Database schema version.
	 */
	const SCHEMA_VERSION = '1.0';

	/**
	 * Check if the consent log table exists.
	 * 
	 * @since 1.0.0
	 * @return bool
	 */
	public static function log_table_exists() {
		global $wpdb;
		$table = self::get_log_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
	}

	/**
	 * Get the full consent log table name with prefix.
	 * 
	 * @since 1.0.0
     * @return string
	 */
	public static function get_log_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::CONSENT_LOG_TABLE_SLUG;
	}

	/**
	 * Create or upgrade consent tables on plugin activation.
     *
     * @since 1.0.0
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$installed_version = get_option( 'trustscript_consent_schema_version', '' );
		if ( $installed_version === self::SCHEMA_VERSION && self::log_table_exists() ) {
			$has_consent_status = self::column_exists( $wpdb->posts, '_trustscript_consent_status' );
			if ( $has_consent_status ) {
				return;
			}
		}

		$charset_collate = $wpdb->get_charset_collate();

		$log_table = esc_sql( self::get_log_table_name() );

		$sql = "CREATE TABLE {$log_table} (
			id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id          BIGINT(20) UNSIGNED NOT NULL COMMENT 'WooCommerce order ID',
			event             VARCHAR(64) NOT NULL COMMENT 'checkout_consent_shown, checkout_consent_given, etc.',
			billing_country   CHAR(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
			consent_type      VARCHAR(32) NOT NULL COMMENT 'double_optin, single_optin, or not_required',
			ip_hash           VARCHAR(64) NULL COMMENT 'SHA256 hash of customer IP address',
			created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC timestamp',
			PRIMARY KEY  (id),
			KEY          idx_order_id (order_id),
			KEY          idx_event (event),
			KEY          idx_billing_country (billing_country),
			KEY          idx_created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'trustscript_consent_schema_version', self::SCHEMA_VERSION );
	}

	/**
     * Check if a column exists in a given table.
     *
     * @since 1.0.0
     * @param string $table_name  Table name.
     * @param string $column_name Column name.
     * @return bool
     */
	private static function column_exists( $table_name, $column_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table_name,
				$column_name
			)
		);
		return ! empty( $result );
	}

	/**
	 * Get the consent type required for a billing country.
	 *
	 * @since 1.0.0
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @return string 'double_optin' | 'single_optin' | 'not_required'
	 */
	public static function get_consent_type_for_country( $country_code ) {
		return TrustScript_Privacy_Settings_Page::get_consent_type_for_country( $country_code );
	}

	/**
	 * Check if the consent checkbox should be shown for a given country.
	*
	* @since 1.0.0
	* @param string $country_code ISO 3166-1 alpha-2 country code.
	* @return bool
	 */
	public static function should_show_checkbox( $country_code ) {
		$consent_type = self::get_consent_type_for_country( $country_code );
		return 'not_required' !== $consent_type;
	}

	/**
	 * Generate a secure 64-char hex token for double opt-in confirmation links.
	*
	* @since 1.0.0
	* @return string
	 */
	public static function generate_consent_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Get the consent status for an order.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @return string 'not_required' | 'pending' | 'confirmed' | 'declined'
	 */
	public static function get_order_consent_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 'not_required';
		}

		$status = $order->get_meta( '_trustscript_consent_status' );
		return ! empty( $status ) ? $status : 'not_required';
	}

	/**
	 * Set the consent status on an order.
	 *
	 * @since 1.0.0
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $status   'not_required' | 'pending' | 'confirmed' | 'declined'.
	 */
	public static function set_order_consent_status( $order_id, $status ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_trustscript_consent_status', $status );
		$order->save();
	}

	/**
	 * Get the billing country stored on the order at checkout.
	* Falls back to the order's billing country if meta is empty.
	*
	* @since 1.0.0
	* @param int $order_id WooCommerce order ID.
	* @return string ISO 3166-1 alpha-2 country code, or empty string on failure.
	 */
	public static function get_order_billing_country( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		$country = $order->get_meta( '_trustscript_consent_country' );
		if ( ! empty( $country ) ) {
			return $country;
		}

		// Fallback to the order's billing country if stored metadata is empty
		return $order->get_billing_country();
	}

	/**
	 * Store the billing country on the order meta.
	 *
	 * @since 1.0.0
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $country  ISO 3166-1 alpha-2 country code.
	 */
	public static function set_order_billing_country( $order_id, $country ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_trustscript_consent_country', strtoupper( $country ) );
		$order->save();
	}

	/**
	 * Get the consent token stored on an order.
	 *
	 * @since 1.0.0
	 * @param  int $order_id WooCommerce order ID.
	 * @return string Token string, or empty string if not set.
	 */
	public static function get_order_consent_token( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		return $order->get_meta( '_trustscript_consent_token' );
	}

	/**
	 * Store the consent token on an order.
	 *
	 * @since 1.0.0
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $token    64-char hex string from generate_consent_token().
	 */
	public static function set_order_consent_token( $order_id, $token ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_trustscript_consent_token', $token );
		$order->save();
	}

	/**
	 * Get the timestamp when consent was given at checkout.
	 *
	 * @since 1.0.0
	 * @param  int $order_id WooCommerce order ID.
	 * @return string ISO 8601 datetime or empty string.
	 */
	public static function get_order_consent_given_at( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		return $order->get_meta( '_trustscript_consent_given_at' );
	}

	/**
	 * Store the timestamp when consent was given at checkout.
	 *
	 * @since 1.0.0
	 * @param int    $order_id  WooCommerce order ID.
	 * @param string $timestamp ISO 8601 datetime.
	 */
	public static function set_order_consent_given_at( $order_id, $timestamp ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_trustscript_consent_given_at', $timestamp );
		$order->save();
	}

	/**
	 * Get the timestamp when double opt-in consent was confirmed.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @return string ISO 8601 datetime, or empty string if not set.
	 */
	public static function get_order_consent_confirmed_at( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		return $order->get_meta( '_trustscript_consent_confirmed_at' );
	}

	/**
	 * Store the timestamp when double opt-in consent was confirmed.
	 *
	 * @since 1.0.0
	 * @param int    $order_id  WooCommerce order ID.
	 * @param string $timestamp ISO 8601 datetime.
	 */
	public static function set_order_consent_confirmed_at( $order_id, $timestamp ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_trustscript_consent_confirmed_at', $timestamp );
		$order->save();
	}

	/**
	 * Log a consent event to the audit trail.
	 *
	 * @since 1.0.0
	 * @param int    $order_id        WooCommerce order ID.
	 * @param string $event           Event slug e.g. 'checkout_consent_given'.
	 * @param string $billing_country ISO 3166-1 alpha-2 country code.
	 * @param string $consent_type    'double_optin' | 'single_optin' | 'not_required'.
	 * @param string $ip_hash         Optional. SHA256 hash of customer IP.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function log_consent_event( $order_id, $event, $billing_country, $consent_type, $ip_hash = null ) {
		global $wpdb;

		if ( ! self::log_table_exists() ) {
			return false;
		}

		$table = self::get_log_table_name();

		$data = array(
			'order_id'        => absint( $order_id ),
			'event'           => sanitize_key( $event ),
			'billing_country' => strtoupper( substr( $billing_country, 0, 2 ) ),
			'consent_type'    => sanitize_key( $consent_type ),
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( ! empty( $ip_hash ) ) {
			$data['ip_hash'] = sanitize_text_field( $ip_hash );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $data );

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get the last N consent events for an order.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @param int $limit    Number of events to return. Default 10.
	 * @return array Array of row objects, or empty array if table missing.
	 */
	public static function get_order_consent_log( $order_id, $limit = 10 ) {
		global $wpdb;

		if ( ! self::log_table_exists() ) {
			return array();
		}

		$table = esc_sql( self::get_log_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at DESC LIMIT %d",
				absint( $order_id ),
				absint( $limit )
			)
		);

		return $results ?: array();
	}

	/**
	 * Check if a review request is permitted based on the order's consent status.
	 *
	 * Returns true for 'not_required' or 'confirmed'; false for 'pending' or 'declined'.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	public static function is_review_request_permitted( $order_id ) {
		$status = self::get_order_consent_status( $order_id );

		// Review is permitted if consent is not required or already confirmed
		return in_array( $status, array( 'not_required', 'confirmed' ), true );
	}

	/**
	 * Check if an order requires explicit consent before sending a review request.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	public static function order_requires_consent( $order_id ) {
		$status = self::get_order_consent_status( $order_id );
		return 'not_required' !== $status;
	}

	/**
	 * Send the double opt-in confirmation email.
	 *
	 * Builds a fully inline-styled, table-based HTML email that renders
	 * correctly in Gmail, Outlook 2007–2021, Apple Mail, and mobile clients.
	 * All CSS is inlined — no <style> blocks — to avoid stripping by webmail.
	 *
	 * @since 1.0.0
	 * @param int $order_id WooCommerce order ID.
	 * @return bool	True if sent successfully, false otherwise.
	 */
	public static function send_confirmation_email( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$country = self::get_order_billing_country( $order_id );
		$consent_type = self::get_consent_type_for_country( $country );

		if ( 'double_optin' !== $consent_type ) {
			return false;
		}

		$status = self::get_order_consent_status( $order_id );
		if ( 'pending' !== $status ) {
			return false;
		}

		$token = self::get_order_consent_token( $order_id );
		if ( empty( $token ) ) {
			return false;
		}

		$customer_email = $order->get_billing_email();
		if ( empty( $customer_email ) ) {
			return false;
		}

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( empty( $customer_name ) ) {
			$customer_name = $order->get_billing_first_name();
		}
		if ( empty( $customer_name ) ) {
			$customer_name = '';
		}

		$confirm_url = add_query_arg(
			array(
				'trustscript_action'	=> 'confirm_consent',
				'token'					=> $token,
				'trustscript_order'		=> $order_id,
			),
			home_url( '/' )
		);

		$store_name   = get_bloginfo( 'name' );
		$order_number = $order->get_order_number();

		$settings = TrustScript_Privacy_Settings_Page::get_confirmation_email_subject( $order_id );
		if ( ! empty( $settings ) && strpos( $settings, '%1$s' ) !== false ) {
			$subject = sprintf( sanitize_text_field( $settings ), $store_name, $order_number );
		} else {
			/* translators: 1: store name, 2: order number */
			$subject = sprintf( __( '%1$s – Confirm your review request for order #%2$s', 'trustscript' ), $store_name, $order_number );
		}

		$confirm_url_escaped = esc_url_raw( $confirm_url );

		$tpl_greeting = ! empty( $customer_name )
		/* translators: Greeting line at the top of the confirmation email. %s: customer name */
			? sprintf( esc_html__( 'Hello %s!', 'trustscript' ), esc_html( $customer_name ) )
			: esc_html__( 'Hello!', 'trustscript' );
		$tpl_body = wp_kses_post(
			__(
				"You recently indicated that you'd like to receive a review request after your order is delivered.<br><br>We'd love to hear about your experience with us! To proceed, please confirm your preference by clicking the button below.",
				'trustscript'
			)
		);

		/* translators: CTA button label in the confirmation email. */
		$tpl_button = esc_html__( 'Confirm', 'trustscript' );

		/* translators: Heading of the privacy/security note box. */
		$tpl_note_heading = esc_html__( 'Why are we asking this?', 'trustscript' );

		/* translators: Body of the privacy/security note box. */
		$tpl_note_body = esc_html__(
			'To protect your privacy and comply with regulations, we need you to confirm this preference. This link expires in 7 days.',
			'trustscript'
		);

		$tpl_footer = sprintf(
			/* translators: %s: HTML anchor linking to the store homepage. */
			esc_html__(
				'If you did not request this, simply ignore this email. No review request will be sent to your account. Questions? Contact us at %s',
				'trustscript'
			),
			'<a href="' . esc_url( home_url( '/' ) ) . '" style="color:#5668d3;text-decoration:none;">'
				. esc_html( $store_name )
			. '</a>'
		);

		$email_preheader = sprintf(
			/* translators: 1: store name, 2: order number. */
			esc_html__( '%1$s – Confirm your review preference for order #%2$s', 'trustscript' ),
			esc_html( $store_name ),
			esc_html( $order_number )
		);

		$message = '<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
			<title>' . esc_html( $store_name ) . '</title>
		</head>
		<body style="margin:0;padding:0;background-color:#f4f4f4;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;word-break:break-word;">

			<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
				. $email_preheader
			. '</div>

			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
				style="background-color:#f4f4f4;border-collapse:collapse;">
				<tr>
					<td align="center" style="padding:40px 16px;">

						<!-- Email container: max 600 px centred -->
						<!--[if mso]>
						<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center">
						<tr><td>
						<![endif]-->
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"
							style="max-width:600px;border-collapse:collapse;">

							<tr>
								<td align="center"
									style="background-color:#667eea;padding:32px 30px;border-radius:8px 8px 0 0;">
									<h1 style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:24px;font-weight:600;color:#ffffff;line-height:1.3;">
										' . esc_html( $store_name ) . '
									</h1>
								</td>
							</tr>

							<tr>
								<td style="background-color:#ffffff;padding:32px 30px;border-radius:0 0 8px 8px;box-shadow:0 2px 4px rgba(0,0,0,0.08);">
									<p style="margin:0 0 20px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:18px;font-weight:600;color:#333333;line-height:1.5;">
										' . $tpl_greeting . '
									</p>
									<p style="margin:0 0 24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:14px;color:#555555;line-height:1.8;">
										' . $tpl_body . '
									</p>
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
										style="border-collapse:collapse;margin:24px 0;">
										<tr>
											<td align="center">
												<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
													xmlns:w="urn:schemas-microsoft-com:office:word"
													href="' . $confirm_url_escaped . '"
													style="height:48px;v-text-anchor:middle;width:220px;"
													arcsize="13%"
													stroke="f"
													fillcolor="#667eea">
													<w:anchorlock/>
													<center style="color:#ffffff;font-family:sans-serif;font-size:15px;font-weight:600;">
														' . $tpl_button . '
													</center>
												</v:roundrect>
												<!--[if !mso]><!-->
												<a href="' . $confirm_url_escaped . '"
													target="_blank"
													rel="noopener noreferrer"
													style="display:inline-block;background-color:#667eea;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:15px;font-weight:600;text-decoration:none;padding:14px 32px;border-radius:6px;mso-hide:all;">
													' . $tpl_button . '
												</a>
											</td>
										</tr>
									</table>

									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
										style="border-collapse:collapse;margin:24px 0;">
										<tr>
											<td style="background-color:#f0f7ff;border-left:4px solid #667eea;border-radius:0 4px 4px 0;padding:14px 16px;">
												<p style="margin:0 0 4px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:13px;font-weight:600;color:#333333;line-height:1.5;">
													' . $tpl_note_heading . '
												</p>
												<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:13px;color:#555555;line-height:1.6;">
													' . $tpl_note_body . '
												</p>
											</td>
										</tr>
									</table>

									<p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #eeeeee;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;font-size:12px;color:#999999;line-height:1.6;">
										' . $tpl_footer . '
									</p>

								</td>
							</tr>

						</table>
						<!--[if mso]>
						</td></tr></table>

					</td>
				</tr>
			</table>

		</body>
		</html>';

		// Send.
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $customer_email, $subject, $message, $headers );

		if ( $sent ) {
			self::log_consent_event(
				$order_id,
				'confirmation_email_sent',
				$country,
				'double_optin'
			);
		}

		return $sent;
	}

	/**
	 * Verify and process a double opt-in confirmation link click.
	 *
	 * Validates the token, checks expiry (7 days), and updates consent status to 'confirmed'.
	 * Also schedules any deferred review request queued at checkout.
	 *
	 * @since 1.0.0
	 * @param string $token    Consent token from the confirmation URL.
	 * @param int    $order_id WooCommerce order ID.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function process_confirmation_link( $token, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'Order not found.', 'trustscript' ) );
		}

		$stored_token = self::get_order_consent_token( $order_id );
		if ( empty( $stored_token ) ) {
			return new WP_Error( 'no_token', __( 'No confirmation pending for this order.', 'trustscript' ) );
		}

		if ( ! hash_equals( $stored_token, sanitize_text_field( $token ) ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid confirmation link.', 'trustscript' ) );
		}

		$status = self::get_order_consent_status( $order_id );
		if ( 'pending' !== $status ) {
			return new WP_Error( 'already_confirmed', __( 'This preference has already been confirmed.', 'trustscript' ) );
		}

		// Check if the confirmation link has expired (7 days after consent was given)
		$given_at = self::get_order_consent_given_at( $order_id );
		if ( ! empty( $given_at ) ) {
			$given_timestamp = strtotime( $given_at );
			if ( false !== $given_timestamp && ( time() - $given_timestamp ) > ( 7 * DAY_IN_SECONDS ) ) {
				return new WP_Error( 'link_expired', __( 'This confirmation link has expired. No review request will be sent.', 'trustscript' ) );
			}
		}

		self::set_order_consent_status( $order_id, 'confirmed' );
		self::set_order_consent_confirmed_at( $order_id, current_time( 'mysql' ) );

		$deferred_service = $order->get_meta( '_trustscript_consent_deferred_service' );
		$deferred_delay   = (int) $order->get_meta( '_trustscript_consent_deferred_delay' );

		if ( ! empty( $deferred_service ) && class_exists( 'TrustScript_Queue' ) ) {
			TrustScript_Queue::add( $order_id, $deferred_service, 'delay', $deferred_delay );

			$order->delete_meta_data( '_trustscript_consent_deferred_delay' );
			$order->delete_meta_data( '_trustscript_consent_deferred_service' );
			$order->save();
		}

		$country = self::get_order_billing_country( $order_id );
		$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_hash   = ! empty( $client_ip ) ? hash( 'sha256', $client_ip ) : null;
		self::log_consent_event(
			$order_id,
			'confirmation_clicked',
			$country,
			'double_optin',
			$ip_hash
		);

		return true;
	}
}
