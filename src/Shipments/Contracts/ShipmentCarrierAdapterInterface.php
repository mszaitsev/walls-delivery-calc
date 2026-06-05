<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Contracts;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;

defined( 'ABSPATH' ) || exit;

interface ShipmentCarrierAdapterInterface {
	public function carrier_key(): string;

	public function supports( ShipmentCreateRequest $request ): bool;

	/**
	 * @return array<string,mixed>
	 */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array;

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult;
}
