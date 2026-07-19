<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutFeatureGate {
	public function __construct(
		private SettingsRepository $settings
	) {
	}

	public function enabled(): bool {
		return $this->settings->get_bool( 'enable_new_checkout_shipping', false );
	}

	public function debug_panel_enabled(): bool {
		return $this->enabled()
			&& $this->settings->get_bool( 'show_checkout_debug_panel', false );
	}
}
