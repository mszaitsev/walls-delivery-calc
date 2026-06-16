<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

interface DpdSoapClientInterface {
	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $options
	 */
	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse;

	public function is_available(): bool;
}

