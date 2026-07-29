<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusService {
	public function __construct(
		private JetLogisticApiClient $api,
		private JetLogisticStatusMapper $mapper
	) {
	}

	/** @param array<string,mixed> $current @return array<string,mixed> */
	public function update( array $current ): array {
		$tracking = trim( (string) ( $current['tracking_number'] ?? $current['external_id'] ?? '' ) );
		if ( '' === $tracking ) {
			return array( 'success' => false, 'message' => 'Jet Logistic tracking number is missing.' );
		}
		$response = $this->api->status( $tracking );
		$events = $this->events( is_array( $response['logs'] ?? null ) ? $response['logs'] : array() );
		$latest = $events[0] ?? array();
		$message = (string) ( $latest['message'] ?? '' );
		$mapped = '' !== $message ? $this->mapper->map( $message ) : '';
		$current_status = (string) ( $current['universal_status_code'] ?? DeliveryStatus::IN_TRANSIT );
		$universal = '' !== $mapped ? $mapped : $current_status;

		return array(
			'success' => true,
			'shipment_patch' => array(
				'carrier_status_message' => $message,
				'carrier_status_date' => (string) ( $latest['date'] ?? '' ),
				'status_events' => $events,
				'status_updated_at' => current_time( 'mysql' ),
				'universal_status_code' => $universal,
				'universal_status_label' => DeliveryStatus::label( $universal ),
			),
			'status' => $message,
			'message' => 'Статус Jet Logistic обновлен.',
		);
	}

	/** @param array<int,mixed> $logs @return array<int,array{date:string,message:string}> */
	private function events( array $logs ): array {
		$events = array();
		$seen = array();
		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}
			$date = trim( (string) ( $log['date'] ?? '' ) );
			$message = trim( (string) ( $log['message'] ?? '' ) );
			if ( '' === $date && '' === $message ) {
				continue;
			}
			$key = $date . '|' . $message;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$events[] = array( 'date' => $date, 'message' => $message );
		}
		usort( $events, static fn( array $a, array $b ): int => strcmp( (string) $b['date'], (string) $a['date'] ) );

		return array_slice( $events, 0, 5 );
	}
}
