<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class ShippingMethodRegistrar {
	public function __construct(
		private CheckoutFeatureGate $feature_gate,
		private SettingsRepository $settings,
		private CheckoutOrchestrator $orchestrator,
		private WooCommercePackageMapper $package_mapper,
		private WooCommerceRateMapper $rate_mapper,
		private CheckoutSessionManager $session_manager,
		private RuleRepository $rule_repository,
		private PluginEnvironment $environment,
		private Logger $logger
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		if ( $this->feature_gate->enabled() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public function register_shipping_method( array $methods ): array {
		if ( ! $this->feature_gate->enabled() || ! class_exists( '\WC_Shipping_Method' ) ) {
			return $methods;
		}

		NewShippingMethod::configure(
			$this->orchestrator,
			$this->package_mapper,
			$this->rate_mapper,
			$this->session_manager,
			$this->rule_repository,
			$this->settings,
			$this->environment,
			$this->logger
		);

		$methods[ NewShippingMethod::METHOD_ID ] = NewShippingMethod::class;

		return $methods;
	}

	public function enqueue_assets(): void {
		if ( ! $this->feature_gate->enabled() || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style(
			'wdc-platform-checkout-rates',
			$this->environment->plugin_url() . 'assets/frontend/checkout-rates.css',
			array(),
			$this->environment->version()
		);
		wp_enqueue_style(
			'wdc-platform-pickup-foundation',
			$this->environment->plugin_url() . 'assets/frontend/pickup-foundation.css',
			array( 'wdc-platform-checkout-rates' ),
			$this->environment->version()
		);
		wp_enqueue_style(
			'wdc-platform-address-normalization',
			$this->environment->plugin_url() . 'assets/frontend/address-normalization.css',
			array( 'wdc-platform-checkout-rates' ),
			$this->environment->version()
		);
		wp_enqueue_style(
			'wdc-platform-city-selector',
			$this->environment->plugin_url() . 'assets/frontend/checkout-city-selector.css',
			array( 'wdc-platform-checkout-rates' ),
			$this->environment->version()
		);
		if ( function_exists( 'wp_enqueue_script' ) ) {
			$city_selector_dependencies = array( 'jquery' );
			if ( function_exists( 'wp_script_is' ) && wp_script_is( 'wc-checkout', 'registered' ) ) {
				$city_selector_dependencies[] = 'wc-checkout';
			}

			wp_enqueue_script(
				'wdc-platform-city-selector',
				$this->environment->plugin_url() . 'assets/frontend/checkout-city-selector.js',
				$city_selector_dependencies,
				$this->environment->version(),
				true
			);
			if ( function_exists( 'wp_localize_script' ) ) {
				wp_localize_script(
					'wdc-platform-city-selector',
					'wdcPlatformCitySelector',
					$this->city_selector_config()
				);
			}
			wp_enqueue_script(
				'wdc-platform-checkout-sort',
				$this->environment->plugin_url() . 'assets/frontend/checkout-sort.js',
				array( 'jquery' ),
				$this->environment->version(),
				true
			);
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function city_selector_config(): array {
		return array(
			'ajax_url'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'     => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( CheckoutLocationAjax::NONCE_ACTION ) : '',
			'min_chars' => 3,
			'location_search_limit' => max( 10, min( 300, $this->settings->get_int( 'location_search_limit', 100 ) ) ),
			'debug'     => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && $this->settings->get_bool( 'show_checkout_debug_panel', false ),
			'strings'   => array(
				'start'     => __( 'Начните вводить населенный пункт', 'walls-delivery-calc' ),
				'not_found' => __( 'Населенный пункт не найден. Будет использовано введенное значение.', 'walls-delivery-calc' ),
				'error'     => __( 'Ошибка поиска населенного пункта.', 'walls-delivery-calc' ),
				'searching' => __( 'Идет поиск...', 'walls-delivery-calc' ),
			),
		);
	}

}
