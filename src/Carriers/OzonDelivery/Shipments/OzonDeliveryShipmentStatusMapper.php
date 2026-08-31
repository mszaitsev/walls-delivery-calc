<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentStatusMapper {
	public function __construct( private OzonDeliverySettings $settings ) {}

	/** @return array<int,string> */
	public function documented_statuses(): array {
		return OzonDeliveryShipmentStatusMapping::documented_statuses();
	}

	/** @return array<string,string> */
	public function mapping(): array {
		$defaults = OzonDeliveryShipmentStatusMapping::default_mapping();
		$stored = $this->settings->shipment_status_mapping();
		foreach ( $stored as $status => $universal ) {
			$normalized = OzonDeliveryShipmentStatusMapping::normalize( (string) $status );
			if ( array_key_exists( $normalized, $defaults ) && DeliveryStatus::is_valid( (string) $universal ) ) {
				$defaults[ $normalized ] = (string) $universal;
			}
		}

		return $defaults;
	}

	public function universal( string $ozon_status ): string {
		$normalized = OzonDeliveryShipmentStatusMapping::normalize( $ozon_status );
		return $this->mapping()[ $normalized ] ?? DeliveryStatus::UNKNOWN;
	}

	/** @param array<int,string> $statuses */
	public function aggregate( array $statuses ): string {
		if ( array() === $statuses ) {
			return DeliveryStatus::UNKNOWN;
		}
		$universal = array_map( fn( string $status ): string => $this->universal( $status ), $statuses );
		$universal = array_values( array_filter( $universal, static fn( string $status ): bool => '' !== $status ) );
		if ( array() === $universal ) {
			return DeliveryStatus::UNKNOWN;
		}
		if ( count( array_unique( $universal ) ) === 1 ) {
			return $universal[0];
		}
		if ( in_array( DeliveryStatus::REJECTED, $universal, true ) ) {
			return DeliveryStatus::REJECTED;
		}
		if ( in_array( DeliveryStatus::CANCELLED, $universal, true ) || in_array( DeliveryStatus::DELIVERED, $universal, true ) ) {
			return DeliveryStatus::UNKNOWN;
		}
		if ( in_array( DeliveryStatus::RETURNING_TO_SENDER, $universal, true ) || in_array( DeliveryStatus::RETURNED_TO_SENDER, $universal, true ) ) {
			return DeliveryStatus::RETURNING_TO_SENDER;
		}
		foreach ( array( DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::IN_TRANSIT, DeliveryStatus::CREATED_IN_CARRIER, DeliveryStatus::PENDING_CREATION_IN_CARRIER ) as $candidate ) {
			if ( in_array( $candidate, $universal, true ) ) {
				return $candidate;
			}
		}

		return DeliveryStatus::UNKNOWN;
	}

	/** @param array<string,mixed> $post */
	public function save_from_admin( array $post ): void {
		$submitted = is_array( $post[ OzonDeliverySettings::SHIPMENT_STATUS_MAPPING_KEY ] ?? null ) ? $post[ OzonDeliverySettings::SHIPMENT_STATUS_MAPPING_KEY ] : array();
		$defaults = OzonDeliveryShipmentStatusMapping::default_mapping();
		$mapping = array();
		foreach ( $this->documented_statuses() as $status ) {
			$normalized = OzonDeliveryShipmentStatusMapping::normalize( $status );
			$value = (string) ( $submitted[ $status ] ?? $submitted[ $normalized ] ?? ( $defaults[ $normalized ] ?? DeliveryStatus::UNKNOWN ) );
			$mapping[ $normalized ] = DeliveryStatus::is_valid( $value ) ? $value : ( $defaults[ $normalized ] ?? DeliveryStatus::UNKNOWN );
		}
		$this->settings->save_shipment_status_mapping( $mapping );
	}
}
