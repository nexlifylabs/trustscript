<?php
/**
 * TrustScript Pricing Configuration
 * @package TrustScript
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get TrustScript pricing tiers
 * 
 * @return array Array of pricing tiers
 */
function trustscript_get_pricing_tiers() {
	return array(
		'free' => array(
			'name' => 'Free',
			'price' => '$0',
			'period' => 'forever',
			'requests' => 25,
			'features' => array(
				'25 review requests per month',
				'AI review enhancement',
				'Email automation',
				'Single Project/Domain',
				'Email support',
			),
			'limits' => array(
				'review_requests' => 25,
				'white_label' => false,
				'api_access' => true,
			),
		),
		'starter' => array(
			'name' => 'Starter',
			'price' => '$4.99',
			'period' => 'per month',
			'requests' => 250,
			'features' => array(
				'250 review requests per month',
				'AI review enhancement',
				'Single Project/Domain',
				'Email automation',
				'Priority support',
			),
			'limits' => array(
				'review_requests' => 250,
				'white_label' => false,
				'api_access' => true,
			),
			'button_text' => 'Upgrade to Starter',
			'button_url' => TRUSTSCRIPT_PRICING_URL,
		),
		'pro' => array(
			'name' => 'Pro',
			'price' => '$9.99',
			'period' => 'per month',
			'requests' => 500,
			'features' => array(
				'500 review requests per month',
				'AI review enhancement',
				'Three Projects/Domains',
				'Full Analytics Dashboard',
				'Custom branding',
				'Custom domain support',
				'Email automation',
				'Priority support',
				'Annual Plan: Unlimited reviews after 500 AI quota',
			),
			'limits' => array(
				'review_requests' => 500,
				'white_label' => true,
				'api_access' => true,
			),
			'button_text' => 'Upgrade to Pro',
			'button_url' => TRUSTSCRIPT_PRICING_URL,
		),
		'business' => array(
			'name' => 'Business',
			'price' => '$19.99',
			'period' => 'per month',
			'requests' => 2000,
			'badge' => 'Best Value',
			'features' => array(
				'2,000 review requests per month',
				'AI review enhancement',
				'Ten Projects/Domains',
				'Full Analytics Dashboard',
				'Custom branding',
				'Custom domain support',
				'Email automation',
				'Priority support',
				'Unlimited reviews after 2,000 AI quota exhausted',
			),
			'limits' => array(
				'review_requests' => 2000,
				'white_label' => true,
				'api_access' => true,
			),
			'button_text' => 'Upgrade to Business',
			'button_url' => TRUSTSCRIPT_PRICING_URL,
		),
	);
}

/**
 * Get API keys dashboard URL
 * 
 * @return string
 */
function trustscript_get_api_keys_url() {
	return trustscript_get_app_url() . '/dashboard/api-keys';
}

/**
 * Get pricing page URL
 * 
 * @return string
 */
function trustscript_get_pricing_url() {
	return trustscript_get_app_url() . '/pricing';
}

/**
 * Get login/register URL with redirect
 */
function trustscript_get_login_url( $redirect = '' ) {
	$url = trustscript_get_app_url() . '/login';
	if ( ! empty( $redirect ) ) {
		$url .= '?redirect=' . urlencode( $redirect );
	}
	return $url;
}
