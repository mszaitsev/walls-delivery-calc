<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Api;

defined( 'ABSPATH' ) || exit;

interface CdekHttpClientInterface {
	/**
	 * @param array<string,mixed> $args
	 */
	public function request( string $method, string $url, array $args = array() ): CdekApiResponse;
}
