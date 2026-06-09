<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Api;

defined( 'ABSPATH' ) || exit;

final class CdekApiResponse {
	/**
	 * @param array<string,string> $headers
	 */
	public function __construct(
		public readonly int $status_code,
		public readonly string $body,
		public readonly array $headers = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function json(): array {
		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
