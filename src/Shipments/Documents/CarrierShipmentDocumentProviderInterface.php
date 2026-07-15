<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Documents;

defined( 'ABSPATH' ) || exit;

interface CarrierShipmentDocumentProviderInterface {
	public function carrier_key(): string;

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,ShipmentDocumentAction>
	 */
	public function actions( object $order, array $shipment ): array;

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument;
}
