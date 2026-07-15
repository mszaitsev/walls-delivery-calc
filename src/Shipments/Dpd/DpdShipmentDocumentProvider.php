<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public const ACTION_DOWNLOAD_DOCUMENTS = 'download_documents';

	public function __construct( private DpdShipmentDocumentService $service ) {
	}

	public function carrier_key(): string {
		return DpdSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		if ( ! DpdShipmentDocumentService::can_download_documents( $shipment ) ) {
			return array();
		}

		return array( new ShipmentDocumentAction( self::ACTION_DOWNLOAD_DOCUMENTS, 'Скачать документы', true, 'download', array( 'attrs' => array( 'data-wdc-dpd-documents-download' => '1' ) ) ) );
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		if ( self::ACTION_DOWNLOAD_DOCUMENTS !== $action_key || ! DpdShipmentDocumentService::can_download_documents( $shipment ) ) {
			throw new \RuntimeException( 'Документы DPD недоступны для текущего отправления.' );
		}
		$result = $this->service->create_zip_for_order( $order );
		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( (string) ( $result['message'] ?? 'Не удалось скачать документы DPD.' ) );
		}
		$path = (string) ( $result['path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( 'ZIP-файл документов DPD не найден.' );
		}
		$body = (string) file_get_contents( $path );
		$this->service->delete_temp_file( $path );

		return new ShipmentBinaryDocument(
			$body,
			'application/zip',
			$this->safe_filename( (string) ( $result['filename'] ?? 'dpd-documents.zip' ), 'dpd-documents.zip' )
		);
	}

	private function safe_filename( string $filename, string $fallback ): string {
		$filename = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $filename ) : preg_replace( '/[^A-Za-z0-9_.-]+/', '-', $filename );

		return '' !== (string) $filename ? (string) $filename : $fallback;
	}
}
