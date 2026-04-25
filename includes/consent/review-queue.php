<?php
/**
 * Handles review queue gating based on consent status.
 *
 * Should be called before adding any order to the review queue.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TrustScript_Review_Queue_Gating {

    /**
     * Check if a review request can be sent for an order.
     *
     * @since 1.0.0
     * @param int $order_id WooCommerce order ID.
     * @return bool
     */
    public static function can_send_review_request( $order_id ) {
        if ( ! TrustScript_Consent_Manager::is_review_request_permitted( $order_id ) ) {
            self::log_skipped_order( $order_id, 'consent_not_permitted' );
            return false;
        }

        return true;
    }

    /**
     * Log that an order was skipped from the review queue due to consent.
     *
     * @since 1.0.0
     * @param int    $order_id WooCommerce order ID.
     * @param string $reason   Reason slug e.g. 'consent_not_permitted', 'pending', 'declined'.
     */
    private static function log_skipped_order( $order_id, $reason ) {
        $consent_status = TrustScript_Consent_Manager::get_order_consent_status( $order_id );
        $country        = TrustScript_Consent_Manager::get_order_billing_country( $order_id );

        $message = sprintf(
            /* translators: 1. Order ID, 2. Reason for blocking, 3. Consent status, 4. Billing country */
            'TrustScript: Order %d skipped from review queue. Reason: %s, Consent status: %s, Country: %s',
            $order_id,
            $reason,
            $consent_status,
            $country
        );

        $logger = wc_get_logger();
        $logger->info( $message, array( 'source' => 'trustscript-consent' ) );

        TrustScript_Consent_Manager::log_consent_event(
            $order_id,
            'review_request_blocked_by_consent',
            $country,
            TrustScript_Consent_Manager::get_consent_type_for_country( $country )
        );
    }

    /**
     * Get the reason why a review request is blocked for an order.
     *
     * Returns null if permitted; 'pending', 'declined', or 'unknown' otherwise.
     *
     * @since 1.0.0
     * @param int $order_id WooCommerce order ID.
     * @return string|null
     */
    public static function get_blocking_reason( $order_id ) {
        $status = TrustScript_Consent_Manager::get_order_consent_status( $order_id );

        if ( 'pending' === $status ) {
            return 'pending';
        }
        if ( 'declined' === $status ) {
            return 'declined';
        }
        if ( 'confirmed' === $status || 'not_required' === $status ) {
            return null;
        }

        return 'unknown';
    }

    /**
     * Get all orders blocked from review requests due to consent status.
     *
     * @since 1.0.0
     * @param array $args Optional. Query arguments passed to wc_get_orders().
     * @return array Array of order IDs.
     */
    public static function get_blocked_orders( $args = array() ) {
        $default_args = array(
            'limit'        => 100,
            'status'       => 'completed',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'     => '_trustscript_consent_status',
            'meta_compare' => 'IN',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'   => array( 'pending', 'declined' ),
            'return'       => 'ids',
        );

        $query_args = wp_parse_args( $args, $default_args );

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $orders = wc_get_orders( $query_args );

        return is_array( $orders ) ? $orders : array();
    }

    /**
     * Generate a compliance report of consent-blocked orders.
     *
     * @since 1.0.0
     * @param int $days_back Number of days back to report on. Default 30.
     * @return array
     */
    public static function get_compliance_report( $days_back = 30 ) {
        global $wpdb;

        $table = TrustScript_Consent_Manager::get_log_table_name();

        if ( ! TrustScript_Consent_Manager::log_table_exists() ) {
            return array();
        }

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_back} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as blocked_count,
                    consent_type,
                    billing_country,
                    event
                FROM " . esc_sql( $table ) . "
                WHERE event IN ('checkout_consent_declined', 'confirmation_email_sent')
                    AND created_at >= %s
                GROUP BY consent_type, billing_country, event
                ORDER BY blocked_count DESC",
                $cutoff
            )
        );

        return $results ?: array();
    }
}