<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentRepository {
	public const REQUEST_ID_META_KEY = '_wdc_yandex_delivery_request_id';
	public const REGISTRATION_SEQUENCE_META_KEY = '_wdc_yandex_delivery_registration_sequence';
	private const REGISTRATION_LOCK_META_KEY = '_wdc_yandex_delivery_registration_lock';
	private const REGISTRATION_LOCK_TTL_SECONDS = 120;

	public function __construct( private OrderShipmentRepository $repository ) {}

	/** @return array<string,mixed> */
	public function find( object $order ): array { return $this->repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ); }

	/** @param array<string,mixed> $shipment */
	public function save( object $order, array $shipment ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$shipments = $this->repository->all_for_order( $order );
		$shipments[ YandexDeliverySettings::CARRIER_KEY ] = $shipment;
		$order->update_meta_data( OrderShipmentRepository::META_KEY, $shipments );
		$order->update_meta_data( OrderShipmentRepository::LAST_ERROR_META_KEY, '' );
		$this->sync_lookup_meta( $order, $shipment );
		$order->save();
	}

	/** @param array<string,mixed> $shipment */
	public function sync_lookup_meta( object $order, array $shipment ): void {
		$request_id = trim( (string) ( $shipment['yandex_request_id'] ?? $shipment['request_id'] ?? $shipment['external_id'] ?? '' ) );
		if ( '' !== $request_id && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, $request_id );
		}
	}

	public function delete( object $order ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$shipments = $this->repository->all_for_order( $order );
		unset( $shipments[ YandexDeliverySettings::CARRIER_KEY ] );
		$order->update_meta_data( OrderShipmentRepository::META_KEY, $shipments );
		$order->update_meta_data( OrderShipmentRepository::LAST_ERROR_META_KEY, '' );
		if ( method_exists( $order, 'delete_meta_data' ) ) {
			$order->delete_meta_data( self::REQUEST_ID_META_KEY );
		} else {
			$order->update_meta_data( self::REQUEST_ID_META_KEY, '' );
		}
		$order->save();
	}

	public function order_id( object $order ): int { return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0; }

	public function base_operator_request_id( object $order ): string {
		if ( method_exists( $order, 'get_order_number' ) ) {
			return trim( (string) $order->get_order_number() );
		}

		return (string) $this->order_id( $order );
	}

	/** @return array{index:int,operator_request_id:string} */
	public function peek_next_operator_request_id( object $order, string $base = '' ): array {
		$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
		$sequence = $this->registration_sequence( $order, $base );
		$index = max( 0, (int) ( $sequence['last_index'] ?? -1 ) + 1 );

		return array( 'index' => $index, 'operator_request_id' => $this->operator_request_id_for_index( $base, $index ) );
	}

	/** @return array{index:int,operator_request_id:string,started_at:string,lock_token:string} */
	public function reserve_operator_request_id( object $order, string $base, string $now ): array {
		$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
		$lock_token = $this->acquire_registration_lock( $order, $now );
		return $this->reserve_operator_request_id_under_lock( $order, $base, $now, $lock_token );
	}

	/** @return array{index:int,operator_request_id:string,started_at:string,lock_token:string} */
	public function reserve_operator_request_id_under_lock( object $order, string $base, string $now, string $lock_token ): array {
		$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
		$next = $this->peek_next_operator_request_id( $order, $base );
		$sequence = array(
			'last_index' => $next['index'],
			'last_operator_request_id' => $next['operator_request_id'],
			'current_attempt' => array(
				'operator_request_id' => $next['operator_request_id'],
				'sequence_index' => $next['index'],
				'started_at' => $now,
				'order_id' => $this->order_id( $order ),
				'registration_phase' => 'offers_create',
				'lock_token' => $lock_token,
			),
			'updated_at' => $now,
		);
		$this->save_registration_sequence( $order, $sequence );

		return array( 'index' => $next['index'], 'operator_request_id' => $next['operator_request_id'], 'started_at' => $now, 'lock_token' => $lock_token );
	}

	public function release_registration_lock( object $order, string $lock_token ): void {
		$lock = $this->order_meta_array( $order, self::REGISTRATION_LOCK_META_KEY );
		if ( '' === $lock_token || $lock_token !== (string) ( $lock['token'] ?? '' ) ) {
			return;
		}
		if ( method_exists( $order, 'delete_meta_data' ) ) {
			$order->delete_meta_data( self::REGISTRATION_LOCK_META_KEY );
		} elseif ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::REGISTRATION_LOCK_META_KEY, array() );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	public function sync_sequence_from_operator_request_id( object $order, string $operator_request_id, string $base = '', string $now = '' ): void {
		$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
		$parsed = $this->parse_operator_request_id( $operator_request_id, $base );
		if ( null === $parsed ) {
			return;
		}
		$now = '' !== $now ? $now : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$sequence = $this->registration_sequence( $order, $base );
		$current_last = (int) ( $sequence['last_index'] ?? -1 );
		if ( $parsed['index'] <= $current_last ) {
			return;
		}

		$updated = array(
			'last_index' => $parsed['index'],
			'last_operator_request_id' => $parsed['operator_request_id'],
			'updated_at' => $now,
		);
		if ( is_array( $sequence['current_attempt'] ?? null ) && array() !== $sequence['current_attempt'] ) {
			$updated['current_attempt'] = $sequence['current_attempt'];
		}

		$this->save_registration_sequence( $order, $updated );
	}

	/** @return array{index:int,operator_request_id:string}|null */
	public function parse_operator_request_id( string $operator_request_id, string $base ): ?array {
		$operator_request_id = trim( $operator_request_id );
		$base = trim( $base );
		if ( '' === $operator_request_id || '' === $base ) {
			return null;
		}
		if ( $operator_request_id === $base ) {
			return array( 'index' => 0, 'operator_request_id' => $operator_request_id );
		}
		$pattern = '/^' . preg_quote( $base, '/' ) . '\/([1-9][0-9]*)$/';
		if ( 1 === preg_match( $pattern, $operator_request_id, $matches ) ) {
			return array( 'index' => (int) $matches[1], 'operator_request_id' => $operator_request_id );
		}

		return null;
	}

	public function operator_request_id_for_index( string $base, int $index ): string {
		return $index <= 0 ? trim( $base ) : trim( $base ) . '/' . (string) $index;
	}

	public function temporary_barcode_prefix( string $operator_request_id ): string {
		$prefix = preg_replace( '/[^A-Za-z0-9_-]+/', '-', trim( $operator_request_id ) ) ?? '';
		$prefix = trim( $prefix, '-' );

		return '' !== $prefix ? $prefix : 'yandex';
	}

	/** @return array<string,mixed> */
	public function registration_sequence( object $order, string $base = '' ): array {
		$raw_sequence = $this->order_meta_array( $order, self::REGISTRATION_SEQUENCE_META_KEY );
		$sequence = $raw_sequence;
		if ( array() === $sequence ) {
			$index = $this->sequence_index_from_current_shipment( $order, $base );
			if ( $index >= 0 ) {
				$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
				$id = $this->operator_request_id_for_index( $base, $index );
				$sequence = array(
					'last_index' => $index,
					'last_operator_request_id' => $id,
					'updated_at' => '',
				);
			}
		} elseif ( ! array_key_exists( 'last_index', $sequence ) && is_array( $sequence['allocated_ids'] ?? null ) ) {
			$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
			$legacy_index = $this->max_sequence_index_from_legacy_ids( array_values( array_map( 'strval', $sequence['allocated_ids'] ) ), $base );
			if ( $legacy_index >= 0 ) {
				$sequence['last_index'] = $legacy_index;
				$sequence['last_operator_request_id'] = $this->operator_request_id_for_index( $base, $legacy_index );
			}
		}
		$sequence = $this->canonical_sequence( $sequence );
		if ( $sequence !== $raw_sequence && array() !== $raw_sequence && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$this->save_registration_sequence( $order, $sequence );
		}

		return $sequence;
	}

	/** @return array<string,mixed> */
	private function order_meta_array( object $order, string $key ): array {
		$value = method_exists( $order, 'get_meta' ) ? $order->get_meta( $key, true ) : array();

		return is_array( $value ) ? $value : array();
	}

	/** @param array<string,mixed> $sequence */
	private function save_registration_sequence( object $order, array $sequence ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$order->update_meta_data( self::REGISTRATION_SEQUENCE_META_KEY, $this->canonical_sequence( $sequence ) );
		$order->save();
	}

	/**
	 * @param array<string,mixed> $sequence
	 * @return array<string,mixed>
	 */
	private function canonical_sequence( array $sequence ): array {
		$canonical = array(
			'last_index' => (int) ( $sequence['last_index'] ?? -1 ),
			'last_operator_request_id' => (string) ( $sequence['last_operator_request_id'] ?? '' ),
		);
		if ( is_array( $sequence['current_attempt'] ?? null ) && array() !== $sequence['current_attempt'] ) {
			$canonical['current_attempt'] = $sequence['current_attempt'];
		}
		$canonical['updated_at'] = (string) ( $sequence['updated_at'] ?? '' );

		return $canonical;
	}

	/** @param array<int,string> $ids */
	private function max_sequence_index_from_legacy_ids( array $ids, string $base ): int {
		$base = '' !== trim( $base ) ? trim( $base ) : '';
		if ( '' === $base ) {
			return -1;
		}
		$max = -1;
		foreach ( $ids as $id ) {
			$parsed = $this->parse_operator_request_id( $id, $base );
			if ( null !== $parsed ) {
				$max = max( $max, $parsed['index'] );
			}
		}

		return $max;
	}

	private function acquire_registration_lock( object $order, string $now ): string {
		$lock = $this->order_meta_array( $order, self::REGISTRATION_LOCK_META_KEY );
		$expires_at = strtotime( (string) ( $lock['expires_at'] ?? '' ) ) ?: 0;
		if ( '' !== (string) ( $lock['token'] ?? '' ) && $expires_at > time() ) {
			throw new \RuntimeException( 'Регистрация отправления Яндекс уже выполняется. Дождитесь завершения текущей попытки.' );
		}
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$expires = gmdate( 'Y-m-d H:i:s', ( strtotime( $now ) ?: time() ) + self::REGISTRATION_LOCK_TTL_SECONDS );
		if ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( self::REGISTRATION_LOCK_META_KEY, array( 'token' => $token, 'started_at' => $now, 'expires_at' => $expires ) );
			$order->save();
		}

		return $token;
	}

	private function sequence_index_from_current_shipment( object $order, string $base ): int {
		$base = '' !== trim( $base ) ? trim( $base ) : $this->base_operator_request_id( $order );
		$shipment = $this->find( $order );
		foreach ( array( 'yandex_operator_request_id', 'operator_request_id' ) as $key ) {
			$parsed = $this->parse_operator_request_id( (string) ( $shipment[ $key ] ?? '' ), $base );
			if ( null !== $parsed ) {
				return $parsed['index'];
			}
		}
		$snapshot = is_array( $shipment['yandex_request_info_snapshot']['request']['info'] ?? null ) ? $shipment['yandex_request_info_snapshot']['request']['info'] : array();
		$parsed = $this->parse_operator_request_id( (string) ( $snapshot['operator_request_id'] ?? '' ), $base );

		return null !== $parsed ? $parsed['index'] : -1;
	}
}
