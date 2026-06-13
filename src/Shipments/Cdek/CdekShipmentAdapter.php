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
		$this->log( 'debug', 'CDEK order create payload prepared.', array( 'request' => $payload ) );
		try {
			$response = $this->client->registerOrder( $payload );
		} catch ( CdekApiException $exception ) {
			$this->log( 'error', 'CDEK order create failed.', $exception->details() );
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
		$raw = array(
			'http_code' => (int) ( $response['http_code'] ?? 0 ),
			'request' => $payload,
			'response' => $body,
			'entity_uuid' => $uuid,
			'request_uuid' => $request_uuid,
			'cdek_number' => $cdek_number,
			'registration_state' => (string) ( $request_row['state'] ?? '' ),
		);
		$this->log( 'info', 'CDEK order create request accepted.', array( 'entity_uuid' => $uuid, 'request_uuid' => $request_uuid, 'cdek_number' => $cdek_number ) );

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
