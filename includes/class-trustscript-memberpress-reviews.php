<?php
/**
 * TrustScript MemberPress Reviews Display
 * This class handles displaying reviews for MemberPress membership products via a shortcode and settings page.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_MemberPress_Reviews extends TrustScript_Frontend_Reviews_Base {

	private $is_shortcode_rendered = false;

	public function __construct() {
		add_shortcode( 'trustscript_memberpress_reviews', array( $this, 'render_reviews_shortcode' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_footer', array( $this, 'enqueue_verification_assets' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_verification_modal' ), 100 );
	}

	protected function get_css_handle() {
		return 'trustscript-memberpress-reviews';
	}

	protected function get_css_path() {
		return 'assets/css/memberpress-reviews.css';
	}

	protected function get_shortcode_tag() {
		return 'trustscript_memberpress_reviews';
	}

	protected function get_verified_label() {
		return __( 'Verified Purchase', 'trustscript' );
	}

	protected function get_verified_simple_label() {
		return __( 'Verified Member', 'trustscript' );
	}

	protected function get_modal_title() {
		return __( 'Verified Purchase Hash', 'trustscript' );
	}

	protected function should_enqueue_shared_css() {
		return false;
	}

	/**
	 * Determine if the verification modal should be rendered on this page.
	  * The modal is only rendered if the shortcode is present on the page or has been rendered,
	  * to avoid unnecessary DOM bloat and potential confusion for users not leaving reviews.
	  *
	 * @return bool
	 */
	protected function should_render_modal() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, $this->get_shortcode_tag() ) ) {
			return true;
		}

		if ( $this->is_shortcode_rendered ) {
			return true;
		}

		return false;
	}

	public function add_settings_page() {
		if ( class_exists( 'MeprOptions' ) ) {
			add_submenu_page(
				'memberpress',
				__( 'Review Display Settings', 'trustscript' ),
				__( 'Review Display', 'trustscript' ),
				'manage_options',
				'trustscript-memberpress-settings',
				array( $this, 'render_settings_page' )
			);
		} else {
			add_options_page(
				__( 'MemberPress Review Display', 'trustscript' ),
				__( 'MemberPress Reviews', 'trustscript' ),
				'manage_options',
				'trustscript-memberpress-settings',
				array( $this, 'render_settings_page' )
			);
		}
	}

	public function register_settings() {
		register_setting( 'trustscript_memberpress_settings', 'trustscript_memberpress_default_layout', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'trustscript_memberpress_settings', 'trustscript_memberpress_who_can_see', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['trustscript_memberpress_save_settings'] ) && check_admin_referer( 'trustscript_memberpress_settings' ) ) {
			update_option( 'trustscript_memberpress_default_layout', isset( $_POST['default_layout'] ) ? sanitize_text_field( wp_unslash( $_POST['default_layout'] ) ) : 'list' );
			update_option( 'trustscript_memberpress_who_can_see', isset( $_POST['who_can_see'] ) ? sanitize_text_field( wp_unslash( $_POST['who_can_see'] ) ) : 'everyone' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully!', 'trustscript' ) . '</p></div>';
		}

		$default_layout = get_option( 'trustscript_memberpress_default_layout', 'list' );
		$who_can_see    = get_option( 'trustscript_memberpress_who_can_see', 'everyone' );
		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'MemberPress Review Display Settings', 'trustscript' ); ?></h1>

			<div style="max-width: 800px; margin: 20px 0; padding: 20px; background: #f0f6ff; border-left: 4px solid #0073aa; border-radius: 4px;">
				<h2><?php esc_html_e( 'How to Display Reviews', 'trustscript' ); ?></h2>
				<p style="margin: 0; font-size: 14px; line-height: 1.6;">
					<?php esc_html_e( 'Use the shortcode below to display member reviews anywhere on your site. The shortcode parameters allow full control over what and how reviews are displayed.', 'trustscript' ); ?>
				</p>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'trustscript_memberpress_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Layout', 'trustscript' ); ?></th>
						<td>
							<select name="default_layout">
								<option value="list" <?php selected( $default_layout, 'list' ); ?>><?php esc_html_e( 'List', 'trustscript' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Reviews display in a vertical list format', 'trustscript' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Who Can See Reviews', 'trustscript' ); ?></th>
						<td>
							<select name="who_can_see">
								<option value="everyone" <?php selected( $who_can_see, 'everyone' ); ?>><?php esc_html_e( 'Everyone', 'trustscript' ); ?></option>
								<option value="members" <?php selected( $who_can_see, 'members' ); ?>><?php esc_html_e( 'Members Only', 'trustscript' ); ?></option>
								<option value="active_members" <?php selected( $who_can_see, 'active_members' ); ?>><?php esc_html_e( 'Active Members Only', 'trustscript' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Control who can view member reviews on your site', 'trustscript' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="trustscript_memberpress_save_settings" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'trustscript' ); ?>" />
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Shortcode Reference', 'trustscript' ); ?></h2>
			
			<h3><?php esc_html_e( 'Basic Usage', 'trustscript' ); ?></h3>
			<p><?php esc_html_e( 'Display member reviews for a specific membership product:', 'trustscript' ); ?></p>
			<code style="display: block; padding: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; font-family: 'Courier New', monospace;">[trustscript_memberpress_reviews id="123"]</code>

			<h3><?php esc_html_e( 'Shortcode Parameters', 'trustscript' ); ?></h3>
			<table class="widefat" style="margin-top: 10px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Parameter', 'trustscript' ); ?></th>
						<th><?php esc_html_e( 'Description', 'trustscript' ); ?></th>
						<th><?php esc_html_e( 'Example', 'trustscript' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>id</code></td>
						<td><?php esc_html_e( 'Membership product ID (required)', 'trustscript' ); ?></td>
						<td><code>id="123"</code></td>
					</tr>
					<tr>
						<td><code>count</code></td>
						<td><?php esc_html_e( 'Number of reviews per page. Default: 10', 'trustscript' ); ?></td>
						<td><code>count="5"</code></td>
					</tr>
					<tr>
						<td><code>layout</code></td>
						<td><?php esc_html_e( 'Display layout (list). Default: list', 'trustscript' ); ?></td>
						<td><code>layout="list"</code></td>
					</tr>
					<tr>
						<td><code>rating</code></td>
						<td><?php esc_html_e( 'Filter by minimum rating (1-5). Default: 0 (show all)', 'trustscript' ); ?></td>
						<td><code>rating="4"</code></td>
					</tr>
					<tr>
						<td><code>page</code></td>
						<td><?php esc_html_e( 'Page number for pagination. Default: 1', 'trustscript' ); ?></td>
						<td><code>page="2"</code></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Example Shortcodes', 'trustscript' ); ?></h3>
			<p><?php esc_html_e( 'Show 5 reviews per page:', 'trustscript' ); ?></p>
			<code style="display: block; padding: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; font-family: 'Courier New', monospace;">[trustscript_memberpress_reviews id="123" count="5"]</code>

			<p><?php esc_html_e( 'Show only 4 and 5 star reviews:', 'trustscript' ); ?></p>
			<code style="display: block; padding: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; font-family: 'Courier New', monospace;">[trustscript_memberpress_reviews id="123" rating="4"]</code>

			<p><?php esc_html_e( 'Show 15 reviews per page:', 'trustscript' ); ?></p>
			<code style="display: block; padding: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; font-family: 'Courier New', monospace;">[trustscript_memberpress_reviews id="123" count="15"]</code>
		</div>

		<?php
	}


	public function enqueue_verification_assets() {
		$should_enqueue = false;

		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, $this->get_shortcode_tag() ) ) {
			$should_enqueue = true;
		}

		if ( $this->is_shortcode_rendered ) {
			$should_enqueue = true;
		}

		if ( ! $should_enqueue && ( is_singular() || is_page() || is_single() ) ) {
			$should_enqueue = apply_filters( 'trustscript_memberpress_should_enqueue_assets', false );
		}

		if ( ! $should_enqueue ) {
			return;
		}

		$this->maybe_enqueue_assets_inline();
	}


	/**
	 * [trustscript_memberpress_reviews id="" count="" layout="" rating="" page=""]
	 */
	public function render_reviews_shortcode( $atts ) {
		$default_layout = get_option( 'trustscript_memberpress_default_layout', 'list' );

		$atts = shortcode_atts( array(
			'id'     => '',
			'count'  => 10,
			'layout' => $default_layout,
			'rating' => 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe usage of $_GET for pagination.
			'page'   => isset( $_GET['review_page'] ) ? intval( $_GET['review_page'] ) : 1,
		), $atts );

		$membership_id = ! empty( $atts['id'] )
			? intval( $atts['id'] )
			: ( is_singular( 'memberpressproduct' ) ? get_the_ID() : 0 );

		if ( ! $membership_id ) {
			return '';
		}

		if ( ! $this->can_view_reviews() ) {
			return '<p>' . esc_html__( 'You must be a member to view reviews.', 'trustscript' ) . '</p>';
		}

		$this->is_shortcode_rendered = true;

		return $this->render_reviews( $membership_id, $atts );
	}


	/**
	 * Render the full reviews block for a given membership ID.
	 *
	 * @param  int   $membership_id
	 * @param  array $args  count, layout, rating, page.
	 * @return string
	 */
	private function render_reviews( $membership_id, $args = array() ) {
		$this->maybe_enqueue_assets_inline();

		$args = wp_parse_args( $args, array(
			'count'  => 10,
			'layout' => 'list',
			'rating' => 0,
			'page'   => 1,
		) );

		$paged    = intval( $args['page'] );
		$per_page = intval( $args['count'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$base_args = array(
			'post_id' => $membership_id,
			'type'    => 'review',
			'status'  => 'approve',
		);

		if ( intval( $args['rating'] ) > 0 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary meta query for rating filter.
			$base_args['meta_query'] = $this->build_rating_meta_query( $args['rating'] );
		}

		$total_reviews = get_comments( array_merge( $base_args, array( 'count' => true ) ) );
		$total_pages   = ceil( $total_reviews / $per_page );

		$reviews = get_comments( array_merge( $base_args, array(
			'number'  => $per_page,
			'offset'  => $offset,
			'orderby' => 'comment_date',
			'order'   => 'DESC',
		) ) );

		if ( empty( $reviews ) && $paged === 1 ) {
			return $this->render_no_reviews();
		}

		update_meta_cache( 'comment', wp_list_pluck( $reviews, 'comment_ID' ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required direct query for average rating calculation.
		$average_rating = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(CAST(m.meta_value AS UNSIGNED))
			 FROM {$wpdb->comments} c
			 INNER JOIN {$wpdb->commentmeta} m ON c.comment_ID = m.comment_id
			 WHERE c.comment_post_ID = %d
			   AND c.comment_type = 'review'
			   AND c.comment_approved = '1'
			   AND m.meta_key = 'rating'",
			$membership_id
		) ) ?: 0;

		ob_start();
		?>

		<div class="trustscript-memberpress-reviews">
			<div class="trustscript-reviews-header">
				<h3><?php esc_html_e( 'Member Reviews', 'trustscript' ); ?></h3>
				<div class="trustscript-reviews-summary">
					<span class="trustscript-average-rating">
						<?php echo esc_html( number_format_i18n( $average_rating, 1 ) ); ?>
						<span class="trustscript-stars trustscript-stars-font">
							<?php echo wp_kses_post( TrustScript_Review_Renderer::render_stars( $average_rating ) ); ?>
						</span>
					</span>
					<span class="trustscript-review-count">
						<?php
						/* translators: %s: Number of reviews */
						echo wp_kses_post( sprintf( _n( '%s review', '%s reviews', $total_reviews, 'trustscript' ), number_format_i18n( $total_reviews ) ) );
						?>
					</span>
				</div>
			</div>
 
			<div class="trustscript-reviews-list <?php echo esc_attr( 'layout-' . $args['layout'] ); ?>">
				<?php foreach ( $reviews as $review ) : ?>
					<?php echo wp_kses_post( $this->render_single_review( $review ) ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="trustscript-pagination">
					<?php echo wp_kses_post( $this->render_pagination( intval( $paged ), intval( $total_pages ) ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php
		return ob_get_clean();
	}

	/**
	 * "No reviews yet" placeholder for membership products.
	 *
	 * @return string
	 */
	private function render_no_reviews() {
		ob_start();
		?>

		<div class="trustscript-no-reviews">
			<div class="trustscript-no-reviews-icon">💬</div>
			<h4><?php esc_html_e( 'No Reviews Yet', 'trustscript' ); ?></h4>
			<p><?php esc_html_e( 'Be the first to share your experience with this membership!', 'trustscript' ); ?></p>
		</div>

		<?php
		return ob_get_clean();
	}


	/**
	 * Determine if the current user can view the reviews based on settings and membership status.
	 *
	 * @return bool
	 */
	private function can_view_reviews() {
		$who_can_see = get_option( 'trustscript_memberpress_who_can_see', 'everyone' );

		if ( $who_can_see === 'everyone' ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( $who_can_see === 'members' ) {
			return true;
		}

		if ( $who_can_see === 'active_members' ) {
			if ( ! class_exists( 'MeprUser' ) ) {
				return true;
			}
			$user = new MeprUser( get_current_user_id() );
			return $user->is_active();
		}

		return false;
	}
}

// Initialize.
new TrustScript_MemberPress_Reviews();