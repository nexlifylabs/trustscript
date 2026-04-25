<?php 

// TrustScript Review Immutability Handler

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class TrustScript_Immutability {

	public function __construct() {
		add_filter( 'wp_update_comment_data', array( $this, 'prevent_comment_update' ), 10, 3 );
		add_filter( 'rest_pre_insert_comment', array( $this, 'prevent_rest_comment_update' ), 10, 2 );
		add_action( 'edit_form_top', array( $this, 'show_immutable_notice' ) );
	}

	public function prevent_comment_update( $data, $comment, $args ) {
		$comment_id = $comment['comment_ID'];
		$review_token = get_comment_meta( $comment_id, '_trustscript_review_token', true );
		$product_token = get_comment_meta( $comment_id, '_trustscript_product_token', true );
		
		if ( empty( $review_token ) && empty( $product_token ) ) {
			return $data;
		}
		
		$original_content = $comment['comment_content'];
		$new_content = isset( $data['comment_content'] ) ? $data['comment_content'] : $original_content;
		
		if ( $new_content !== $original_content ) {
			return new WP_Error(
				'trustscript_comment_locked',
				__( 'This review is locked by TrustScript and cannot be edited. TrustScript reviews are immutable to maintain authenticity and customer trust. You can delete this review if needed, but the text cannot be modified.', 'trustscript' ),
				array( 'status' => 403 )
			);

		}
		
		return $data;
	}


	public function prevent_rest_comment_update( $prepared_comment, $request ) {
		$comment_id = $request->get_param( 'id' );
		if ( ! $comment_id ) {
			return $prepared_comment;
		}
		
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return $prepared_comment;
		}
		
		$review_token = get_comment_meta( $comment_id, '_trustscript_review_token', true );
		$product_token = get_comment_meta( $comment_id, '_trustscript_product_token', true );
		
		if ( empty( $review_token ) && empty( $product_token ) ) {
			return $prepared_comment;
		}
		
		$original_content = $comment->comment_content;
		$new_content = isset( $prepared_comment['content'] ) ? $prepared_comment['content'] : $original_content;
		
		if ( $new_content !== $original_content ) {
			return new WP_Error(
				'trustscript_comment_locked',
				'This review is locked by TrustScript and cannot be edited via REST API. TrustScript reviews are immutable to maintain authenticity and customer trust.',
				array( 'status' => 403 )
			);
		}
		
		return $prepared_comment;
	}

	public function show_immutable_notice() {
		$current_screen = get_current_screen();
		if ( $current_screen->base !== 'comment' ) {
			return;
		}

		if ( ! isset( $_GET['c'] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'update-comment_' . intval( $_GET['c'] ) ) ) {
			return;
		}

		$comment_id = intval( $_GET['c'] );
		$review_token = get_comment_meta( $comment_id, '_trustscript_review_token', true );
		$product_token = get_comment_meta( $comment_id, '_trustscript_product_token', true );
		$publishing_mode = get_comment_meta( $comment_id, '_trustscript_publishing_mode', true );
		
		if ( ! empty( $review_token ) || ! empty( $product_token ) ) {
			$mode_label = $this->get_publishing_mode_label( $publishing_mode );
			
			?>
			<div style="background-color: #fff8e5; border-left: 4px solid #ffb81c; padding: 12px; margin-bottom: 20px; border-radius: 2px;">
				<p style="margin: 0; color: #333;">
					<strong>🔒 This review is locked by TrustScript</strong>
				</p>
				<p style="margin: 8px 0 0 0; color: #666; font-size: 13px;">
					TrustScript reviews are <strong>immutable</strong> to maintain authenticity and customer trust. 
					Once published, they cannot be edited by site owners. This review was published via 
					<strong><?php echo esc_html( $mode_label ); ?></strong>.
				</p>
				<p style="margin: 8px 0 0 0; color: #666; font-size: 13px;">
					<strong>If you need to remove this review:</strong> You can delete it using the Delete button below, 
					which will remove it completely and log the deletion. Editing is not permitted to preserve review integrity.
				</p>
			</div>
			<?php
		}
	}

	private function get_publishing_mode_label( $mode ) {
		$modes = array(
			'webhook'     => __('TrustScript App (Webhook)', 'trustscript'),
			'manual_sync'  => __('Manual Sync (WordPress Admin)', 'trustscript'),
			'auto_sync'    => __('Automatic Daily Sync', 'trustscript'),
		);

		return isset( $modes[ $mode ] ) ? $modes[ $mode ] : __('TrustScript', 'trustscript');
	}
}
