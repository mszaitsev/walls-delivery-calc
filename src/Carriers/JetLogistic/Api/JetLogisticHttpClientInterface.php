<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

defined( 'ABSPATH' ) || exit;

interface JetLogisticHttpClientInterface {
	/** @param array<string,mixed> $payload @return array{status:int,body:string} */
	public function post_json( string $url, array $payload, int $timeout ): array;
}
