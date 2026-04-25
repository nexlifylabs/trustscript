<?php
/**
 * TrustScript Reviews Page Handler
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Reviews_Page {
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$app_url = trustscript_get_app_url();
		$enabled = get_option( 'trustscript_reviews_enabled', false );
		$categories = get_option( 'trustscript_review_categories', array() );
		$auto_publish = get_option( 'trustscript_auto_publish', false );
		$collect_rating = get_option( 'trustscript_collect_rating', true );
		$collect_photos = get_option( 'trustscript_collect_photos', true );
		$collect_videos = get_option( 'trustscript_collect_videos', false );
		$delay_hours = get_option( 'trustscript_review_delay_hours', 1 );
		$intl_delay_hours = get_option( 'trustscript_international_delay_hours', 336 );
		$preset_delays = array( 0, 1, 12, 24, 48, 72, 168, 336, 504, 672 );
		$delay_is_custom = ! in_array( (int) $delay_hours, $preset_delays, true );
		$custom_delay_value = 0;
		$custom_delay_unit = 'hours';
		
		if ( $delay_is_custom ) {
			if ( (int) $delay_hours % 24 === 0 ) {
				$custom_delay_value = (int) $delay_hours / 24;
				$custom_delay_unit = 'days';
			} else {
				$custom_delay_value = (int) $delay_hours;
				$custom_delay_unit = 'hours';
			}
		}
		
		$intl_preset_delays = array( 336, 504, 672, 1344 ); 
		$intl_delay_is_custom = ! in_array( (int) $intl_delay_hours, $intl_preset_delays, true );
		$custom_intl_delay_value = 0;
		$custom_intl_delay_unit = 'hours';
		
		if ( $intl_delay_is_custom ) {
			if ( (int) $intl_delay_hours % 24 === 0 ) {
				$custom_intl_delay_value = (int) $intl_delay_hours / 24;
				$custom_intl_delay_unit = 'days';
			} else {
				$custom_intl_delay_value = (int) $intl_delay_hours;
				$custom_intl_delay_unit = 'hours';
			}
		}
		
		$trigger_status = get_option( 'trustscript_review_trigger_status', 'delivered' );
		$auto_sync_enabled = get_option( 'trustscript_auto_sync_enabled', false );
		$auto_sync_time = get_option( 'trustscript_auto_sync_time', '02:00' );
		$auto_sync_lookback = get_option( 'trustscript_auto_sync_lookback', 2 );
		$review_keywords = get_option( 'trustscript_review_keywords', array() );
		$available_keywords = TrustScript_Review_Renderer::get_available_keywords();
		$next_run = TrustScript_Auto_Sync::get_next_run();
		$last_run = TrustScript_Auto_Sync::get_last_run_time();
		$last_stats = TrustScript_Auto_Sync::get_last_run_stats();
		
		$order_status = new TrustScript_Order_Status();
		$existing_delivered_status = $order_status->get_existing_delivered_status();
		$delivered_status_name = TrustScript_Order_Status::get_delivered_status_name();

		$wc_categories = array();
		if ( function_exists( 'wc_get_product_terms' ) || taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms( array(
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $terms ) ) {
				$wc_categories = $terms;
			}
		}

		$admin = TrustScript_Plugin_Admin::get_instance();

		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Review Settings', 'trustscript' ); ?></h1>
			
			<div class="trustscript-card trustscript-mb-24">
				<h2><?php esc_html_e( 'Review Settings', 'trustscript' ); ?></h2>
				<p><?php esc_html_e( 'Configure how TrustScript generates and collects AI-powered reviews from your customers across all services.', 'trustscript' ); ?></p>
			</div>
			<?php
			$admin->render_service_detection_ui();
			?>

			<div class="trustscript-card trustscript-faq-card">
				<div class="trustscript-faq-header">
					<h2><?php esc_html_e( 'Review Email Timing Guide', 'trustscript' ); ?></h2>
					<p><?php esc_html_e( 'Common questions about when and how to send review requests', 'trustscript' ); ?></p>
				</div>

				<div class="trustscript-faq-item">
					<button type="button" class="trustscript-faq-question" aria-expanded="false">
						<span class="trustscript-faq-icon">+</span>
						<span class="trustscript-faq-text"><?php esc_html_e( 'Why shouldn\'t I use "Completed" status?', 'trustscript' ); ?></span>
					</button>
					<div class="trustscript-faq-answer">
						<p>
							<?php esc_html_e( 'Most stores mark orders as "Completed" when shipped, NOT when delivered. This means customers receive review requests while the package is still in transit - before they even have the product!', 'trustscript' ); ?>
						</p>
						<div class="trustscript-faq-warning">
							<span class="dashicons dashicons-warning"></span>
							<strong><?php esc_html_e( 'Result:', 'trustscript' ); ?></strong>
							<?php esc_html_e( 'Low response rates, frustrated customers, and poor quality reviews.', 'trustscript' ); ?>
						</div>
					</div>
				</div>

				<div class="trustscript-faq-item">
					<button type="button" class="trustscript-faq-question" aria-expanded="false">
						<span class="trustscript-faq-icon">+</span>
						<span class="trustscript-faq-text"><?php esc_html_e( 'What is the "Delivered" status?', 'trustscript' ); ?></span>
					</button>
					<div class="trustscript-faq-answer">
						<p>
							<?php esc_html_e( 'TrustScript creates a new "Delivered" order status. Mark orders as "Delivered" only when customers actually receive their products.', 'trustscript' ); ?>
						</p>
						<ul class="trustscript-faq-list">
							<li><?php esc_html_e( '✅ Customers have the product in hand', 'trustscript' ); ?></li>
							<li><?php esc_html_e( '✅ Review emails sent at the perfect time', 'trustscript' ); ?></li>
							<li><?php esc_html_e( '✅ Higher response rates and quality reviews', 'trustscript' ); ?></li>
							<li><?php esc_html_e( '✅ Professional customer experience', 'trustscript' ); ?></li>
						</ul>
					</div>
				</div>

				<div class="trustscript-faq-item">
					<button type="button" class="trustscript-faq-question" aria-expanded="false">
						<span class="trustscript-faq-icon">+</span>
						<span class="trustscript-faq-text"><?php esc_html_e( 'How do I use the Delivered status?', 'trustscript' ); ?></span>
					</button>
					<div class="trustscript-faq-answer">
						<?php if ( $existing_delivered_status ) : ?>
							<div class="trustscript-faq-success">
								<strong><?php esc_html_e( 'Delivered Status Detected!', 'trustscript' ); ?></strong>
								<p>
									<?php 
									printf( 
										/* translators: %s: status name */
										esc_html__( 'TrustScript found an existing "Delivered" status. We\'ll use this automatically!', 'trustscript' ), 
										'<code>' . esc_html( $existing_delivered_status ) . '</code>' 
									); 
									?>
								</p>
							</div>
						<?php endif; ?>
						
						<div class="trustscript-faq-method">
							<h4><?php esc_html_e( 'Manual Method:', 'trustscript' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Go to WooCommerce → Orders', 'trustscript' ); ?></li>
								<li><?php esc_html_e( 'When a customer confirms delivery, change order status to "Delivered"', 'trustscript' ); ?></li>
								<li><?php esc_html_e( 'TrustScript will send review request after configured delay', 'trustscript' ); ?></li>
							</ol>
						</div>

						<div class="trustscript-faq-method">
							<h4><?php esc_html_e( 'Automatic Method (Recommended):', 'trustscript' ); ?></h4>
							<ul>
								<li><?php esc_html_e( 'Use a shipment tracking plugin like ShipStation, TrackShip, or WooCommerce Shipment Tracking', 'trustscript' ); ?></li>
								<li><?php esc_html_e( 'Configure it to auto-update orders to "Delivered" when tracking shows delivery', 'trustscript' ); ?></li>
								<li><?php esc_html_e( 'TrustScript will handle the rest automatically!', 'trustscript' ); ?></li>
							</ul>
						</div>
					</div>
				</div>

				<div class="trustscript-faq-item">
					<button type="button" class="trustscript-faq-question" aria-expanded="false">
						<span class="trustscript-faq-icon">+</span>
						<span class="trustscript-faq-text"><?php esc_html_e( 'Can I still use "Completed" status?', 'trustscript' ); ?></span>
					</button>
					<div class="trustscript-faq-answer">
						<div class="trustscript-faq-tip">
							<span class="dashicons dashicons-lightbulb"></span>
							<p>
								<strong><?php esc_html_e( 'Yes, but with caution.', 'trustscript' ); ?></strong>
								<?php esc_html_e( 'You can use "Completed" status, but set a longer delay to ensure customers receive products first. By default, international orders use this same domestic delay. To send international orders on a different schedule, enable the "Handle international orders differently" option above to set a separate delay.', 'trustscript' ); ?>
							</p>
						</div>
					</div>
				</div>
			</div>

			<div class="trustscript-grid" style="margin-top: 24px;">
				<div class="trustscript-card">
					<h2><?php esc_html_e( 'Review Collection', 'trustscript' ); ?></h2>
					<div class="trustscript-form-group">
						<label class="trustscript-toggle">
							<input type="checkbox" id="trustscript_reviews_enabled" <?php checked( $enabled ); ?>>
							<span class="trustscript-toggle-slider"></span>
						</label>
						<label for="trustscript_reviews_enabled" style="margin-left: 10px; cursor: pointer;">
							<?php esc_html_e( 'Enable automatic review requests', 'trustscript' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, TrustScript will automatically send review request emails to customers after order completion.', 'trustscript' ); ?></p>
					</div>

					<div class="trustscript-form-group">
						<label for="trustscript_review_delay_hours"><?php esc_html_e( 'Domestic Review Request Delay', 'trustscript' ); ?></label>
						<div style="display: flex; gap: 10px; align-items: flex-end;">
							<div style="flex: 1;">
								<select id="trustscript_review_delay_hours" class="trustscript-form-input">
									<option value="0" <?php selected( ! $delay_is_custom && $delay_hours == 0 ); ?>><?php esc_html_e( 'Immediately', 'trustscript' ); ?></option>
									<option value="1" <?php selected( ! $delay_is_custom && $delay_hours == 1 ); ?>><?php esc_html_e( '1 hour', 'trustscript' ); ?></option>
									<option value="12" <?php selected( ! $delay_is_custom && $delay_hours == 12 ); ?>><?php esc_html_e( '12 hours', 'trustscript' ); ?></option>
									<option value="24" <?php selected( ! $delay_is_custom && $delay_hours == 24 ); ?>><?php esc_html_e( '1 day', 'trustscript' ); ?></option>
									<option value="48" <?php selected( ! $delay_is_custom && $delay_hours == 48 ); ?>><?php esc_html_e( '2 days', 'trustscript' ); ?></option>
									<option value="72" <?php selected( ! $delay_is_custom && $delay_hours == 72 ); ?>><?php esc_html_e( '3 days', 'trustscript' ); ?></option>
									<option value="168" <?php selected( ! $delay_is_custom && $delay_hours == 168 ); ?>><?php esc_html_e( '1 week', 'trustscript' ); ?></option>
									<option value="336" <?php selected( ! $delay_is_custom && $delay_hours == 336 ); ?>><?php esc_html_e( '2 weeks', 'trustscript' ); ?></option>
									<option value="504" <?php selected( ! $delay_is_custom && $delay_hours == 504 ); ?>><?php esc_html_e( '3 weeks', 'trustscript' ); ?></option>
									<option value="672" <?php selected( ! $delay_is_custom && $delay_hours == 672 ); ?>><?php esc_html_e( '4 weeks (1 month)', 'trustscript' ); ?></option>
									<option value="custom" <?php selected( $delay_is_custom ); ?> style="color: #10b981; font-weight: bold;"><?php esc_html_e( 'Custom...', 'trustscript' ); ?></option>
								</select>
							</div>
							<div style="flex: 1; display: <?php echo $delay_is_custom ? 'flex' : 'none'; ?>;" id="trustscript-custom-delay-wrapper">
								<div style="display: flex; gap: 8px;">
									<input type="number" id="trustscript_custom_delay_value" class="trustscript-form-input" min="0" placeholder="<?php esc_attr_e( 'Value', 'trustscript' ); ?>" value="<?php echo esc_attr( $custom_delay_value ); ?>" style="max-width: 80px;">
									<select id="trustscript_custom_delay_unit" class="trustscript-form-input" style="min-width: 100px;">
										<option value="hours" <?php selected( $custom_delay_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'trustscript' ); ?></option>
										<option value="days" <?php selected( $custom_delay_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'trustscript' ); ?></option>
									</select>
								</div>
							</div>
						</div>
						<p class="description" id="delay-description"><?php esc_html_e( 'Time to wait after order status change before sending review request to customers.', 'trustscript' ); ?></p>
					</div>

					<div class="trustscript-form-group" style="border-top: 1px solid #e5e7eb; padding-top: 20px; margin-top: 20px;">
						<label class="trustscript-toggle">
							<input type="checkbox" id="trustscript_enable_international_handling" <?php checked( get_option( 'trustscript_enable_international_handling', false ) ); ?>>
							<span class="trustscript-toggle-slider"></span>
						</label>
						<label for="trustscript_enable_international_handling" style="margin-left: 10px; cursor: pointer;">
							<?php esc_html_e( 'Handle international orders differently', 'trustscript' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, international orders will use a separate, longer review request delay. This gives international customers more time to receive and verify their orders.', 'trustscript' ); ?></p>
					</div>

					<div id="trustscript-international-delay-section" style="display: <?php echo get_option( 'trustscript_enable_international_handling', false ) ? 'block' : 'none'; ?>; padding: 16px; background: #f0f9ff; border: 1px solid #93c5fd; border-radius: 8px; margin-top: 12px;">
						<h3 style="margin-top: 0; color: #1e40af;"><?php esc_html_e( 'International Order Review Request Delay', 'trustscript' ); ?></h3>
						<div class="trustscript-form-group">
							<label for="trustscript_international_delay_hours"><?php esc_html_e( 'International Delay', 'trustscript' ); ?></label>
							<div style="display: flex; gap: 10px; align-items: flex-end;">
								<div style="flex: 1;">
									<select id="trustscript_international_delay_hours" class="trustscript-form-input">
										<option value="336" <?php selected( ! $intl_delay_is_custom && $intl_delay_hours == 336 ); ?>><?php esc_html_e( '2 weeks', 'trustscript' ); ?></option>
										<option value="504" <?php selected( ! $intl_delay_is_custom && $intl_delay_hours == 504 ); ?>><?php esc_html_e( '3 weeks', 'trustscript' ); ?></option>
										<option value="672" <?php selected( ! $intl_delay_is_custom && $intl_delay_hours == 672 ); ?>><?php esc_html_e( '4 weeks (1 month)', 'trustscript' ); ?></option>
										<option value="1344" <?php selected( ! $intl_delay_is_custom && $intl_delay_hours == 1344 ); ?>><?php esc_html_e( '8 weeks (2 months)', 'trustscript' ); ?></option>
										<option value="custom" <?php selected( $intl_delay_is_custom ); ?> style="color: #10b981; font-weight: bold;"><?php esc_html_e( 'Custom...', 'trustscript' ); ?></option>
									</select>
								</div>
								<div style="flex: 1; display: <?php echo $intl_delay_is_custom ? 'flex' : 'none'; ?>;" id="trustscript-custom-intl-delay-wrapper">
									<div style="display: flex; gap: 8px;">
										<input type="number" id="trustscript_custom_intl_delay_value" class="trustscript-form-input" min="0" placeholder="<?php esc_attr_e( 'Value', 'trustscript' ); ?>" value="<?php echo esc_attr( $custom_intl_delay_value ); ?>" style="max-width: 80px;">
										<select id="trustscript_custom_intl_delay_unit" class="trustscript-form-input" style="min-width: 100px;">
											<option value="hours" <?php selected( $custom_intl_delay_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'trustscript' ); ?></option>
											<option value="days" <?php selected( $custom_intl_delay_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'trustscript' ); ?></option>
										</select>
									</div>
								</div>
							</div>
							<p class="description"><?php esc_html_e( 'International shipments typically take longer. Set a longer delay to ensure customers receive and verify orders before review requests arrive.', 'trustscript' ); ?></p>
						</div>
					</div>
				</div>

				<div class="trustscript-card">
					<h2><?php esc_html_e( 'Service-Specific Settings', 'trustscript' ); ?></h2>
					<p><?php esc_html_e( 'Configure filtering and options for each active service independently.', 'trustscript' ); ?></p>
					
					<?php
					$service_manager = TrustScript_Service_Manager::get_instance();
					$active_services = $service_manager->get_active_providers();
					?>
					
					<?php if ( count( $active_services ) > 1 ) : ?>
						<div class="trustscript-service-tabs">
							<div class="trustscript-tab-navigation">
								<?php $first = true; ?>
								<?php foreach ( $active_services as $service_id => $provider ) : ?>
									<button type="button" class="trustscript-tab-button <?php echo $first ? 'active' : ''; ?>" 
											data-service="<?php echo esc_attr( $service_id ); ?>">
										<?php echo esc_html( $provider->get_service_icon() . ' ' . $provider->get_service_name() ); ?>
									</button>
									<?php $first = false; ?>
								<?php endforeach; ?>
							</div>
							
							<div class="trustscript-tab-content-wrapper">
								<?php $first = true; ?>
								<?php foreach ( $active_services as $service_id => $provider ) : ?>
									<div class="trustscript-tab-panel <?php echo $first ? 'active' : ''; ?>" 
										 id="trustscript-service-<?php echo esc_attr( $service_id ); ?>">
										<?php $admin->render_service_specific_settings( $service_id, $provider, $categories ); ?>
									</div>
									<?php $first = false; ?>
								<?php endforeach; ?>
							</div>
						</div>
					<?php else : ?>
						<?php foreach ( $active_services as $service_id => $provider ) : ?>
							<?php $admin->render_service_specific_settings( $service_id, $provider, $categories ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
					
					<?php if ( empty( $active_services ) ) : ?>
						<div class="trustscript-alert trustscript-alert-warning">
							<p><?php esc_html_e( 'No active services detected. Please install and activate WooCommerce, MemberPress, or other supported plugins.', 'trustscript' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<div class="trustscript-card">
					<h2><?php esc_html_e( 'Review Publishing', 'trustscript' ); ?></h2>
					<div class="trustscript-form-group">
						<label class="trustscript-toggle">
							<input type="checkbox" id="trustscript_auto_publish" <?php checked( $auto_publish ); ?>>
							<span class="trustscript-toggle-slider"></span>
						</label>
						<label for="trustscript_auto_publish" style="margin-left: 10px; cursor: pointer;">
							<?php esc_html_e( 'Auto-publish approved reviews', 'trustscript' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, customer-approved reviews will be published immediately to your WooCommerce product pages. When disabled, reviews will require admin approval first.', 'trustscript' ); ?></p>
					</div>
				</div>

				<div class="trustscript-card">
					<div class="trustscript-alert trustscript-alert-info">
						<span class="dashicons dashicons-info"></span>
						<strong><?php esc_html_e( 'Review & Email Settings', 'trustscript' ); ?></strong>
						<p><?php esc_html_e( 'The following settings are now managed from your TrustScript Dashboard:', 'trustscript' ); ?></p>
						<ul style="margin: 12px 0; padding-left: 20px;">
							<li><?php esc_html_e( 'Email Mode Settings (Auto Send or Manual Send)', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'Photo Uploads (Enable/Disable)', 'trustscript' ); ?></li>
							<li><?php esc_html_e( 'Video Uploads (Coming Soon)', 'trustscript' ); ?></li>
						</ul>
						<p>
							<a href="<?php echo esc_url( $app_url . '/dashboard/wordpress-orders' ); ?>" target="_blank" class="button button-primary">
								<?php esc_html_e( 'Go to TrustScript Dashboard →', 'trustscript' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>

			<div class="trustscript-setting-box">
				<h2><?php esc_html_e( 'Review Filter Keywords', 'trustscript' ); ?></h2>
				<p><?php esc_html_e( 'Select which keywords should appear as filter chips on product pages. Keywords are only displayed if they exist in actual customer reviews. You can also configure product-specific keywords from the product editor.', 'trustscript' ); ?></p>
				
				<div class="trustscript-form-group">
					<label><?php esc_html_e( 'Available Keywords', 'trustscript' ); ?></label>
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 12px;">
						<?php foreach ( $available_keywords as $keyword ) : ?>
							<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; transition: all 0.2s;">
								<input type="checkbox" 
									   name="trustscript_keywords[]" 
									   value="<?php echo esc_attr( $keyword ); ?>"
									   class="trustscript-keyword-checkbox"
									   <?php checked( in_array( $keyword, (array) $review_keywords, true ) ); ?>>
								<span><?php echo esc_html( $keyword ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description" style="margin-top: 12px;">
						<?php esc_html_e( '💡 Tip: Select the keywords that matter most for your products. Unselected keywords will never appear as filter chips, even if found in reviews.', 'trustscript' ); ?>
					</p>
				</div>
			</div>

			<div class="trustscript-setting-box">
				<h2><?php esc_html_e( 'Review Voting', 'trustscript' ); ?></h2>
				<p><?php esc_html_e( 'Allow logged-in users to vote on review helpfulness. One vote per review per user.', 'trustscript' ); ?></p>
				
				<div class="trustscript-setting-row">
					<div class="trustscript-setting-control">
						<label class="trustscript-toggle">
							<input type="checkbox" id="trustscript_enable_voting" <?php checked( get_option( 'trustscript_enable_voting', false ) ); ?>>
							<span class="trustscript-toggle-slider"></span>
						</label>
					</div>
					<div class="trustscript-setting-label">
						<label for="trustscript_enable_voting">
							<?php esc_html_e( 'Enable upvote/downvote on reviews', 'trustscript' ); ?>
						</label>
						<p class="trustscript-setting-description">
							<?php esc_html_e( 'When enabled, logged-in users can vote on review helpfulness. Users must be logged in to vote, and each user can vote once per review.', 'trustscript' ); ?>
						</p>
					</div>
				</div>  

				<div class="trustscript-privacy-notice">
					<p class="trustscript-privacy-title">
						<span class="dashicons dashicons-shield"></span>
						<?php esc_html_e( 'Privacy Guarantee', 'trustscript' ); ?>
					</p>
					<p class="trustscript-privacy-description">
						<?php esc_html_e( '✓ Vote data is stored ONLY on your WordPress site', 'trustscript' ); ?><br>
						<?php esc_html_e( '✓ NO voting data is ever sent to or stored on TrustScript servers', 'trustscript' ); ?><br>
						<?php esc_html_e( '✓ All vote tracking is handled locally using WordPress user accounts', 'trustscript' ); ?><br>
						<?php esc_html_e( '✓ Users must be logged in to vote', 'trustscript' ); ?>
					</p>
				</div>
			</div>

			<div class="trustscript-card trustscript-setting-box">
				<h2><?php esc_html_e( 'Automatic Daily Sync', 'trustscript' ); ?></h2>
				
				<div class="trustscript-privacy-notice">
					<h3 class="trustscript-privacy-title">
						<strong><?php esc_html_e( 'Two-way synchronization', 'trustscript' ); ?></strong>
					</h3>
					<p class="trustscript-sync-info-box__body"><?php esc_html_e( 'TrustScript runs a complete synchronization once every day. During this cycle, it:', 'trustscript' ); ?></p>
					<ol class="trustscript-sync-info-box__list">
						<li><?php esc_html_e( 'Publishes any approved reviews from TrustScript to your products (WooCommerce, MemberPress, etc.)', 'trustscript' ); ?></li>
						<li><?php esc_html_e( 'Sends completed orders/bookings to TrustScript (WooCommerce, MemberPress)', 'trustscript' ); ?></li>
					</ol>
					<p class="trustscript-sync-info-box__note"><?php esc_html_e( 'This ensures all your services stay synced even if webhooks are unavailable or manual sync is not triggered.', 'trustscript' ); ?></p>
				</div>

				<div class="trustscript-setting-row">
					<div class="trustscript-setting-control">
						<label class="trustscript-toggle">
							<input type="checkbox" id="trustscript_auto_sync_enabled" <?php checked( $auto_sync_enabled ); ?>>
							<span class="trustscript-toggle-slider"></span>
						</label>
					</div>
					<div class="trustscript-setting-label">
						<label for="trustscript_auto_sync_enabled">
							<?php esc_html_e( 'Enable automatic daily sync', 'trustscript' ); ?>
						</label>
						<p class="trustscript-setting-description">
							<?php esc_html_e( 'When enabled, TrustScript will automatically check for missed orders once per day.', 'trustscript' ); ?>
						</p>
					</div>
				</div>

				<div class="trustscript-setting-row">
					<div class="trustscript-setting-label">
						<label for="trustscript_auto_sync_time"><?php esc_html_e( 'Daily Sync Time', 'trustscript' ); ?></label>
						<p class="trustscript-setting-description"><?php esc_html_e( 'Time of day to run automatic sync (in your site timezone).', 'trustscript' ); ?></p>
					</div>
					<div class="trustscript-setting-control">
						<input 
							type="time" 
							id="trustscript_auto_sync_time" 
							class="trustscript-form-input" 
							value="<?php echo esc_attr( $auto_sync_time ); ?>"
							style="min-width: 200px;"
						>
					</div>
				</div>

				<?php if ( $next_run ) : ?>
					<div class="trustscript-alert trustscript-alert-success">
						<strong class="trustscript-alert-label">
							<?php esc_html_e( 'Next Scheduled Sync:', 'trustscript' ); ?>
						</strong>
						<span class="trustscript-alert-time">
							<?php echo esc_html( wp_date( 'F j, Y \a\t g:i A', $next_run ) ); ?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $last_run ) : ?>
					<div class="trustscript-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 16px;">
						<div class="trustscript-stat-card" style="background: white; color: black">
							<div class="trustscript-stat-value"><?php echo esc_html( $last_stats['processed'] ); ?></div>
							<div class="trustscript-stat-label"><?php esc_html_e( 'Last Run: Processed', 'trustscript' ); ?></div>
						</div>
						<div class="trustscript-stat-card" style="background: white; color: black">
							<div class="trustscript-stat-value"><?php echo esc_html( $last_stats['skipped'] ); ?></div>
							<div class="trustscript-stat-label"><?php esc_html_e( 'Last Run: Skipped', 'trustscript' ); ?></div>
						</div>
						<div class="trustscript-stat-card" style="background: white; color: black">
							<div class="trustscript-stat-value"><?php echo esc_html( $last_stats['errors'] ); ?></div>
							<div class="trustscript-stat-label"><?php esc_html_e( 'Last Run: Errors', 'trustscript' ); ?></div>
						</div>
					</div>
					<p class="description" style="margin-top: 8px; font-size: 12px;">
						<?php esc_html_e( 'Last run:', 'trustscript' ); ?> 
						<?php echo esc_html( wp_date( 'F j, Y \a\t g:i A', $last_run ) ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="trustscript-save-button-wrapper">
				<button type="button" id="trustscript-save-review-settings" class="trustscript-btn trustscript-btn-primary">
					<?php esc_html_e( 'Save Review Settings', 'trustscript' ); ?>
				</button>
				<span id="trustscript-review-save-status" class="trustscript-save-status"></span>
			</div>

			<div class="trustscript-card trustscript-manual-sync-card">
				<h2 class="trustscript-manual-sync-title">
					<span class="dashicons dashicons-update trustscript-update-icon"></span>
					<?php esc_html_e( 'Manual Sync & Test', 'trustscript' ); ?>
				</h2>
				<p>
					<?php esc_html_e( 'Run a complete sync immediately without waiting for the daily schedule. This is helpful for testing on staging sites.', 'trustscript' ); ?>
				</p>
				<p style="background: #dbeafe; padding: 12px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 16px;">
					<strong>
						<strong>⚡<?php esc_html_e( 'What happens when you click:', 'trustscript' ); ?></strong>
					</strong><br>
					<?php esc_html_e( '1. Fetches any approved reviews from TrustScript and publishes them to your products/services', 'trustscript' ); ?><br>
					<?php esc_html_e( '2. Sends existing orders/bookings from all enabled services (WooCommerce, MemberPress, etc.) to TrustScript', 'trustscript' ); ?>
				</p>
				
				<div class="trustscript-form-group">
					<label for="trustscript-sync-days"><?php esc_html_e( 'Sync orders from the last:', 'trustscript' ); ?></label>
					<select id="trustscript-sync-days" class="trustscript-form-input trustscript-sync-select">
						<option value="1">1 day (24 hours)</option>
						<option value="2" selected>2 days (48 hours)</option>
					</select>
				</div>

				<button type="button" id="trustscript-sync-orders" class="trustscript-btn trustscript-btn-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Run Complete Sync Now', 'trustscript' ); ?>
				</button>
				<span id="trustscript-sync-status" class="trustscript-sync-status"></span>
				
				<div id="trustscript-sync-results" class="trustscript-sync-results-card">
					<div class="trustscript-stat-card trustscript-stat-card-success trustscript-sync-results-success">
						<div class="trustscript-stat-value trustscript-sync-results-value" id="sync-count">0</div>
						<div class="trustscript-stat-label trustscript-sync-results-label"><?php esc_html_e( 'TOTAL PROCESSED', 'trustscript' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}