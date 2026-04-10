<?php
/**
 * TrustScript Elementor Integration
 * 
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Elementor {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_editor_panel_script() {
		wp_register_script(
			'trustscript-marquee-editor-panel',
			plugin_dir_url( TRUSTSCRIPT_PLUGIN_FILE ) . 'assets/js/marquee-editor-panel.js',
			array( 'jquery', 'elementor-editor' ),  // Depends on Elementor editor
			TRUSTSCRIPT_VERSION,
			true
		);
		wp_enqueue_script( 'trustscript-marquee-editor-panel' );
	}

	public function __construct() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_elementor_widget_categories' ) );
		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'enqueue_widget_styles' ) );
		add_action( 'wp_enqueue_scripts',                      array( $this, 'register_marquee_assets' ) );
		add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'register_marquee_assets' ) );
		add_action( 'elementor/preview/enqueue_scripts',       array( $this, 'register_marquee_assets' ) );
		add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'register_editor_panel_script' ) );
	}

	/**
	 * Check if Elementor is active
	 */
	public static function is_elementor_active() {
		return did_action( 'elementor/loaded' );
	}

	/**
	 * Add TrustScript widget category
	 */
	public function add_elementor_widget_categories( $elements_manager ) {
		$elements_manager->add_category(
			'trustscript',
			array(
				'title' => __( 'TrustScript', 'trustscript' ),
				'icon' => 'fa fa-shield-alt',
			)
		);
	}

	public function register_widgets( $widgets_manager ) {
		require_once plugin_dir_path( __FILE__ ) . 'widgets/reviews-showcase.php';

		$widgets_manager->register( new \TrustScript_Reviews_Showcase_Widget() );
	}

	public function enqueue_widget_styles() {
		$should_enqueue = \Elementor\Plugin::$instance->preview->is_preview_mode()
					   || \Elementor\Plugin::$instance->editor->is_edit_mode();

		if ( ! $should_enqueue ) {
			global $post;
			$should_enqueue = is_object( $post )
						   && \Elementor\Plugin::$instance->documents->get( $post->ID )->is_built_with_elementor();
		}

		if ( $should_enqueue ) {
			wp_enqueue_style(
				'trustscript-elementor-widgets',
				plugin_dir_url( TRUSTSCRIPT_PLUGIN_FILE ) . 'assets/css/elementor-widgets.css',
				array(),
				TRUSTSCRIPT_VERSION
			);
		}
	}

	public function register_marquee_assets() {
		wp_register_style(
			'trustscript-marquee-slider',
			plugin_dir_url( TRUSTSCRIPT_PLUGIN_FILE ) . 'assets/css/marquee-slider.css',
			array(),
			TRUSTSCRIPT_VERSION
		);
		
		wp_register_script(
			'trustscript-marquee-slider',
			plugin_dir_url( TRUSTSCRIPT_PLUGIN_FILE ) . 'assets/js/marquee-slider.js',
			array( 'jquery', 'elementor-frontend' ),  
			TRUSTSCRIPT_VERSION,
			true
		);
	}
}

// Initialize Elementor integration if Elementor is active
function trustscript_init_elementor() {
	if ( TrustScript_Elementor::is_elementor_active() ) {
		TrustScript_Elementor::get_instance();
	}
}
add_action( 'plugins_loaded', 'trustscript_init_elementor' );