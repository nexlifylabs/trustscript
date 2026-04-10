<?php
/**
 * TrustScript_Shop_Display
 *
 * Displays star ratings and review counts on product listings (shop, category, homepage, etc.)
 * 
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Shop_Display {

	public function __construct() {
		if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shop_assets' ) );

		// Remove WooCommerce's default loop rating so it does not duplicate alongside
		// TrustScript's rating. This only affects product loops — the single-product
		// rating runs on woocommerce_single_product_summary and is untouched.
		// Note: priority must match WooCommerce's own registration (currently 5).
		// If WooCommerce ever changes that default, this remove_action will silently stop working.
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );

		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'display_product_rating' ), 5 );
	}

	/**
	 * Enqueue CSS for shop display
	 */
	public function enqueue_shop_assets() {
		wp_enqueue_style(
			'trustscript-shop-display',
			TRUSTSCRIPT_PLUGIN_URL . 'assets/css/trustscript-shop-display.css',
			array(),
			TRUSTSCRIPT_VERSION
		);
	}

	/**
	 * Display product rating and review count on shop/category pages.
	 */
	public function display_product_rating() {
		global $product;

		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();

		$rating_data = $this->get_product_rating_data( $product_id );

		if ( empty( $rating_data ) ) {
			return;
		}

		$avg_rating = $rating_data['average_rating'];
		$total_reviews = $rating_data['total_reviews'];

		if ( 0 === $total_reviews ) {
			return;
		}

		$this->render_rating_html( $avg_rating, $total_reviews );
	}

	/**
	 * Get rating data for a product
	 *
	 * @param int $product_id
	 * @return array Array with 'average_rating' and 'total_reviews'
	 */
	private function get_product_rating_data( $product_id ) {
		$product_id = (int) $product_id;
		$cache_key = 'ts_shop_rating_' . $product_id;
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$reviews = get_comments( array(
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'parent'  => 0,
		) );

		$total_reviews = count( $reviews );

		if ( empty( $reviews ) ) {
			$data = array(
				'average_rating' => 0,
				'total_reviews'  => 0,
			);
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
			return $data;
		}

		$rating_sum = 0;
		$rated_count = 0;

		foreach ( $reviews as $review ) {
			$rating = 0;
			if ( ! empty( $review->rating ) ) {
				$rating = (int) $review->rating;
			} else {
				$rating = (int) get_comment_meta( $review->comment_ID, '_trustscript_rating', true );
				if ( ! $rating ) {
					$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
				}
			}

			if ( $rating >= 1 && $rating <= 5 ) {
				$rating_sum += $rating;
				$rated_count++;
			}
		}

		$average_rating = $rated_count > 0 ? round( $rating_sum / $rated_count, 1 ) : 0;

		$data = array(
			'average_rating' => $average_rating,
			'total_reviews'  => $total_reviews,
		);

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Render the HTML for the rating display, including stars and review count.
	 *
	 * @param float $avg_rating
	 * @param int $total_reviews
	 */
	private function render_rating_html( $avg_rating, $total_reviews ) {
		?>
		<div class="trustscript-shop-rating">
			<span class="trustscript-shop-rating-value"><?php echo esc_html( number_format( $avg_rating, 1 ) ); ?></span>
			<?php $this->render_stars( $avg_rating ); ?>
			<span class="trustscript-shop-review-count">(<?php echo esc_html( $total_reviews ); ?>)</span>
		</div>
		<?php
	}

	/**
	 * Render star icons based on the average rating. Supports full and half stars.
	 *
	 * @param float $rating 1-5
	 */
	private function render_stars( $rating ) {
		$rating = min( 5, max( 0, (float) $rating ) );
		$full_stars = floor( $rating );
		$has_half = ( $rating - $full_stars ) >= 0.5;
		$empty_stars = 5 - $full_stars - ( $has_half ? 1 : 0 );
		?>
		<span class="trustscript-shop-stars-display" aria-label="<?php echo esc_attr( number_format( $rating, 1 ) . ' out of 5 stars' ); ?>">
			<?php
			// Full stars
			for ( $i = 0; $i < $full_stars; $i++ ) {
				echo '<span class="trustscript-shop-star trustscript-shop-star-full">★</span>';
			}

			// Half star
			if ( $has_half ) {
				echo '<span class="trustscript-shop-star trustscript-shop-star-half" aria-hidden="true"></span>';
			}

			// Empty stars
			for ( $i = 0; $i < $empty_stars; $i++ ) {
				echo '<span class="trustscript-shop-star trustscript-shop-star-empty">★</span>';
			}
			?>
		</span>
		<?php
	}

	/**
	 * Clear the cached rating data for a product when a review is added/updated/deleted, 
	 * so it will be recalculated on next display. 
	 *
	 * @param int $product_id
	 */
	public static function clear_product_rating_cache( $product_id ) {
		$product_id = (int) $product_id;
		$cache_key = 'ts_shop_rating_' . $product_id;
		delete_transient( $cache_key );
	}

}