<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentEnrichmentService {
	public function __construct( private DpdApiClient $client, private DpdShipmentRepository $repository ) {}

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
		if ( null === $cost || '' === $date ) { return array( 'success' => true, 'message' => 'DPD пока не вернул цену и плановую дату.', 'complete' => false ); }
		$now = $this->now();
		if ( 'manual' !== (string) ( $shipment['actual_cost_source'] ?? '' ) ) {
			$shipment['actual_cost_kopecks'] = $cost;
			$shipment['actual_cost_currency'] = 'RUB';
			$shipment['actual_cost_source'] = 'carrier_status';
			$shipment['actual_cost_source_detail'] = 'dpd_events';
			$shipment['actual_cost_updated_at'] = $now;
		}
		$shipment['planned_delivery_date'] = $date;
		$shipment['dpd_enrichment_checked_at'] = $now;
		$shipment['updated_at'] = $now;
		$this->repository->save( $order, $shipment );
		return array( 'success' => true, 'message' => 'Цена и плановая дата DPD обновлены.', 'complete' => true, 'shipment' => $shipment );
	}

	/** @param array<string,mixed> $body @return array<string,mixed> */
	private function first_state( array $body ): array {
		$value = $body['return']['states'] ?? $body['states'] ?? $body['return'] ?? array();
		if ( is_array( $value ) && is_array( $value[0] ?? null ) ) { return $value[0]; }
		return is_array( $value ) ? $value : array();
	}
	private function pickup_year( string $date ): ?int { try { return '' !== $date ? (int) ( new \DateTimeImmutable( $date ) )->format( 'Y' ) : null; } catch ( \Throwable ) { return null; } }
	private function cost_kopecks( mixed $value ): ?int { return is_numeric( $value ) && (float) $value > 0 ? (int) round( (float) $value * 100 ) : null; }
	private function now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
}
