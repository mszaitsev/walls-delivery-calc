<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

defined( 'ABSPATH' ) || exit;

interface PekHttpClientInterface {
	/** @param array<string,mixed> $args */
	public function post( string $url, array $args ): array;
}
