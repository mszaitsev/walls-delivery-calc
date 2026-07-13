<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Contracts;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;

defined( 'ABSPATH' ) || exit;

interface CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string;

	/**
	 * @param array<string,mixed> $preview
	 * @return array<string,mixed>
	 */
	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array;

	/**
	 * @param array<string,mixed> $preview
	 * @return array<string,mixed>
	 */
	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array;

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function after_persist( object $order, array $shipment ): void;
}
