<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\ShopProcessingOrderQueueCounter;

defined( 'ABSPATH' ) || exit;

final class ShopProcessingDaysResolver {
	public function __construct(
		private SettingsRepository $settings,
		private ShopProcessingOrderQueueCounter $queue_counter
	) {
	}

	public function resolve(): int {
		if ( SettingsRepository::SHOP_PROCESSING_MODE_DYNAMIC !== $this->settings->shop_processing_mode() ) {
			return $this->settings->shop_processing_working_days();
		}

		$capacity = $this->settings->shop_processing_dynamic_orders_per_day();
		$statuses = $this->settings->shop_processing_dynamic_order_statuses();
		$extra_days = $this->settings->shop_processing_dynamic_extra_days();
		$active_orders = $this->queue_counter->count( $statuses );

		return $extra_days + (int) ceil( $active_orders / $capacity );
	}
}
