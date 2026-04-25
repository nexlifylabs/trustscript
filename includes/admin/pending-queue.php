<?php
/**
 * Pending Queue Management Page
 *
 * @package TrustScript
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}


class TrustScript_Pending_Queue_Page
{

	public function __construct()
	{
		add_action('wp_ajax_trustscript_queue_retry', array($this, 'handle_queue_retry'));
		add_action('wp_ajax_trustscript_queue_clear', array($this, 'handle_queue_clear'));
		add_action('wp_ajax_trustscript_queue_process_all', array($this, 'handle_queue_process_all'));
	}

	public static function render()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'trustscript'));
		}

		$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_num = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 25;

		$status_filter = ('failed' === $tab) ? 'failed' : (('completed' === $tab) ? 'completed' : (('all' === $tab) ? '' : 'pending'));
		$data = TrustScript_Queue::get_items($page_num, $per_page, $status_filter);
		$items = $data['items'];
		$total = $data['total'];
		$total_pages = max(1, (int) ceil($total / $per_page));

		$pending_count = TrustScript_Queue::count_pending();
		$quota_info = get_transient('trustscript_quota_exceeded_notice');
		$reset_date = ($quota_info && !empty($quota_info['resetDate'])) ? $quota_info['resetDate'] : null;
		$current_plan = ($quota_info && !empty($quota_info['currentPlan'])) ? ucfirst($quota_info['currentPlan']) : null;
		$next_plan = ($quota_info && !empty($quota_info['nextPlan'])) ? ucfirst($quota_info['nextPlan']) : null;
		$next_limit = ($quota_info && !empty($quota_info['nextLimit'])) ? (int) $quota_info['nextLimit'] : null;

		$pricing_url = trustscript_get_app_url() . '/pricing';

		?>

		<?php
		$notice_key = 'trustscript_queue_processed_notice_' . get_current_user_id();
		$process_notice = get_transient($notice_key);
		if ($process_notice) {
			delete_transient($notice_key);
			$processed = (int) $process_notice['processed'];
			$failed = (int) $process_notice['failed'];
			$still_pending = (int) $process_notice['still_pending'];
			?>
			<div class="trustscript-queue-processed-notice">
				<?php if ($processed > 0 && $still_pending === 0): ?>
					&#x2705; <?php
					printf(
						/* translators: %d = number of processed review requests */
						esc_html__('All done! %d review request(s) were sent successfully.', 'trustscript'),
						(int) $processed
					);
					?>
				<?php elseif ($processed > 0 && $still_pending > 0): ?>
					&#x2705; <?php
					printf(
						/* translators: 1: number of processed review requests, 2: number of still pending orders */
						esc_html__('%1$d review request(s) sent. %2$d order(s) still pending — quota may still be limited.', 'trustscript'),
						(int) $processed,
						(int) $still_pending
					);
					?>
				<?php elseif ($failed > 0): ?>
					&#x26A0;&#xFE0F; <?php
					printf(
						/* translators: %d = number of failed orders */
						esc_html__('%d order(s) could not be processed. Check the Failed tab for details.', 'trustscript'),
						(int) $failed
					);
					?>
				<?php else: ?>
					&#x2139;&#xFE0F;
					<?php esc_html_e('Queue processed — no new orders were sent (quota may still be limited).', 'trustscript'); ?>
				<?php endif; ?>
			</div>
			<?php
		}
		?>

		<div class="wrap trustscript-queue-wrap">
			<h1><?php esc_html_e('Pending Review Queue', 'trustscript'); ?></h1>

			<?php if ($pending_count > 0): ?>
				<div class="trustscript-queue-summary">
					<span class="trustscript-queue-count">
						<?php
						/* translators: %d number of pending orders */
						printf(esc_html(_n('%d order pending', '%d orders pending', $pending_count, 'trustscript')), (int) $pending_count);
						?>
					</span>

					<?php if ($reset_date): ?>
						<span class="trustscript-queue-reset">
							<?php
							try {
								$dt = new DateTime($reset_date);
								/* translators: %s formatted reset date */
								printf(esc_html__('&nbsp;&middot;&nbsp; Quota resets %s', 'trustscript'), esc_html($dt->format('F j, Y')));
							} catch (Exception $e) {
								unset($e); // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
							}
							?>
						</span>
					<?php endif; ?>

					<?php if ($next_plan && $next_limit): ?>
						<span class="trustscript-queue-upgrade">
							&nbsp;&middot;&nbsp;
							<a href="<?php echo esc_url($pricing_url); ?>" target="_blank" class="button button-primary button-small">
								<?php
								printf(
									/* translators: 1: next plan name 2: limit */
									esc_html__('Upgrade to %1$s for %2$s reviews/mo', 'trustscript'),
									esc_html($next_plan),
									esc_html(number_format_i18n($next_limit))
								);
								?>
							</a>
						</span>
					<?php elseif ($current_plan): ?>
						<span class="trustscript-queue-upgrade">
							&nbsp;&middot;&nbsp;
							<a href="<?php echo esc_url($pricing_url); ?>" target="_blank" class="button button-primary button-small">
								<?php esc_html_e('Upgrade Plan', 'trustscript'); ?>
							</a>
						</span>
					<?php endif; ?>

					<button type="button" id="trustscript-queue-process-all" class="button button-secondary"
						data-nonce="<?php echo esc_attr(wp_create_nonce('trustscript_admin')); ?>">
						<?php esc_html_e('Process Queue Now', 'trustscript'); ?>
					</button>
					<p class="trustscript-queue-summary-help">
						<em><?php esc_html_e('Processes quota failures and other non-scheduled errors only. To process scheduled items early, use the Retry button on each individual item.', 'trustscript'); ?></em>
					</p>
				</div>
			<?php else: ?>
				<div class="trustscript-queue-summary trustscript-queue-summary--empty">
					<span><?php esc_html_e('✅ Queue is empty — all review requests are up to date.', 'trustscript'); ?></span>
				</div>
			<?php endif; ?>

			<?php
			$ready_count = TrustScript_Queue::count_ready();
			$scheduled_count = TrustScript_Queue::count_scheduled();
			if ($ready_count > 0 || $scheduled_count > 0):
				?>
				<div class="trustscript-queue-status-info">
					<strong>📊 Queue Status:</strong>
					<?php if ($ready_count > 0): ?>
						<span class="trustscript-queue-status-ready">⚡ <strong><?php echo esc_html($ready_count); ?> ready
								now</strong> (will process on next site visit or hourly cron)</span>
					<?php endif; ?>
					<?php if ($scheduled_count > 0): ?>
						<span class="trustscript-queue-status-scheduled">⏰ <strong><?php echo esc_html($scheduled_count); ?>
								scheduled</strong> (waiting for delay to arrive)</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="trustscript-queue-tabs wp-clearfix">
				<?php
				$tabs = array(
					'pending' => sprintf(
						/* translators: %d = number of pending items */
						__('Pending (%d)', 'trustscript'),
						TrustScript_Queue::count_pending()
					),
					'failed' => sprintf(
						/* translators: %d = number of failed items */
						__('Failed (%d)', 'trustscript'),
						TrustScript_Queue::count_failed()
					),
					'completed' => sprintf(
						/* translators: %d = number of completed items */
						__('Completed (%d)', 'trustscript'),
						TrustScript_Queue::count_completed()
					),
					'all' => __('All', 'trustscript'),
				);

				$base_url = admin_url('admin.php?page=trustscript-queue');
				foreach ($tabs as $t_key => $t_label) {
					$class = ($tab === $t_key) ? 'nav-tab nav-tab-active' : 'nav-tab';
					$href = add_query_arg('tab', $t_key, $base_url);
					echo '<a href="' . esc_url($href) . '" class="' . esc_attr($class) . '">' . esc_html($t_label) . '</a>';
				}
				?>
			</div>

			<table class="widefat fixed striped wp-list-table trustscript-queue-table" id="trustscript-queue-table">
				<thead>
					<tr>
						<th scope="col" style="width:50px;"><?php esc_html_e('ID', 'trustscript'); ?></th>
						<th scope="col" style="width:90px;"><?php esc_html_e('Order #', 'trustscript'); ?></th>
						<th scope="col" style="width:110px;"><?php esc_html_e('Service', 'trustscript'); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e('Reason', 'trustscript'); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e('Queued At', 'trustscript'); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e('Scheduled For', 'trustscript'); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e('Last Attempt', 'trustscript'); ?></th>
						<th scope="col" style="width:55px;"><?php esc_html_e('Retries', 'trustscript'); ?></th>
						<th scope="col" style="width:80px;"><?php esc_html_e('Status', 'trustscript'); ?></th>
						<th scope="col"><?php esc_html_e('Actions', 'trustscript'); ?></th>
					</tr>
				</thead>
				<tbody id="trustscript-queue-tbody">
					<?php if (empty($items)): ?>
						<tr>
							<td colspan="10">
								<?php
								if ('failed' === $tab) {
									esc_html_e('No permanently failed items.', 'trustscript');
								} elseif ('completed' === $tab) {
									esc_html_e('No completed review requests yet.', 'trustscript');
								} elseif ('all' === $tab) {
									esc_html_e('The queue is empty.', 'trustscript');
								} else {
									esc_html_e('No pending orders. New quota-exceeded orders will appear here automatically.', 'trustscript');
								}
								?>
							</td>
						</tr>
					<?php else: ?>
						<?php foreach ($items as $item): ?>
							<tr id="trustscript-queue-row-<?php echo esc_attr($item['id']); ?>"
								data-row-id="<?php echo esc_attr($item['id']); ?>">
								<td><?php echo esc_html($item['id']); ?></td>
								<td>
									<?php
									$edit_url = get_edit_post_link($item['order_id']);
									if ($edit_url) {
										echo '<a href="' . esc_url($edit_url) . '">#' . esc_html($item['order_id']) . '</a>';
									} else {
										echo '#' . esc_html($item['order_id']);
									}
									?>
								</td>
								<td>
									<code><?php echo esc_html($item['service_id']); ?></code>
								</td>
								<td>
									<?php
									$reason_labels = array(
										'quota' => __('Quota', 'trustscript'),
										'rate_limit' => __('Rate limit', 'trustscript'),
										'network' => __('Network', 'trustscript'),
										'api_error' => __('API error', 'trustscript'),
										'delay' => __('Scheduled', 'trustscript'),
									);
									$reason_class_map = array(
										'quota' => 'trustscript-queue-reason-quota',
										'rate_limit' => 'trustscript-queue-reason-rate-limit',
										'network' => 'trustscript-queue-reason-network',
										'api_error' => 'trustscript-queue-reason-api-error',
										'delay' => 'trustscript-queue-reason-scheduled',
									);
									$reason = $item['failure_reason'];
									if (isset($reason_labels[$reason])) {
										$class = isset($reason_class_map[$reason]) ? $reason_class_map[$reason] : '';
										echo '<span class=\"' . esc_attr($class) . '\">' . esc_html($reason_labels[$reason]) . '</span>';
									} else {
										echo esc_html($reason);
									}
									?>
								</td>
								<td><?php echo esc_html( $item['queued_at'] ? TrustScript_Date_Formatter::format( $item['queued_at'], 'datetime' ) : '—' ); ?></td>
								<td>
									<?php
									if (!empty($item['scheduled_for'])) {
										$sf_formatted = TrustScript_Date_Formatter::format( $item['scheduled_for'], 'datetime' );
										echo '<code class=\"trustscript-queue-status-scheduled\">' . esc_html( $sf_formatted ) . '</code>';
									} else {
										echo '<em class=\"trustscript-queue-placeholder-text\">' . esc_html__('Immediate', 'trustscript') . '</em>';
									}
									?>
								</td>
								<td><?php echo $item['last_attempt_at'] ? esc_html( TrustScript_Date_Formatter::format( $item['last_attempt_at'], 'datetime' ) ) : '<em class=\"trustscript-queue-placeholder-text\">' . esc_html__('Never', 'trustscript') . '</em>'; ?>
								</td>
								<td class=\"trustscript-queue-retry-count\"><?php echo esc_html($item['retry_count']); ?></td>
								<td>
									<?php
									$status_labels = array(
										'pending' => __('Pending', 'trustscript'),
										'failed' => __('Failed', 'trustscript'),
										'completed' => __('Completed', 'trustscript'),
									);
									$status_class_map = array(
										'pending' => 'trustscript-queue-status-pending',
										'failed' => 'trustscript-queue-status-failed',
										'completed' => 'trustscript-queue-status-completed',
									);
									$status = $item['status'];
									if (isset($status_labels[$status])) {
										$class = isset($status_class_map[$status]) ? $status_class_map[$status] : '';
										echo '<span class=\"' . esc_attr($class) . '\">' . esc_html($status_labels[$status]) . '</span>';
									} else {
										echo esc_html($status);
									}
									?>
								</td>
								<td class="trustscript-queue-actions">
									<?php if ('completed' !== $item['status']): ?>
										<button type="button" class="button button-small trustscript-queue-retry"
											data-id="<?php echo esc_attr($item['id']); ?>"
											data-nonce="<?php echo esc_attr(wp_create_nonce('trustscript_admin')); ?>">
											<?php esc_html_e('Retry', 'trustscript'); ?>
										</button>
										<button type="button" class="button button-small button-link-delete trustscript-queue-clear"
											data-id="<?php echo esc_attr($item['id']); ?>"
											data-order="<?php echo esc_attr($item['order_id']); ?>"
											data-nonce="<?php echo esc_attr(wp_create_nonce('trustscript_admin')); ?>">
											<?php esc_html_e('Clear', 'trustscript'); ?>
										</button>
									<?php else: ?>
										<em
											class="trustscript-queue-no-action"><?php esc_html_e('No actions available', 'trustscript'); ?></em>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ($total_pages > 1): ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$pagination_args = array(
							'base' => add_query_arg('paged', '%#%', add_query_arg('tab', $tab, admin_url('admin.php?page=trustscript-queue'))),
							'format' => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total' => $total_pages,
							'current' => $page_num,
						);
						echo wp_kses_post(paginate_links($pagination_args));
						?>
					</div>
					<p class="displaying-num">
						<?php printf(
							/* translators: %d = total number of items */
							esc_html(_n('%d item', '%d items', $total, 'trustscript')),
							(int) $total
						); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_queue_retry()
	{
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated immediately after, sanitization not needed for wp_verify_nonce
		$nonce_verified = wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'trustscript_admin');

		if (!$nonce_verified) {
			wp_send_json_error(array('message' => __('Security verification failed. Please refresh the page.', 'trustscript')), 403);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'trustscript')), 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if (!$id) {
			wp_send_json_error(array('message' => __('Invalid ID', 'trustscript')));
		}

		global $wpdb;
		$table = esc_sql(TrustScript_Queue::get_table_name());
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table name is safely generated by get_table_name() method
		$item = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table . " WHERE id = %d", $id), ARRAY_A);

		if (!$item) {
			wp_send_json_error(array('message' => __('Queue item not found', 'trustscript')));
		}

		// Prevent retrying completed items
		if ('completed' === $item['status']) {
			wp_send_json_error(array('message' => __('This order has already been completed. No retry needed.', 'trustscript')));
		}

		$service_manager = TrustScript_Service_Manager::get_instance();
		$providers = $service_manager->get_active_providers();
		$service_id = sanitize_key($item['service_id']);
		$order_id = (int) $item['order_id'];

		if (!isset($providers[$service_id])) {
			wp_send_json_error(array('message' => sprintf( /* translators: %s: service ID */ __('Service provider not active: %s', 'trustscript'), esc_html($service_id))));
		}

		$provider = $providers[$service_id];
		$success = $provider->retry_review_request($order_id);

		if ($success) {
			TrustScript_Queue::remove($id);
			wp_send_json_success(array(
				'removed' => true,
				'message' => sprintf( /* translators: %d: order ID */ __('Order #%d processed successfully.', 'trustscript'), $order_id),
			));
		} else {
			$error = $provider->get_last_api_error();
			TrustScript_Queue::reset_to_pending($id);
			wp_send_json_error(array(
				'removed' => false,
				'reason' => $error,
				'message' => sprintf( /* translators: %s: error message */ __('Request failed (%s). Order remains in queue.', 'trustscript'), $error ?: 'unknown'),
			));
		}
	}

	public function handle_queue_clear()
	{
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated immediately after, sanitization not needed for wp_verify_nonce
		$nonce_verified = wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'trustscript_admin');

		if (!$nonce_verified) {
			wp_send_json_error(array('message' => __('Security verification failed. Please refresh the page.', 'trustscript')), 403);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'trustscript')), 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if (!$id) {
			wp_send_json_error(array('message' => __('Invalid ID', 'trustscript')));
		}

		global $wpdb;
		$table = esc_sql(TrustScript_Queue::get_table_name());
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table name is safely generated by get_table_name() method
		$item = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $table . " WHERE id = %d", $id), ARRAY_A);

		if ($item && 'completed' === $item['status']) {
			wp_send_json_error(array('message' => __('Completed items cannot be cleared (kept for audit trail). Use the Completed tab to view processed orders.', 'trustscript')));
		}

		if (TrustScript_Queue::remove($id)) {
			wp_send_json_success(array('message' => __('Item removed from queue.', 'trustscript')));
		} else {
			wp_send_json_error(array('message' => __('Item not found or already removed.', 'trustscript')));
		}
	}

	public function handle_queue_process_all()
	{
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated immediately after, sanitization not needed for wp_verify_nonce
		$nonce_verified = wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'trustscript_admin');

		if (!$nonce_verified) {
			wp_send_json_error(array('message' => __('Security verification failed. Please refresh the page and try again.', 'trustscript')), 403);
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied', 'trustscript')), 403);
		}

		try {
			$results = TrustScript_Queue::process_batch(20, true);

			if (!is_array($results) || !isset($results['processed'])) {
				$results = array(
					'processed' => 0,
					'skipped' => 0,
					'failed' => 0,
				);
			}

			set_transient(
				'trustscript_queue_processed_notice_' . get_current_user_id(),
				array(
					'processed' => (int) $results['processed'],
					'skipped' => (int) $results['skipped'],
					'failed' => (int) $results['failed'],
					'still_pending' => TrustScript_Queue::count_pending(),
				),
				60
			);

			wp_send_json_success(array(
				'processed' => (int) $results['processed'],
				'skipped' => (int) $results['skipped'],
				'failed' => (int) $results['failed'],
				'stillPending' => TrustScript_Queue::count_pending(),
			));
		} catch (Exception $e) {
			wp_trigger_error(__METHOD__, '[TrustScript] AJAX Error in handle_queue_process_all: ' . $e->getMessage(), E_USER_WARNING);
			wp_send_json_error(array('message' => __('An error occurred while processing the queue. Check the error logs for details.', 'trustscript')), 500);
		}
	}
}