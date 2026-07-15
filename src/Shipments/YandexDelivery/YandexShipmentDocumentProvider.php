<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public const ACTION_DOWNLOAD_LABEL = 'download_yandex_label';

	public function __construct(
		private YandexShipmentDocumentService $service,
		private YandexShipmentLabelPolicy $policy
	) {
	}

	public function carrier_key(): string {
		return YandexDeliverySettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		if ( ! $this->policy->can_download( $shipment ) ) {
			return array();
		}

		return array( new ShipmentDocumentAction( self::ACTION_DOWNLOAD_LABEL, 'Скачать ярлык', true, 'download', array( 'attrs' => array( 'data-wdc-yandex-label-download' => '1' ) ) ) );
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		if ( self::ACTION_DOWNLOAD_LABEL !== $action_key || ! $this->policy->can_download( $shipment ) ) {
			throw new \RuntimeException( 'Для текущего статуса отправления ярлык Яндекс недоступен.' );
		}
		$result = $this->service->label_pdf_for_order( $order );
		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( (string) ( $result['message'] ?? 'Не удалось получить ярлык Яндекс.Доставки.' ) );
		}

		return new ShipmentBinaryDocument(
			(string) ( $result['body'] ?? '' ),
			(string) ( $result['content_type'] ?? 'application/pdf' ),
			$this->safe_filename( (string) ( $result['filename'] ?? 'yandex-label.pdf' ), 'yandex-label.pdf' )
		);
	}

	private function safe_filename( string $filename, string $fallback ): string {
		$filename = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $filename ) : preg_replace( '/[^A-Za-z0-9_.-]+/', '-', $filename );

		return '' !== (string) $filename ? (string) $filename : $fallback;
	}
}
