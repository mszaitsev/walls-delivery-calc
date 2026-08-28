<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryApiResponse {
	/** @param array<string,string> $headers */
	public function __construct( public readonly int $status_code, public readonly string $body, public readonly array $headers = array() ) {}
}
