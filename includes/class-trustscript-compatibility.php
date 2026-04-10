<?php
/**
 * TrustScript Compatibility Checker
 * @package TrustScript
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Compatibility {

	private $review_plugins = array(
		'yotpo-social-reviews-for-woocommerce/yotpo.php' => array(
			'name'        => 'Yotpo',
			'severity'    => 'high',
			'type'        => 'review_system',
			'description' => 'Yotpo uses its own review system separate from WooCommerce.',
			'solution'    => 'Choose one review system: either disable TrustScript auto-publishing or disable Yotpo.',
			'docs_url'    => '',
		),
		'judgeme/judgeme.php' => array(
			'name'        => 'Judge.me',
			'severity'    => 'high',
			'type'        => 'review_system',
			'description' => 'Judge.me manages reviews in its own database.',
			'solution'    => 'Disable TrustScript auto-publishing and use Judge.me API integration (coming soon).',
			'docs_url'    => '',
		),
		'loox-photo-reviews/loox.php' => array(
			'name'        => 'LOOX Photo Reviews',
			'severity'    => 'high',
			'type'        => 'review_system',
			'description' => 'LOOX uses a custom review system with photo capabilities.',
			'solution'    => 'Disable TrustScript auto-publishing to avoid duplicate reviews.',
			'docs_url'    => '',
		),
		'stamped-io/stamped-io.php' => array(
			'name'        => 'Stamped.io',
			'severity'    => 'high',
			'type'        => 'review_system',
			'description' => 'Stamped.io manages reviews externally.',
			'solution'    => 'Keep reviews in TrustScript only, or use API integration.',
			'docs_url'    => '',
		),
		'woocommerce-photo-reviews/woocommerce-photo-reviews.php' => array(
			'name'        => 'WooCommerce Photo Reviews',
			'severity'    => 'medium',
			'type'        => 'review_enhancement',
			'description' => 'This plugin enhances WooCommerce reviews with photos.',
			'solution'    => 'TrustScript reviews will work but won\'t have photo capabilities from this plugin.',
			'docs_url'    => '',
		),
		'customer-reviews-woocommerce/customer-reviews-woocommerce.php' => array(
			'name'        => 'Customer Reviews for WooCommerce',
			'severity'    => 'medium',
			'type'        => 'review_enhancement',
			'description' => 'May override WooCommerce review display.',
			'solution'    => 'Test review display on product pages. Contact support if issues occur.',
			'docs_url'    => '',
		),
	);

	private $detected_issues = array();

	public function __construct() {
		register_activation_hook( TRUSTSCRIPT_PLUGIN_FILE, array( $this, 'on_plugin_activation' ) );
		add_action( 'activated_plugin', array( $this, 'on_any_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_any_plugin_deactivated' ) );
		add_action( 'admin_init', array( $this, 'run_compatibility_check' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_compatibility_script' ) );
		add_action( 'admin_notices', array( $this, 'display_compatibility_notices' ) );
		add_action( 'wp_ajax_trustscript_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
	}

	public function on_plugin_activation() {
		delete_transient( 'trustscript_compatibility_check' );
	}

	public function on_any_plugin_activated() {
		delete_transient( 'trustscript_compatibility_check' );
	}

	public function on_any_plugin_deactivated() {
		delete_transient( 'trustscript_compatibility_check' );
	}

	public function enqueue_compatibility_script( $hook ) {
		if ( strpos( $hook, 'trustscript' ) === false ) {
			return;
		}
		
		wp_enqueue_script(
			'trustscript-compatibility',
			plugin_dir_url( __DIR__ ) . 'assets/js/compatibility.js',
			array( 'jquery' ),
			'1.0.0',
			true
		);
		wp_localize_script(
			'trustscript-compatibility',
			'TrustScriptCompatibility',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'trustscript_compatibility' ),
			)
		);
	}

	public function run_compatibility_check() {
		$cached = get_transient( 'trustscript_compatibility_check' );
		if ( false !== $cached ) {
			$this->detected_issues = $cached;
			return;
		}
		
		$this->check_review_plugins();
		$this->check_woocommerce_version();
		$this->check_wordpress_version();
		$this->check_php_version();
		$this->check_required_functions();
		
		set_transient( 'trustscript_compatibility_check', $this->detected_issues, YEAR_IN_SECONDS );
	}

	private function check_review_plugins() {
		foreach ( $this->review_plugins as $plugin_file => $plugin_info ) {
			if ( $this->is_plugin_active( $plugin_file ) ) {
				$this->detected_issues[] = array(
					'type'        => 'plugin_conflict',
					'severity'    => $plugin_info['severity'],
					'plugin'      => $plugin_info['name'],
					'description' => $plugin_info['description'],
					'solution'    => $plugin_info['solution'],
					'docs_url'    => $plugin_info['docs_url'],
				);
			}
		}
	}

	private function check_woocommerce_version() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->detected_issues[] = array(
				'type'        => 'missing_dependency',
				'severity'    => 'critical',
				'plugin'      => 'WooCommerce',
				'description' => 'TrustScript requires WooCommerce to function.',
				'solution'    => 'Install and activate WooCommerce plugin.',
				'docs_url'    => 'https://wordpress.org/plugins/woocommerce/',
			);
			return;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '10.0.0', '<' ) ) {
			$this->detected_issues[] = array(
				'type'        => 'outdated_dependency',
				'severity'    => 'high',
				'plugin'      => 'WooCommerce',
				'description' => 'Your WooCommerce version is outdated. TrustScript requires WooCommerce 10.0 or higher.',
				'solution'    => 'Update WooCommerce to the latest version.',
				'docs_url'    => '',
			);
		}
	}

	private function check_wordpress_version() {
		global $wp_version;

		if ( version_compare( $wp_version, '6.2', '<' ) ) {
			$this->detected_issues[] = array(
				'type'        => 'outdated_wordpress',
				'severity'    => 'medium',
				'plugin'      => 'WordPress',
				'description' => 'Your WordPress version is outdated. TrustScript works best with WordPress 6.2 or higher.',
				'solution'    => 'Update WordPress to the latest version.',
				'docs_url'    => '',
			);
		}
	}

	private function check_php_version() {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$this->detected_issues[] = array(
				'type'        => 'outdated_php',
				'severity'    => 'high',
				'plugin'      => 'PHP',
				'description' => 'Your PHP version (' . PHP_VERSION . ') is outdated and may cause issues.',
				'solution'    => 'Contact your hosting provider to upgrade to PHP 8.0 or higher.',
				'docs_url'    => '',
			);
		}
	}

	private function check_required_functions() {
		$required_functions = array(
			'wp_insert_comment' => 'WordPress Comments',
			'wp_remote_get'     => 'WordPress HTTP API',
			'hash_hmac'         => 'PHP HMAC',
			'curl_init'         => 'PHP cURL',
		);

		foreach ( $required_functions as $function => $description ) {
			if ( ! function_exists( $function ) ) {
				$this->detected_issues[] = array(
					'type'        => 'missing_function',
					'severity'    => 'critical',
					'plugin'      => $description,
					'description' => "Required function '{$function}' is not available.",
					'solution'    => 'Contact your hosting provider to enable this PHP function.',
					'docs_url'    => '',
				);
			}
		}
	}

	private function is_plugin_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $plugin_file );
	}

	public function display_compatibility_notices() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'trustscript' ) === false ) {
			return;
		}

		if ( empty( $this->detected_issues ) ) {
			return;
		}

		foreach ( $this->detected_issues as $issue ) {
			$this->display_notice( $issue );
		}
	}

	private function display_notice( $issue ) {
		$notice_class = $this->get_notice_class( $issue['severity'] );
		$notice_id    = 'trustscript-compat-' . sanitize_title( $issue['plugin'] );

		if ( get_user_meta( get_current_user_id(), 'dismissed_' . $notice_id, true ) ) {
			return;
		}

		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible" data-notice-id="<?php echo esc_attr( $notice_id ); ?>">
			<h3>
				<?php echo wp_kses_post( $this->get_severity_icon( $issue['severity'] ) ); ?>
				<?php
				/* translators: %s: Plugin/Component name */
				echo esc_html( sprintf( __( 'TrustScript Compatibility: %s', 'trustscript' ), $issue['plugin'] ) );
				?>
			</h3>
			<p><strong><?php echo esc_html( $issue['description'] ); ?></strong></p>
			<p>
				<strong><?php esc_html_e( 'Solution:', 'trustscript' ); ?></strong> 
				<?php echo esc_html( $issue['solution'] ); ?>
			</p>
			<?php if ( ! empty( $issue['docs_url'] ) ) : ?>
				<p>
					<a href="<?php echo esc_url( $issue['docs_url'] ); ?>" target="_blank" class="button button-primary">
						<?php esc_html_e( 'View Documentation', 'trustscript' ); ?>
					</a>
					<?php if ( 'plugin_conflict' === $issue['type'] ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=trustscript-settings&tab=compatibility' ) ); ?>" class="button">
							<?php esc_html_e( 'Configure Compatibility Settings', 'trustscript' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_notice_class( $severity ) {
		switch ( $severity ) {
			case 'critical':
				return 'notice-error';
			case 'high':
				return 'notice-error';
			case 'medium':
				return 'notice-warning';
			case 'info':
				return 'notice-info';
			default:
				return 'notice-warning';
		}
	}

	private function get_severity_icon( $severity ) {
		$icons = array(
			'critical' => array( 'emoji' => '🚨', 'label' => __( 'Critical', 'trustscript' ) ),
			'high'     => array( 'emoji' => '⚠️', 'label' => __( 'Warning', 'trustscript' ) ),
			'medium'   => array( 'emoji' => '⚡', 'label' => __( 'Caution', 'trustscript' ) ),
			'info'     => array( 'emoji' => 'ℹ️', 'label' => __( 'Information', 'trustscript' ) ),
		);
		
		$default = array( 'emoji' => '⚠️', 'label' => __( 'Warning', 'trustscript' ) );
		$icon = isset( $icons[ $severity ] ) ? $icons[ $severity ] : $default;
		
		return sprintf(
			'<span role="img" aria-label="%s">%s</span>',
			esc_attr( $icon['label'] ),
			esc_html( $icon['emoji'] )
		);
	}

	public function get_detected_issues() {
		return $this->detected_issues;
	}

	public function has_critical_issues() {
		foreach ( $this->detected_issues as $issue ) {
			if ( 'critical' === $issue['severity'] ) {
				return true;
			}
		}
		return false;
	}

	public function get_compatibility_report() {
		return array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'Not installed',
			'php_version'       => PHP_VERSION,
			'active_theme'      => wp_get_theme()->get( 'Name' ),
			'active_plugins'    => get_option( 'active_plugins' ),
			'detected_issues'   => $this->detected_issues,
			'timestamp'         => current_time( 'mysql' ),
		);
	}

	public function handle_dismiss_notice() {
		check_ajax_referer( 'trustscript_compatibility', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : '';

		if ( empty( $notice_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing notice ID' ), 400 );
		}

		update_user_meta( get_current_user_id(), 'dismissed_' . $notice_id, true );

		wp_send_json_success();
	}
}
