<?php
/**
 * Main plugin bootstrap.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

final class WDC_Plugin {
	private static ?WDC_Plugin $instance = null;

	private WDC_Logger $logger;

	private WDC_Settings $settings;

	public static function instance(): WDC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->logger = new WDC_Logger();
		$this->settings = new WDC_Settings();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init(): void {
		add_action( 'woocommerce_shipping_init', array( $this, 'load_shipping_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

		if ( is_admin() ) {
			$admin = new WDC_Admin( $this->logger, $this->settings );
			$admin->init();
		}
	}

	public function load_shipping_method(): void {
		if ( class_exists( 'WC_Shipping_Method' ) ) {
			require_once WDC_PLUGIN_DIR . 'includes/shipping-methods/class-wdc-shipping-method.php';
		}
	}

	/**
	 * @param array<string, string> $methods Registered WooCommerce shipping methods.
	 * @return array<string, string>
	 */
	public function register_shipping_method( array $methods ): array {
		$methods['wdc_dynamic_delivery'] = 'WDC_Shipping_Method';

		return $methods;
	}

	public function logger(): WDC_Logger {
		return $this->logger;
	}

	public function settings(): WDC_Settings {
		return $this->settings;
	}

	private function load_dependencies(): void {
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-logger.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-settings.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-carrier-registry.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-quote-normalizer.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-cache.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-weight-calculator.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-order-meta.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-admin.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-country-mapper.php';
		require_once WDC_PLUGIN_DIR . 'includes/class-wdc-location-mapper.php';
		require_once WDC_PLUGIN_DIR . 'includes/carriers/interface-wdc-carrier.php';
		require_once WDC_PLUGIN_DIR . 'includes/carriers/russian-post/class-wdc-russian-post-carrier.php';
		require_once WDC_PLUGIN_DIR . 'includes/carriers/russian-post/class-wdc-russian-post-api.php';
		require_once WDC_PLUGIN_DIR . 'includes/carriers/russian-post/class-wdc-russian-post-countries.php';
	}
}
