<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class PekDestinationTerminalSearchCache {
	private const FORMAT_VERSION = 1;

	public function ttl(): int {
		return 600;
	}

	/** @param array<string,mixed> $metadata @param array<int,PickupPoint> $points */
	public function save( string $fingerprint, array $metadata, array $points, int $ttl = 600 ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient(
			$this->key( $fingerprint ),
			array(
				'format_version' => self::FORMAT_VERSION,
				'fingerprint' => $fingerprint,
				'metadata' => $this->sanitize_metadata( $metadata ),
				'points' => array_map( fn( PickupPoint $point ): array => $this->safe_point_array( $point ), $points ),
			),
			max( 60, min( 3600, $ttl ) )
		);
	}

	/** @return array{hit:bool,metadata:array<string,mixed>,points:array<int,PickupPoint>} */
	public function get( string $fingerprint ): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array( 'hit' => false, 'metadata' => array(), 'points' => array() );
		}
		$value = get_transient( $this->key( $fingerprint ) );
		if ( ! is_array( $value ) ) {
			return array( 'hit' => false, 'metadata' => array(), 'points' => array() );
		}
		if (
			self::FORMAT_VERSION !== (int) ( $value['format_version'] ?? 0 )
			|| $fingerprint !== (string) ( $value['fingerprint'] ?? '' )
			|| ! is_array( $value['metadata'] ?? null )
			|| ! array_key_exists( 'points', $value )
			|| ! is_array( $value['points'] )
		) {
			$this->delete( $fingerprint );
			return array( 'hit' => false, 'metadata' => array(), 'points' => array() );
		}
		$points = array();
		foreach ( $value['points'] as $point ) {
			if ( ! is_array( $point ) ) {
				$this->delete( $fingerprint );
				return array( 'hit' => false, 'metadata' => array(), 'points' => array() );
			}
			$pickup_point = PickupPoint::from_array( $point );
			if ( PekSettings::CARRIER_KEY !== $pickup_point->carrier_key || array() !== $pickup_point->validate() ) {
				$this->delete( $fingerprint );
				return array( 'hit' => false, 'metadata' => array(), 'points' => array() );
			}
			$points[] = $pickup_point;
		}

		return array( 'hit' => true, 'metadata' => $value['metadata'], 'points' => $points );
	}

	public function fingerprint( array $parts ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $parts ) : json_encode( $parts, JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', false !== $json ? $json : serialize( $parts ) );
	}

	private function key( string $fingerprint ): string {
		return 'wdc_pek_destination_terminals_' . preg_replace( '/[^a-f0-9]/i', '', $fingerprint );
	}

	private function delete( string $fingerprint ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->key( $fingerprint ) );
		}
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	private function sanitize_metadata( array $metadata ): array {
		foreach ( array_keys( $metadata ) as $key ) {
			$normalized = strtolower( (string) $key );
			if ( in_array( $normalized, array( 'raw_response', 'response', 'credentials', 'authorization', 'headers', 'request_args', 'body' ), true ) ) {
				unset( $metadata[ $key ] );
				continue;
			}
			if ( is_array( $metadata[ $key ] ) ) {
				$metadata[ $key ] = $this->sanitize_metadata( $metadata[ $key ] );
			}
		}

		return $metadata;
	}

	/** @return array<string,mixed> */
	private function safe_point_array( PickupPoint $point ): array {
		return array(
			'carrier_key' => $point->carrier_key,
			'code' => $point->code,
			'address' => $point->address,
			'city' => $point->city,
			'region' => $point->region,
			'postcode' => $point->postcode,
			'latitude' => $point->latitude,
			'longitude' => $point->longitude,
			'type' => $point->type,
			'work_time' => $point->work_time,
			'comment' => $point->comment,
			'extra_cost' => $point->extra_cost?->to_array(),
			'active' => $point->active,
			'raw_reference' => $this->safe_raw_reference( $point->raw_reference ),
		);
	}

	/** @param array<string,mixed> $raw_reference @return array<string,mixed> */
	private function safe_raw_reference( array $raw_reference ): array {
		$allowed = array(
			'warehouse_id',
			'branch_id',
			'branch_name',
			'division_name',
			'department_type_id',
			'department_type',
			'source',
			'priority',
			'limits',
			'timezone',
			'availability',
			'mapping_state',
			'mapping_precision',
		);
		$safe = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $raw_reference ) ) {
				$safe[ $key ] = $this->sanitize_compact_value( $raw_reference[ $key ] );
			}
		}

		return $safe;
	}

	private function sanitize_compact_value( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$safe = array();
		foreach ( $value as $key => $nested ) {
			$normalized = strtolower( (string) $key );
			if ( in_array( $normalized, array( 'raw_response', 'response', 'credentials', 'authorization', 'headers', 'request_args', 'body', 'api_key', 'login', 'password', 'token' ), true ) ) {
				continue;
			}
			$safe[ is_int( $key ) ? $key : (string) $key ] = $this->sanitize_compact_value( $nested );
		}

		return $safe;
	}
}
