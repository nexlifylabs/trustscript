<?php
/**
 * Handles incoming consent confirmation link requests.
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Consent_Confirmation_Link {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_confirmation_link' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	public static function enqueue_styles() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action check.
		if ( ! isset( $_GET['trustscript_action'] ) || 'confirm_consent' !== $_GET['trustscript_action'] ) {
			return;
		}

		wp_enqueue_style(
			'trustscript-consent-confirmation',
			TRUSTSCRIPT_PLUGIN_URL . 'assets/css/trustscript-consent-confirmation.css',
			array(),
			TRUSTSCRIPT_VERSION
		);
	}

	public static function handle_confirmation_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( ! isset( $_GET['trustscript_action'] ) || 'confirm_consent' !== $_GET['trustscript_action'] ) {
			return; // Not our action
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email confirmation link; uses database token instead of nonce.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email confirmation link; verified by database token.
		$order_id = isset( $_GET['trustscript_order'] ) ? absint( wp_unslash( $_GET['trustscript_order'] ) ) : 0;

		if ( empty( $token ) || empty( $order_id ) ) {
			self::display_error( __( 'Invalid confirmation link. Missing required parameters.', 'trustscript' ) );
			exit;
		}

		// Process the confirmation
		$result = TrustScript_Consent_Manager::process_confirmation_link( $token, $order_id );

		if ( is_wp_error( $result ) ) {
			self::display_error( $result->get_error_message() );
			exit;
		}

		// Success!
		self::display_success();
		exit;
	}

	/**
	 * Display a success message after confirmation.
	 */
	private static function display_success() {
		get_header();
		?>
		<div class="trustscript-confirmation-wrap">
			<div class="trustscript-confirmation-container">
				<div class="icon">✅</div>
				<h1><?php esc_html_e( 'Preference Confirmed', 'trustscript' ); ?></h1>
				<p>
					<?php
					esc_html_e( 'Thank you! Your review request preference has been confirmed. You will receive a review request after your order is delivered.', 'trustscript' );
					?>
				</p>
				<a href="<?php echo esc_url( home_url() ); ?>" class="back-link">
					<?php esc_html_e( 'Return to Store', 'trustscript' ); ?>
				</a>
			</div>
		</div>
		<?php
		get_footer();
	}

	/**
	 * Display an error message.
	 *
	 * @param string $error_message The error message to display.
	 */
	private static function display_error( $error_message ) {
		get_header();
		?>
		<div class="trustscript-confirmation-wrap">
			<div class="trustscript-confirmation-container is-error">
				<div class="icon">⚠️</div>
				<h1><?php esc_html_e( 'Confirmation Failed', 'trustscript' ); ?></h1>
				<p>
					<?php esc_html_e( 'We encountered an issue with your confirmation request:', 'trustscript' ); ?>
				</p>
				<div class="error-message">
					<?php echo esc_html( $error_message ); ?>
				</div>
				<p>
					<?php esc_html_e( 'No review request will be sent for this order. If you believe this is an error, please contact our support team.', 'trustscript' ); ?>
				</p>
				<a href="<?php echo esc_url( home_url() ); ?>" class="back-link">
					<?php esc_html_e( 'Return to Store', 'trustscript' ); ?>
				</a>
			</div>
		</div>
		<?php
		get_footer();
	}
}

// Initialize on plugins_loaded
add_action( 'plugins_loaded', array( 'TrustScript_Consent_Confirmation_Link', 'init' ) );

