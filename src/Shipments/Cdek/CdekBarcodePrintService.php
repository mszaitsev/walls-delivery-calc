<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class CdekBarcodePrintService {
	private const MAX_ATTEMPTS = 30;
	private const INTERVAL_SECONDS = 2;

	/**
	 * @param callable|null $sleeper
	 */
	public function __construct(
		private OrderShipmentRepository $repository,
		private CdekApiClient $client,
		private $sleeper = null,
		private int $max_attempts = self::MAX_ATTEMPTS,
		private int $interval_seconds = self::INTERVAL_SECONDS
	) {
	}

	/**
	 * @return array{success:bool,message:string,body?:string,content_type?:string,filename?:string,http_code?:int}
	 */
	public function pdf_for_order( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
		if ( ! $this->shipment_can_print( $shipment ) ) {
			return $this->failure( 'ШК СДЭК доступен только для зарегистрированного отправления.' );
		}

		$cdek_number = $this->cdek_number( $shipment );
		$order_uuid = $this->order_uuid( $shipment );
		if ( '' === $cdek_number && '' === $order_uuid ) {
			return $this->failure( 'Не найден номер или UUID заказа СДЭК.' );
		}

		try {
			$print_uuid = $this->create_print_uuid( $cdek_number, $order_uuid );
			$ready_uuid = $this->wait_ready_uuid( $print_uuid, $cdek_number, $order_uuid, true );
			if ( '' === $ready_uuid ) {
				return $this->failure( 'ШК СДЭК еще формируется. Повторите попытку позже.' );
			}

			$pdf = $this->client->downloadBarcodePrintPdf( $ready_uuid );
		} catch ( CdekApiException $exception ) {
			return $this->failure( $exception->getMessage() );
		}

		$body = (string) ( $pdf['body'] ?? '' );
		if ( '' === $body ) {
			return $this->failure( 'СДЭК вернул пустой PDF ШК.' );
		}

		return array(
			'success' => true,
			'message' => '',
			'body' => $body,
			'content_type' => (string) ( $pdf['content_type'] ?? 'application/pdf' ),
			'filename' => 'cdek-barcode-' . ( '' !== $cdek_number ? $cdek_number : $order_uuid ) . '.pdf',
			'http_code' => (int) ( $pdf['http_code'] ?? 200 ),
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function shipment_can_print( array $shipment ): bool {
		if ( array() === $shipment || CdekSettings::CARRIER_KEY !== (string) ( $shipment['carrier_key'] ?? CdekSettings::CARRIER_KEY ) ) {
			return false;
		}
		$status = (string) ( $shipment['status'] ?? '' );
		$order_status = strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );
		if ( in_array( $status, array( 'registration_pending', 'failed', 'removed' ), true ) || in_array( $order_status, array( 'ACCEPTED', 'INVALID', 'REMOVED' ), true ) ) {
			return false;
		}

		return '' !== $this->cdek_number( $shipment ) || '' !== $this->order_uuid( $shipment );
	}

	private function create_print_uuid( string $cdek_number, string $order_uuid ): string {
		$order = array();
		if ( '' !== $cdek_number ) {
			$order['cdek_number'] = $cdek_number;
		} elseif ( '' !== $order_uuid ) {
			$order['order_uuid'] = $order_uuid;
		}

		$response = $this->client->createBarcodePrint(
			array(
				'orders' => array( $order ),
				'copy_count' => 1,
				'format' => 'A6',
				'lang' => 'RUS',
			)
		);
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$uuid = trim( (string) ( $entity['uuid'] ?? '' ) );
		if ( '' === $uuid ) {
			throw new CdekApiException( 'СДЭК не вернул UUID печатной формы ШК.' );
		}

		return $uuid;
	}

	private function wait_ready_uuid( string $uuid, string $cdek_number, string $order_uuid, bool $allow_recreate ): string {
		for ( $attempt = 0; $attempt < max( 1, $this->max_attempts ); $attempt++ ) {
			$this->sleep_before_poll();
			$response = $this->client->getBarcodePrint( $uuid );
			$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
			$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
			$status = $this->latest_print_status( $entity );

			if ( 'READY' === $status ) {
				return $uuid;
			}
			if ( 'INVALID' === $status ) {
				throw new CdekApiException( 'СДЭК не смог сформировать ШК.' );
			}
			if ( 'REMOVED' === $status ) {
				if ( ! $allow_recreate ) {
					throw new CdekApiException( 'Ссылка на ШК СДЭК устарела.' );
				}

				return $this->wait_ready_uuid( $this->create_print_uuid( $cdek_number, $order_uuid ), $cdek_number, $order_uuid, false );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $entity
	 */
	private function latest_print_status( array $entity ): string {
		$statuses = is_array( $entity['statuses'] ?? null ) ? $entity['statuses'] : array();
		$latest = null;
		$latest_ts = null;
		foreach ( $statuses as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = trim( (string) ( $row['date_time'] ?? '' ) );
			$timestamp = '' !== $date ? strtotime( $date ) : false;
			if ( false !== $timestamp && ( null === $latest_ts || $timestamp > $latest_ts ) ) {
				$latest_ts = $timestamp;
				$latest = $row;
			}
		}
		if ( ! is_array( $latest ) ) {
			$last = end( $statuses );
			$latest = is_array( $last ) ? $last : array();
		}

		return strtoupper( (string) ( $latest['code'] ?? '' ) );
	}

	private function sleep_before_poll(): void {
		if ( $this->interval_seconds <= 0 ) {
			return;
		}
		if ( is_callable( $this->sleeper ) ) {
			call_user_func( $this->sleeper, $this->interval_seconds );
			return;
		}
		sleep( $this->interval_seconds );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function cdek_number( array $shipment ): string {
		return trim( (string) ( $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function order_uuid( array $shipment ): string {
		return trim( (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ) );
	}

	/**
	 * @return array{success:false,message:string}
	 */
	private function failure( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
