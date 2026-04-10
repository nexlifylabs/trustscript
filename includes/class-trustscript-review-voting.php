<?php
/**
 * TrustScript Review Voting Handler
 * Handles upvote/downvote functionality for reviews
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Review_Voting {

	const VERSION       = '1.0.0';
	const TABLE_VERSION = '1.0';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'create_votes_table' ) );
		}
	}

	/**
	 * Check if the custom votes table exists.
	 */
	public static function votes_table_exists() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'trustscript_votes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
	}

	/**
	 * Create the custom votes table if it doesn't exist or if the version is outdated.
	 */
	public function create_votes_table() {
		$current_version = get_option( 'trustscript_votes_table_version', '0' );
		if ( version_compare( $current_version, self::TABLE_VERSION, '>=' ) && self::votes_table_exists() ) {
			return;
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . 'trustscript_votes';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			user_id    bigint(20) unsigned NOT NULL,
			vote_type  varchar(10)         NOT NULL,
			voted_at   datetime            NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY   comment_user (comment_id, user_id),
			KEY          comment_id   (comment_id),
			KEY          user_id      (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'trustscript_votes_table_version', self::TABLE_VERSION );
	}

	public function register_routes() {
		register_rest_route(
			'trustscript/v1',
			'/vote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_vote' ),
				'permission_callback' => array( $this, 'check_vote_permission' ),
				'args'                => array(
					'comment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'vote_type'  => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'upvote', 'downvote' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'trustscript/v1',
			'/vote-count/(?P<comment_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_vote_count' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'comment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for vote submission endpoint. Checks nonce and user capabilities.
	 * Ensures only logged-in users with a valid nonce can submit votes.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_vote_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid or missing nonce.', 'trustscript' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to vote.', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle vote submission.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_vote( $request ) {
		$enabled = get_option( 'trustscript_enable_voting', false );
		if ( ! $enabled ) {
			return new WP_Error(
				'voting_disabled',
				__( 'Voting is currently disabled', 'trustscript' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'login_required',
				__( 'You must be logged in to vote', 'trustscript' ),
				array( 'status' => 401 )
			);
		}

		$comment_id = $request->get_param( 'comment_id' );
		$vote_type  = $request->get_param( 'vote_type' );

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return new WP_Error(
				'invalid_comment',
				__( 'Invalid request', 'trustscript' ),
				array( 'status' => 400 )
			);
		}

		$success = $this->cast_vote_atomic( $comment_id, $vote_type );
		if ( ! $success ) {
			return new WP_Error(
				'vote_failed',
				__( 'Could not process your vote. Please try again.', 'trustscript' ),
				array( 'status' => 500 )
			);
		}

		$counts = $this->get_vote_counts( $comment_id );

		return rest_ensure_response(
			array(
				'success'   => true,
				'vote_type' => $vote_type,
				'upvotes'   => $counts['upvotes'],
				'downvotes' => $counts['downvotes'],
				'message'   => __( 'Vote recorded successfully', 'trustscript' ),
			)
		);
	}

	/**
	 * Handle GET request to fetch current vote counts and user vote status for a comment. 
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_vote_count( $request ) {
		$comment_id = $request->get_param( 'comment_id' );

		if ( ! get_comment( $comment_id ) ) {
			return new WP_Error(
				'invalid_comment',
				__( 'Invalid request', 'trustscript' ),
				array( 'status' => 400 )
			);
		}

		$counts    = $this->get_vote_counts( $comment_id );
		$user_id   = get_current_user_id();
		$user_vote = $user_id ? $this->has_user_voted( $comment_id ) : false;

		return rest_ensure_response(
			array(
				'comment_id'     => $comment_id,
				'upvotes'        => $counts['upvotes'],
				'downvotes'      => $counts['downvotes'],
				'has_voted'      => $user_vote !== false,   // true boolean for JS
				'user_vote_type' => $user_vote ?: null,     // 'upvote'|'downvote'|null
			)
		);
	}

	/**
	 * Cast or update a vote atomically using an INSERT...ON DUPLICATE KEY UPDATE query. 
	 *
	 * @param int    $comment_id
	 * @param string $vote_type  'upvote'|'downvote'
	 * @return bool  True if vote was recorded or updated, false only on DB error.
	 */
	private function cast_vote_atomic( $comment_id, $vote_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT...UPDATE on custom table, no WP API equivalent
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}trustscript_votes
				 (comment_id, user_id, vote_type, voted_at)
				 VALUES (%d, %d, %s, UTC_TIMESTAMP())
				 ON DUPLICATE KEY UPDATE vote_type = VALUES(vote_type), voted_at = UTC_TIMESTAMP()",
				$comment_id,
				get_current_user_id(),
				$vote_type
			)
		);

		return $result !== false;
	}

	/**
	 * Check if the current user has already voted on a comment and return the vote type.
	 *
	 * @param int $comment_id
	 * @return string|false  Vote type string, or false if not voted / not logged in.
	 */
	private function has_user_voted( $comment_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, keyed lookup
		$vote_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT vote_type
				 FROM {$wpdb->prefix}trustscript_votes
				 WHERE comment_id = %d AND user_id = %d",
				$comment_id,
				$user_id
			)
		);

		return $vote_type ?: false;
	}

	/**
	 * Fetch the total upvote and downvote counts for a comment by aggregating the custom votes table.
	 *
	 * @param int $comment_id
	 * @return array{ upvotes: int, downvotes: int }
	 */
	private function get_vote_counts( $comment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table aggregate
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( vote_type = 'upvote' )   AS upvotes,
					SUM( vote_type = 'downvote' ) AS downvotes
				 FROM {$wpdb->prefix}trustscript_votes
				 WHERE comment_id = %d",
				$comment_id
			)
		);

		return array(
			'upvotes'   => (int) ( $row->upvotes   ?? 0 ),
			'downvotes' => (int) ( $row->downvotes ?? 0 ),
		);
	}

	/**
	 * Public static wrapper to fetch vote counts for a comment. Allows other 
	 * classes (like the renderer) to get current counts without instantiation.
	 *
	 * @param int $comment_id
	 * @return array{ upvotes: int, downvotes: int }
	 */
	public static function get_vote_counts_public( $comment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table aggregate
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( vote_type = 'upvote' )   AS upvotes,
					SUM( vote_type = 'downvote' ) AS downvotes
				 FROM {$wpdb->prefix}trustscript_votes
				 WHERE comment_id = %d",
				$comment_id
			)
		);

		return array(
			'upvotes'   => (int) ( $row->upvotes   ?? 0 ),
			'downvotes' => (int) ( $row->downvotes ?? 0 ),
		);
	}

	/**
	 * Public static wrapper to check if the current user has voted on a comment 
	 * and return the vote type. Allows the renderer to show the user's vote state 
	 * on page load.
	 *
	 * @param int $comment_id
	 * @return string|false  Vote type string ('upvote'|'downvote'), or false if not voted or not logged in.
	 */
	public static function get_user_vote_type( $comment_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$vote_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT vote_type
				 FROM {$wpdb->prefix}trustscript_votes
				 WHERE comment_id = %d AND user_id = %d",
				$comment_id,
				$user_id
			)
		);

		return $vote_type ?: false;
	}
}