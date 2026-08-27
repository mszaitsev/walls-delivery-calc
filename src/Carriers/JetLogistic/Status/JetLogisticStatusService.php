<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusService {
	public function __construct(
		private JetLogisticApiClient $api,
		private JetLogisticStatusMapper $mapper,
		private ?JetLogisticStatusEventResolver $resolver = null
	) {
		$this->resolver ??= new JetLogisticStatusEventResolver( $this->mapper );
	}

	/** @param array<string,mixed> $current @return array<string,mixed> */
	public function update( array $current ): array {
		$tracking = trim( (string) ( $current['tracking_number'] ?? $current['external_id'] ?? '' ) );
		if ( '' === $tracking ) {
			return array( 'success' => false, 'message' => 'Номер груза Jet Logistic не указан.' );
		}
		$response = $this->api->status( $tracking );
		$resolved = $this->resolver->resolve( is_array( $response['logs'] ?? null ) ? $response['logs'] : array() );
		$events = $resolved['events'];
		$current_event = $resolved['current_event'];
		$message = (string) ( $current_event['message'] ?? '' );
		$mapped = (string) ( $current_event['universal_status'] ?? '' );
		$current_status = (string) ( $current['universal_status_code'] ?? DeliveryStatus::IN_TRANSIT );
		$universal = '' !== $mapped ? $mapped : $current_status;

		return array(
			'success' => true,
			'shipment_patch' => array(
				'carrier_status_message' => $message,
				'carrier_status_date' => (string) ( $current_event['date'] ?? '' ),
				'status_events' => $events,
				'status_updated_at' => current_time( 'mysql' ),
				'universal_status_code' => $universal,
				'universal_status_label' => DeliveryStatus::label( $universal ),
			),
			'status' => $message,
			'message' => 'Статус Jet Logistic обновлен.',
		);
	}
}
