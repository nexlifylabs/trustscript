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

	/**
	 * Register hooks when WooCommerce is active and not in the admin context.
	 */
	public function __construct() {
		if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts',              array( $this, 'enqueue_assets' ) );
		add_filter( 'woocommerce_product_tabs',        array( $this, 'replace_review_tab_content' ), 98 );
		add_filter( 'wc_get_template', array( $this, 'intercept_reviews_template' ), 10, 2 );
		add_filter( 'comments_template', array( $this, 'intercept_comments_template' ), 20 );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'enqueue_preview_assets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
	}

	/**
	 * Enqueue frontend CSS and JS assets for the review section.
	 *
	 * Skipped on the Elementor editor panel unless $force is true.
	 * Also skipped on non-product pages unless Elementor has built the page.
	 *
	 * @since 1.0.0
	 * @param bool $force Whether to bypass the editor-panel guard. Default false.
	 */
	public function enqueue_assets( $force = false ) {
		if ( ! $force && $this->is_elementor_editor_panel() ) {
			return;
		}

		if ( ! is_product() && ! $this->is_elementor_product_page() ) {
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
				'product_id'     => get_the_ID(),
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
				'imageUnavailable'  => __( 'Image unavailable', 'trustscript' ),
				'copyHash'          => __( 'Copy Hash', 'trustscript' ),
				'copied'            => __( '✓ Copied!', 'trustscript' ),
				),
			)
		);
	}

	/**
	 * Replace the default WooCommerce reviews tab callback with our own renderer.
	 *
	 * @since 1.0.0
	 * @param array $tabs Registered product tabs.
	 * @return array Tabs with the reviews callback replaced.
	 */
	public function replace_review_tab_content( $tabs ) {
		if ( ! isset( $tabs['reviews'] ) ) {
			return $tabs;
		}

		$tabs['reviews']['callback'] = array( $this, 'render_reviews_tab' );

		return $tabs;
	}

	/**
	 * Redirect WooCommerce template loader to our reviews template.
	 *
	 * Intercepts `wc_get_template()` for `single-product/reviews.php` so that
	 * theme or plugin calls that load it directly use our template instead.
	 *
	 * @since 1.0.0
	 * @param string $located       Full path to the located template file.
	 * @param string $template_name Relative template name, e.g. `single-product/reviews.php`.
	 * @return string Path to use for the template file.
	 */
	public function intercept_reviews_template( $located, $template_name ) {
		if ( 'single-product/reviews.php' === $template_name ) {
			// Construct plugin path defensively in case constant not defined yet.
			$plugin_path = defined( 'TRUSTSCRIPT_PLUGIN_PATH' ) ? TRUSTSCRIPT_PLUGIN_PATH : dirname( dirname( __FILE__ ) ) . '/';
			$located = $plugin_path . 'templates/single-product/reviews.php';
		}
		return $located;
	}

	/**
	 * Override the comments template on product pages to use our reviews template.
	 *
	 * Guards against themes that include the comments template directly, bypassing
	 * WooCommerce tabs and the `wc_get_template` filter entirely.
	 *
	 * @since 1.0.0
	 * @param string $template Full path to the comments template.
	 * @return string Path to our reviews template if it exists, or the original template.
	 */
	public function intercept_comments_template( $template ) {
		if ( ! is_product() ) {
			return $template;
		}

		// Ensure global $product is set for our template.
		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}

		$plugin_path = defined( 'TRUSTSCRIPT_PLUGIN_PATH' ) ? TRUSTSCRIPT_PLUGIN_PATH : dirname( dirname( __FILE__ ) ) . '/';
		$trustscript_template = $plugin_path . 'templates/single-product/reviews.php';

		if ( file_exists( $trustscript_template ) ) {
			return $trustscript_template;
		}

		return $template;
	}

	/**
	 * Output the review section HTML for the WooCommerce reviews tab.
	 *
	 * @since 1.0.0
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

	/**
	 * Enqueue assets inside the Elementor preview iframe for product pages.
	 *
	 * Hooked to both `elementor/preview/enqueue_styles` and
	 * `elementor/preview/enqueue_scripts` to cover CSS and JS.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_preview_assets() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		$this->enqueue_assets( true );
	}

	/**
	 * Check whether the current request is in the Elementor editor panel.
	 *
	 * Returns true only for the editor UI itself, not the preview iframe.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_elementor_editor_panel() {
		return defined( 'ELEMENTOR_VERSION' )
			&& \Elementor\Plugin::$instance !== null
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Check whether the current page is a product built with Elementor.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_elementor_product_page() {
		return (
			defined( 'ELEMENTOR_VERSION' ) &&
			is_singular( 'product' ) &&
			\Elementor\Plugin::$instance->db->is_built_with_elementor( get_the_ID() )
		);
	}
}