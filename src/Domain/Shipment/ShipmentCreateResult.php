<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Shipment;

final class ShipmentCreateResult {
	/**
	 * @param array<int|string,mixed> $documents
	 * @param array<string,mixed> $raw_reference
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $external_id = '',
		public readonly string $tracking_number = '',
		public readonly string $backlog_order_id = '',
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly array $documents = array(),
		public readonly array $raw_reference = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'success'         => $this->success,
			'external_id'     => $this->external_id,
			'tracking_number' => $this->tracking_number,
			'backlog_order_id' => $this->backlog_order_id,
			'error_code'      => $this->error_code,
			'error_message'   => $this->error_message,
			'documents'       => $this->documents,
			'raw_reference'   => $this->raw_reference,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(bool) ( $data['success'] ?? false ),
			(string) ( $data['external_id'] ?? '' ),
			(string) ( $data['tracking_number'] ?? '' ),
			(string) ( $data['backlog_order_id'] ?? '' ),
			(string) ( $data['error_code'] ?? '' ),
			(string) ( $data['error_message'] ?? '' ),
			is_array( $data['documents'] ?? null ) ? $data['documents'] : array(),
			is_array( $data['raw_reference'] ?? null ) ? $data['raw_reference'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		if ( $this->success || '' !== $this->error_code || '' !== $this->error_message ) {
			return array();
		}

		return array( 'error_code or error_message is required for failed shipment creation' );
	}
}
