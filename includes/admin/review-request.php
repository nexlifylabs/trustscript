<?php
/**
 * Review Requests Management Page
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Review_Request_Page {

	const PER_PAGE = 20;

	public function __construct() {
		add_action( 'wp_ajax_trustscript_fetch_review_requests', array( $this, 'handle_fetch_requests' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" style="max-width:1400px;margin:0 auto;padding-top:20px;">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Review Requests', 'trustscript' ); ?></h1>

			<div class="trustscript-card">
				<h2><?php esc_html_e( 'Track Synced Orders', 'trustscript' ); ?></h2>
				<p>
					<?php esc_html_e( 'View orders synced with your TrustScript account, including pending, scheduled, published, and opt-out statuses.', 'trustscript' ); ?>
					<?php esc_html_e( 'For deeper insights such as review request activity, link open rates, and conversion tracking, explore the', 'trustscript' ); ?>
					<a href="<?php echo esc_url( TRUSTSCRIPT_DASHBOARD_URL . '/analytics' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'TrustScript Analytics Dashboard', 'trustscript' ); ?>
					</a>.
				</p>
			</div>

			<div id="review-requests-stats" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:16px;margin-bottom:32px;">
				<div class="trustscript-stat-card trustscript-stat-card-primary">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Total Requests', 'trustscript' ); ?></div>
					<div id="rr-stat-total" class="trustscript-stat-value">-</div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-warning">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Scheduled', 'trustscript' ); ?></div>
					<div id="rr-stat-scheduled" class="trustscript-stat-value">-</div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-info">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Pending', 'trustscript' ); ?></div>
					<div id="rr-stat-pending" class="trustscript-stat-value">-</div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-success">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Published', 'trustscript' ); ?></div>
					<div id="rr-stat-published" class="trustscript-stat-value">-</div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-purple">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Opt-Out', 'trustscript' ); ?></div>
					<div id="rr-stat-optout" class="trustscript-stat-value">-</div>
				</div>
				<div class="trustscript-stat-card trustscript-stat-card-warning">
					<div class="trustscript-stat-label"><?php esc_html_e( 'Awaiting Consent', 'trustscript' ); ?></div>
					<div id="rr-stat-consent-pending" class="trustscript-stat-value">-</div>
				</div>
			</div>

			<div class="trustscript-review-requests-main-card">
				<div class="trustscript-review-requests-filters-section">
					<h3 class="trustscript-review-requests-filters-title"><?php esc_html_e( 'All Review Requests', 'trustscript' ); ?></h3>
					<div class="trustscript-review-requests-filters-grid">
						<div class="trustscript-review-requests-filter-group">
							<label for="rr-search">
								<?php esc_html_e( 'Search', 'trustscript' ); ?>
							</label>
							<input
								type="text"
								id="rr-search"
								class="trustscript-review-requests-filter-input"
								placeholder="<?php esc_attr_e( 'Order # or customer...', 'trustscript' ); ?>"
							>
						</div>
						<div class="trustscript-review-requests-filter-group">
							<label for="rr-status-filter">
								<?php esc_html_e( 'Status', 'trustscript' ); ?>
							</label>
							<select id="rr-status-filter" class="trustscript-review-requests-filter-select">
								<option value=""><?php esc_html_e( 'All Statuses', 'trustscript' ); ?></option>
								<option value="scheduled"><?php esc_html_e( 'Scheduled', 'trustscript' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending Review', 'trustscript' ); ?></option>
								<option value="published"><?php esc_html_e( 'Published', 'trustscript' ); ?></option>
								<option value="opt-out"><?php esc_html_e( 'Opt-Out', 'trustscript' ); ?></option>
								<option value="consent_pending"><?php esc_html_e( 'Awaiting Consent', 'trustscript' ); ?></option>
							</select>
						</div>
						<div class="trustscript-review-requests-filter-group">
							<label for="rr-date-filter">
								<?php esc_html_e( 'Date Range', 'trustscript' ); ?>
							</label>
							<select id="rr-date-filter" class="trustscript-review-requests-filter-select">
								<option value="0"><?php esc_html_e( 'All Time', 'trustscript' ); ?></option>
								<option value="7"><?php esc_html_e( 'Last 7 Days', 'trustscript' ); ?></option>
								<option value="30"><?php esc_html_e( 'Last 30 Days', 'trustscript' ); ?></option>
								<option value="90"><?php esc_html_e( 'Last 90 Days', 'trustscript' ); ?></option>
							</select>
						</div>
					</div>
					<button type="button" id="rr-apply-filters" class="trustscript-review-requests-apply-btn">
						<?php esc_html_e( 'Apply Filters', 'trustscript' ); ?>
					</button>
				</div>

				<div id="review-requests-loading" class="trustscript-review-requests-loading">
					<div><?php esc_html_e( 'Loading orders...', 'trustscript' ); ?></div>
				</div>

				<div id="review-requests-list" class="trustscript-review-requests-list" style="display:none;">
					<div id="rr-results-info" class="trustscript-review-requests-results-info"></div>
					<table class="trustscript-review-requests-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Customer', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Product', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Order Date', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Sent', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Consent', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Status', 'trustscript' ); ?></th>
								<th><?php esc_html_e( 'Action', 'trustscript' ); ?></th>
							</tr>
						</thead>
						<tbody id="review-requests-tbody"></tbody>
					</table>

					<div id="rr-pagination" class="trustscript-review-requests-pagination">
						<div id="rr-pagination-info" class="trustscript-review-requests-pagination-info"></div>
						<div id="rr-pagination-buttons" class="trustscript-review-requests-pagination-buttons"></div>
					</div>
				</div>

				<div id="review-requests-empty" class="trustscript-review-requests-empty" style="display:none;">
					<div><?php esc_html_e( 'No tracked orders found.', 'trustscript' ); ?></div>
				</div>
			</div>

			<!-- Action Button -->
			<div class="trustscript-review-requests-action-buttons">
				<button type="button" id="refresh-review-requests" class="trustscript-review-requests-refresh-btn">
					<?php esc_html_e( 'Refresh', 'trustscript' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	public function handle_fetch_requests() {
		check_ajax_referer( 'trustscript_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}

		$page          = max( 1, absint( isset( $_POST['page'] )       ? $_POST['page']       : 1 ) );
		$search        = sanitize_text_field( wp_unslash( isset( $_POST['search'] )     ? $_POST['search']     : '' ) );
		$status_filter = sanitize_key( isset( $_POST['status'] )       ? $_POST['status']     : '' );
		$date_days     = absint( isset( $_POST['date_range'] )         ? $_POST['date_range'] : 0 );
		$date_after    = $date_days > 0 ? gmdate( 'Y-m-d', strtotime( "-{$date_days} days" ) ) : null;
		$per_page      = self::PER_PAGE;

		$base = array(
			'status'  => 'any',
			'return'  => 'ids',
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
		);
		if ( $date_after ) {
			$base['date_after'] = $date_after;
		}

		$entries  = array();
		$seen_ids = array();

		if ( '' === $status_filter || 'published' === $status_filter ) {
			$ids = wc_get_orders( array_merge( $base, array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => '_trustscript_review_published',
						'value'   => 'yes',
						'compare' => '=',
					),
					array(
						'key'     => '_trustscript_customer_opted_out',
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_trustscript_consent_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_trustscript_consent_status',
							'value'   => array( 'confirmed', 'not_required' ),
							'compare' => 'IN',
						),
					),
				),
				'meta_relation' => 'AND',
			) ) );
			foreach ( $ids as $id ) {
				$published_at = get_post_meta( $id, '_trustscript_review_published_at', true );
				$status_time  = $published_at ? strtotime( $published_at ) : time();
				$seen_ids[ $id ] = true;
				$entries[]       = array(
					'order_id'    => (int) $id,
					'status'      => 'published',
					'scheduled_for' => null,
					'status_time' => $status_time,
				);
			}
		}

		if ( '' === $status_filter || 'opt-out' === $status_filter ) {
			$ids = wc_get_orders( array_merge( $base, array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => '_trustscript_customer_opted_out',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => '1',
			) ) );
			foreach ( $ids as $id ) {
				if ( isset( $seen_ids[ $id ] ) ) { continue; }
				$sent_at     = get_post_meta( (int) $id, '_trustscript_review_sent_at', true );
				$status_time = $sent_at ? strtotime( $sent_at ) : time();
				$seen_ids[ $id ] = true;
				$entries[]       = array(
					'order_id'      => (int) $id,
					'status'        => 'opt-out',
					'scheduled_for' => null,
					'status_time'   => $status_time,
				);
			}
		}

		if ( '' === $status_filter || 'pending' === $status_filter ) {
			$ids = wc_get_orders( array_merge( $base, array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => '_trustscript_email_sent',
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => '_trustscript_customer_opted_out',
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_trustscript_consent_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_trustscript_consent_status',
							'value'   => array( 'confirmed', 'not_required' ),
							'compare' => 'IN',
						),
					),
				),
				'meta_relation' => 'AND',
			) ) );
			foreach ( $ids as $id ) {
				if ( isset( $seen_ids[ $id ] ) ) { continue; }
				$sent_at      = get_post_meta( $id, '_trustscript_review_sent_at', true );
				$status_time  = $sent_at ? strtotime( $sent_at ) : time();
				$seen_ids[ $id ] = true;
				$entries[]       = array(
					'order_id'    => (int) $id,
					'status'      => 'pending',
					'scheduled_for' => null,
					'status_time' => $status_time,
				);
			}
		}

		if ( '' === $status_filter || 'scheduled' === $status_filter ) {
			if ( TrustScript_Queue::table_exists() ) {
				$queue_result = TrustScript_Queue::get_items( 1, 9999, 'pending' );
				foreach ( $queue_result['items'] as $item ) {
					$id = absint( $item['order_id'] );
					if ( isset( $seen_ids[ $id ] ) ) { continue; }

					if ( $date_after && ! empty( $item['queued_at'] ) ) {
						if ( strtotime( $item['queued_at'] ) < strtotime( $date_after ) ) {
							continue;
						}
					}

					$status_time = ! empty( $item['queued_at'] ) ? strtotime( $item['queued_at'] ) : time();
					$seen_ids[ $id ] = true;
					$entries[]       = array(
						'order_id'      => $id,
						'status'        => 'scheduled',
						'scheduled_for' => $item['scheduled_for'],
						'status_time'   => $status_time,
					);
				}
			}
		}

		if ( '' === $status_filter || 'consent_pending' === $status_filter ) {
			$consent_pending_ids = wc_get_orders( array_merge( $base, array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => '_trustscript_consent_status',
						'value'   => 'pending',
						'compare' => '=',
					),
					array(
						'key'     => '_trustscript_consent_deferred_service',
						'compare' => 'EXISTS',
					),
				),
			) ) );
			foreach ( $consent_pending_ids as $id ) {
				if ( isset( $seen_ids[ $id ] ) ) { continue; }

				if ( $date_after ) {
					$cp_order  = wc_get_order( $id );
					$created   = $cp_order ? $cp_order->get_date_created() : null;
					if ( $created && $created->getTimestamp() < strtotime( $date_after ) ) {
						continue;
					}
				}

				$status_time     = time();
				$seen_ids[ $id ] = true;
				$entries[]       = array(
					'order_id'      => (int) $id,
					'status'        => 'consent_pending',
					'scheduled_for' => null,
					'status_time'   => $status_time,
				);
			}
		}

		usort( $entries, function ( $a, $b ) {
			if ( $b['order_id'] !== $a['order_id'] ) {
				return $b['order_id'] - $a['order_id'];
			}
			$time_a = isset( $a['status_time'] ) ? (int) $a['status_time'] : 0;
			$time_b = isset( $b['status_time'] ) ? (int) $b['status_time'] : 0;
			return $time_b - $time_a;
		} );

		if ( ! empty( $search ) ) {
			$clean        = ltrim( $search, '#' );
			$search_ids   = wc_get_orders( array(
				's'      => $clean,
				'status' => 'any',
				'return' => 'ids',
				'limit'  => -1,
			) );
			$search_map = array_flip( $search_ids );
			$entries    = array_values( array_filter( $entries, static function ( $e ) use ( $search_map ) {
				return isset( $search_map[ $e['order_id'] ] );
			} ) );
		}

		$stats = self::compute_global_stats();

		$total       = count( $entries );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$paged       = array_slice( $entries, ( $page - 1 ) * $per_page, $per_page );

		$orders_data = array();
		foreach ( $paged as $entry ) {
			$order = wc_get_order( $entry['order_id'] );
			if ( ! $order ) {
				continue;
			}

			$product_name = '';
			$product_url  = '';
			foreach ( $order->get_items() as $item ) {
				$product_name = $item->get_name();
				$pid          = $item->get_product_id();
				$product_url  = $pid ? (string) get_permalink( $pid ) : '';
				break;
			}

			$date_created  = $order->get_date_created();
			$sent_raw      = $order->get_meta( '_trustscript_review_sent_at' );
			$published_raw = $order->get_meta( '_trustscript_review_published_at' );

			$consent_status = $order->get_meta( '_trustscript_consent_status' );
			$consent_country = $order->get_meta( '_trustscript_consent_country' );
			$consent_confirmed_at = $order->get_meta( '_trustscript_consent_confirmed_at' );

			$consent_display = 'N/A';
			$consent_class   = 'consent-na';
			$consent_subtext = '';

			if ( 'not_required' !== $consent_status ) {
				if ( 'declined' === $consent_status ) {
					$consent_display = esc_html__( 'Opted Out', 'trustscript' );
					$consent_class   = 'consent-declined';
				} elseif ( 'pending' === $consent_status ) {
					$consent_display = esc_html__( 'Pending', 'trustscript' );
					$consent_class   = 'consent-pending';
					$consent_subtext = 'Pending from customer';
				} else {
					$consent_display = esc_html__( 'Opted In', 'trustscript' );
					$consent_class   = 'consent-confirmed';
					if ( ! empty( $consent_confirmed_at ) ) {
						// stored via current_time('mysql') = local site time.
						$consent_subtext = 'Approved on ' . TrustScript_Date_Formatter::format( $consent_confirmed_at, 'short' );
					}
				}
			}

			$sent_date = null;
			if ( 'scheduled' !== $entry['status'] && $sent_raw ) {
				// Stored as site-local time — use the formatter which parses via wp_timezone().
				$sent_date = TrustScript_Date_Formatter::format( $sent_raw, 'datetime' );
			}

			$orders_data[] = array(
				'orderId'       => '#' . $order->get_order_number(),
				'orderAdminUrl' => (string) get_edit_post_link( $order->get_id(), '' ),
				'customerName'  => $order->get_formatted_billing_full_name() ?: esc_html__( 'Guest', 'trustscript' ),
				'productName'   => $product_name ?: esc_html__( 'Unknown product', 'trustscript' ),
				'productUrl'    => $product_url,
				'orderDate'     => $date_created ? wp_date( 'M j, Y', $date_created->getTimestamp() ) : '—',
				'sentDate'      => $sent_date,
				'scheduledFor'  => ! empty( $entry['scheduled_for'] )
					? TrustScript_Date_Formatter::format( $entry['scheduled_for'], 'datetime' )
					: null,
				'publishedDate' => $published_raw ? TrustScript_Date_Formatter::format( $published_raw, 'short' ) : null,
				'status'        => $entry['status'],
				'consentStatus' => $consent_status ?: 'unknown',
				'consentCountry' => $consent_country ?: '',
				'consentDisplay' => $consent_display,
				'consentClass'  => $consent_class,
				'consentSubtext' => $consent_subtext,

			);
		}

		wp_send_json_success( array(
			'stats'   => $stats,
			'orders'  => $orders_data,
			'total'   => $total,
			'pages'   => $total_pages,
			'page'    => $page,
			'perPage' => $per_page,
		) );
	}

	/**
	 * Compute aggregate stats for all tracked orders, categorized by their current review request status.
	 *
	 * @return array { total, published, optOut, pending, scheduled }
	 */
	private static function compute_global_stats() {
		$published_ids = wc_get_orders( array(
			'status'     => 'any',
			'return'     => 'ids',
			'limit'      => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'   => '_trustscript_review_published',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => 'yes',
		) );

		$optout_ids = wc_get_orders( array(
			'status'     => 'any',
			'return'     => 'ids',
			'limit'      => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'   => '_trustscript_customer_opted_out',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => '1',
		) );

		$email_sent_ids = wc_get_orders( array(
			'status'     => 'any',
			'return'     => 'ids',
			'limit'      => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'   => '_trustscript_email_sent',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_value' => '1',
		) );

		$consent_pending_ids = wc_get_orders( array(
			'status'     => 'any',
			'return'     => 'ids',
			'limit'      => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'     => '_trustscript_consent_status',
					'value'   => 'pending',
				),
				array(
					'key'     => '_trustscript_consent_deferred_service',
					'compare' => 'EXISTS',
				),
			),
		) );

		$pub_map  = array_flip( $published_ids );
		$opt_map  = array_flip( $optout_ids );
		$pending  = count( array_filter( $email_sent_ids, static function ( $id ) use ( $pub_map, $opt_map ) {
			return ! isset( $pub_map[ $id ] ) && ! isset( $opt_map[ $id ] );
		} ) );

		$scheduled       = TrustScript_Queue::count_pending();
		$published       = count( $published_ids );
		$opt_out         = count( $optout_ids );
		$consent_pending = count( $consent_pending_ids );

		return array(
			'total'          => $published + $opt_out + $pending + $scheduled + $consent_pending,
			'published'      => $published,
			'optOut'         => $opt_out,
			'pending'        => $pending,
			'scheduled'      => $scheduled,
			'consentPending' => $consent_pending,
		);
	}
}