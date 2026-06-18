<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTerminalCodeTariffRequest {
	/**
	 * @param array<int,DpdTariffParcel> $parcels
	 */
	public function __construct(
		public readonly string $pickup_city_id,
		public readonly string $delivery_city_id,
		public readonly array $parcels,
		public readonly float $declared_value_rub,
		public readonly bool $self_pickup,
		public readonly bool $self_delivery,
		public readonly string $pickup_terminal_code,
		public readonly string $delivery_terminal_code = '',
		public readonly string $service_code = '',
		public readonly string $pickup_date = ''
	) {
	}
}
