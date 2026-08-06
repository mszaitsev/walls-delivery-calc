<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class PekShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public const ACTION_APPLICATION = 'download_application';
	public const ACTION_LABEL = 'download_label';
	public const ACTION_ALL_LABELS = 'download_all_labels';

	public function __construct( private PekShipmentDocumentService $documents ) {
	}

	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment @return array<int,ShipmentDocumentAction> */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		if ( '' === $this->cargo_code( $shipment ) ) {
			return array();
		}
		$actions = array(
			new ShipmentDocumentAction( self::ACTION_APPLICATION, 'Скачать заявку ПЭК', true, 'download' ),
			new ShipmentDocumentAction( self::ACTION_LABEL, 'Скачать этикетку ПЭК', true, 'download' ),
		);
		if ( count( is_array( $shipment['pek_cargo_codes'] ?? null ) ? $shipment['pek_cargo_codes'] : array() ) > 1 ) {
			$actions[] = new ShipmentDocumentAction( self::ACTION_ALL_LABELS, 'Скачать все этикетки ПЭК', true, 'download' );
		}

		return $actions;
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		unset( $order );
		$type = match ( $action_key ) {
			self::ACTION_APPLICATION => 'big',
			self::ACTION_LABEL => 'simple',
			self::ACTION_ALL_LABELS => 'multiple',
			default => '',
		};
		if ( '' === $type ) {
			throw new \RuntimeException( 'Неизвестный документ ПЭК.' );
		}
		$code = $this->cargo_code( $shipment );
		if ( '' === $code ) {
			throw new \RuntimeException( 'Не указан код груза ПЭК.' );
		}

		return $this->documents->download( $code, $type );
	}

	/** @param array<string,mixed> $shipment */
	private function cargo_code( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? '' ) );
	}
}
