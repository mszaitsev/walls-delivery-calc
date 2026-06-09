<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek;

defined( 'ABSPATH' ) || exit;

final class CdekCredentials {
	public function __construct(
		public readonly string $account,
		public readonly string $secure_password
	) {
	}

	public function is_complete(): bool {
		return '' !== trim( $this->account ) && '' !== trim( $this->secure_password );
	}
}
