<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Contracts;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;

defined( 'ABSPATH' ) || exit;

interface CarrierShipmentAdapterInterface {
	public function carrier_key(): string;

	public function supports( ShipmentCreateRequest $request ): bool;

	/**
	 * @return array<string,mixed>
	 */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array;

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult;

	/**
	 * @return array<string,string>
	 */
	public function presentation(): array;

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( object $order, array $shipment ): array;

	/**
	 * @return array<string,mixed>
	 */
	public function update_status( object $order, string $shipment_key = '' ): array;

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function attach_manual( object $order, array $payload ): array;

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array;

	/**
	 * @return array<string,mixed>
	 */
	public function remove_from_order( object $order, string $shipment_key = '' ): array;

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,array<string,mixed>>
	 */
	public function document_actions( object $order, array $shipment ): array;

	public function supports_status_auto_sync(): bool;

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function tracking_identifier( array $shipment ): string;

	public function auto_sync_throttle_microseconds(): int;
}
