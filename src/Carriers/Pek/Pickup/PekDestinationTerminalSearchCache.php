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
				'points' => array_map( static fn( PickupPoint $point ): array => $point->to_array(), $points ),
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
		unset( $metadata['credentials'], $metadata['Authorization'], $metadata['raw_response'], $metadata['request_args'] );

		return $metadata;
	}
}
