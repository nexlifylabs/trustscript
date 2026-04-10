<?php
/**
 * TrustScript_WooCommerce_Reviews - Replaces the default WooCommerce reviews 
 * tab content with our custom review section, and enqueues necessary assets.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_WooCommerce_Reviews {

	public function __construct() {
		if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts',              array( $this, 'enqueue_assets' ) );
		add_filter( 'woocommerce_product_tabs',        array( $this, 'replace_review_tab_content' ), 98 );
	}

	public function enqueue_assets() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$base  = TRUSTSCRIPT_PLUGIN_URL;
		$ver   = TRUSTSCRIPT_VERSION;

		wp_enqueue_style(
			'trustscript-reviews',
			$base . 'assets/css/trustscript-reviews.css',
			array(),
			$ver
		);

		wp_enqueue_style(
			'trustscript-lightbox',
			$base . 'assets/css/trustscript-lightbox.css',
			array( 'trustscript-reviews' ),
			$ver
		);

		wp_enqueue_script(
			'trustscript-reviews',
			$base . 'assets/js/trustscript-reviews.js',
			array(),          
			$ver,
			true              
		);

		wp_localize_script(
			'trustscript-reviews',
			'trustscript',
			array(
				'rest_url'       => esc_url_raw( rest_url() ),
				'wp_rest_nonce'  => wp_create_nonce( 'wp_rest' ),
				'is_logged_in'   => is_user_logged_in(),
				'voting_enabled' => (bool) get_option( 'trustscript_enable_voting', false ),
				'strings'        => array(
					'vote_thanks'     => __( 'Thank you for your feedback!', 'trustscript' ),
					'vote_already'    => __( 'You have already voted on this review', 'trustscript' ),
					'vote_error'      => __( 'Something went wrong. Please try again.', 'trustscript' ),
					'loginToVote'     => __( 'Login to vote', 'trustscript' ),
					'showing_one'     => __( 'Showing 1 review', 'trustscript' ),
					/* translators: %d = number of reviews */
					'showing_n'       => __( 'Showing %d reviews', 'trustscript' ),
					/* translators: %1$d = reviews shown, %2$d = total reviews */
					'showing_x_of_y'  => __( 'Showing %1$d of %2$d reviews', 'trustscript' ),
				),
			)
		);
	}

	/**
	 * Replace the default WooCommerce reviews tab content with our custom review section. 
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function replace_review_tab_content( $tabs ) {
		if ( ! isset( $tabs['reviews'] ) ) {
			return $tabs;
		}

		$tabs['reviews']['callback'] = array( $this, 'render_reviews_tab' );

		return $tabs;
	}

	/**
	 * Render the reviews tab content using our custom review renderer. 
	 * This method is called as the callback for the reviews tab, and it 
	 * outputs the HTML for the review section, including the list of reviews, 
	 * voting buttons, and any other relevant information. It retrieves the 
	 * current product context and passes the product ID along with display 
	 * options to the TrustScript_Review_Renderer, which handles the actual 
	 * rendering of the review section. 
	 */
	public function render_reviews_tab() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce core global.
		}

		if ( ! $product ) {
			return;
		}

		echo TrustScript_Review_Renderer::render_review_section( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$product->get_id(),
			array(
				'show_product_name' => false,
				'date_format'       => 'full',
				'show_voting'       => (bool) get_option( 'trustscript_enable_voting', false ),
			)
		);
	}
}