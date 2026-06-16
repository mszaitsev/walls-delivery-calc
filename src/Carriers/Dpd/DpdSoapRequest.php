<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdSoapRequest {
	public const WRAPPER_DIRECT = 'direct';
	public const WRAPPER_REQUEST = 'request';

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
		$payload = array_merge(
			array(
				'auth' => array(
					'clientNumber' => $this->credentials->client_number,
					'clientKey' => $this->credentials->client_key,
				),
			),
			$this->payload
		);

		return self::WRAPPER_REQUEST === $this->wrapper_mode()
			? array( 'request' => $payload )
			: $payload;
	}

	public function wrapper_mode(): string {
		$wrapper = (string) ( $this->options['wrapper'] ?? self::WRAPPER_DIRECT );

		return self::WRAPPER_REQUEST === $wrapper ? self::WRAPPER_REQUEST : self::WRAPPER_DIRECT;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function redacted_payload_shape(): array {
		return array(
			'service' => $this->service,
			'method' => $this->method,
			'wrapper' => $this->wrapper_mode(),
			'has_auth' => $this->credentials->is_complete() ? 'yes' : 'no',
			'request_business_fields' => $this->payload,
			'soap_payload_shape' => $this->redact_auth( $this->payload_with_auth() ),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function redact_auth( array $payload ): array {
		if ( isset( $payload['auth'] ) && is_array( $payload['auth'] ) ) {
			$payload['auth']['clientNumber'] = '' !== (string) ( $payload['auth']['clientNumber'] ?? '' ) ? '[redacted]' : '';
			$payload['auth']['clientKey'] = '' !== (string) ( $payload['auth']['clientKey'] ?? '' ) ? '[redacted]' : '';
		}
		if ( isset( $payload['request'] ) && is_array( $payload['request'] ) ) {
			$payload['request'] = $this->redact_auth( $payload['request'] );
		}

		return $payload;
	}
}
