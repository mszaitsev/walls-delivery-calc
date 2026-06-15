<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class CdekBarcodePrintService {
	private const CACHE_TTL_SECONDS = 50 * 60;
	private const STUCK_STATUS_SECONDS = 60;
	private const STUCK_STATUS_CHECKS = 30;

	/**
	 * @param callable|null $sleeper
	 */
	public function __construct(
		private OrderShipmentRepository $repository,
		private CdekApiClient $client,
		private $sleeper = null,
		private int $max_attempts = 1,
		private int $interval_seconds = 0
	) {
	}

	/**
	 * @return array{success:bool,message:string,status?:string,print_uuid?:string,cdek_number?:string}
	 */
	public function prepare_for_order( object $order ): array {
		$context = $this->print_context( $order );
		if ( empty( $context['success'] ) ) {
			return $context;
		}

		$cache_key = (string) $context['cache_key'];
		$cache = $this->cache_get( $cache_key );
		if ( 'READY' === (string) ( $cache['status'] ?? '' ) && '' !== trim( (string) ( $cache['print_uuid'] ?? '' ) ) ) {
			return $this->prepared_status( 'READY', (string) $cache['print_uuid'], (string) $context['cdek_number'] );
		}

		try {
			$print_uuid = trim( (string) ( $cache['print_uuid'] ?? '' ) );
			if ( '' === $print_uuid ) {
				$print_uuid = $this->create_print_uuid( (string) $context['cdek_number'], (string) $context['order_uuid'] );
				$this->cache_set(
					$cache_key,
					array(
						'print_uuid' => $print_uuid,
						'status' => 'ACCEPTED',
						'cdek_number' => (string) $context['cdek_number'],
						'created_at' => time(),
						'last_checked_at' => null,
						'checked_count' => 0,
						'ready_at' => null,
					)
				);
			}

			$status = $this->print_status( $print_uuid );
			$checked_count = max( 0, (int) ( $cache['checked_count'] ?? $cache['attempts'] ?? 0 ) ) + 1;
			$created_at = (int) ( $cache['created_at'] ?? time() );
			if ( 'READY' === $status ) {
				$this->cache_set(
					$cache_key,
					array(
						'print_uuid' => $print_uuid,
						'status' => 'READY',
						'cdek_number' => (string) $context['cdek_number'],
						'created_at' => $created_at,
						'last_checked_at' => time(),
						'checked_count' => $checked_count,
						'ready_at' => time(),
					)
				);

				return $this->prepared_status( 'READY', $print_uuid, (string) $context['cdek_number'] );
			}
			if ( 'INVALID' === $status ) {
				$this->cache_delete( $cache_key );
				return $this->failure( 'СДЭК не смог сформировать этикетку.' );
			}
			if ( 'REMOVED' === $status ) {
				$this->cache_delete( $cache_key );
				$new_uuid = $this->create_print_uuid( (string) $context['cdek_number'], (string) $context['order_uuid'] );
				$this->cache_set(
					$cache_key,
					array(
						'print_uuid' => $new_uuid,
						'status' => 'ACCEPTED',
						'cdek_number' => (string) $context['cdek_number'],
						'created_at' => time(),
						'last_checked_at' => time(),
						'checked_count' => 0,
						'ready_at' => null,
					)
				);

				return $this->prepared_status( 'ACCEPTED', $new_uuid, (string) $context['cdek_number'], true );
			}

			$status = in_array( $status, array( 'ACCEPTED', 'PROCESSING' ), true ) ? $status : 'PROCESSING';
			if ( $this->stuck_pending_print( $status, $created_at, $checked_count ) ) {
				$this->cache_delete( $cache_key );
				$new_uuid = $this->create_print_uuid( (string) $context['cdek_number'], (string) $context['order_uuid'] );
				$this->cache_set(
					$cache_key,
					array(
						'print_uuid' => $new_uuid,
						'status' => 'ACCEPTED',
						'cdek_number' => (string) $context['cdek_number'],
						'created_at' => time(),
						'last_checked_at' => time(),
						'checked_count' => 0,
						'ready_at' => null,
					)
				);

				return $this->prepared_status( 'ACCEPTED', $new_uuid, (string) $context['cdek_number'], true );
			}
			$this->cache_set(
				$cache_key,
				array(
					'print_uuid' => $print_uuid,
					'status' => $status,
					'cdek_number' => (string) $context['cdek_number'],
					'created_at' => $created_at,
					'last_checked_at' => time(),
					'checked_count' => $checked_count,
					'ready_at' => null,
				)
			);

			return $this->prepared_status( $status, $print_uuid, (string) $context['cdek_number'] );
		} catch ( CdekApiException $exception ) {
			return $this->failure( $exception->getMessage() );
		}
	}

	/**
	 * @return array{success:bool,message:string,body?:string,content_type?:string,filename?:string,http_code?:int}
	 */
	public function download_ready_pdf_for_order( object $order ): array {
		$context = $this->print_context( $order );
		if ( empty( $context['success'] ) ) {
			return $context;
		}

		$cache = $this->cache_get( (string) $context['cache_key'] );
		$ready_uuid = trim( (string) ( $cache['print_uuid'] ?? '' ) );
		if ( 'READY' !== (string) ( $cache['status'] ?? '' ) || '' === $ready_uuid ) {
			return $this->failure( 'Этикетка СДЭК еще не готова. Нажмите "Скачать этикетку" еще раз.' );
		}

		try {
			$pdf = $this->client->downloadBarcodePrintPdf( $ready_uuid );
		} catch ( CdekApiException $exception ) {
			return $this->failure( $exception->getMessage() );
		}

		$http_code = (int) ( $pdf['http_code'] ?? 200 );
		if ( $http_code < 200 || $http_code >= 300 ) {
			return $this->failure( 'Не удалось скачать этикетку СДЭК.' );
		}
		$body = (string) ( $pdf['body'] ?? '' );
		if ( '' === $body ) {
			return $this->failure( 'СДЭК вернул пустой PDF этикетки.' );
		}
		$content_type = strtolower( trim( (string) ( $pdf['content_type'] ?? '' ) ) );
		if ( '' !== $content_type && ! str_contains( $content_type, 'application/pdf' ) ) {
			return $this->failure( 'Сервер вернул не PDF-файл этикетки СДЭК.' );
		}

		return array(
			'success' => true,
			'message' => '',
			'body' => $body,
			'content_type' => '' !== $content_type ? (string) ( $pdf['content_type'] ?? 'application/pdf' ) : 'application/pdf',
			'filename' => 'cdek-barcode-' . ( '' !== (string) $context['cdek_number'] ? (string) $context['cdek_number'] : (string) $context['order_uuid'] ) . '.pdf',
			'http_code' => $http_code,
		);
	}

	/**
	 * Backward-compatible wrapper for older callers. It performs at most one status check.
	 *
	 * @return array{success:bool,message:string,body?:string,content_type?:string,filename?:string,http_code?:int}
	 */
	public function pdf_for_order( object $order ): array {
		$prepared = $this->prepare_for_order( $order );
		if ( empty( $prepared['success'] ) ) {
			return $prepared;
		}
		if ( 'READY' !== (string) ( $prepared['status'] ?? '' ) ) {
			return $this->failure( 'Этикетка СДЭК еще формируется. Повторите попытку позже.' );
		}

		return $this->download_ready_pdf_for_order( $order );
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

	private function print_status( string $uuid ): string {
		$response = $this->client->getBarcodePrint( $uuid );
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();

		return $this->latest_print_status( $entity );
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
	 * @return array{success:bool,message:string,shipment?:array<string,mixed>,cdek_number?:string,order_uuid?:string,cache_key?:string}
	 */
	private function print_context( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
		if ( ! $this->shipment_can_print( $shipment ) ) {
			return $this->failure( 'Этикетка СДЭК доступна только для зарегистрированного отправления.' );
		}

		$cdek_number = $this->cdek_number( $shipment );
		$order_uuid = $this->order_uuid( $shipment );
		if ( '' === $cdek_number && '' === $order_uuid ) {
			return $this->failure( 'Не найден номер или UUID заказа СДЭК.' );
		}

		return array(
			'success' => true,
			'message' => '',
			'shipment' => $shipment,
			'cdek_number' => $cdek_number,
			'order_uuid' => $order_uuid,
			'cache_key' => $this->cache_key( $order, $cdek_number, $order_uuid ),
		);
	}

	private function cache_key( object $order, string $cdek_number, string $order_uuid ): string {
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$identity = '' !== $cdek_number ? $cdek_number : $order_uuid;
		$identity = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $identity ) ?? '';
		$identity = '' !== $identity ? $identity : 'unknown';

		return 'wdc_cdek_barcode_' . $order_id . '_' . $identity;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cache_get( string $key ): array {
		$value = function_exists( 'get_transient' ) ? get_transient( $key ) : false;

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function cache_set( string $key, array $value ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, self::CACHE_TTL_SECONDS );
		}
	}

	private function cache_delete( string $key ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}
	}

	private function stuck_pending_print( string $status, int $created_at, int $checked_count ): bool {
		if ( ! in_array( $status, array( 'ACCEPTED', 'PROCESSING' ), true ) ) {
			return false;
		}
		$age = time() - max( 0, $created_at );

		return $age > self::STUCK_STATUS_SECONDS || $checked_count > self::STUCK_STATUS_CHECKS;
	}

	/**
	 * @return array{success:true,message:string,status:string,print_uuid:string,cdek_number:string}
	 */
	private function prepared_status( string $status, string $print_uuid, string $cdek_number, bool $recreated = false ): array {
		$result = array(
			'success' => true,
			'message' => '',
			'status' => $status,
			'print_uuid' => $print_uuid,
			'cdek_number' => $cdek_number,
		);
		if ( $recreated ) {
			$result['recreated'] = true;
		}

		return $result;
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
