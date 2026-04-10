<?php
/**
 * TrustScript_Review_Query
 *
 * @package TrustScript
 * @since 1.0.0
 */

class TrustScript_Review_Query {

	/**
	 * Build WHERE clauses and prepare array for review queries based on filter arguments.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array Array with 'where' and 'prepare' keys.
	 */
	private static function build_where_clauses( $args ) {
		global $wpdb;

		$where   = array( "c.comment_approved = '1'", "c.comment_type = 'review'" );
		$prepare = array();

		$min_rating  = absint( $args['min_rating'] ?? 1 ) ?: 1;
		$source_type = sanitize_text_field( $args['source_type'] ?? 'all' );

		if ( $min_rating > 1 ) {
			$where[]    = "EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} cm
				WHERE cm.comment_id = c.comment_ID
				AND cm.meta_key = 'rating'
				AND CAST(cm.meta_value AS UNSIGNED) >= %d
			)";
			$prepare[] = $min_rating;
		}

		if ( 'specific' === $source_type && ! empty( $args['product_ids'] ) ) {
			$product_ids = is_array( $args['product_ids'] ) ? $args['product_ids'] : explode( ',', $args['product_ids'] );
			$product_ids = array_map( 'absint', $product_ids );
			$product_ids = array_filter( $product_ids );

			if ( ! empty( $product_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
				$where[]      = "c.comment_post_ID IN ({$placeholders})";
				$prepare      = array_merge( $prepare, $product_ids );
			}
		} elseif ( 'category' === $source_type && ! empty( $args['category_id'] ) ) {
			$product_ids = self::get_products_by_category( absint( $args['category_id'] ) );
			if ( ! empty( $product_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
				$where[]      = "c.comment_post_ID IN ({$placeholders})";
				$prepare      = array_merge( $prepare, $product_ids );
			}
		}

		return array(
			'where'   => $where,
			'prepare' => $prepare,
		);
	}

	/**
	 * Get reviews based on filter criteria.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $max_reviews  Maximum number of reviews to return. Default 10.
	 *     @type int    $min_rating   Minimum rating (1-5) to include. Default 1.
	 *     @type string $source_type   Source filter type: 'all', 'specific', or 'category'. Default 'all'.
	 *     @type array  $product_ids   Array of product IDs to filter by (if source_type is 'specific').
	 *     @type int    $category_id   Product category ID to filter by (if source_type is 'category').
	 *     @type string $sort_by       Sort order: 'recent', 'highest_rated', 'oldest', 'random'. Default 'recent'.
	 *     @type int    $page          Page number for pagination. Default 1.
	 *     @type int    $per_page      Number of reviews per page (overrides max_reviews if set). Default 0 (no pagination).
	 * }
	 *
	 * @return array Array of review objects with metadata.
	 */
	public static function get_reviews( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'max_reviews' => 10,
			'min_rating'  => 1,
			'source_type' => 'all',
			'product_ids' => array(),
			'category_id' => 0,
			'sort_by'     => 'recent',
			'page'        => 1,
			'per_page'    => 0,
		);

		$args = wp_parse_args( $args, $defaults );
		$max_reviews = absint( $args['max_reviews'] ) ?: 10;
		$min_rating  = absint( $args['min_rating'] ) ?: 1;
		$source_type = sanitize_text_field( $args['source_type'] );
		$sort_by     = sanitize_text_field( $args['sort_by'] );
		$page        = absint( $args['page'] ) ?: 1;
		$per_page    = absint( $args['per_page'] ) ?: 0;

		if ( $per_page > 0 ) {
			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;
		} else {
			$limit  = $max_reviews;
			$offset = 0;
		}

		$where_data = self::build_where_clauses( $args );
		$where      = $where_data['where'];
		$prepare    = $where_data['prepare'];

		$orderby = 'c.comment_date DESC';
		switch ( $sort_by ) {
			case 'highest_rated':
				$orderby = "(SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->commentmeta} WHERE comment_id = c.comment_ID AND meta_key = 'rating' LIMIT 1) DESC, c.comment_date DESC";
				break;
			case 'recent':
				$orderby = 'c.comment_date DESC';
				break;
			case 'oldest':
				$orderby = 'c.comment_date ASC';
				break;
			case 'random':
				$orderby = 'RAND()';
				break;
		}

		$where_clause = implode( ' AND ', $where );

		$query = "
			SELECT c.*, p.post_title as product_name,
			       cm_rating.meta_value as rating,
			       cm_verify.meta_value as trustscript_hash,
			       cm_vp.meta_value as verified_purchase
			FROM {$wpdb->comments} c
			LEFT JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
			LEFT JOIN {$wpdb->commentmeta} cm_rating ON c.comment_ID = cm_rating.comment_id AND cm_rating.meta_key = 'rating'
			LEFT JOIN {$wpdb->commentmeta} cm_verify ON c.comment_ID = cm_verify.comment_id AND cm_verify.meta_key = '_trustscript_verification_hash'
			LEFT JOIN {$wpdb->commentmeta} cm_vp ON c.comment_ID = cm_vp.comment_id AND cm_vp.meta_key = 'verified_purchase'
			WHERE {$where_clause}
			ORDER BY {$orderby}
			LIMIT %d OFFSET %d
		";

		$prepare[] = $limit;
		$prepare[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $query, $prepare ) );

		if ( ! empty( $results ) ) {
			foreach ( $results as $review ) {
				$review->rating            = (int) $review->rating;
				$review->trustscript_hash  = $review->trustscript_hash ?: '';
				$review->verified          = ! empty( $review->trustscript_hash );
				$review->verified_purchase = (bool) $review->verified_purchase;
			}
		}

		return $results ?: array();
	}

	/**
	 * Get total count of reviews matching filter criteria (for pagination).
	 *
	 * @param array $args Query arguments (same as get_reviews).
	 *
	 * @return int Total count of matching reviews.
	 */
	public static function get_total_count( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'min_rating'  => 1,
			'source_type' => 'all',
			'product_ids' => array(),
			'category_id' => 0,
		);

		$args = wp_parse_args( $args, $defaults );
		$where_data = self::build_where_clauses( $args );
		$where      = $where_data['where'];
		$prepare    = $where_data['prepare'];

		$where_clause = implode( ' AND ', $where );

		$query = "SELECT COUNT(c.comment_ID) FROM {$wpdb->comments} c WHERE {$where_clause}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $query, $prepare ) );
	}

	/**
	 * Get product IDs for a given category ID.
	 *
	 * @param int $category_id Product category ID.
	 *
	 * @return array Array of product IDs in the category.
	 */
	private static function get_products_by_category( $category_id ) {
		$products = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_id,
				),
			),
		) );

		return array_map( 'absint', $products );
	}

	/**
	 * Get average rating for all reviews filter criteria.
	 *
	 * @param array $args Query arguments (same as get_reviews).
	 *
	 * @return float Average rating (0 if no reviews).
	 */
	public static function get_average_rating( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'min_rating'  => 1,
			'source_type' => 'all',
			'product_ids' => array(),
			'category_id' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where_data = self::build_where_clauses( $args );
		$where      = $where_data['where'];
		$prepare    = $where_data['prepare'];

		$where_clause = implode( ' AND ', $where );

		$query = "SELECT AVG(CAST(cm.meta_value AS UNSIGNED)) 
				  FROM {$wpdb->comments} c
				  INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id AND cm.meta_key = 'rating'
				  WHERE {$where_clause}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$average = $wpdb->get_var( $wpdb->prepare( $query, $prepare ) );

		return (float) $average ?: 0;
	}
}