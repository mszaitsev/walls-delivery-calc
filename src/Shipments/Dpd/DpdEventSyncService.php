<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;

defined( 'ABSPATH' ) || exit;

final class DpdEventSyncService {
	private const LOCK_OPTION = 'wdc_dpd_events_lock';
	private const LOCK_TTL = 300;
	private const MAX_PACKAGES = 20;

	public function __construct(
		private DpdApiClient $client,
		private DpdSettings $settings,
		private DpdShipmentRepository $repository,
		private DpdEventNormalizer $normalizer,
		private DpdStatusMapping $mapping,
		private ShipmentOrderStatusMappingService $order_status_mapping,
		private ?Logger $logger = null
	) {}

	public function sync( ?bool $confirm = null ): DpdEventSyncResult {
		$confirm = null === $confirm ? $this->settings->events_confirm_enabled() : $confirm;
		$token = $this->acquire_lock();
		if ( '' === $token ) { return new DpdEventSyncResult( true, 'События DPD уже обрабатываются другим запросом', extra: array( 'lock_busy' => true ) ); }
		$result = new DpdEventSyncResult( true, 'События DPD обработаны.', confirm_status: $confirm ? 'pending' : 'disabled' );
		try {
			for ( $batch = 1; $batch <= self::MAX_PACKAGES; $batch++ ) {
				$response = $this->client->getEvents( $this->events_request() );
				if ( empty( $response['success'] ) ) { $result->success = false; $result->message = (string) ( $response['error_message'] ?? 'Не удалось получить события DPD.' ); break; }
				$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
				$packet = is_array( $body['return'] ?? null ) ? $body['return'] : $body;
				$doc_id = (string) ( $packet['docId'] ?? '' );
				$result_complete = (bool) ( $packet['resultComplete'] ?? true );
				$events = $this->normalizer->normalize_many( $packet['event'] ?? array() );
				$result->packages++; $result->events += count( $events ); $result->result_complete = $result_complete;
				$this->process_events( $events, $result );
				if ( $confirm && '' !== $doc_id ) {
					$confirmed = $this->client->confirmEvents( array( 'docId' => $doc_id ) );
					if ( empty( $confirmed['success'] ) ) { $result->success = false; $result->confirm_status = 'error'; $result->message = (string) ( $confirmed['error_message'] ?? 'DPD не подтвердил пакет событий.' ); break; }
					$result->confirm_status = 'confirmed';
				} else {
					$result->confirm_status = $confirm ? 'missing_doc_id' : 'disabled';
				}
				$this->log( 'info', 'DPD getEvents batch processed.', array( 'docId' => $doc_id, 'resultComplete' => $result_complete, 'events' => count( $events ), 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'unmatched' => $result->unmatched, 'confirm' => $result->confirm_status, 'batch' => $batch ) );
				if ( ! $confirm || $result_complete ) { break; }
				if ( self::MAX_PACKAGES === $batch ) { $result->success = false; $result->message = 'DPD events batch limit reached; запустите обновление ещё раз.'; $result->extra['warning'] = 'batch_limit'; }
			}
		} finally { $this->release_lock( $token ); }
		return $result;
	}

	/** @param array<int,array<string,mixed>> $events */
	private function process_events( array $events, DpdEventSyncResult $result ): void {
		foreach ( $this->normalizer->latest_by_order( $events ) as $event ) {
			$order = $this->match_order( $event );
			if ( null === $order ) { $result->unmatched++; $this->log_unmatched( $event ); continue; }
			$shipment = $this->repository->find( $order );
			if ( array() === $shipment ) { $result->unmatched++; $this->log_unmatched( $event ); continue; }
			if ( ! $this->event_matches_shipment( $shipment, $event ) ) { $result->unmatched++; $this->log_unmatched( $event, (string) ( $shipment['dpd_order_number'] ?? '' ) ); continue; }
			if ( $this->is_stale_pending_client_event( $shipment, $event ) || ! $this->is_new_event( $shipment, $event ) ) { $result->unchanged++; continue; }
			$status = $this->mapping->resolve( (string) $event['eventNumber'] );
			$now = $this->now();
			$updated = array_merge( $shipment, array(
				'dpd_event_code' => (string) $event['eventNumber'], 'dpd_event_marker' => (string) $event['eventCode'], 'dpd_event_name' => (string) $event['eventName'], 'dpd_event_time' => (string) $event['eventDate'], 'dpd_event_timestamp' => (int) $event['timestamp'],
				'dpd_order_number' => (string) $event['dpdOrderNr'], 'dpd_client_order_number' => (string) $event['clientOrderNr'], 'dpd_parcel_number' => (string) $event['dpdParcelNr'],
				'tracking_number' => (string) ( $event['dpdOrderNr'] ?: ( $shipment['tracking_number'] ?? '' ) ), 'barcode' => (string) ( $event['dpdOrderNr'] ?: ( $shipment['barcode'] ?? '' ) ), 'external_id' => (string) ( $event['dpdOrderNr'] ?: ( $shipment['external_id'] ?? '' ) ),
				'universal_status_code' => $status, 'universal_status_label' => DeliveryStatus::label( $status ), 'carrier_status_title' => (string) $event['eventName'], 'carrier_operation_date' => (string) $event['eventDate'], 'carrier_operation_code' => (string) $event['eventNumber'], 'carrier_operation_marker' => (string) $event['eventCode'], 'tracking_checked_at' => $now, 'updated_at' => $now,
			) );
			$this->repository->save( $order, $updated );
			$this->order_status_mapping->apply( $order, $updated, $status );
			$result->updated++;
		}
	}

