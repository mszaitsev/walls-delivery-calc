<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTariffRequest {
	/**
	 * @param array<int,DpdTariffParcel> $parcels
	 */
	public function __construct(
		public readonly string $sender_city_id,
		public readonly string $receiver_city_id,
		public readonly array $parcels,
		public readonly float $declared_value_rub,
		public readonly bool $self_pickup = false,
		public readonly bool $self_delivery = false,
		public readonly string $service_code = '',
		public readonly string $pickup_date = ''
	) {
	}
}
