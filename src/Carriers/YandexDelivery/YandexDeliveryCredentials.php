<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryCredentials {
	public function __construct(
		public readonly string $bearer_token,
		public readonly string $platform_station_id,
		public readonly string $environment = ''
	) {
	}

	public function is_complete(): bool {
		return '' !== trim( $this->bearer_token ) && '' !== trim( $this->platform_station_id );
	}
}

