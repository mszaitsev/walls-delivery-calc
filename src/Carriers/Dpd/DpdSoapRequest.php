<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdSoapRequest {
	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		public readonly string $service,
		public readonly string $method,
		public readonly array $payload,
		public readonly DpdCredentials $credentials,
		public readonly array $options = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function payload_with_auth(): array {
		return array_merge(
			array(
				'auth' => array(
					'clientNumber' => $this->credentials->client_number,
					'clientKey' => $this->credentials->client_key,
				),
			),
			$this->payload
		);
	}
}

