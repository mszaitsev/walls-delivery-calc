<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Status;

final class StatusEvent {
	/**
	 * @param array<string,mixed> $raw_reference
	 */
	public function __construct(
		public readonly string $carrier_key,
		public readonly string $external_id,
		public readonly string $tracking_number,
		public readonly string $external_status,
		public readonly string $internal_status,
		public readonly string $status_title,
		public readonly string $occurred_at,
		public readonly string $received_at,
		public readonly array $raw_reference = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'carrier_key'     => $this->carrier_key,
			'external_id'     => $this->external_id,
			'tracking_number' => $this->tracking_number,
			'external_status' => $this->external_status,
			'internal_status' => $this->internal_status,
			'status_title'    => $this->status_title,
			'occurred_at'     => $this->occurred_at,
			'received_at'     => $this->received_at,
			'raw_reference'   => $this->raw_reference,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['external_id'] ?? '' ),
			(string) ( $data['tracking_number'] ?? '' ),
			(string) ( $data['external_status'] ?? '' ),
			(string) ( $data['internal_status'] ?? DeliveryStatus::UNKNOWN ),
			(string) ( $data['status_title'] ?? '' ),
			(string) ( $data['occurred_at'] ?? '' ),
			(string) ( $data['received_at'] ?? '' ),
			is_array( $data['raw_reference'] ?? null ) ? $data['raw_reference'] : array()
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
