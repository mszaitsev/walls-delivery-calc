<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutFeatureGate {
	public function __construct(
		private FeatureFlags $feature_flags,
		private SettingsRepository $settings
	) {
	}

	public function enabled(): bool {
		return $this->feature_flags->new_shipping_method_enabled()
			|| $this->settings->get_bool( 'enable_new_checkout_shipping', false );
	}

	public function debug_panel_enabled(): bool {
		return $this->enabled()
			&& $this->settings->get_bool( 'show_checkout_debug_panel', false );
	}
}
