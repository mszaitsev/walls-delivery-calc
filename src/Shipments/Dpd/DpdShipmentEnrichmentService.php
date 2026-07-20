<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentEnrichmentService {
	public function __construct(
		private DpdApiClient $client,
		private DpdShipmentRepository $repository,
		private ShipmentActualCostService $actual_cost_service
	) {}

	/** @return array<string,mixed> */
	public function enrich_current_order( object $order ): array {
		$shipment = $this->repository->find( $order );
		$dpd_order = trim( (string) ( $shipment['dpd_order_number'] ?? $shipment['tracking_number'] ?? '' ) );
		if ( '' === $dpd_order ) { return array( 'success' => true, 'message' => 'Нет номера DPD для уточнения цены.' ); }
		$pickup_year = $this->pickup_year( (string) ( $shipment['dpd_date_pickup'] ?? $shipment['datePickup'] ?? '' ) );
		$response = $this->client->getStatesByDPDOrder( array_filter( array( 'dpdOrderNr' => $dpd_order, 'pickupYear' => $pickup_year ) ) );
		if ( empty( $response['success'] ) ) { return array( 'success' => false, 'message' => (string) ( $response['error_message'] ?? 'Не удалось получить цену DPD.' ), 'response' => $response ); }
		$state = $this->first_state( is_array( $response['body'] ?? null ) ? $response['body'] : array() );
		$cost = $this->cost_kopecks( $state['orderCost'] ?? null );
		$date = trim( (string) ( $state['planDeliveryDate'] ?? '' ) );
		$now = $this->now();
		$updated = false;
		if ( '' !== $date ) {
			$shipment['planned_delivery_date'] = $date;
			$shipment['dpd_enrichment_checked_at'] = $now;
			$shipment['updated_at'] = $now;
			$this->repository->save( $order, $shipment );
			$updated = true;
		}
		if ( null !== $cost && $cost > 0 ) {
			$shipment = $this->actual_cost_service->apply_carrier_cost( $order, DpdSettings::CARRIER_KEY, new ShipmentActualCost( $cost, 'RUB', 'carrier_status', 'dpd_events', $now ) );
			$updated = true;
		}
		if ( ! $updated ) {
			return array( 'success' => true, 'message' => 'DPD пока не вернул цену и плановую дату.', 'complete' => false );
		}

		return array( 'success' => true, 'message' => 'Цена или плановая дата DPD обновлены.', 'complete' => null !== $cost && $cost > 0 && '' !== $date, 'shipment' => $shipment );
	}

	/** @param array<string,mixed> $body @return array<string,mixed> */
	private function first_state( array $body ): array {
		$value = $body['return']['states'] ?? $body['states'] ?? $body['return'] ?? array();
		if ( is_array( $value ) && is_array( $value[0] ?? null ) ) { return $value[0]; }
		return is_array( $value ) ? $value : array();
	}
	private function pickup_year( string $date ): ?int { try { return '' !== $date ? (int) ( new \DateTimeImmutable( $date ) )->format( 'Y' ) : null; } catch ( \Throwable ) { return null; } }
	private function cost_kopecks( mixed $value ): ?int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$kopecks = MoneyParser::numeric_to_kopecks( $value );

		return null !== $kopecks && $kopecks > 0 ? $kopecks : null;
	}
	private function now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
}
