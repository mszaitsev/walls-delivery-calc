<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class CdekShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public const ACTION_DOWNLOAD_LABEL = 'download_label';

	public function __construct( private CdekBarcodePrintService $service ) {
	}

	public function carrier_key(): string {
		return CdekSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		if ( ! $this->can_download_label( $shipment ) ) {
			return array();
		}

		return array(
			new ShipmentDocumentAction(
				self::ACTION_DOWNLOAD_LABEL,
				'Скачать этикетку',
				true,
				'ajax_download',
				array(
					'prepare_action' => 'wdc_cdek_barcode_prepare',
					'requires_ready_download_url' => true,
					'attrs' => array(
						'data-wdc-cdek-barcode-download' => '1',
						'data-prepare-action' => 'wdc_cdek_barcode_prepare',
					),
				)
			),
		);
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		if ( self::ACTION_DOWNLOAD_LABEL !== $action_key || ! $this->can_download_label( $shipment ) ) {
			throw new \RuntimeException( 'Для текущего отправления этикетка СДЭК недоступна.' );
		}
		$result = $this->service->download_ready_pdf_for_order( $order );
		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( (string) ( $result['message'] ?? 'Не удалось получить этикетку СДЭК.' ) );
		}

		return new ShipmentBinaryDocument(
			(string) ( $result['body'] ?? '' ),
			(string) ( $result['content_type'] ?? 'application/pdf' ),
			$this->safe_filename( (string) ( $result['filename'] ?? 'cdek-barcode.pdf' ), 'cdek-barcode.pdf' )
		);
	}

	/** @param array<string,mixed> $shipment */
	private function can_download_label( array $shipment ): bool {
		if ( array() === $shipment || CdekSettings::CARRIER_KEY !== (string) ( $shipment['carrier_key'] ?? CdekSettings::CARRIER_KEY ) ) {
			return false;
		}
		$status = (string) ( $shipment['status'] ?? '' );
		$order_status = strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );
		if ( in_array( $status, array( 'registration_pending', 'failed', 'removed' ), true ) || in_array( $order_status, array( 'ACCEPTED', 'INVALID', 'REMOVED' ), true ) ) {
			return false;
		}

		return '' !== trim( (string) ( $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) )
			|| '' !== trim( (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ) );
	}

	private function safe_filename( string $filename, string $fallback ): string {
		$filename = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $filename ) : preg_replace( '/[^A-Za-z0-9_.-]+/', '-', $filename );

		return '' !== (string) $filename ? (string) $filename : $fallback;
	}
}
