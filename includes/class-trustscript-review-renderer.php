<?php
/**
 * Handles rendering of review display components: summary cards, photo galleries,
 * filter chips, sort controls, and individual review cards.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Review_Renderer {
	/** 
	 * Flag to prevent nested rendering which can cause performance issues.
	 */
	private static $rendering_card = false;

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );
		add_action( 'plugins_loaded', function() {
			if ( class_exists( 'WooCommerce' ) ) {
				add_action( 'wp_head', array( __CLASS__, 'render_schema_markup' ), 20 );
			}
		});

		add_action( 'added_option',   array( __CLASS__, 'maybe_flush_stats_on_keywords_change' ), 10, 2 );
		add_action( 'updated_option', array( __CLASS__, 'maybe_flush_stats_on_keywords_change' ), 10, 3 );
	}

	private static $AVATAR_COLORS = array(
		'#C8922A', '#2E7D52', '#2563EB', '#7C3AED', '#B03030', '#0891B2',
	);

	/* Default keywords for review page*/
	private static $KEYWORD_CANDIDATES = array(
		'Quality', 'Packaging', 'Delivery', 'Design',
		'Value', 'Support', 'Colour', 'Color', 'Size', 'Fit',
	);

	const GALLERY_MEDIA_CAP = 15;
	const KEYWORD_SAMPLE_SIZE = 200;
	const STATS_CACHE_TTL = HOUR_IN_SECONDS;
	const ALLOWED_PHOTO_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );
	const ALLOWED_VIDEO_MIMES = array( 'video/mp4', 'video/webm', 'video/quicktime' );
	const MAX_PHOTO_SIZE = 5242880;
	const MAX_VIDEO_SIZE = 52428800;

	/**
	 * Register REST API routes for review data fetching and interactions.
	 */
	public static function init() {
		add_action( 'wp_insert_comment', array( __CLASS__, '_on_insert_comment' ), 999, 2 );
		add_action( 'edit_comment', array( __CLASS__, '_on_edit_comment' ) );
		add_action( 'wp_set_comment_status', array( __CLASS__, '_on_comment_status' ) );
		add_action( 'deleted_comment', array( __CLASS__, '_on_edit_comment' ) );
		add_action( 'added_comment_meta', array( __CLASS__, '_on_comment_meta_change' ), 10, 4 );
		add_action( 'updated_comment_meta', array( __CLASS__, '_on_comment_meta_change' ), 10, 4 );
		add_action( 'woocommerce_new_comment_added', array( __CLASS__, '_on_wc_comment_added' ), 10 );
	}

	/**
	 * Flushes stats cache when a new review is added.
	 *
	 * @since 1.0.0
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 */
	public static function _on_insert_comment( $comment_id, $comment ) {
		$product_id = (int) $comment->comment_post_ID;
		self::flush_stats_cache( $product_id );
		
		if ( class_exists( 'TrustScript_Shop_Display' ) ) {
			TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
		}
	}

	/**
	 * Flushes stats cache when a review is edited.
	 *
	 * @since 1.0.0
	 * @param int $comment_id Comment ID.
	 */
	public static function _on_edit_comment( $comment_id ) {
		$c = get_comment( $comment_id );
		if ( $c ) {
			$product_id = (int) $c->comment_post_ID;
			self::flush_stats_cache( $product_id );
			
			if ( class_exists( 'TrustScript_Shop_Display' ) ) {
				TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
			}
		}
	}

	/**
	 * Flushes stats cache when a review status changes.
	 *
	 * @since 1.0.0
	 * @param int $comment_id Comment ID.
	 */
	public static function _on_comment_status( $comment_id ) {
		$c = get_comment( $comment_id );
		if ( $c ) {
			$product_id = (int) $c->comment_post_ID;
			self::flush_stats_cache( $product_id );
			
			if ( class_exists( 'TrustScript_Shop_Display' ) ) {
				TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
			}
		}
	}

	/**
	 * Flushes stats cache when a relevant review meta key is added or updated.
	 *
	 * @since 1.0.0
	 * @param int    $meta_id    Meta ID.
	 * @param int    $comment_id Comment ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function _on_comment_meta_change( $meta_id, $comment_id, $meta_key, $meta_value ) {
		$watched_keys = array( '_trustscript', 'rating', '_wc_star_rating', 'verified' );
		$should_flush = false;
		
		foreach ( $watched_keys as $key_fragment ) {
			if ( strpos( $meta_key, $key_fragment ) !== false ) {
				$should_flush = true;
				break;
			}
		}
		
		if ( $should_flush ) {
			$c = get_comment( $comment_id );
			if ( $c ) {
				$product_id = (int) $c->comment_post_ID;
				self::flush_stats_cache( $product_id );
				
				if ( class_exists( 'TrustScript_Shop_Display' ) ) {
					TrustScript_Shop_Display::clear_product_rating_cache( $product_id );
				}
			}
		}
	}

	/**
	 * Flushes stats cache when a WooCommerce review is added.
	 *
	 * @since 1.0.0
	 * @param int $comment_id Comment ID.
	 */
	public static function _on_wc_comment_added( $comment_id ) {
		$c = get_comment( $comment_id );
		if ( $c ) {
			self::flush_stats_cache( (int) $c->comment_post_ID );
		}
	}

	/**
	 * Flush the stats transient cache for a product.
	 *
	 * @since 1.0.0
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function flush_stats_cache( $product_id ) {
		delete_transient( 'trustscript_stats_' . (int) $product_id );
	}

	/**
	 * Render JSON-LD schema markup for search engine optimization.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_schema_markup() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$cached_stats = get_transient( 'trustscript_stats_' . $product_id );
		$avg_rating   = $cached_stats ? (float) $cached_stats['avg_rating']   : 0;
		$rated_total  = $cached_stats ? (int)   $cached_stats['rated_total']  : 0;

		if ( ! $cached_stats ) {
			$rated_total = (int) get_comments( array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'parent'  => 0,
				'count'   => true,
			) );
		}

		if ( $rated_total < 1 || $avg_rating <= 0 ) {
			return;
		}

		$reviews = get_comments( array(
			'post_id' => $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'parent'  => 0,
			'orderby' => 'comment_date',
			'order'   => 'DESC',
			'number'  => 50,
		) );

		if ( ! empty( $reviews ) ) {
			update_meta_cache( 'comment', wp_list_pluck( $reviews, 'comment_ID' ) );
		}

		$review_schema = array();

		foreach ( $reviews as $r ) {
			$rating = (int) get_comment_meta( $r->comment_ID, '_trustscript_rating', true );
			if ( ! $rating ) {
				$rating = (int) get_comment_meta( $r->comment_ID, 'rating', true );
			}
			if ( $rating < 1 || $rating > 5 ) {
				continue;
			}

			$verified = (bool) get_comment_meta( $r->comment_ID, '_trustscript_verified_purchase', true )
			         || ! empty( get_comment_meta( $r->comment_ID, '_trustscript_verification_hash', true ) );

			$body = trim( wp_strip_all_tags( $r->comment_content ) );

			$entry = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => sanitize_text_field( $r->comment_author ),
				),
				'datePublished' => gmdate( 'Y-m-d', strtotime( $r->comment_date ) ),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
			);

			if ( ! empty( $body ) ) {
				$entry['reviewBody'] = $body;
			}

			if ( $verified ) {
				$entry['verifiedBuyer'] = true;
			}

			$review_schema[] = $entry;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => get_the_title( $product_id ),
			'url'      => get_permalink( $product_id ),
		);

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$img_src = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
			if ( $img_src ) {
				$schema['image'] = $img_src;
			}
		}

		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$schema['sku'] = $sku;
		}

		$description = $product->get_short_description();
		if ( empty( $description ) ) {
			$description = $product->get_description();
		}
		$description = trim( wp_strip_all_tags( $description ) );
		if ( ! empty( $description ) ) {
			$schema['description'] = wp_trim_words( $description, 60 );
		}

		$schema['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => number_format( $avg_rating, 1 ),
			'reviewCount' => (string) $rated_total,
			'bestRating'  => '5',
			'worstRating' => '1',
		);

		if ( ! empty( $review_schema ) ) {
			$schema['review'] = $review_schema;
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Get the configured keywords for a product, falling back to global settings then defaults.
	 *
	 * @since 1.0.0
	 * @param int $product_id WooCommerce product ID.
	 * @return string[] Array of keyword strings.
	 */
	public static function get_configured_keywords( $product_id ) {
		$product_keywords = get_post_meta( $product_id, '_trustscript_keywords', true );
		if ( ! empty( $product_keywords ) ) {
			if ( is_string( $product_keywords ) ) {
				return array_filter( array_map( 'trim', explode( ',', $product_keywords ) ) );
			}
			if ( is_array( $product_keywords ) ) {
				return array_filter( $product_keywords );
			}
		}

		$global_keywords = get_option( 'trustscript_review_keywords', false );
		if ( $global_keywords !== false ) {
			if ( is_string( $global_keywords ) ) {
				$keywords = array_filter( array_map( 'trim', explode( ',', $global_keywords ) ) );
				if ( ! empty( $keywords ) ) {
					return $keywords;
				}
			}
			if ( is_array( $global_keywords ) ) {
				$keywords = array_filter( $global_keywords );
				if ( ! empty( $keywords ) ) {
					return $keywords;
				}
			}
		}

		return self::$KEYWORD_CANDIDATES;
	}

	/**
	 * Get all available keyword candidates.
	 *
	 * @since 1.0.0
	 * @return string[] Array of keyword strings.
	 */
	public static function get_available_keywords() {
		return self::$KEYWORD_CANDIDATES;
	}

	/**
	 * Normalize a media URL to the current site domain.
	 *
	 * @since 1.0.0
	 * @param string $url Media URL to normalize.
	 * @return string Normalized URL, or empty string on failure.
	 */
	public static function normalize_media_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['path'] ) ) {
			return $url;
		}

		$path = $parsed['path'];

		if ( ! empty( $parsed['query'] ) ) {
			$path .= '?' . $parsed['query'];
		}
		if ( ! empty( $parsed['fragment'] ) ) {
			$path .= '#' . $parsed['fragment'];
		}

		return home_url( $path );
	}

	/**
	 * Render the full review section for a product.
	 *
	 * Outputs the summary card, photo gallery, filter chips, sort controls,
	 * and paginated review cards.
	 *
	 * @since 1.0.0
	 * @param int   $product_id WooCommerce product ID.
	 * @param array $options    Optional rendering options. {
	 *     @type bool   $show_stars          Show star ratings. Default true.
	 *     @type bool   $show_verification   Show verification badge. Default true.
	 *     @type bool   $show_product_name   Show product name on card. Default false.
	 *     @type bool   $show_verified_label Show verified label. Default true.
	 *     @type bool   $show_voting         Show helpful voting. Default true.
	 *     @type string $date_format         Date display format. Default 'full'.
	 *     @type int    $excerpt_length      Excerpt character limit. Default 0 (no limit).
	 *     @type array  $keywords            Override keyword chips. Default empty.
	 *     @type string $heading             Section heading text.
	 *     @type string $subheading          Section subheading text.
	 * }
	 * @return string HTML markup for the full review section.
	 */
	public static function render_review_section( $product_id, $options = array() ) {
		$defaults = array(
			'show_stars'          => true,
			'show_verification'   => true,
			'show_product_name'   => false,
			'show_verified_label' => true,
			'show_voting'         => true,
			'date_format'         => 'full',
			'excerpt_length'      => 0,
			'keywords'            => array(),
			'heading'             => __( 'Customer Reviews', 'trustscript' ),
			'subheading'          => __( 'Honest opinions from verified buyers', 'trustscript' ),
		);
		$options = wp_parse_args( $options, $defaults );
		$per_page  = (int) apply_filters( 'trustscript_per_page', 10 );
		$increment = (int) apply_filters( 'trustscript_increment', 5 );
		$initial_fetch = $per_page + $increment;

		$total = (int) get_comments(
			array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'parent'  => 0,  
				'count'   => true,
			)
		);

		$reviews = get_comments( array(
			'post_id'    => $product_id,
			'status'     => 'approve',
			'type'       => 'review',
			'parent'     => 0,  
			'orderby'    => 'comment_date',
			'order'      => 'DESC',
			'number'     => $initial_fetch,
			'offset'     => 0,
		) );

		if ( empty( $reviews ) ) {
			return '<p class="trustscript-no-reviews">' . esc_html__( 'No reviews yet. Be the first!', 'trustscript' ) . '</p>';
		}

		$comment_ids = wp_list_pluck( $reviews, 'comment_ID' );
		update_meta_cache( 'comment', $comment_ids );

		$stats_key    = 'trustscript_stats_' . (int) $product_id;
		$cached_stats = get_transient( $stats_key );

		if ( false === $cached_stats ) {
			$all_reviews_for_stats = get_comments( array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'parent'  => 0,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			) );

			$all_media_raw = array();
			$all_meta_raw  = array();
			$rating_sum    = 0;
			$rating_counts = array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 );
			$rated_total   = 0;

			$all_stats_ids = wp_list_pluck( $all_reviews_for_stats, 'comment_ID' );
			update_meta_cache( 'comment', $all_stats_ids );

			foreach ( $all_reviews_for_stats as $r ) {
				$rating = 0;
				if ( ! empty( $r->rating ) ) {
					$rating = (int) $r->rating;
				} else {
					$rating = (int) get_comment_meta( $r->comment_ID, '_trustscript_rating', true );
					if ( ! $rating ) {
						$rating = (int) get_comment_meta( $r->comment_ID, 'rating', true );
					}
				}

				if ( $rating >= 1 && $rating <= 5 ) {
					$rating_sum += $rating;
					$rating_counts[ $rating ]++;
					$rated_total++;
				}

				$media_json = get_comment_meta( $r->comment_ID, '_trustscript_media_urls', true );
				if ( ! empty( $media_json ) ) {
					$decoded = json_decode( $media_json, true );
					if ( is_array( $decoded ) ) {
						$verified = (bool) get_comment_meta( $r->comment_ID, '_trustscript_verified_purchase', true )
					         || ! empty( get_comment_meta( $r->comment_ID, '_trustscript_verification_hash', true ) );
						if ( class_exists( 'TrustScript_Review_Voting' ) ) {
							$vote_counts = TrustScript_Review_Voting::get_vote_counts_public( $r->comment_ID );
							$helpful     = (int) $vote_counts['upvotes'];
						} else {
							$helpful = (int) get_comment_meta( $r->comment_ID, '_trustscript_helpful_yes', true );
						}
						$keywords = self::get_card_keywords( $r->comment_content );

						foreach ( $decoded as $url ) {
							$path = wp_parse_url( $url, PHP_URL_PATH );
							if ( preg_match( '/\.(jpg|jpeg|png|webp|mp4)$/i', $path ) ) {
								$all_media_raw[] = self::normalize_media_url( $url );
								$all_meta_raw[]  = array(
									'author'   => $r->comment_author,
									'rating'   => ( $rating >= 1 && $rating <= 5 ) ? $rating : 0,
									'ts'       => strtotime( $r->comment_date ),
									'verified' => $verified,
									'helpful'  => $helpful,
									'keywords' => $keywords,
								);
							}
						}
					}
				}
			}

			$avg_rating = $rated_total > 0 ? round( $rating_sum / $rated_total, 1 ) : 0;

			$keyword_sample = array_slice( $all_reviews_for_stats, 0, self::KEYWORD_SAMPLE_SIZE );
			$configured_keywords = self::get_configured_keywords( $product_id );
			$keywords_found = ! empty( $options['keywords'] )
				? $options['keywords']
				: self::extract_keywords( $keyword_sample, $configured_keywords );

			$cached_stats = array(
				'avg_rating'     => $avg_rating,
				'rated_total'    => $rated_total,
				'rating_counts'  => $rating_counts,
				'all_media'      => $all_media_raw,  
				'all_media_meta' => $all_meta_raw,   
				'keywords'       => $keywords_found,
			);

			set_transient( $stats_key, $cached_stats, self::STATS_CACHE_TTL );
		}

		$avg_rating    = $cached_stats['avg_rating'];
		$rated_total   = $cached_stats['rated_total'];
		$rating_counts = $cached_stats['rating_counts'];
		$keywords      = $cached_stats['keywords'];
		$all_media         = array_slice( $cached_stats['all_media'], 0, self::GALLERY_MEDIA_CAP );
		$all_media_meta    = array_slice( $cached_stats['all_media_meta'] ?? array(), 0, self::GALLERY_MEDIA_CAP );
		$total_media_count = count( $cached_stats['all_media'] );

		ob_start();
		?>
		<div class="trustscript-reviews-wrap" id="reviews">

			<h2 class="trustscript-section-title"><?php echo esc_html( $options['heading'] ); ?></h2>
			<p class="trustscript-section-sub"><?php echo esc_html( $options['subheading'] ); ?></p>

			<div class="trustscript-top-grid<?php echo empty( $all_media ) ? ' trustscript-no-media' : ''; ?>">
			<?php echo self::render_summary_card( $avg_rating, $total, $rating_counts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( ! empty( $all_media ) ) : ?>
				<?php echo self::render_gallery_card( $all_media, $all_media_meta, $total_media_count, $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			</div>

			<?php if ( ! empty( $keywords ) ) : ?>
			<div class="trustscript-filter-section">
				<div class="trustscript-filter-label"><?php esc_html_e( 'Filter by keyword', 'trustscript' ); ?></div>
				<div class="trustscript-filter-chips">
					<button class="trustscript-chip trustscript-chip-active" data-keyword="all">
						<?php esc_html_e( 'All Reviews', 'trustscript' ); ?>
					</button>
					<?php foreach ( $keywords as $kw ) :
						$kw_slug = strtolower( $kw );
					?>
					<button class="trustscript-chip" data-keyword="<?php echo esc_attr( $kw_slug ); ?>">
						<?php echo esc_html( $kw ); ?>
					</button>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="trustscript-sort-bar">
				<span class="trustscript-sort-label"><?php esc_html_e( 'Sort by', 'trustscript' ); ?></span>
				<div class="trustscript-sort-buttons">
					<button class="trustscript-sort-btn trustscript-sort-active" data-sort="helpful">
						<?php esc_html_e( 'Most Helpful', 'trustscript' ); ?>
					</button>
					<button class="trustscript-sort-btn" data-sort="newest">
						<?php esc_html_e( 'Newest', 'trustscript' ); ?>
					</button>
					<button class="trustscript-sort-btn" data-sort="highest">
						<?php esc_html_e( 'Highest Rated', 'trustscript' ); ?>
					</button>
					<button class="trustscript-sort-btn" data-sort="lowest">
						<?php esc_html_e( 'Lowest Rated', 'trustscript' ); ?>
					</button>
				</div>
				<span class="trustscript-review-count-badge" id="trustscript-visible-count">
					<?php
					$initial_visible_count = min( $per_page, $total );
					if ( $initial_visible_count < $total ) {
						printf(
							/* translators: %1$d = reviews shown, %2$d = total reviews */
							esc_html__( 'Showing %1$d of %2$d reviews', 'trustscript' ),
							absint( $initial_visible_count ),
							absint( $total )
						);
					} elseif ( $total === 1 ) {
						esc_html_e( 'Showing 1 review', 'trustscript' );
					} else {
						printf(
							/* translators: %d = number of reviews */
							esc_html( _n( 'Showing %d review', 'Showing %d reviews', $total, 'trustscript' ) ),
							absint( $total )
						);
					}
					?>
				</span>
			</div>

			<?php
			$initial_visible = min( $per_page, $total );
			?>
			<div class="trustscript-reviews-list" id="trustscript-reviews-list"
			     data-per-page="<?php echo esc_attr( $initial_visible ); ?>"
			     data-increment="<?php echo esc_attr( $increment ); ?>"
			     data-total="<?php echo esc_attr( $total ); ?>"
			     data-product-id="<?php echo esc_attr( (int) $product_id ); ?>"
			     data-loaded="<?php echo esc_attr( min( $initial_fetch, $total ) ); ?>">
				<?php
				$i = 0;
				foreach ( $reviews as $review ) {
					$i++;
					$hidden_class = $i > $initial_visible ? ' trustscript-hidden' : '';
					echo '<div class="trustscript-review-card-wrapper' . esc_attr( $hidden_class ) . '">';
					echo self::render_card( $review, $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</div>';
				}
				?>
			</div>

			<?php if ( $total > $initial_visible ) : ?>
			<div class="trustscript-load-more-wrap" id="trustscript-load-more-wrap">
				<button class="trustscript-load-more-btn">
					<?php echo esc_html_x( 'Load more reviews', 'button label', 'trustscript' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<div class="trustscript-empty-state" id="trustscript-empty-state" style="display:none">
				<?php esc_html_e( 'No reviews match your filter.', 'trustscript' ); ?>
			</div>

			<?php echo self::render_lightbox_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo self::render_verify_modal_shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render an individual review card.
	 *
	 * @since 1.0.0
	 * @param WP_Comment $review  Comment object representing the review.
	 * @param array      $options Optional rendering options. {
	 *     @type bool   $show_stars          Show star rating. Default true.
	 *     @type bool   $show_verification   Show verified badge. Default true.
	 *     @type bool   $show_product_name   Show product name. Default false.
	 *     @type bool   $show_verified_label Show verified label. Default true.
	 *     @type bool   $show_voting         Show helpful voting. Default true.
	 *     @type string $date_format         Date display format. Default 'full'.
	 *     @type int    $excerpt_length      Word limit for review text. Default 0 (no limit).
	 * }
	 * @return string HTML markup for the review card.
	 */
	public static function render_card( $review, $options = array() ) {
		$defaults = array(
			'show_stars'          => true,
			'show_verification'   => true,
			'show_product_name'   => false,
			'show_verified_label' => true,
			'show_voting'         => true,
			'date_format'         => 'full',
			'excerpt_length'      => 0,
		);
		$options = wp_parse_args( $options, $defaults );

		if ( empty( $review->rating ) ) {
			$review->rating = (int) get_comment_meta( $review->comment_ID, '_trustscript_rating', true );
			if ( ! $review->rating ) {
				$review->rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			}
			if ( ! $review->rating ) {
				$review->rating = 5;
			}
		}
		$review->rating = min( 5, max( 1, (int) $review->rating ) );

		if ( empty( $review->review_title ) ) {
			$review->review_title = get_comment_meta( $review->comment_ID, '_trustscript_review_title', true );
		}

		if ( ! isset( $review->verified_purchase ) ) {
			$review->verified_purchase = get_comment_meta( $review->comment_ID, '_trustscript_verified_purchase', true );
		}

		$verification_hash = ! empty( $review->verification_hash )
			? $review->verification_hash
			: get_comment_meta( $review->comment_ID, '_trustscript_verification_hash', true );
		$has_verification  = $options['show_verification'] && ! empty( $verification_hash );

		if ( ! isset( $review->media_urls ) ) {
			$media_json         = get_comment_meta( $review->comment_ID, '_trustscript_media_urls', true );
			$review->media_urls = array();
			if ( ! empty( $media_json ) ) {
				$decoded = json_decode( $media_json, true );
				if ( is_array( $decoded ) ) {
					$review->media_urls = array_map( function ( $url ) {
						return self::normalize_media_url( $url );
					}, $decoded );
				}
			}
		}

		if ( ! isset( $review->helpful_yes ) ) {
			if ( class_exists( 'TrustScript_Review_Voting' ) ) {
				$counts              = TrustScript_Review_Voting::get_vote_counts_public( $review->comment_ID );
				$review->helpful_yes = $counts['upvotes'];
				$review->helpful_no  = $counts['downvotes'];
				$review->user_vote   = TrustScript_Review_Voting::get_user_vote_type( $review->comment_ID );
			} else {
				$review->helpful_yes = (int) get_comment_meta( $review->comment_ID, '_trustscript_helpful_yes', true );
				$review->helpful_no  = (int) get_comment_meta( $review->comment_ID, '_trustscript_helpful_no', true );
				$review->user_vote   = false;
			}
		}

		$ts          = strtotime( $review->comment_date );
		$initials    = self::get_initials( $review->comment_author );
		$color       = self::get_avatar_color( $review->comment_author );
		$kw_attr     = self::get_card_keywords( $review->comment_content );
		$is_verified = ! empty( $review->verified_purchase ) || $has_verification;

		if ( class_exists( 'TrustScript_Date_Formatter' ) ) {
			$date_str = TrustScript_Date_Formatter::format( $review->comment_date, $options['date_format'] );
		} else {
			$date_str = date_i18n( 'F j, Y', $ts );
		}

		$content  = $review->comment_content;
		$has_more = false;
		if ( absint( $options['excerpt_length'] ) > 0 ) {
			$words = explode( ' ', $content );
			if ( count( $words ) > absint( $options['excerpt_length'] ) ) {
				$content  = implode( ' ', array_slice( $words, 0, absint( $options['excerpt_length'] ) ) ) . '…';
				$has_more = true;
			}
		}

		$images = array_values( array_filter( $review->media_urls, function ( $u ) {
			return (bool) preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', wp_parse_url( $u, PHP_URL_PATH ) );
		} ) );
		$videos     = array_values( array_diff( $review->media_urls, $images ) );
		$all_media  = array_merge( $images, $videos );

		self::$rendering_card = true;

		ob_start();
		?>
		<div class="trustscript-review-card"
		     data-id="<?php echo esc_attr( $review->comment_ID ); ?>"
		     data-rating="<?php echo esc_attr( $review->rating ); ?>"
		     data-helpful="<?php echo esc_attr( $review->helpful_yes ); ?>"
		     data-ts="<?php echo esc_attr( $ts ); ?>"
		     data-keywords="<?php echo esc_attr( $kw_attr ); ?>">

			<div class="trustscript-card-header">

				<div class="trustscript-header-left">
					<div class="trustscript-avatar" style="background:<?php echo esc_attr( $color ); ?>">
						<?php echo esc_html( $initials ); ?>
					</div>
					<div>
						<div class="trustscript-reviewer-name"><?php echo esc_html( $review->comment_author ); ?></div>

						<?php if ( $is_verified ) : ?>
							<?php if ( $has_verification ) :
								$base_url   = function_exists( 'trustscript_get_base_url' ) ? trustscript_get_base_url() : home_url();
								$verify_url = trailingslashit( $base_url ) . 'verify-review';
							?>
							<button type="button"
							        class="trustscript-verified-badge trustscript-verify-link"
							        data-hash="<?php echo esc_attr( $verification_hash ); ?>"
							        data-verify-url="<?php echo esc_url( $verify_url ); ?>"
							        title="<?php esc_attr_e( 'Click to verify this review', 'trustscript' ); ?>">
								<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
									<path d="m9 12 2 2 4-4"/>
								</svg>
								<span><?php esc_html_e( 'Verified Purchase', 'trustscript' ); ?></span>
							</button>
							<?php else : ?>
							<span class="trustscript-verified-badge">
								<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
									<polyline points="22 4 12 14.01 9 11.01"/>
								</svg>
								<span><?php esc_html_e( 'Verified Purchase', 'trustscript' ); ?></span>
							</span>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="trustscript-card-meta">
					<?php if ( $options['show_stars'] ) : ?>
					<div class="trustscript-rating-stars"
					     aria-label="<?php 
					     	/* translators: %d = star rating number from 1 to 5 */
					     	echo esc_attr( sprintf( __( '%d out of 5 stars', 'trustscript' ), $review->rating ) ); 
					     ?>">
						<?php echo wp_kses_post( self::render_stars( $review->rating ) ); ?>
					</div>
					<?php endif; ?>
					<span class="trustscript-meta-dot" aria-hidden="true">·</span>
					<span class="trustscript-review-date"><?php echo esc_html( $date_str ); ?></span>
				</div>

			</div>

			<?php if ( ! empty( $review->review_title ) ) : ?>
				<div class="trustscript-review-title"><?php echo esc_html( $review->review_title ); ?></div>
			<?php endif; ?>

			<p class="trustscript-review-text"><?php echo esc_html( $content ); ?></p>

			<?php if ( $has_more ) : ?>
				<a href="<?php echo esc_url( get_comment_link( $review->comment_ID ) ); ?>"
				   class="trustscript-read-more">
					<?php esc_html_e( 'Read more', 'trustscript' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( ! empty( $all_media ) ) :
				$count   = count( $all_media );
				$multi   = $count > 1;
				$max_off = max( 0, $count - 3 );
			?>
			<div class="trustscript-photo-carousel"
				id="trustscript-carousel-<?php echo esc_attr( $review->comment_ID ); ?>"
				data-images="<?php echo esc_attr( wp_json_encode( $all_media ) ); ?>">

				<?php if ( $multi ) : ?>
				<button class="trustscript-carousel-arrow trustscript-carousel-prev"
						data-carousel-id="<?php echo esc_attr( $review->comment_ID ); ?>"
						data-dir="-1"
						disabled
						aria-label="<?php esc_attr_e( 'Previous photo', 'trustscript' ); ?>">&#8249;</button>
				<?php endif; ?>

				<div class="trustscript-carousel-viewport"
					id="trustscript-cvp-<?php echo esc_attr( $review->comment_ID ); ?>">
					<?php foreach ( $all_media as $i => $media_url ) : 
						$is_video = preg_match( '/\.(mp4|webm|mov|avi|mkv|flv|m4v|wmv|ogv)$/i', $media_url );
					?>
					<div class="trustscript-carousel-thumb<?php echo $i === 0 ? ' trustscript-thumb-active' : ''; ?>"
						data-lightbox-index="<?php echo (int) $i; ?>"
						style="position:relative;">
						<?php if ( $is_video ) : ?>
						<video src="<?php echo esc_url( $media_url ); ?>"
							width="88"
							height="88"
							loading="lazy"
							preload="metadata"
							style="width:100%;height:100%;object-fit:cover;background:#111;"></video>
						<div style="position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);border-radius:6px;">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M8 5v14l11-7z"/></svg>
						</div>
						<?php else : ?>
						<img src="<?php echo esc_url( $media_url ); ?>"
							width="88"
							height="88"
							loading="lazy"
							decoding="async"
							alt="<?php esc_attr_e( 'Review photo', 'trustscript' ); ?>">
						<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>

					<?php if ( $multi ) : ?>
					<button class="trustscript-carousel-arrow trustscript-carousel-next"
							data-carousel-id="<?php echo esc_attr( $review->comment_ID ); ?>"
							data-dir="1"
							<?php echo $count <= 3 ? 'disabled' : ''; ?>
							aria-label="<?php esc_attr_e( 'Next photo', 'trustscript' ); ?>">&#8250;</button>
					<?php endif; ?>

				</div>
				<?php endif; ?>



				<?php if ( $options['show_voting'] ) :
					$user_vote  = isset( $review->user_vote ) ? $review->user_vote : false;
					$is_logged  = is_user_logged_in();
					$voted_up   = ( $user_vote === 'upvote' )   ? ' trustscript-voted-up'   : '';
					$voted_down = ( $user_vote === 'downvote' ) ? ' trustscript-voted-down' : '';
					$disabled_class = ! $is_logged ? ' trustscript-btn-disabled' : '';
				?>
				<div class="trustscript-helpful-row">
					<span class="trustscript-helpful-label">
						<?php esc_html_e( 'Was this helpful?', 'trustscript' ); ?>
					</span>

					<button type="button"
							class="trustscript-helpful-btn trustscript-helpful-up<?php echo esc_attr( $voted_up . $disabled_class ); ?>"
							data-comment-id="<?php echo esc_attr( $review->comment_ID ); ?>"
							data-vote-type="upvote"
							aria-label="<?php esc_attr_e( 'Yes, helpful', 'trustscript' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>
						<span class="trustscript-vote-label"><?php esc_html_e( 'Yes', 'trustscript' ); ?></span>
						<span class="trustscript-vote-count" id="trustscript-up-<?php echo esc_attr( $review->comment_ID ); ?>"><?php echo $review->helpful_yes > 0 ? esc_html( $review->helpful_yes ) : ''; ?></span>
					</button>

					<button type="button"
							class="trustscript-helpful-btn trustscript-helpful-down<?php echo esc_attr( $voted_down . $disabled_class ); ?>"
							data-comment-id="<?php echo esc_attr( $review->comment_ID ); ?>"
							data-vote-type="downvote"
							aria-label="<?php esc_attr_e( 'No, not helpful', 'trustscript' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10z"/><path d="M17 2h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>
						<span class="trustscript-vote-label"><?php esc_html_e( 'No', 'trustscript' ); ?></span>
						<span class="trustscript-vote-count" id="trustscript-dn-<?php echo esc_attr( $review->comment_ID ); ?>"><?php echo $review->helpful_no > 0 ? esc_html( $review->helpful_no ) : ''; ?></span>
					</button>

					<span class="trustscript-vote-msg" id="trustscript-msg-<?php echo esc_attr( $review->comment_ID ); ?>" aria-live="polite"></span>
				</div>
				<?php endif; ?>

				<?php
				$replies = self::get_review_replies( $review->comment_ID );
				if ( ! empty( $replies ) ) {
					echo self::render_replies( $replies ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>

			</div>
			<?php

			$html = ob_get_clean();
			self::$rendering_card = false;
		return $html;
	}

	/**
	 * Render multiple review cards in a list or grid format.
	 *
	 * @since 1.0.0
	 * @param WP_Comment[] $reviews
	 * @param array        $options
	 * @return string
	 */
	public static function render_cards( $reviews, $options = array() ) {
		if ( empty( $reviews ) ) {
			return '';
		}
		$ids = wp_list_pluck( $reviews, 'comment_ID' );
		update_meta_cache( 'comment', $ids );

		return implode( '', array_map( function ( $r ) use ( $options ) {
			return self::render_card( $r, $options );
		}, $reviews ) );
	}

	/** @param int $rating 1-5. @return string */
	public static function render_stars( $rating ) {
		$rating = min( 5, max( 1, absint( $rating ) ) );
		$html   = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$class = $i <= $rating ? 'trustscript-star trustscript-star-filled' : 'trustscript-star trustscript-star-empty';
			$html .= '<span class="' . $class . '" aria-hidden="true">★</span>';
		}
		return $html;
	}

	/** @param int $rating @return string CSS class */
	public static function get_rating_class( $rating ) {
		return 'trustscript-rating-' . min( 5, max( 1, absint( $rating ) ) );
	}

	/**
	 * Rating summary card.
	 *
	 * @since 1.0.0
	 * @param float $avg   Average rating.
	 * @param int   $total Total review count.
	 * @param array $counts Per-star counts keyed 1-5.
	 * @return string
	 */
	private static function render_summary_card( $avg, $total, $counts ) {
		ob_start();
		?>
		<div class="trustscript-summary-card">
			<div class="trustscript-summary-score">
				<span class="trustscript-big-score"><?php echo esc_html( number_format( $avg, 1 ) ); ?></span>
				<div class="trustscript-score-meta">
					<div class="trustscript-stars-row">
						<?php echo wp_kses_post( self::render_stars( (int) round( $avg ) ) ); ?>
					</div>
					<div class="trustscript-score-count">
						<?php
						printf(
							/* translators: 1: total count */
							esc_html( _n( 'out of 5 · %d review', 'out of 5 · %d reviews', $total, 'trustscript' ) ),
							absint( $total )
						);
						?>
					</div>
				</div>
			</div>
			<div class="trustscript-bar-rows">
				<?php for ( $star = 5; $star >= 1; $star-- ) :
					$count = isset( $counts[ $star ] ) ? (int) $counts[ $star ] : 0;
					$pct   = $total > 0 ? round( ( $count / $total ) * 100 ) : 0;
				?>
				<div class="trustscript-bar-row" data-rating="<?php echo esc_attr( (string) $star ); ?>">
					<span class="trustscript-bar-label">
						<?php echo esc_html( (string) $star ); ?> <span class="trustscript-mini-star" aria-hidden="true">★</span>
					</span>
					<div class="trustscript-bar-track">
						<div class="trustscript-bar-fill" style="width:0" data-pct="<?php echo esc_attr( (string) $pct ); ?>"></div>
					</div>
					<span class="trustscript-bar-num"><?php echo esc_html( (string) $count ); ?></span>
				</div>
				<?php endfor; ?>
			</div>

			<?php
			$positive  = ( isset( $counts[5] ) ? $counts[5] : 0 ) + ( isset( $counts[4] ) ? $counts[4] : 0 );
			$neutral   = isset( $counts[3] ) ? $counts[3] : 0;
			$critical  = ( isset( $counts[2] ) ? $counts[2] : 0 ) + ( isset( $counts[1] ) ? $counts[1] : 0 );
			$pos_pct   = $total > 0 ? round( ( $positive / $total ) * 100 ) : 0;
			$neu_pct   = $total > 0 ? round( ( $neutral  / $total ) * 100 ) : 0;
			$crit_pct  = $total > 0 ? round( ( $critical / $total ) * 100 ) : 0;
			$sentiments = array(
				array( 'label' => __( 'Positive', 'trustscript' ), 'pct' => $pos_pct,  'mod' => 'positive' ),
				array( 'label' => __( 'Neutral',  'trustscript' ), 'pct' => $neu_pct,  'mod' => 'neutral'  ),
				array( 'label' => __( 'Critical', 'trustscript' ), 'pct' => $crit_pct, 'mod' => 'critical' ),
			);
			?>
			<div class="trustscript-sentiment-wrap">
				<div class="trustscript-sentiment-label"><?php esc_html_e( 'Sentiment', 'trustscript' ); ?></div>
				<?php foreach ( $sentiments as $s ) : ?>
				<div class="trustscript-sentiment-row">
					<span class="trustscript-sentiment-name"><?php echo esc_html( $s['label'] ); ?></span>
					<div class="trustscript-sentiment-track">
						<div class="trustscript-sentiment-fill trustscript-sentiment-<?php echo esc_attr( $s['mod'] ); ?>"
						     style="width:0"
						     data-pct="<?php echo esc_attr( $s['pct'] ); ?>"></div>
					</div>
					<span class="trustscript-sentiment-pct"><?php echo esc_html( $s['pct'] ); ?>%</span>
				</div>
				<?php endforeach; ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render aggregated photo gallery card with up to 5 image previews.
	 *
	 * @since 1.0.0
	 * @param string[] $display_media    Inline image URL array (GALLERY_MEDIA_CAP items max).
	 * @param array    $display_meta     Associated metadata for images.
	 * @param int      $total_media_count True total image count across ALL reviews (for header + lazy-load).
	 * @param int      $product_id       Product ID passed to JS so REST /gallery knows what to fetch.
	 * @return string
	 */
	private static function render_gallery_card( $display_media, $display_meta = array(), $total_media_count = 0, $product_id = 0 ) {
		$total_count   = $total_media_count > 0 ? $total_media_count : count( $display_media );
		$display_count = min( 5, count( $display_media ) );
		$has_more      = $total_count > 5;
		$media_json = wp_json_encode( array_values( $display_media ) );
		$meta_json  = wp_json_encode( array_values( $display_meta ) );

		ob_start();
		?>
		<div class="trustscript-gallery-card"
		     data-media="<?php echo esc_attr( $media_json ); ?>"
		     data-meta="<?php echo esc_attr( $meta_json ); ?>"
		     data-total="<?php echo esc_attr( (string) $total_count ); ?>"
		     data-product-id="<?php echo esc_attr( (string) absint( $product_id ) ); ?>">
			<div class="trustscript-gallery-header">
				<span class="trustscript-gallery-title">
					<?php esc_html_e( 'All Product Photos', 'trustscript' ); ?>
				</span>
				<span class="trustscript-gallery-count">
					<?php 
					printf(
						/* translators: %d is an integer count of photos */
						esc_html__( '%d images', 'trustscript' ), absint( $total_count ) ); ?>
				</span>
			</div>
			<div class="trustscript-gallery-grid">
				<?php foreach ( array_slice( $display_media, 0, $display_count ) as $i => $url ) : 
					$is_video = preg_match( '/\.(mp4|webm|mov|avi|mkv|flv|m4v|wmv|ogv)$/i', $url );
					$is_image = preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $url );
				?>
					<?php if ( $i === 4 && $has_more ) : ?>
					<div class="trustscript-gallery-overflow-thumb"
					     data-gallery-lightbox="true"
					     data-lightbox-index="4"
					     title="<?php echo esc_attr(
							/* translators: %d = total count of images in the gallery */
							sprintf( __( 'View all %d images', 'trustscript' ), $total_count ) ); ?>"
					     role="button"
					     tabindex="0"
					     aria-label="<?php echo esc_attr( 
							/* translators: %d = total count of images in the gallery */
							sprintf( __( 'View all %d images', 'trustscript' ), $total_count ) ); ?>">
						<?php if ( $is_video ) : ?>
							<video src="<?php echo esc_url( $url ); ?>"
							       loading="lazy"
							       preload="metadata"
							       style="width:100%;height:100%;object-fit:cover;background:#111;"></video>
							<div style="position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);border-radius:6px;">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M8 5v14l11-7z"/></svg>
							</div>
						<?php else : ?>
							<img src="<?php echo esc_url( $url ); ?>"
								 loading="lazy"
								 decoding="async"
								 alt="<?php esc_attr_e( 'Product photo', 'trustscript' ); ?>">
						<?php endif; ?>
						<div class="trustscript-gallery-overlay">
							<span class="trustscript-overlay-text"><?php esc_html_e( '+More', 'trustscript' ); ?></span>
						</div>
					</div>
					<?php else : ?>
					<div class="trustscript-gallery-thumb"
					     data-gallery-lightbox="true"
					     data-lightbox-index="<?php echo (int) $i; ?>">
						<?php if ( $is_video ) : ?>
							<video src="<?php echo esc_url( $url ); ?>"
							       loading="lazy"
							       preload="metadata"
							       style="width:100%;height:100%;object-fit:cover;background:#111;"></video>
							<div style="position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);border-radius:6px;">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M8 5v14l11-7z"/></svg>
							</div>
						<?php else : ?>
							<img src="<?php echo esc_url( $url ); ?>"
								 loading="lazy"
								 decoding="async"
								 alt="<?php esc_attr_e( 'Product photo', 'trustscript' ); ?>">
						<?php endif; ?>
					</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Lightbox gallery HTML shell.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function render_lightbox_shell() {
		ob_start();
		?>
		<div id="trustscript-lightbox" class="trustscript-lightbox-overlay"
			role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Photo viewer', 'trustscript' ); ?>">

			<div class="trustscript-lb-navbar">
				<div class="trustscript-lb-nav-group">
					<button class="trustscript-lb-nav-btn trustscript-lb-prev" id="trustscript-lb-prev"
							aria-label="<?php esc_attr_e( 'Previous photo', 'trustscript' ); ?>">
						&lt;
					</button>
					<span id="trustscript-lb-counter" class="trustscript-lb-counter"></span>
					<button class="trustscript-lb-nav-btn trustscript-lb-next" id="trustscript-lb-next"
							aria-label="<?php esc_attr_e( 'Next photo', 'trustscript' ); ?>">
						&gt;
					</button>
				</div>
				<button class="trustscript-lb-close" id="trustscript-lb-close"
						aria-label="<?php esc_attr_e( 'Close', 'trustscript' ); ?>">✕</button>
			</div>

			<div class="trustscript-lb-main">
				<div class="trustscript-lb-media-wrap">
				<img id="trustscript-lb-img" src="" alt="...">
				<!-- Shown over video before user clicks play -->
				<div id="trustscript-lb-video-play" class="trustscript-lb-video-play" style="display:none">
					<svg viewBox="0 0 24 24" fill="white" width="36" height="36">
						<path d="M8 5v14l11-7z"/>
					</svg>
				</div>
				</div>

				<div class="trustscript-lb-info" id="trustscript-lb-info" style="display:none">
					<div class="trustscript-lb-info-top">
						<div class="trustscript-lb-info-left">
							<span class="trustscript-lb-info-date" id="trustscript-lb-info-date"></span>
							<span class="trustscript-lb-info-author" id="trustscript-lb-info-author"></span>
							<span class="trustscript-lb-info-stars" id="trustscript-lb-info-stars"></span>
						</div>
						<span class="trustscript-lb-info-verified" id="trustscript-lb-info-verified" style="display:none">
							<?php esc_html_e( '✓ Verified Buyer', 'trustscript' ); ?>
						</span>
					</div>

					<div class="trustscript-lb-info-bottom">
						<span class="trustscript-lb-info-keywords" id="trustscript-lb-info-keywords"></span>
						<span class="trustscript-lb-info-helpful" id="trustscript-lb-info-helpful"></span>
					</div>
				</div>
			</div>

			<div id="trustscript-lb-strip" class="trustscript-lb-strip"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the verification modal HTML shell.
	 *
	 * @since 1.0.0
	 * @param string $title Optional modal title. Default: 'Review Verified by TrustScript'
	 * @return string HTML markup for the verification modal.
	 */
	public static function get_verification_modal_html( $title = '' ) {
		if ( empty( $title ) ) {
			$title = __( 'Review Verified by TrustScript', 'trustscript' );
		}

		$verify_url = trustscript_get_base_url() . '/verify-review';

		ob_start();
		?>
		<div class="trustscript-modal-overlay" id="trustscript-verify-modal">
			<div class="trustscript-modal">

				<button class="trustscript-modal-close" aria-label="<?php esc_attr_e( 'Close', 'trustscript' ); ?>">&times;</button>
				<div class="trustscript-modal-header">
					<svg class="trustscript-modal-shield" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
						<path d="m9 12 2 2 4-4"/>
					</svg>
					<h3 class="trustscript-modal-title"><?php echo esc_html( $title ); ?></h3>
				</div>

				<div class="trustscript-modal-reviewer" id="trustscript-modal-reviewer">
					<span class="trustscript-modal-reviewer-name" id="trustscript-modal-author"></span>
					<span class="trustscript-modal-reviewer-stars" id="trustscript-modal-stars" aria-label="<?php esc_attr_e( 'Star rating', 'trustscript' ); ?>"></span>
				</div>

				<div class="trustscript-modal-content">

					<p class="trustscript-modal-explanation">
						<?php esc_html_e( 'This code ties the review to its original submission on TrustScript. You can use it to confirm the review is genuine and unedited.', 'trustscript' ); ?>
					</p>

					<div class="trustscript-hash-container" id="trustscript-modal-hash"></div>

					<div class="trustscript-modal-actions">
						<button class="trustscript-copy-btn" id="trustscript-copy-hash">
							<?php esc_html_e( 'Copy Hash', 'trustscript' ); ?>
						</button>
					</div>

				</div>

				<div class="trustscript-modal-footer">
					<span class="trustscript-modal-powered"><?php esc_html_e( 'Powered by TrustScript', 'trustscript' ); ?></span>
					<a href="<?php echo esc_url( $verify_url ); ?>" id="trustscript-verify-link-btn" class="trustscript-verify-external-link" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Verify externally ↗', 'trustscript' ); ?>
					</a>
				</div>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_verify_modal_shell() {
		return self::get_verification_modal_html();
	}



	/**
	 * Fetch all approved replies to a review.
	 *
	 * @param int $parent_comment_id The review comment ID.
	 * @return WP_Comment[]
	 * @since 1.0.0
	 */
	private static function get_review_replies( $parent_comment_id ) {
		return get_comments( array(
			'parent'     => (int) $parent_comment_id,
			'status'     => 'approve',
			'orderby'    => 'comment_date',
			'order'      => 'ASC',
		) );
	}

	/**
	 * Render merchant/admin replies to a review.
	 *
	 * @since 1.0.0
	 * @param WP_Comment[] $replies
	 * @return string
	 */
	private static function render_replies( $replies ) {
		if ( empty( $replies ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="trustscript-review-replies">
			<?php foreach ( $replies as $reply ) : ?>
			<div class="trustscript-reply" data-id="<?php echo esc_attr( $reply->comment_ID ); ?>">
				<div class="trustscript-reply-header">
					<strong class="trustscript-reply-author"><?php echo esc_html( $reply->comment_author ); ?></strong>
					<?php if ( $reply->user_id ) : ?>
						<span class="trustscript-reply-admin-badge">
							<?php esc_html_e( 'Store Owner', 'trustscript' ); ?>
						</span>
					<?php endif; ?>
					<span class="trustscript-reply-date">
						<?php
						if ( class_exists( 'TrustScript_Date_Formatter' ) ) {
							echo esc_html( TrustScript_Date_Formatter::format( $reply->comment_date, 'full' ) );
						} else {
							echo esc_html( date_i18n( 'F j, Y', strtotime( $reply->comment_date ) ) );
						}
						?>
					</span>
				</div>
				<p class="trustscript-reply-text"><?php echo esc_html( $reply->comment_content ); ?></p>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Extract which configured keywords appear in a sample of review texts.
	 *
	 * @since 1.0.0
	 * @param WP_Comment[] $reviews            Sample of review comment objects.
	 * @param string[]     $keyword_candidates Keywords to check against.
	 * @return string[] Keywords found in the review sample.
	 */
	private static function extract_keywords( $reviews, $keyword_candidates = array() ) {
		if ( empty( $keyword_candidates ) ) {
			return array();
		}
		
		$combined = strtolower( implode( ' ', array_map( function ( $r ) {
			return $r->comment_content;
		}, $reviews ) ) );

		return array_values( array_filter(
			$keyword_candidates,
			function ( $kw ) use ( $combined ) {
				return strpos( $combined, strtolower( $kw ) ) !== false;
			}
		) );
	}

	/**
	 * Get keywords found in a single review body.
	 *
	 * @since 1.0.0
	 * @param string        $text       Review body text.
	 * @param string[]|null $candidates Keywords to check against. Defaults to all candidates.
	 * @return string Comma-separated lowercase keywords found.
	 */
	private static function get_card_keywords( $text, $candidates = null ) {
		if ( $candidates === null ) {
			$candidates = self::$KEYWORD_CANDIDATES;
		}
		$lower = strtolower( $text );
		$found = array_filter(
			$candidates,
			function ( $kw ) use ( $lower ) {
				return strpos( $lower, strtolower( $kw ) ) !== false;
			}
		);
		return implode( ',', array_map( 'strtolower', $found ) );
	}

	/**
	 * Generate two-character initials from a reviewer's name.
	 *
	 * @since 1.0.0
	 * @param string $name Reviewer display name.
	 * @return string Uppercase initials.
	 */
	private static function get_initials( $name ) {
		$words = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) >= 2 ) {
			return strtoupper( mb_substr( $words[0], 0, 1 ) . mb_substr( $words[1], 0, 1 ) );
		}
		return strtoupper( mb_substr( $name, 0, 2 ) );
	}

	/**
	 * Get a consistent avatar background colour derived from a name.
	 *
	 * @since 1.0.0
	 * @param string $name Reviewer display name.
	 * @return string Hex colour string.
	 */
	private static function get_avatar_color( $name ) {
		return self::$AVATAR_COLORS[ abs( crc32( $name ) ) % count( self::$AVATAR_COLORS ) ];
	}

	/**
	 * Register REST API routes for reviews and gallery endpoints.
	 *
	 * @since 1.0.0
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'trustscript/v1',
			'/reviews',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_fetch_reviews' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'validate_callback' => function( $v ) { return is_numeric( $v ); },
						'sanitize_callback' => 'absint',
					),
					'offset' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'count' => array(
						'default'           => 5,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'trustscript/v1',
			'/gallery',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_fetch_gallery' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'validate_callback' => function( $v ) { return is_numeric( $v ); },
						'sanitize_callback' => 'absint',
					),
					'offset' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'count' => array(
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Fetch a paginated batch of rendered review cards for a product.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object with product_id, offset, and count params.
	 * @return WP_REST_Response Response containing HTML markup, has_more flag, and total count.
	 */
	public static function rest_fetch_reviews( WP_REST_Request $request ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		try {
			$product_id = $request->get_param( 'product_id' );
			$offset     = max( 0, (int) $request->get_param( 'offset' ) );
			$count      = min( 20, max( 1, (int) $request->get_param( 'count' ) ) );

			$post = get_post( $product_id );
			if ( ! $post || $post->post_type !== 'product' || $post->post_status !== 'publish' ) {
				return new WP_REST_Response( array( 'html' => '', 'has_more' => false ), 200 );
			}

			$reviews = get_comments( array(
				'post_id'    => $product_id,
				'status'     => 'approve',
				'type'       => 'review',
				'parent'     => 0,  
				'orderby'    => 'comment_date',
				'order'      => 'DESC',
				'number'     => $count,
				'offset'     => $offset,
			) );

			if ( empty( $reviews ) ) {
				return new WP_REST_Response( array( 'html' => '', 'has_more' => false ), 200 );
			}

			$comment_ids = wp_list_pluck( $reviews, 'comment_ID' );
			update_meta_cache( 'comment', $comment_ids );

			$options = array(
				'show_stars'          => true,
				'show_verification'   => true,
				'show_verified_label' => true,
				'show_voting'         => (bool) get_option( 'trustscript_enable_voting', false ),
				'date_format'         => 'full',
				'excerpt_length'      => 0,
			);

			foreach ( $reviews as $r ) {
				if ( empty( $r->rating ) ) {
					$r->rating = (int) get_comment_meta( $r->comment_ID, '_trustscript_rating', true );
					if ( ! $r->rating ) {
						$r->rating = (int) get_comment_meta( $r->comment_ID, 'rating', true );
					}
					if ( ! $r->rating ) {
						$r->rating = 5;
					}
				}
				$r->rating            = min( 5, max( 1, (int) $r->rating ) );
				$r->review_title      = get_comment_meta( $r->comment_ID, '_trustscript_review_title', true );
				$r->verified_purchase = get_comment_meta( $r->comment_ID, '_trustscript_verified_purchase', true );
				$r->verification_hash = get_comment_meta( $r->comment_ID, '_trustscript_verification_hash', true );
				$r->media_urls        = array();
				$media_json           = get_comment_meta( $r->comment_ID, '_trustscript_media_urls', true );
				if ( ! empty( $media_json ) ) {
					$decoded = json_decode( $media_json, true );
					if ( is_array( $decoded ) ) {
						$r->media_urls = array_map( function ( $url ) {
							return self::normalize_media_url( $url );
						}, $decoded );
					}
				}
				if ( class_exists( 'TrustScript_Review_Voting' ) ) {
					$counts         = TrustScript_Review_Voting::get_vote_counts_public( $r->comment_ID );
					$r->helpful_yes = $counts['upvotes'];
					$r->helpful_no  = $counts['downvotes'];
					$r->user_vote   = TrustScript_Review_Voting::get_user_vote_type( $r->comment_ID );
				} else {
					$r->helpful_yes = (int) get_comment_meta( $r->comment_ID, '_trustscript_helpful_yes', true );
					$r->helpful_no  = (int) get_comment_meta( $r->comment_ID, '_trustscript_helpful_no', true );
					$r->user_vote   = false;
				}
			}

			$html = '';
			foreach ( $reviews as $review ) {
				$html .= '<div class="trustscript-review-card-wrapper trustscript-hidden">';
				$html .= self::render_card( $review, $options );
				$html .= '</div>';
			}

			$total_count = (int) get_comments( array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'parent'  => 0,
				'count'   => true,
			) );

			return new WP_REST_Response(
				array(
					'html'     => $html,
					'has_more' => ( $offset + count( $reviews ) ) < $total_count,
					'total'    => $total_count,
				),
				200
			);

		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array(
					'html'    => '',
					'has_more' => false,
					'error'   => 'Unable to load reviews',
				),
				200  
			);
		}
	}

	/**
	 * Fetch a paginated batch of media URLs from a product's reviews.
	 *
	 * Serves from the cached stats transient when available. Falls back to
	 * querying all approved reviews directly when the cache is cold.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object with product_id, offset, and count params.
	 * @return WP_REST_Response Response containing urls, meta, total, and has_more fields.
	 */
	public static function rest_fetch_gallery( WP_REST_Request $request ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		try {
			$product_id = $request->get_param( 'product_id' );
			$offset     = max( 0, (int) $request->get_param( 'offset' ) );
			$count      = min( 50, max( 1, (int) $request->get_param( 'count' ) ) );
			$stats_key    = 'trustscript_stats_' . (int) $product_id;
			$cached_stats = get_transient( $stats_key );

			if ( false !== $cached_stats && isset( $cached_stats['all_media'] ) ) {
				$all_media      = $cached_stats['all_media'];
				$all_media_meta = $cached_stats['all_media_meta'] ?? array();
			} else {
				$all_reviews = get_comments( array(
					'post_id' => $product_id,
					'status'  => 'approve',
					'type'    => 'review',
					'parent'  => 0,
					'orderby' => 'comment_date',
					'order'   => 'DESC',
				) );

				$comment_ids = wp_list_pluck( $all_reviews, 'comment_ID' );
				update_meta_cache( 'comment', $comment_ids );

				$all_media = array();
				foreach ( $all_reviews as $r ) {
					$media_json = get_comment_meta( $r->comment_ID, '_trustscript_media_urls', true );
					if ( ! empty( $media_json ) ) {
						$decoded = json_decode( $media_json, true );
						if ( is_array( $decoded ) ) {
							foreach ( $decoded as $url ) {
								$path = wp_parse_url( $url, PHP_URL_PATH );
								if ( preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $path ) ) {
									$all_media[] = self::normalize_media_url( $url );
								}
							}
						}
					}
				}
			}

			$total      = count( $all_media );
			$batch      = array_values( array_slice( $all_media,      $offset, $count ) );
			$batch_meta = array_values( array_slice( $all_media_meta ?? array(), $offset, $count ) );

			return new WP_REST_Response(
				array(
					'urls'     => $batch,
					'meta'     => $batch_meta,
					'total'    => $total,
					'has_more' => ( $offset + count( $batch ) ) < $total,
				),
				200
			);

		} catch ( \Throwable $e ) {
			return new WP_REST_Response(
				array( 'urls' => array(), 'total' => 0, 'has_more' => false ),
				200
			);
		}
	}

	/**
	 * Flush all product stats transients when the review keywords setting changes.
	 *
	 * Hooked to `update_option` / `add_option` for `trustscript_review_keywords`.
	 *
	 * @since 1.0.0
	 * @param string $option    Option name being updated.
	 * @param mixed  $old_value Previous option value (unused).
	 * @param mixed  $value     New option value (unused).
	 * @return void
	 */
	public static function maybe_flush_stats_on_keywords_change( $option, $old_value = null, $value = null ) {
		if ( $option !== 'trustscript_review_keywords' ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk deletion of transients, caching not applicable
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_trustscript_stats_%'
				OR option_name LIKE '_transient_timeout_trustscript_stats_%'"
		);
	}
}