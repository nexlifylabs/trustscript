<?php
/**
 * TrustScript Frontend Reviews Base Class
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class TrustScript_Frontend_Reviews_Base {

	/**
	 * Unique CSS handle for this module's stylesheet.
	 *
	 * Used to prevent double-enqueue across modules.
	 *
	 * @return string e.g. 'trustscript-memberpress-reviews'
	 */
	abstract protected function get_css_handle();

	/** 
	 * @return string e.g. 'assets/css/memberpress-reviews.css' 
	 */
	abstract protected function get_css_path();

	/** 
	 * @return string e.g. 'trustscript_memberpress_reviews' 
	 */
	abstract protected function get_shortcode_tag();

	/** 
	 * @return string e.g. 'Verified Purchase' 
	 */
	abstract protected function get_verified_label();

	/**
	 * @return string  e.g. 'Verified Member'
	 */
	abstract protected function get_verified_simple_label();

	/**
	 * @return string  e.g. 'Verified Purchase Hash'
	 */
	abstract protected function get_modal_title();

	/**
	 * Whether to render the verification hash modal HTML in wp_footer.
	 *
	 * Subclasses that do not use the modal can return false to skip the output.
	 *
	 * @return bool
	 */
	abstract protected function should_render_modal();

	/**
	 * Whether to enqueue the shared verification badge CSS.
	 *
	 * Subclasses that bundle the badge styles in their own stylesheet
	 * can return false to avoid double-enqueue.
	 *
	 * @return bool
	 */
	protected function should_enqueue_shared_css() {
		return true;
	}


	/**
	 * Enqueue this module's CSS and JS assets if not already loaded.
	 *
	 * @since 1.0.0
	 */
	protected function maybe_enqueue_assets_inline() {
		if ( wp_style_is( $this->get_css_handle(), 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			$this->get_css_handle(),
			TRUSTSCRIPT_PLUGIN_URL . $this->get_css_path(),
			array(),
			TRUSTSCRIPT_VERSION
		);

		if ( $this->should_enqueue_shared_css() ) {
			wp_enqueue_style(
				'trustscript-verification-badge',
				TRUSTSCRIPT_PLUGIN_URL . 'assets/css/trustscript-reviews.css',
				array(),
				TRUSTSCRIPT_VERSION
			);
		}

		wp_enqueue_script(
			'trustscript-verification-badge',
			TRUSTSCRIPT_PLUGIN_URL . 'assets/js/trustscript-reviews.js',
			array(),
			TRUSTSCRIPT_VERSION,
			true
		);
	}

 
	/**
	 * Render a single review as an HTML string, including the verification badge.
	 *
	 * @since 1.0.0
	 * @param WP_Comment $review Review comment object.
	 * @return string HTML markup for the review item.
	 */
	protected function render_single_review( $review ) {
		$rating            = get_comment_meta( $review->comment_ID, 'rating', true );
		$verified          = get_comment_meta( $review->comment_ID, 'verified', true );
		$verification_hash = get_comment_meta( $review->comment_ID, '_trustscript_verification_hash', true );
		$review_date       = TrustScript_Date_Formatter::format( $review->comment_date, 'full' );
		$verify_url        = trustscript_get_base_url() . '/verify-review';

		ob_start();
		?>

		<div class="trustscript-review-item">
			<div class="trustscript-review-header">
				<div class="trustscript-reviewer-info">
					<span class="trustscript-reviewer-name"><?php echo esc_html( $review->comment_author ); ?></span>

					<?php if ( $verified && ! empty( $verification_hash ) ) : ?>
						<span class="trustscript-verification-badge-inline">
							<button type="button"
								class="trustscript-verify-link"
								title="<?php esc_attr_e( 'View verification hash', 'trustscript' ); ?>"
								data-hash="<?php echo esc_attr( $verification_hash ); ?>"
								data-author="<?php echo esc_attr( $review->comment_author ); ?>"
								data-rating="<?php echo esc_attr( (int) $rating ); ?>"
								data-verify-url="<?php echo esc_url( $verify_url ); ?>">
								<svg class="trustscript-shield-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
									<path d="m9 12 2 2 4-4"/>
								</svg>
								<span class="trustscript-verify-text"><?php echo esc_html( $this->get_verified_label() ); ?></span>
							</button>
						</span>

					<?php elseif ( $verified ) : ?>
						<span class="trustscript-verification-badge-inline">
							<button type="button"
								class="trustscript-verify-link"
								title="<?php esc_attr_e( 'View verification details', 'trustscript' ); ?>"
								data-hash="<?php esc_attr_e( 'Internal Membership Verification', 'trustscript' ); ?>"
								data-author="<?php echo esc_attr( $review->comment_author ); ?>"
								data-rating="<?php echo esc_attr( (int) $rating ); ?>"
								data-verify-url="<?php echo esc_url( $verify_url ); ?>">
								<svg class="trustscript-shield-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
									<path d="m9 12 2 2 4-4"/>
								</svg>
								<span class="trustscript-verify-text"><?php echo esc_html( $this->get_verified_simple_label() ); ?></span>
							</button>
						</span>
					<?php endif; ?>
				</div>

				<span class="trustscript-review-rating trustscript-rating-font">
					<?php echo wp_kses_post( TrustScript_Review_Renderer::render_stars( $rating ) ); ?>
				</span>
			</div>

			<div class="trustscript-review-content">
				<?php echo wp_kses_post( $review->comment_content ); ?>
			</div>

			<div class="trustscript-review-meta">
				<span class="trustscript-review-date"><?php echo esc_html( $review_date ); ?></span>
			</div>
		</div>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render previous/next pagination links for a reviews listing.
	 *
	 * @since 1.0.0
	 * @param int $current_page Current page number (1-based).
	 * @param int $total_pages  Total number of pages.
	 * @return string HTML markup for the pagination wrapper.
	 */
	protected function render_pagination( $current_page, $total_pages ) {
		ob_start();
		?>

		<div class="trustscript-pagination-wrapper">
			<?php if ( $current_page > 1 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'review_page', $current_page - 1 ) ); ?>" class="trustscript-pagination-btn">
					&larr; <?php esc_html_e( 'Previous', 'trustscript' ); ?>
				</a>
			<?php endif; ?>

			<span class="trustscript-pagination-info">
				<?php
				/* translators: 1: Current page number, 2: Total pages */
				echo esc_html( sprintf( esc_html__( 'Page %1$d of %2$d', 'trustscript' ), intval( $current_page ), intval( $total_pages ) ) );
				?>
			</span>

			<?php if ( $current_page < $total_pages ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'review_page', $current_page + 1 ) ); ?>" class="trustscript-pagination-btn">
					<?php esc_html_e( 'Next', 'trustscript' ); ?> &rarr;
				</a>
			<?php endif; ?>
		</div>

		<?php
		return ob_get_clean();
	}


	/**
	 * Output the verification hash modal HTML to the page.
	 *
	 * Populated and triggered by JS when a user clicks a verification badge.
	 * Guarded by `should_render_modal()` and a static flag to ensure it is
	 * output at most once per page load.
	 *
	 * Uses the shared utility method from TrustScript_Review_Renderer to avoid code duplication.
	 *
	 * @since 1.0.0
	 */
	public function render_verification_modal() {
		if ( ! $this->should_render_modal() ) {
			return;
		}

		static $modal_rendered = false;
		if ( $modal_rendered ) {
			return;
		}
		$modal_rendered = true;

		$this->maybe_enqueue_assets_inline();

		if ( class_exists( 'TrustScript_Review_Renderer' ) ) {
			echo TrustScript_Review_Renderer::get_verification_modal_html( $this->get_modal_title() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}


	/**
	 * Build a `meta_query` array for filtering comments by minimum star rating.
	 *
	 * @since 1.0.0
	 * @param int $rating Minimum rating threshold (1–5).
	 * @return array Single-element meta_query array ready for use in `get_comments()`.
	 */
	protected function build_rating_meta_query( $rating ) {
		return array(
			array(
				'key'     => 'rating',
				'value'   => intval( $rating ),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		);
	}
}