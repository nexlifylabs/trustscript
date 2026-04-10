<?php
/**
 * TrustScript MemberPress Reviews Meta Box
 *
 * @package TrustScript
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_MemberPress_Reviews_MetaBox extends TrustScript_Frontend_Reviews_Base {

	/**
	 * Constructor: Initialize hooks and functionality.
	 */
	public function __construct() {
		if ( ! class_exists( 'MeprOptions' ) ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_filter( 'the_content', array( $this, 'append_reviews_to_content' ) );
		add_action( 'wp_footer', array( $this, 'render_verification_modal' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_metabox_scripts' ) );
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

	protected function should_render_modal() {
		if ( ! is_singular( array( 'page', 'post' ) ) ) {
			return false;
		}
		$post_id = get_the_ID();
		$enabled = get_post_meta( $post_id, '_trustscript_reviews_enabled', true );
		return (bool) $enabled;
	}

	protected function should_enqueue_shared_css() {
		return false;
	}

	public function register_meta_box() {
		add_meta_box(
			'trustscript-memberpress-reviews',
			__( 'TrustScript Member Reviews', 'trustscript' ),
			array( $this, 'render_meta_box_content' ),
			array( 'page', 'post' ),
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue metabox-specific JavaScript and CSS.
	 */
	public function enqueue_metabox_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Enqueue metabox JS
		wp_enqueue_script(
			'trustscript-memberpress-metabox',
			TRUSTSCRIPT_PLUGIN_URL . 'assets/js/memberpress-reviews-metabox.js',
			array(),
			TRUSTSCRIPT_VERSION,
			true
		);
	}

	public function render_meta_box_content( $post ) {
		$enabled = get_post_meta( $post->ID, '_trustscript_reviews_enabled', true );
		$mode    = get_post_meta( $post->ID, '_trustscript_reviews_mode', true ) ?: 'dropdown';
		$membership_id = get_post_meta( $post->ID, '_trustscript_reviews_membership_id', true );
		$shortcode = get_post_meta( $post->ID, '_trustscript_reviews_shortcode', true );
		$count   = get_post_meta( $post->ID, '_trustscript_reviews_count', true ) ?: '10';
		$rating  = get_post_meta( $post->ID, '_trustscript_reviews_rating', true ) ?: '0';

		wp_nonce_field( 'trustscript_reviews_nonce', 'trustscript_reviews_nonce_field' );
		$this->maybe_enqueue_assets_inline();

		?>
		<div class="trustscript-reviews-metabox">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="trustscript_reviews_enabled">
							<input type="checkbox" id="trustscript_reviews_enabled" name="trustscript_reviews_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
							<?php esc_html_e( 'Display Member Reviews Below This Page', 'trustscript' ); ?>
						</label>
					</th>
				</tr>
			</table>

			<div id="trustscript-reviews-options" class="<?php echo $enabled ? '' : 'trustscript-reviews-options-hidden'; ?>">
				<h3><?php esc_html_e( 'Review Display Options', 'trustscript' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Display Method', 'trustscript' ); ?></label>
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Display Method', 'trustscript' ); ?></legend>
								<label>
									<input type="radio" name="trustscript_reviews_mode" value="dropdown" <?php checked( $mode, 'dropdown' ); ?> />
									<?php esc_html_e( 'Select from Dropdown', 'trustscript' ); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="trustscript_reviews_mode" value="shortcode" <?php checked( $mode, 'shortcode' ); ?> />
									<?php esc_html_e( 'Paste Shortcode', 'trustscript' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr id="trustscript-membership-dropdown" class="<?php echo $mode === 'dropdown' ? '' : 'trustscript-hidden'; ?>">
						<th scope="row">
							<label for="trustscript_reviews_membership_id"><?php esc_html_e( 'Membership', 'trustscript' ); ?></label>
						</th>
						<td>
							<select id="trustscript_reviews_membership_id" name="trustscript_reviews_membership_id">
								<option value=""><?php esc_html_e( '-- Select a Membership --', 'trustscript' ); ?></option>
								<?php $this->render_membership_options( $membership_id ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose a membership to display its reviews.', 'trustscript' ); ?></p>
						</td>
					</tr>

					<tr id="trustscript-shortcode-field" class="<?php echo $mode === 'shortcode' ? '' : 'trustscript-hidden'; ?>">
						<th scope="row">
							<label for="trustscript_reviews_shortcode"><?php esc_html_e( 'Shortcode', 'trustscript' ); ?></label>
						</th>
						<td>
							<input type="text" id="trustscript_reviews_shortcode" name="trustscript_reviews_shortcode" value="<?php echo esc_attr( $shortcode ); ?>" placeholder="[trustscript_memberpress_reviews id=&quot;123&quot; count=&quot;10&quot;]" />
							<p class="description">
								<?php esc_html_e( 'Example: ', 'trustscript' ); ?>
								<code>[trustscript_memberpress_reviews id="123" count="10"]</code>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="trustscript_reviews_count"><?php esc_html_e( 'Reviews Per Page', 'trustscript' ); ?></label>
						</th>
						<td>
							<input type="number" id="trustscript_reviews_count" name="trustscript_reviews_count" value="<?php echo esc_attr( $count ); ?>" min="1" max="50" />
							<p class="description"><?php esc_html_e( 'Number of reviews to display per page. Default: 10', 'trustscript' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="trustscript_reviews_rating"><?php esc_html_e( 'Minimum Rating Filter', 'trustscript' ); ?></label>
						</th>
						<td>
							<select id="trustscript_reviews_rating" name="trustscript_reviews_rating">
								<option value="0" <?php selected( $rating, '0' ); ?>><?php esc_html_e( 'Show All Ratings', 'trustscript' ); ?></option>
								<option value="1" <?php selected( $rating, '1' ); ?>>★ 1+ Stars</option>
								<option value="2" <?php selected( $rating, '2' ); ?>>★★ 2+ Stars</option>
								<option value="3" <?php selected( $rating, '3' ); ?>>★★★ 3+ Stars</option>
								<option value="4" <?php selected( $rating, '4' ); ?>>★★★★ 4+ Stars</option>
								<option value="5" <?php selected( $rating, '5' ); ?>>★★★★★ 5 Stars Only</option>
							</select>
							<p class="description"><?php esc_html_e( 'Only show reviews with this minimum rating. Default: Show all', 'trustscript' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="trustscript-memberpress-reviews-tip">
					<strong><?php esc_html_e( '💡 Tip:', 'trustscript' ); ?></strong>
					<?php esc_html_e( 'These reviews will display below the page content, outside of MemberPress access restrictions. This allows public visibility for SEO and social proof.', 'trustscript' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_membership_options( $selected_id ) {
		if ( ! class_exists( 'MeprProduct' ) ) {
			return;
		}

		$products = get_posts( array(
			'post_type'   => 'memberpressproduct',
			'posts_per_page' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );

		foreach ( $products as $product ) {
			echo sprintf(
				'<option value="%d" %s>%s</option>',
				absint( $product->ID ),
				selected( $selected_id, $product->ID, false ),
				esc_html( $product->post_title )
			);
		}
	}

	public function save_meta_box( $post_id ) {
		// Nonces must NOT be sanitized as sanitization would corrupt the hash verification.
		// Security is enforced via wp_verify_nonce() which validates the nonce integrity.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST['trustscript_reviews_nonce_field'] ) ? wp_unslash( $_POST['trustscript_reviews_nonce_field'] ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'trustscript_reviews_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( isset( $_POST['trustscript_reviews_enabled'] ) ) {
			update_post_meta( $post_id, '_trustscript_reviews_enabled', 1 );
		} else {
			delete_post_meta( $post_id, '_trustscript_reviews_enabled' );
		}

		if ( isset( $_POST['trustscript_reviews_mode'] ) ) {
			$mode = sanitize_text_field( wp_unslash( $_POST['trustscript_reviews_mode'] ) );
			update_post_meta( $post_id, '_trustscript_reviews_mode', $mode );
		}

		// Initialize raw membership ID from POST. Immediately stored in variable to avoid repeated
		// unsanitized access. Value is validated with intval() before database storage.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$membership_id_raw = isset( $_POST['trustscript_reviews_membership_id'] ) ? wp_unslash( $_POST['trustscript_reviews_membership_id'] ) : '';
		if ( ! empty( $membership_id_raw ) ) {
			$membership_id = intval( $membership_id_raw );
			update_post_meta( $post_id, '_trustscript_reviews_membership_id', $membership_id );
		} else {
			delete_post_meta( $post_id, '_trustscript_reviews_membership_id' );
		}

		// Initialize raw shortcode from POST. Immediately stored in variable to avoid repeated
		// unsanitized access. Value is sanitized via sanitize_text_field() before database storage.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$shortcode_raw = isset( $_POST['trustscript_reviews_shortcode'] ) ? wp_unslash( $_POST['trustscript_reviews_shortcode'] ) : '';
		if ( ! empty( $shortcode_raw ) ) {
			$shortcode = sanitize_text_field( $shortcode_raw );
			update_post_meta( $post_id, '_trustscript_reviews_shortcode', $shortcode );
		} else {
			delete_post_meta( $post_id, '_trustscript_reviews_shortcode' );
		}

		if ( isset( $_POST['trustscript_reviews_count'] ) ) {
			$count = intval( $_POST['trustscript_reviews_count'] );
			$count = max( 1, min( 50, $count ) ); // Clamp between 1-50
			update_post_meta( $post_id, '_trustscript_reviews_count', $count );
		}

		if ( isset( $_POST['trustscript_reviews_rating'] ) ) {
			$rating = intval( $_POST['trustscript_reviews_rating'] );
			$rating = max( 0, min( 5, $rating ) ); // Clamp between 0-5
			update_post_meta( $post_id, '_trustscript_reviews_rating', $rating );
		}
	}

	/**
	 * Append reviews HTML to the end of the post content if enabled for this post and user has access.
	 *
	 * @param  string $content The existing post content HTML.
	 * @return string Content with reviews HTML appended.
	 */
	public function append_reviews_to_content( $content ) {
		if ( ! is_singular( array( 'page', 'post' ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$enabled = get_post_meta( $post_id, '_trustscript_reviews_enabled', true );

		if ( ! $enabled ) {
			return $content;
		}

		if ( ! $this->can_view_reviews() ) {
			return $content;
		}

		$mode      = get_post_meta( $post_id, '_trustscript_reviews_mode', true ) ?: 'dropdown';
		$shortcode = '';

		if ( $mode === 'dropdown' ) {
			$membership_id = get_post_meta( $post_id, '_trustscript_reviews_membership_id', true );
			if ( ! $membership_id ) {
				return $content;
			}

			$count  = get_post_meta( $post_id, '_trustscript_reviews_count', true ) ?: '10';
			$rating = get_post_meta( $post_id, '_trustscript_reviews_rating', true ) ?: '0';

			$shortcode = sprintf(
				'[trustscript_memberpress_reviews id="%d" count="%d" rating="%d"]',
				intval( $membership_id ),
				intval( $count ),
				intval( $rating )
			);
		} else {
			$shortcode = get_post_meta( $post_id, '_trustscript_reviews_shortcode', true );
		}

		if ( ! $shortcode ) {
			return $content;
		}

		$this->maybe_enqueue_assets_inline();

		$reviews_html  = '<div class="trustscript-reviews-content-wrapper" style="margin-top: 40px; padding-top: 40px; border-top: 1px solid #e5e5e5;">';
		$reviews_html .= do_shortcode( $shortcode );
		$reviews_html .= '</div>';

		return $content . $reviews_html;
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
new TrustScript_MemberPress_Reviews_MetaBox();