	/** @param array<string,mixed> $event */
	private function match_order( array $event ): ?object {
		$dpd_order = trim( (string) ( $event['dpdOrderNr'] ?? '' ) );
		if ( '' !== $dpd_order ) {
			$by_dpd = $this->repository->find_order_by_dpd_order_number( $dpd_order );
			if ( null !== $by_dpd ) {
				return $by_dpd;
			}
		}

		return $this->repository->find_order_by_client_order_number( (string) ( $event['clientOrderNr'] ?? '' ) );
	}

	/** @param array<string,mixed> $shipment @param array<string,mixed> $event */
	private function event_matches_shipment( array $shipment, array $event ): bool {
		$saved_dpd = $this->normalize_dpd_number( (string) ( $shipment['dpd_order_number'] ?? '' ) );
		if ( '' === $saved_dpd ) {
			return true;
		}

		return $saved_dpd === $this->normalize_dpd_number( (string) ( $event['dpdOrderNr'] ?? '' ) );
	}

	private function normalize_dpd_number( string $value ): string {
		return strtoupper( trim( $value ) );
	}

	/** @param array<string,mixed> $shipment @param array<string,mixed> $event */
	private function is_new_event( array $shipment, array $event ): bool {
		$saved_ts = (int) ( $shipment['dpd_event_timestamp'] ?? 0 ); $incoming_ts = (int) $event['timestamp'];
		if ( $saved_ts > 0 && $incoming_ts <= 0 ) { return false; }
		if ( $incoming_ts > 0 && $saved_ts > 0 && $incoming_ts < $saved_ts ) { return false; }
		if ( $incoming_ts === $saved_ts && (string) ( $shipment['dpd_event_code'] ?? '' ) === (string) $event['eventNumber'] && (string) ( $shipment['dpd_event_marker'] ?? '' ) === (string) $event['eventCode'] ) { return false; }
		return true;
	}


	/** @param array<string,mixed> $shipment @param array<string,mixed> $event */
	private function is_stale_pending_client_event( array $shipment, array $event ): bool {
		if ( '' !== trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) || '' !== trim( (string) ( $event['dpdOrderNr'] ?? '' ) ) ) {
			return false;
		}
		$started_at = trim( (string) ( $shipment['registration_started_at'] ?? '' ) );
		$event_ts = (int) ( $event['timestamp'] ?? 0 );
		if ( '' === $started_at || $event_ts <= 0 ) {
			return false;
		}
		$started_ts = strtotime( $started_at );
		if ( false === $started_ts ) {
			return false;
		}

		return $event_ts < ( $started_ts - 300 );
	}

	/** @return array<string,mixed> */
	private function events_request(): array {
		$request = array(); $days = $this->settings->events_lookback_days();
		if ( null !== $days ) { $now = $this->now_datetime(); $from = $now->setTime( 0, 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' ); $request['dateFrom'] = $from->format( DATE_ATOM ); $request['dateTo'] = $now->format( DATE_ATOM ); }
		$request['maxRowCount'] = 500; return $request;
	}

	private function acquire_lock(): string { $token = sha1( uniqid( 'dpd-events-', true ) ); $value = array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ); $existing = function_exists( 'get_option' ) ? get_option( self::LOCK_OPTION, array() ) : array(); if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > time() ) { return ''; } if ( function_exists( 'delete_option' ) ) { delete_option( self::LOCK_OPTION ); } if ( function_exists( 'add_option' ) && add_option( self::LOCK_OPTION, $value, '', 'no' ) ) { return $token; } return ''; }
	private function release_lock( string $token ): void { $existing = function_exists( 'get_option' ) ? get_option( self::LOCK_OPTION, array() ) : array(); if ( is_array( $existing ) && $token === (string) ( $existing['token'] ?? '' ) && function_exists( 'delete_option' ) ) { delete_option( self::LOCK_OPTION ); } }
	/** @param array<string,mixed> $event */ private function log_unmatched( array $event, string $saved_dpd_order_number = '' ): void { $context = array( 'eventNumber' => $event['eventNumber'], 'eventCode' => $event['eventCode'], 'eventDate' => $event['eventDate'], 'clientOrderNr' => $event['clientOrderNr'], 'dpdOrderNr' => $event['dpdOrderNr'] ); if ( '' !== trim( $saved_dpd_order_number ) ) { $context['saved_dpd_order_number'] = trim( $saved_dpd_order_number ); } $this->log( 'warning', 'DPD event unmatched.', $context ); }
	/** @param array<string,mixed> $context */ private function log( string $level, string $message, array $context ): void { if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) { $this->logger->{$level}( $message, $context ); } }
	private function now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
	private function now_datetime(): \DateTimeImmutable { $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Novosibirsk' ); return new \DateTimeImmutable( 'now', $tz ); }
}
