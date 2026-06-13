<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class CdekShipmentAdapter implements ShipmentCarrierAdapterInterface {
	public function __construct(
		private CdekApiClient $client,
		private CdekCreateRequestBuilder $builder,
		private ?Logger $logger = null
	) {
	}

	public function carrier_key(): string {
		return CdekSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return CdekSettings::CARRIER_KEY === $request->carrier_key;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		$errors = $this->builder->validate( $request );
		return array(
			'method' => 'POST',
			'path' => '/v2/orders',
			'body' => array() === $errors ? $this->builder->build( $request ) : array(),
			'errors' => $errors,
		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		$errors = $this->builder->validate( $request );
		if ( array() !== $errors ) {
			return new ShipmentCreateResult( false, error_code: 'cdek_validation_failed', error_message: implode( "\n", $errors ), raw_reference: array( 'errors' => $errors ) );
		}

		$payload = $this->builder->build( $request );
		$this->log( 'debug', 'CDEK order create payload prepared.', array( 'request' => $this->request_summary( $request, $payload ) ) );
		try {
			$response = $this->client->registerOrder( $payload );
		} catch ( CdekApiException $exception ) {
			$this->log( 'error', 'CDEK order create failed.', $this->sanitize_response_snapshot( $exception->details() ) );
			return new ShipmentCreateResult(
				false,
				error_code: (string) ( $exception->details()['cdek_error_code'] ?? 'cdek_order_create_failed' ),
				error_message: $exception->getMessage(),
				raw_reference: $exception->details()
			);
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$request_row = is_array( $body['requests'][0] ?? null ) ? $body['requests'][0] : array();
		$uuid = (string) ( $entity['uuid'] ?? '' );
		$request_uuid = (string) ( $request_row['request_uuid'] ?? '' );
		$cdek_number = $this->first_related_cdek_number( $body );
		$registration_state = strtoupper( (string) ( $request_row['state'] ?? '' ) );
		if ( 'INVALID' === $registration_state ) {
			$message = $this->errors_message( $request_row ) ?: 'Регистрация СДЭК завершилась ошибкой.';
			$this->log( 'error', 'CDEK order create failed.', array_merge( $this->sanitize_response_snapshot( $body ), array( 'error_message' => $message ) ) );
			return new ShipmentCreateResult(
				false,
				error_code: 'cdek_registration_invalid',
				error_message: $message,
				raw_reference: array(
					'http_code' => (int) ( $response['http_code'] ?? 0 ),
					'request' => $this->sanitize_request_snapshot( $request, $payload ),
					'response' => $this->sanitize_response_snapshot( $body ),
					'entity_uuid' => $uuid,
					'request_uuid' => $request_uuid,
					'cdek_number' => $cdek_number,
					'registration_state' => $registration_state,
				)
			);
		}
		$raw = array(
			'http_code' => (int) ( $response['http_code'] ?? 0 ),
			'request' => $this->sanitize_request_snapshot( $request, $payload ),
			'response' => $this->sanitize_response_snapshot( $body ),
			'entity_uuid' => $uuid,
			'request_uuid' => $request_uuid,
			'cdek_number' => $cdek_number,
			'registration_state' => $registration_state,
		);
		$this->log( 'info', 'CDEK order create request accepted.', $this->sanitize_response_snapshot( $body ) );

		return new ShipmentCreateResult(
			true,
			external_id: $uuid,
			tracking_number: $cdek_number,
			backlog_order_id: $request_uuid,
			raw_reference: $raw
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function first_related_cdek_number( array $body ): string {
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		if ( '' !== (string) ( $entity['cdek_number'] ?? '' ) ) {
			return (string) $entity['cdek_number'];
		}
		foreach ( is_array( $body['related_entities'] ?? null ) ? $body['related_entities'] : array() as $row ) {
			if ( is_array( $row ) && '' !== (string) ( $row['cdek_number'] ?? '' ) ) {
				return (string) $row['cdek_number'];
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function request_summary( ShipmentCreateRequest $request, array $payload ): array {
		return array(
			'number' => (string) ( $payload['number'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'tariff_code' => (int) ( $payload['tariff_code'] ?? 0 ),
			'delivery_mode' => (int) ( $request->meta['delivery_mode'] ?? 0 ),
			'shipment_point' => (string) ( $payload['shipment_point'] ?? '' ),
			'delivery_point' => (string) ( $payload['delivery_point'] ?? '' ),
			'package_count' => $this->package_count( $payload ),
			'item_count' => $this->item_count( $payload ),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function sanitize_request_snapshot( ShipmentCreateRequest $request, array $payload ): array {
		return array(
			'type' => (int) ( $payload['type'] ?? 0 ),
			'number' => (string) ( $payload['number'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'tariff_code' => (int) ( $payload['tariff_code'] ?? 0 ),
			'delivery_mode' => (int) ( $request->meta['delivery_mode'] ?? 0 ),
			'shipment_point' => (string) ( $payload['shipment_point'] ?? '' ),
			'delivery_point' => (string) ( $payload['delivery_point'] ?? '' ),
			'from_location' => $this->sanitize_location( is_array( $payload['from_location'] ?? null ) ? $payload['from_location'] : array() ),
			'to_location' => $this->sanitize_location( is_array( $payload['to_location'] ?? null ) ? $payload['to_location'] : array() ),
			'recipient' => $this->sanitize_recipient( is_array( $payload['recipient'] ?? null ) ? $payload['recipient'] : array() ),
			'print' => (string) ( $payload['print'] ?? '' ),
			'package_count' => $this->package_count( $payload ),
			'item_count' => $this->item_count( $payload ),
		);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function sanitize_response_snapshot( array $body ): array {
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$request_row = is_array( $body['requests'][0] ?? null ) ? $body['requests'][0] : array();
		$status = $this->latest_order_status( $entity );

		return array(
			'entity_uuid' => (string) ( $entity['uuid'] ?? $body['entity_uuid'] ?? '' ),
			'cdek_number' => (string) ( $entity['cdek_number'] ?? $body['cdek_number'] ?? $this->first_related_cdek_number( $body ) ),
			'request_uuid' => (string) ( $request_row['request_uuid'] ?? $body['request_uuid'] ?? '' ),
			'request_state' => (string) ( $request_row['state'] ?? $body['registration_state'] ?? '' ),
			'order_status' => (string) ( $status['code'] ?? $body['order_status'] ?? '' ),
			'errors' => $this->safe_errors( $request_row ),
		);
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<string,mixed>
	 */
	private function sanitize_location( array $location ): array {
		if ( array() === $location ) {
			return array();
		}

		return array(
			'code' => (string) ( $location['code'] ?? '' ),
			'city' => (string) ( $location['city'] ?? '' ),
			'postal_code' => (string) ( $location['postal_code'] ?? '' ),
			'address' => isset( $location['address'] ) ? '[redacted]' : '',
		);
	}

	/**
	 * @param array<string,mixed> $recipient
	 * @return array<string,mixed>
	 */
	private function sanitize_recipient( array $recipient ): array {
		if ( array() === $recipient ) {
			return array();
		}

		return array(
			'name' => isset( $recipient['name'] ) ? '[redacted]' : '',
			'phones' => array_map(
				static fn ( mixed $_row ): array => array( 'number' => '[redacted]' ),
				is_array( $recipient['phones'] ?? null ) ? $recipient['phones'] : array()
			),
			'email' => isset( $recipient['email'] ) ? '[redacted]' : '',
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function package_count( array $payload ): int {
		return count( is_array( $payload['packages'] ?? null ) ? $payload['packages'] : array() );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function item_count( array $payload ): int {
		$count = 0;
		foreach ( is_array( $payload['packages'] ?? null ) ? $payload['packages'] : array() as $package ) {
			$count += is_array( $package ) && is_array( $package['items'] ?? null ) ? count( $package['items'] ) : 0;
		}

		return $count;
	}

	/**
	 * @param array<string,mixed> $entity
	 * @return array<string,mixed>
	 */
	private function latest_order_status( array $entity ): array {
		$statuses = is_array( $entity['statuses'] ?? null ) ? $entity['statuses'] : array();
		$last = end( $statuses );

		return is_array( $last ) ? $last : array();
	}

	/**
	 * @param array<string,mixed> $request_row
	 * @return array<int,array<string,string>>
	 */
	private function safe_errors( array $request_row ): array {
		$errors = array();
		foreach ( is_array( $request_row['errors'] ?? null ) ? $request_row['errors'] : array() as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = array(
					'code' => (string) ( $error['code'] ?? '' ),
					'message' => (string) ( $error['message'] ?? '' ),
				);
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $request_row
	 */
	private function errors_message( array $request_row ): string {
		$messages = array();
		foreach ( $this->safe_errors( $request_row ) as $error ) {
			$message = trim( (string) ( $error['message'] ?: $error['code'] ) );
			if ( '' !== $message ) {
				$messages[] = $message;
			}
		}

		return implode( "\n", $messages );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$method = method_exists( $this->logger, $level ) ? $level : 'debug';
		$this->logger->{$method}( $message, $context );
	}
}
