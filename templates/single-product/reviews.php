<?php
/**
 * Template for displaying product reviews using the TrustScript review system.
 * This template is used to replace the default WooCommerce reviews tab content 
 * with our custom review section, and it is also designed to be compatible with 
 * themes that may directly include the reviews template.
 *
 * @package TrustScript
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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