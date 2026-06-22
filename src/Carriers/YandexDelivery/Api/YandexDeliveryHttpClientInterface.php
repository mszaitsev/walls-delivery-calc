<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Api;

defined( 'ABSPATH' ) || exit;

interface YandexDeliveryHttpClientInterface {
	/** @param array<string,mixed> $args */
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse;
}

