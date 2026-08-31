<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	private const ACTION_PREFIX = 'ozon_label_';

	public function __construct( private OzonDeliveryApiClient $api ) {}

	public function carrier_key(): string {
		return OzonDeliverySettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment @return array<int,ShipmentDocumentAction> */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		$postings = $this->postings( $shipment );
		$total = count( $postings );
		$actions = array();
		foreach ( $postings as $index => $posting ) {
			if ( empty( $posting['approved'] ) ) {
				continue;
			}
			$place = (int) ( $posting['place_number'] ?? $index + 1 );
			$label = 1 === $total ? 'Скачать этикетку' : sprintf( 'Скачать этикетку %d из %d', $place, $total );
			$actions[] = new ShipmentDocumentAction( self::ACTION_PREFIX . $place, $label, true, 'download', array( 'place_number' => $place ) );
		}
		return $actions;
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		$place = (int) substr( $action_key, strlen( self::ACTION_PREFIX ) );
		if ( $place <= 0 ) {
			throw new \RuntimeException( 'Неизвестный ярлык Ozon.' );
		}
		foreach ( $this->postings( $shipment ) as $posting ) {
			if ( (int) ( $posting['place_number'] ?? 0 ) !== $place ) {
				continue;
			}
			if ( empty( $posting['approved'] ) ) {
				throw new \RuntimeException( 'Ярлык Ozon доступен только после подтверждения отправления.' );
			}
			$number = (string) ( $posting['posting_number'] ?? '' );
			if ( '' === $number ) {
				break;
			}
			$label = $this->api->posting_label( $number );
			return new ShipmentBinaryDocument( $label['body'], $label['content_type'], $this->filename( $order, count( $this->postings( $shipment ) ), $place ) );
		}

		throw new \RuntimeException( 'Отправление Ozon для выбранной коробки не найдено.' );
	}

	/** @param array<string,mixed> $shipment @return array<int,array<string,mixed>> */
	private function postings( array $shipment ): array {
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? array_values( array_filter( $shipment['ozon_postings'], 'is_array' ) ) : array();
		usort( $postings, static fn( array $left, array $right ): int => (int) ( $left['place_number'] ?? 0 ) <=> (int) ( $right['place_number'] ?? 0 ) );
		return $postings;
	}

	private function filename( object $order, int $total, int $place ): string {
		$order_number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : 'order';
		$order_number = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $order_number ) : preg_replace( '/[^A-Za-z0-9._-]+/', '-', $order_number );
		$order_number = trim( (string) $order_number, '.-_' );
		if ( '' === $order_number ) {
			$order_number = 'order';
		}

		return $total <= 1 ? sprintf( 'ozon-%s.pdf', $order_number ) : sprintf( 'ozon-%s-%d.pdf', $order_number, $place );
	}
}
