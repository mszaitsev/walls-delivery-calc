<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;

defined( 'ABSPATH' ) || exit;

final class ShipmentMetaboxButtonPolicy {
	/**
	 * @param array<string,mixed> $shipment
	 * @param array<string,mixed> $status_payload
	 * @return array<string,bool>
	 */
	public function resolve( string $carrier_key, array $shipment, array $status_payload, bool $legacy_russian_post_can_cancel = false ): array {
		$legacy_has_shipment = $this->legacy_has_shipment( $shipment );
		$has_shipment = $this->capability( $status_payload, 'has_shipment', $legacy_has_shipment );
		$can_create = $this->capability( $status_payload, 'can_create', '' !== $carrier_key && ! $has_shipment );
		$can_attach_manual = $this->capability( $status_payload, 'can_attach_manual', $can_create );
		$can_cancel = $this->capability( $status_payload, 'can_cancel', RussianPostDomesticSettings::CARRIER_KEY === $carrier_key && $legacy_russian_post_can_cancel );
		$can_remove = $this->capability( $status_payload, 'can_remove_from_order', $this->legacy_can_remove( $carrier_key, $shipment, $can_cancel ) );
		$can_update = $this->capability( $status_payload, 'can_update_status', $this->legacy_can_update( $carrier_key, $shipment, $has_shipment ) );

		return array(
			'has_shipment' => $has_shipment,
			'can_create' => $can_create,
			'can_attach_manual' => $can_attach_manual,
			'can_update_status' => $can_update,
			'can_cancel' => $can_cancel,
			'can_remove_from_order' => $can_remove,
			'show_create' => $can_create,
			'show_manual_attach' => $can_attach_manual,
			'show_update' => $has_shipment && $can_update,
			'show_cancel' => $has_shipment && $can_cancel,
			'show_remove' => $has_shipment && $can_remove,
		);
	}

	/** @param array<string,mixed> $status_payload */
	private function capability( array $status_payload, string $key, bool $fallback ): bool {
		return array_key_exists( $key, $status_payload ) ? ! empty( $status_payload[ $key ] ) : $fallback;
	}

	/** @param array<string,mixed> $shipment */
	private function legacy_has_shipment( array $shipment ): bool {
		if ( in_array( (string) ( $shipment['status'] ?? '' ), array( 'registration_pending', 'created', 'registered' ), true ) ) {
			return true;
		}

		return '' !== $this->barcode( $shipment )
			|| '' !== trim( (string) ( $shipment['backlog_order_id'] ?? '' ) )
			|| '' !== trim( (string) ( $shipment['dpd_order_number'] ?? '' ) )
			|| '' !== trim( (string) ( $shipment['dpd_request_number'] ?? '' ) );
	}

	/** @param array<string,mixed> $shipment */
	private function legacy_can_update( string $carrier_key, array $shipment, bool $has_shipment ): bool {
		return $has_shipment && ( CdekSettings::CARRIER_KEY === $carrier_key || '' !== $this->barcode( $shipment ) );
	}

	/** @param array<string,mixed> $shipment */
	private function legacy_can_remove( string $carrier_key, array $shipment, bool $can_cancel ): bool {
		return RussianPostDomesticSettings::CARRIER_KEY === $carrier_key && '' !== $this->barcode( $shipment ) && ! $can_cancel;
	}

	/** @param array<string,mixed> $shipment */
	private function barcode( array $shipment ): string {
		return trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}
}
