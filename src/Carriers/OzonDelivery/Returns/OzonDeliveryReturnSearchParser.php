<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Returns;

use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryReturnSearchParser {
	/** @return array{returns:array<int,array<string,mixed>>,next_cursor:string} */
	public function parse( array $response ): array {
		if ( ! isset( $response['returns'] ) || ! is_array( $response['returns'] ) || ( isset( $response['next_cursor'] ) && ! is_scalar( $response['next_cursor'] ) && null !== $response['next_cursor'] ) ) {
			throw new \UnexpectedValueException( 'Ozon Delivery вернул некорректный список возвратов.' );
		}
		$returns = array();
		foreach ( $response['returns'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$return_number = trim( (string) ( $row['return_number'] ?? '' ) );
			$return_external_id = trim( (string) ( $row['return_external_id'] ?? '' ) );
			if ( '' === $return_number ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? 'unknown' );
			$returns[] = array(
				'return_number' => $return_number,
				'return_external_id' => $return_external_id,
				'status' => $status,
				'normalized_status' => OzonDeliveryShipmentStatusMapping::normalize( $status ),
				'status_changed_at' => (string) ( $row['status_changed_at'] ?? '' ),
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'barcode' => (string) ( $row['barcode'] ?? '' ),
				'return_type' => (string) ( $row['return_type'] ?? '' ),
				'return_delivery_type' => (string) ( $row['return_delivery_type'] ?? '' ),
			);
		}

		return array(
			'returns' => $returns,
			'next_cursor' => trim( (string) ( $response['next_cursor'] ?? '' ) ),
		);
	}
}
