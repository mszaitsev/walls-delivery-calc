<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCreationAttemptService {
	public const META_KEY = '_wdc_shipment_creation_attempts';
	public const STATE_ACTIVE = 'active';
	public const STATE_PENDING = 'pending';
	public const STATE_TERMINAL = 'terminal';
	public const STATE_ERROR_MESSAGE = 'Не удалось восстановить состояние попытки создания отправления. Обновите данные заказа перед повторной попыткой.';

	/** @var callable():string */
	private $uuid_factory;

	/** @param callable():string|null $uuid_factory */
	public function __construct(
		private OrderShipmentRepository $repository,
		?callable $uuid_factory = null
	) {
		$this->uuid_factory = $uuid_factory ?? fn(): string => $this->generate_uuid_v4();
	}

	public function reserve_for_request( object $order, ShipmentCreateRequest $request ): ShipmentCreateRequest {
		$existing_shipment = $this->repository->find_by_carrier( $order, $request->carrier_key );
		if ( array() !== $existing_shipment && $this->repository->has_created_for_carrier( $order, $request->carrier_key ) && ! $this->valid_uuid( $existing_shipment['creation_attempt_id'] ?? null ) ) {
			return $request;
		}

		$scope = $this->scope_key( $request->carrier_key, $this->service_key( $request ) );
		$records = $this->records( $order );
		$record = $records[ $scope ] ?? null;
		$new = false;

		if ( null === $record ) {
			$record = $this->new_record( 1, self::STATE_ACTIVE );
			$new = true;
		} else {
			$record = $this->normalize_record( $record );
			if ( self::STATE_TERMINAL === $record['state'] ) {
				$record = $this->new_record( $record['generation'] + 1, self::STATE_ACTIVE );
				$new = true;
			}
		}

		$record['state'] = self::STATE_ACTIVE === $record['state'] ? self::STATE_ACTIVE : $record['state'];
		$record['updated_at'] = $this->now();
		$records[ $scope ] = $record;
		$this->save_records( $order, $records );

		return $this->with_attempt_meta( $request, $record, $new );
	}

	public function mark_pending( object $order, ShipmentCreateRequest $request ): void {
		$this->mark_state( $order, $request->carrier_key, $this->service_key( $request ), self::STATE_PENDING, $this->attempt_id_from_request( $request ) );
	}

	public function mark_active( object $order, ShipmentCreateRequest $request ): void {
		$this->mark_state( $order, $request->carrier_key, $this->service_key( $request ), self::STATE_ACTIVE, $this->attempt_id_from_request( $request ) );
	}

	public function mark_terminal_for_shipment( object $order, string $carrier_key, array $shipment, string $reason = 'terminal' ): void {
		unset( $reason );
		$attempt_id = $shipment['creation_attempt_id'] ?? null;
		if ( ! $this->valid_uuid( $attempt_id ) ) {
			return;
		}
		$service_key = trim( (string) ( $shipment['service_key'] ?? '' ) );
		if ( '' === $service_key ) {
			$service_key = trim( (string) ( $shipment['rate_id'] ?? '' ) );
		}
		if ( '' === $service_key ) {
			return;
		}

		$this->mark_state( $order, $carrier_key, $service_key, self::STATE_TERMINAL, (string) $attempt_id );
	}

	public function validate_attempt_id( mixed $value ): bool {
		return $this->valid_uuid( $value );
	}

	/** @return callable():void|null */
	public function acquire_create_lock( object $order, ShipmentCreateRequest $request ): ?callable {
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : $request->order_id;
		$key = 'wdc_shipment_create_lock_' . hash( 'sha256', $order_id . '|' . $this->scope_key( $request->carrier_key, $this->service_key( $request ) ) );
		$token = $this->generate_uuid_v4();
		$expires = time() + 300;
		$value = array( 'token' => $token, 'expires' => $expires );

		if ( function_exists( 'add_option' ) && function_exists( 'delete_option' ) ) {
			if ( add_option( $key, $value, '', 'no' ) ) {
				return static function () use ( $key ): void {
					delete_option( $key );
				};
			}
			if ( function_exists( 'get_option' ) ) {
				$existing = get_option( $key, array() );
				$existing_expires = is_array( $existing ) ? (int) ( $existing['expires'] ?? 0 ) : 0;
				if ( $existing_expires > 0 && $existing_expires < time() ) {
					delete_option( $key );
					if ( add_option( $key, $value, '', 'no' ) ) {
						return static function () use ( $key ): void {
							delete_option( $key );
						};
					}
				}
			}

			return null;
		}

		static $locks = array();
		if ( isset( $locks[ $key ] ) && (int) $locks[ $key ] >= time() ) {
			return null;
		}
		$locks[ $key ] = $expires;

		return static function () use ( &$locks, $key ): void {
			unset( $locks[ $key ] );
		};
	}

	/** @return array<string,mixed> */
	public function current_record_for_request( object $order, ShipmentCreateRequest $request ): array {
		$records = $this->records( $order );
		$record = $records[ $this->scope_key( $request->carrier_key, $this->service_key( $request ) ) ] ?? null;
		if ( null === $record ) {
			return array();
		}

		return $this->normalize_record( $record );
	}

	/** @return array<string,array<string,mixed>> */
	private function records( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( self::META_KEY, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $scope => $record ) {
			if ( ! is_string( $scope ) || ! is_array( $record ) || array_is_list( $record ) ) {
				throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
			}
			$result[ $scope ] = $this->normalize_record( $record );
		}

		return $result;
	}

	/** @param array<string,array<string,mixed>> $records */
	private function save_records( object $order, array $records ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return;
		}
		$order->update_meta_data( self::META_KEY, $records );
		$order->save();
	}

	/** @param array<string,mixed> $record @return array{current_attempt_id:string,generation:int,state:string,updated_at:string} */
	private function normalize_record( array $record ): array {
		$attempt_id = $record['current_attempt_id'] ?? '';
		if ( ! $this->valid_uuid( $attempt_id ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}
		$generation = $record['generation'] ?? null;
		if ( ! is_int( $generation ) || $generation < 1 ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}
		$state = $record['state'] ?? '';
		if ( ! is_string( $state ) || ! in_array( $state, array( self::STATE_ACTIVE, self::STATE_PENDING, self::STATE_TERMINAL ), true ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}
		$updated_at = $record['updated_at'] ?? '';
		if ( ! is_string( $updated_at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated_at ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}

		return array(
			'current_attempt_id' => strtolower( $attempt_id ),
			'generation' => $generation,
			'state' => $state,
			'updated_at' => $updated_at,
		);
	}

	/** @return array{current_attempt_id:string,generation:int,state:string,updated_at:string} */
	private function new_record( int $generation, string $state ): array {
		$id = ( $this->uuid_factory )();
		if ( ! $this->valid_uuid( $id ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}

		return array(
			'current_attempt_id' => strtolower( $id ),
			'generation' => $generation,
			'state' => $state,
			'updated_at' => $this->now(),
		);
	}

	private function mark_state( object $order, string $carrier_key, string $service_key, string $state, string $attempt_id ): void {
		if ( ! $this->valid_uuid( $attempt_id ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}
		$scope = $this->scope_key( $carrier_key, $service_key );
		$records = $this->records( $order );
		$record = $records[ $scope ] ?? null;
		if ( null === $record ) {
			$record = array(
				'current_attempt_id' => strtolower( $attempt_id ),
				'generation' => 1,
				'state' => $state,
				'updated_at' => $this->now(),
			);
		} else {
			$record = $this->normalize_record( $record );
			if ( strtolower( $attempt_id ) !== $record['current_attempt_id'] ) {
				throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
			}
			$record['state'] = $state;
			$record['updated_at'] = $this->now();
		}
		$records[ $scope ] = $record;
		$this->save_records( $order, $records );
	}

	/** @param array<string,mixed> $record */
	private function with_attempt_meta( ShipmentCreateRequest $request, array $record, bool $new ): ShipmentCreateRequest {
		$meta = $request->meta;
		$meta['creation_attempt_id'] = $record['current_attempt_id'];
		$meta['creation_attempt_generation'] = $record['generation'];
		$meta['creation_attempt_state'] = $record['state'];
		$meta['creation_attempt_new'] = $new;
		$meta['creation_attempt_reused'] = ! $new;

		return new ShipmentCreateRequest(
			$request->order_id,
			$request->carrier_key,
			$request->delivery_type,
			$request->rate_id,
			$request->recipient_address,
			$request->pickup_point,
			$request->places,
			$request->declared_value,
			$request->insurance_enabled,
			$request->services,
			$request->recipient,
			$meta
		);
	}

	private function attempt_id_from_request( ShipmentCreateRequest $request ): string {
		$value = $request->meta['creation_attempt_id'] ?? '';
		if ( ! $this->valid_uuid( $value ) ) {
			throw new \RuntimeException( self::STATE_ERROR_MESSAGE );
		}

		return strtolower( (string) $value );
	}

	private function service_key( ShipmentCreateRequest $request ): string {
		$service_key = trim( (string) ( $request->meta['service_key'] ?? $request->rate_id ) );

		return '' !== $service_key ? $service_key : $request->rate_id;
	}

	private function scope_key( string $carrier_key, string $service_key ): string {
		return $this->sanitize_scope_part( $carrier_key ) . '|' . $this->sanitize_scope_part( $service_key );
	}

	private function sanitize_scope_part( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
	}

	private function valid_uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	private function generate_uuid_v4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = (string) wp_generate_uuid4();
			if ( $this->valid_uuid( $uuid ) ) {
				return strtolower( $uuid );
			}
		}
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $bytes );

		return sprintf( '%s-%s-%s-%s-%s', substr( $hex, 0, 8 ), substr( $hex, 8, 4 ), substr( $hex, 12, 4 ), substr( $hex, 16, 4 ), substr( $hex, 20, 12 ) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
