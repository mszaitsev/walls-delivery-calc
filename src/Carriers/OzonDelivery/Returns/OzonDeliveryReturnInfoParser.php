<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Returns;

use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryReturnInfoParser {
	/** @return array<int,array<string,mixed>> */
	public function parse( array $response ): array {
		if ( ! isset( $response['returns'] ) || ! is_array( $response['returns'] ) ) {
			throw new \UnexpectedValueException( 'Ozon Delivery вернул некорректную информацию о возвратах.' );
		}
		$returns = array();
		foreach ( $response['returns'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$return_number = trim( (string) ( $row['return_number'] ?? '' ) );
			if ( '' === $return_number ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? 'unknown' );
			$returns[] = array(
				'return_number' => $return_number,
				'return_external_id' => trim( (string) ( $row['return_external_id'] ?? '' ) ),
				'status' => $status,
				'normalized_status' => OzonDeliveryShipmentStatusMapping::normalize( $status ),
				'status_changed_at' => (string) ( $row['status_changed_at'] ?? '' ),
				'barcode' => (string) ( $row['barcode'] ?? '' ),
				'return_type' => (string) ( $row['return_type'] ?? '' ),
				'return_delivery_type' => (string) ( $row['return_delivery_type'] ?? '' ),
			);
		}

		return $returns;
	}
}
