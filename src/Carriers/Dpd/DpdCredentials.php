<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdCredentials {
	public function __construct(
		public readonly string $client_number,
		public readonly string $client_key,
		public readonly string $environment = ''
	) {
	}

	public function is_complete(): bool {
		return '' !== trim( $this->client_number ) && '' !== trim( $this->client_key );
	}
}

