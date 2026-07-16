<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Contracts;

defined( 'ABSPATH' ) || exit;

interface CarrierShipmentLifecycleContinuationInterface {
	/**
	 * @return array<string,mixed>
	 */
	public function continue_lifecycle( object $order, string $continuation_token ): array;
}
