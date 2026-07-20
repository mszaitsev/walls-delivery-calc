<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostLookupService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function __construct( private ?RussianPostShipmentActualCostLookupService $actual_cost_lookup = null ) {}

	public function carrier_key(): string { return RussianPostDomesticSettings::CARRIER_KEY; }

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		unset( $request, $preview, $now );
		$raw = $result->raw_reference;
		$backlog_order_id = trim( $result->backlog_order_id );
		$fields = array(
			'group_name' => (string) ( $raw['group_name'] ?? '' ),
			'status' => 'created',
			'status_title' => '',
		);
		if ( '' !== $backlog_order_id ) {
			$fields['backlog_order_id'] = ctype_digit( $backlog_order_id ) ? (int) $backlog_order_id : $backlog_order_id;
		}

		return array_merge( $fields, $this->actual_cost_after_create( $result->tracking_number ) );
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		unset( $request, $result, $preview, $now );
		return null;
	}

	public function after_persist( object $order, array $shipment ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					'Отправление Почты России создано. Barcode: %s. Мест: %d%s',
					(string) ( $shipment['tracking_number'] ?? '' ),
					count( is_array( $shipment['places'] ?? null ) ? $shipment['places'] : array() ),
					'' !== (string) ( $shipment['group_name'] ?? '' ) ? '. ММО group-name: ' . (string) $shipment['group_name'] : ''
				)
			);
		}
	}

	/** @return array<string,mixed> */
	private function actual_cost_after_create( string $barcode ): array {
		if ( '' === trim( $barcode ) || ! $this->actual_cost_lookup instanceof RussianPostShipmentActualCostLookupService ) {
			return array();
		}

		try {
			$result = $this->actual_cost_lookup->lookup_after_create( $barcode );
		} catch ( \Throwable ) {
			return array( 'russian_post_actual_cost_lookup_error' => 'exception' );
		}

		$fields = is_array( $result['fields'] ?? null ) ? $result['fields'] : array();
		if ( array() !== $fields ) {
			$amount = is_numeric( $fields['actual_cost_kopecks'] ?? $fields['russian_post_actual_cost_kopecks'] ?? null )
				? (int) ( $fields['actual_cost_kopecks'] ?? $fields['russian_post_actual_cost_kopecks'] )
				: 0;
			unset( $fields['actual_cost_kopecks'], $fields['actual_cost_currency'], $fields['actual_cost_source'], $fields['actual_cost_source_detail'], $fields['actual_cost_updated_at'] );
			if ( $amount > 0 ) {
				$fields['actual_cost_candidate'] = new ShipmentActualCost( $amount, 'RUB', 'carrier_api', (string) ( $fields['russian_post_actual_cost_source'] ?? 'russian_post_shipment_search' ) );
			}

			return $fields;
		}
		$error_code = trim( (string) ( $result['error_code'] ?? '' ) );

		return '' !== $error_code ? array( 'russian_post_actual_cost_lookup_error' => $error_code ) : array();
	}
}
