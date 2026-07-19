<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public const ACTION_DOWNLOAD_LABEL = 'download_label';

	public function __construct( private RussianPostShipmentDocumentService $service ) {
	}

	public function carrier_key(): string {
		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $shipment */
	public function actions( object $order, array $shipment ): array {
		unset( $order );
		if ( ! $this->service->can_download_label( $shipment ) ) {
			return array();
		}

		return array( new ShipmentDocumentAction( self::ACTION_DOWNLOAD_LABEL, 'Скачать почтовый ярлык' ) );
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		if ( self::ACTION_DOWNLOAD_LABEL !== $action_key ) {
			throw new \RuntimeException( 'Неизвестное действие документа Почты России.' );
		}

		return $this->service->download_label( $order, $shipment );
	}
}
