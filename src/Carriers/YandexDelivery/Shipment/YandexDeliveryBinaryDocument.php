<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryBinaryDocument {
	/** @param array<string,mixed> $headers */
	public function __construct(
		public readonly string $body,
		public readonly string $content_type,
		public readonly int $http_code,
		public readonly array $headers = array()
	) {
	}
}
