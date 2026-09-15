<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Modal;

defined( 'ABSPATH' ) || exit;

interface CarrierShipmentModalExtensionInterface {
	public function carrier_key(): string;

	/**
	 * @param array<string,mixed> $draft
	 * @return array<string,mixed>
	 */
	public function modal_context( object $order, array $draft ): array;

	/**
	 * @param array<string,mixed> $draft
	 * @param array<string,mixed> $context
	 */
	public function render_fields( object $order, array $draft, array $context ): void;

	/**
	 * @param array<string,mixed> $draft
	 * @param array<string,mixed> $context
	 */
	public function render_pickup_fields( object $order, array $draft, array $context ): void;

	/**
	 * @param array<string,mixed> $draft
	 * @param array<string,mixed> $context
	 */
	public function render_courier_fields( object $order, array $draft, array $context ): void;
}
