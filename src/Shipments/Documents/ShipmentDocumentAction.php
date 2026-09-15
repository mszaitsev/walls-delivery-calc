<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Documents;

defined( 'ABSPATH' ) || exit;

final class ShipmentDocumentAction {
	/** @param array<string,mixed> $data */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly bool $visible = true,
		public readonly string $type = 'download',
		public readonly array $data = array()
	) {
		if ( '' === trim( $this->key ) ) {
			throw new \InvalidArgumentException( 'Shipment document action key must not be empty.' );
		}
		if ( '' === trim( $this->label ) ) {
			throw new \InvalidArgumentException( 'Shipment document action label must not be empty.' );
		}
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'key' => $this->key,
			'label' => $this->label,
			'type' => $this->type,
			'visible' => $this->visible,
			'data' => $this->data,
		);
	}
}
