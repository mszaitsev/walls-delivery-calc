<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class ShippingMethodRegistrar {
	public function __construct(
		private FeatureFlags $feature_flags,
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public function register_shipping_method( array $methods ): array {
		if ( ! $this->enabled() || ! class_exists( '\WC_Shipping_Method' ) ) {
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
		if ( ! $this->enabled() || ! function_exists( 'wp_enqueue_style' ) ) {
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
	}

	private function enabled(): bool {
		return $this->feature_flags->new_shipping_method_enabled()
			|| $this->settings->get_bool( 'enable_new_checkout_shipping', false );
	}
}
