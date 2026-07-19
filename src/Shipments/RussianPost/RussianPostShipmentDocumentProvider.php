<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;

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
		unset( $order, $shipment );
		// Otpravka currently returns "Forbidden mail type" for /1.0/forms/backlog/{id}/forms before batch formation; keep download code for future API re-check.
		return array();
	}

	/** @param array<string,mixed> $shipment */
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		if ( self::ACTION_DOWNLOAD_LABEL !== $action_key ) {
			throw new \RuntimeException( 'Неизвестное действие документа Почты России.' );
		}

		return $this->service->download_label( $order, $shipment );
	}
}
