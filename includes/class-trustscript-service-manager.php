<?php
/**
 * TrustScript Service Manager
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Service_Manager {
	
	private static $instance = null;
	
	private $providers = array();
	
	private $active_providers = array();
	
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_abstract_class();
		$this->load_providers();
		$this->detect_active_providers();
	}
	
	private function load_abstract_class() {
		require_once plugin_dir_path( __FILE__ ) . 'abstracts/class-trustscript-service-provider.php';
	}
	
	private function load_providers() {
		$provider_files = array(
			'woocommerce' => 'providers/class-trustscript-woocommerce-provider.php',
			'memberpress' => 'providers/class-trustscript-memberpress-provider.php',
		);
		
		foreach ( $provider_files as $provider_id => $file_path ) {
			$full_path = plugin_dir_path( __FILE__ ) . $file_path;
			
			if ( file_exists( $full_path ) ) {
				require_once $full_path;
			}
		}
	}
	
	private function detect_active_providers() {
		$provider_classes = array(
			'TrustScript_WooCommerce_Provider',
			'TrustScript_MemberPress_Provider',

			'TrustScript_Events_Calendar_Provider',
			'TrustScript_Gravity_Forms_Provider',
			'TrustScript_WPForms_Provider',
		);
		
		foreach ( $provider_classes as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$provider = new $class_name();
				$provider_id = $provider->get_service_id();
				
				$this->providers[ $provider_id ] = $provider;
				
				if ( $provider->is_active() ) {
					$this->active_providers[ $provider_id ] = $provider;
				}
			}
		}
	}
	
	public function get_all_providers() {
		return $this->providers;
	}
	
	public function get_active_providers() {
		return $this->active_providers;
	}
	
	public function get_provider( $provider_id ) {
		return isset( $this->providers[ $provider_id ] ) ? $this->providers[ $provider_id ] : null;
	}
	
	public function has_active_services() {
		return ! empty( $this->active_providers );
	}
	
	public function get_all_statuses() {
		$all_statuses = array();
		
		foreach ( $this->active_providers as $provider_id => $provider ) {
			$all_statuses[ $provider_id ] = array(
				'name' => $provider->get_service_name(),
				'icon' => $provider->get_service_icon(),
				'statuses' => $provider->get_available_statuses(),
				'default' => $provider->get_default_status(),
			);
		}
		
		return $all_statuses;
	}
	
	public function get_trigger_status( $provider_id ) {
		$status = get_option( "trustscript_trigger_status_{$provider_id}", '' );
		return ! empty( $status ) ? $status : false;
	}
	
	public function get_primary_service() {
		if ( empty( $this->active_providers ) ) {
			return null;
		}
		
		if ( isset( $this->active_providers['woocommerce'] ) ) {
			return $this->active_providers['woocommerce'];
		}
		
		return reset( $this->active_providers );
	}
	
	public function get_service_stats() {
		$stats = array(
			'total_providers' => count( $this->providers ),
			'active_providers' => count( $this->active_providers ),
			'configured_providers' => 0,
		);
		
		foreach ( $this->active_providers as $provider_id => $provider ) {
			if ( $this->get_trigger_status( $provider_id ) ) {
				$stats['configured_providers']++;
			}
		}
		
		return $stats;
	}
	
	public function get_active_services_list() {
		if ( empty( $this->active_providers ) ) {
			return __( 'No services detected', 'trustscript' );
		}
		
		$names = array();
		foreach ( $this->active_providers as $provider ) {
			$names[] = $provider->get_service_name();
		}
		
		return implode( ', ', $names );
	}
}
