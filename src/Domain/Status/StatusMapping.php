<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Status;

final class StatusMapping {
	public function __construct(
		public readonly string $carrier_key,
		public readonly string $external_status,
		public readonly string $external_status_title,
		public readonly string $internal_status,
		public readonly string $wc_status,
		public readonly bool $terminal = false
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'carrier_key'           => $this->carrier_key,
			'external_status'       => $this->external_status,
			'external_status_title' => $this->external_status_title,
			'internal_status'       => $this->internal_status,
			'wc_status'             => $this->wc_status,
			'terminal'              => $this->terminal,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['external_status'] ?? '' ),
			(string) ( $data['external_status_title'] ?? '' ),
			(string) ( $data['internal_status'] ?? DeliveryStatus::UNKNOWN ),
			(string) ( $data['wc_status'] ?? '' ),
			(bool) ( $data['terminal'] ?? false )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->carrier_key ) ) {
			$errors[] = 'carrier_key is required';
		}

		if ( '' === trim( $this->external_status ) ) {
			$errors[] = 'external_status is required';
		}

		if ( ! DeliveryStatus::is_valid( $this->internal_status ) ) {
			$errors[] = 'internal_status is invalid';
		}

		return $errors;
	}
}
