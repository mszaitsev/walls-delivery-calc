<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
interface OzonDeliveryHttpClientInterface {
	/** @param array<string,mixed> $args */
	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse;
}
