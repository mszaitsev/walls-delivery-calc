<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTariffResult {
	/**
	 * @param array<int,string> $errors
	 * @param array<int,array<string,mixed>> $options
	 * @param array<string,mixed> $payload
	 * @param mixed $raw_response
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly bool $success,
		public readonly array $errors = array(),
		public readonly array $options = array(),
		public readonly array $payload = array(),
		public readonly mixed $raw_response = null,
		public readonly array $meta = array()
	) {
	}

	/**
	 * @param array<int,string> $errors
	 * @param array<string,mixed> $meta
	 */
	public static function failure( array $errors, array $meta = array() ): self {
		return new self( false, $errors, array(), array(), null, $meta );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'errors' => $this->errors,
			'options' => $this->options,
			'payload' => $this->payload,
			'raw_response' => $this->raw_response,
			'meta' => $this->meta,
		);
	}
}
