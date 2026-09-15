<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdSoapResponse {
	/**
	 * @param mixed $body
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly bool $success,
		public readonly mixed $body,
		public readonly array $meta = array()
	) {
	}
}

