<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;

use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryPickupProgressiveQueryService {
	private const CANDIDATE_BATCH = 2500;
	private const DEFAULT_CHUNK = 1500;
	private const MAX_CHUNK = 2000;

	public function __construct(
		private OzonDeliveryPickupRepository $repository,
		private OzonDeliveryPickupPointProvider $provider
	) {}

	/** @param array{west:float,south:float,east:float,north:float}|null $viewport @return array<string,mixed> */
	public function start( CarrierPickupPointQuery $query, ?array $viewport, int $chunk_size = self::DEFAULT_CHUNK ): array {
		$this->assert_query( $query );
		$generation_id = $this->repository->active_generation_id();
		if ( $generation_id <= 0 ) {
			return $this->result( array(), '', '', 0, true );
		}
		$viewport = $this->normalize_viewport( $viewport );
		$chunk_size = $this->chunk_size( $chunk_size );
		$total = 0;
		$viewport_first = array();
		$viewport_cursor = null;
		$nearest_fallback = array();
		$fallback_cursor = null;
		$after = null;
		do {
			$rows = $this->candidates( $generation_id, $query, $viewport, $after );
			foreach ( $rows as $row ) {
				$after = $this->row_cursor( $row );
				$point = $this->provider->eligible_point_from_row( $row, $query );
				if ( ! $point instanceof PickupPoint ) {
					continue;
				}
				++$total;
				if ( 0 === (int) ( $row['wdc_phase'] ?? 1 ) && count( $viewport_first ) < $chunk_size ) {
					$viewport_first[] = $point;
					$viewport_cursor = $after;
				} elseif ( 1 === (int) ( $row['wdc_phase'] ?? 1 ) && count( $nearest_fallback ) < $chunk_size ) {
					$nearest_fallback[] = $point;
					$fallback_cursor = $after;
				}
			}
		} while ( count( $rows ) === self::CANDIDATE_BATCH );

		$first = array() !== $viewport_first ? $viewport_first : $nearest_fallback;
		$first_cursor = array() !== $viewport_first ? $viewport_cursor : $fallback_cursor;
		$fingerprint = $this->query_fingerprint( $query );
		$dataset_id = hash( 'sha256', (string) wp_json_encode( array( $generation_id, $fingerprint, $viewport, $total ) ) );
		$dataset_payload = array( 'v' => 1, 'id' => $dataset_id, 'generation' => $generation_id, 'query' => $fingerprint, 'viewport' => $viewport, 'total' => $total );
		$dataset = $this->encode_token( $dataset_payload );
		$complete = count( $first ) >= $total;
		$cursor = $complete || null === $first_cursor ? '' : $this->encode_token( array_merge( $dataset_payload, array( 'after' => $first_cursor, 'loaded' => count( $first ) ) ) );
		return $this->result( $first, $dataset, $cursor, $total, $complete );
	}

	/** @return array<string,mixed> */
	public function next( CarrierPickupPointQuery $query, string $dataset, string $cursor, int $chunk_size = self::DEFAULT_CHUNK ): array {
		$this->assert_query( $query );
		$dataset_payload = $this->decode_token( $dataset );
		$cursor_payload = $this->decode_token( $cursor );
		if ( ! $this->compatible_payloads( $query, $dataset_payload, $cursor_payload ) ) {
			throw new \RuntimeException( 'ozon_progressive_dataset_mismatch' );
		}
		$generation_id = (int) $dataset_payload['generation'];
		if ( $generation_id !== $this->repository->active_generation_id() ) {
			throw new \RuntimeException( 'ozon_progressive_generation_changed' );
		}
		$viewport = $this->normalize_viewport( is_array( $dataset_payload['viewport'] ?? null ) ? $dataset_payload['viewport'] : null );
		$after = is_array( $cursor_payload['after'] ?? null ) ? $cursor_payload['after'] : null;
		$loaded = max( 0, (int) ( $cursor_payload['loaded'] ?? 0 ) );
		$total = max( 0, (int) ( $dataset_payload['total'] ?? 0 ) );
		$points = array();
		$chunk_size = $this->chunk_size( $chunk_size );
		$exhausted = false;
		do {
			$rows = $this->candidates( $generation_id, $query, $viewport, $after );
			if ( array() === $rows ) {
				$exhausted = true;
				break;
			}
			foreach ( $rows as $row ) {
				$after = $this->row_cursor( $row );
				$point = $this->provider->eligible_point_from_row( $row, $query );
				if ( $point instanceof PickupPoint ) {
					$points[] = $point;
					if ( count( $points ) >= $chunk_size ) {
						break 2;
					}
				}
			}
			$exhausted = count( $rows ) < self::CANDIDATE_BATCH;
		} while ( ! $exhausted );

		$new_loaded = min( $total, $loaded + count( $points ) );
		$complete = $exhausted || $new_loaded >= $total;
		$next_cursor = $complete || null === $after ? '' : $this->encode_token( array_merge( $dataset_payload, array( 'after' => $after, 'loaded' => $new_loaded ) ) );
		return $this->result( $points, $dataset, $next_cursor, $total, $complete, $new_loaded );
	}

	/** @return array<int,array<string,mixed>> */
	private function candidates( int $generation_id, CarrierPickupPointQuery $query, ?array $viewport, ?array $after ): array {
		$latitude_delta = rad2deg( $query->radius_km / 6371.0088 );
		$longitude_delta = rad2deg( $query->radius_km / ( 6371.0088 * max( 0.01, abs( cos( deg2rad( (float) $query->latitude ) ) ) ) ) );
		return $this->repository->find_generation_candidates_ordered( $generation_id, (float) $query->latitude, (float) $query->longitude, $latitude_delta, $longitude_delta, $viewport, $after, self::CANDIDATE_BATCH );
	}

	/** @param array<string,mixed> $row @return array{phase:int,distance:string,point_id:int} */
	private function row_cursor( array $row ): array {
		return array( 'phase' => (int) ( $row['wdc_phase'] ?? 1 ), 'distance' => (string) ( $row['wdc_distance'] ?? '0' ), 'point_id' => (int) ( $row['point_id'] ?? 0 ) );
	}

	/** @param array<int,PickupPoint> $points @return array<string,mixed> */
	private function result( array $points, string $dataset, string $cursor, int $total, bool $complete, ?int $loaded = null ): array {
		return array( 'points' => $points, 'loaded' => null === $loaded ? count( $points ) : $loaded, 'total' => $total, 'complete' => $complete, 'cursor' => $cursor, 'dataset' => $dataset );
	}

	private function assert_query( CarrierPickupPointQuery $query ): void {
		if ( array() !== $query->validate() || 'ozon_delivery' !== $query->normalized_carrier_key() || 'RU' !== $query->normalized_country_code() || null === $query->latitude || null === $query->longitude ) {
			throw new \InvalidArgumentException( 'ozon_progressive_query_invalid' );
		}
	}

	/** @param array<string,mixed>|null $viewport @return array{west:float,south:float,east:float,north:float}|null */
	private function normalize_viewport( ?array $viewport ): ?array {
		if ( ! is_array( $viewport ) || ! isset( $viewport['west'], $viewport['south'], $viewport['east'], $viewport['north'] ) ) {
			return null;
		}
		$values = array_map( 'floatval', $viewport );
		return $values['west'] <= $values['east'] && $values['south'] <= $values['north'] ? $values : null;
	}

	private function chunk_size( int $value ): int { return max( 1, min( self::MAX_CHUNK, $value ) ); }

	private function query_fingerprint( CarrierPickupPointQuery $query ): string {
		return hash( 'sha256', (string) wp_json_encode( array(
			'carrier_key' => $query->normalized_carrier_key(),
			'location_id' => $query->location_id,
			'country_code' => $query->normalized_country_code(),
			'fallback_address' => $query->fallback_address,
			'latitude' => $query->latitude,
			'longitude' => $query->longitude,
			'cargo' => $query->cargo->to_array(),
			'purpose' => $query->purpose,
			'radius_km' => $query->radius_km,
			'service_key' => $query->normalized_service_key(),
		) ) );
	}

	/** @param array<string,mixed> $dataset @param array<string,mixed> $cursor */
	private function compatible_payloads( CarrierPickupPointQuery $query, array $dataset, array $cursor ): bool {
		return 1 === (int) ( $dataset['v'] ?? 0 )
			&& hash_equals( (string) ( $dataset['id'] ?? '' ), (string) ( $cursor['id'] ?? '' ) )
			&& (int) ( $dataset['generation'] ?? 0 ) === (int) ( $cursor['generation'] ?? -1 )
			&& hash_equals( (string) ( $dataset['query'] ?? '' ), $this->query_fingerprint( $query ) )
			&& hash_equals( (string) ( $dataset['query'] ?? '' ), (string) ( $cursor['query'] ?? '' ) )
			&& (int) ( $dataset['total'] ?? -1 ) === (int) ( $cursor['total'] ?? -2 );
	}

	/** @param array<string,mixed> $payload */
	private function encode_token( array $payload ): string {
		$json = (string) wp_json_encode( $payload );
		$body = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$signature = hash_hmac( 'sha256', $body, $this->signing_key() );
		return $body . '.' . $signature;
	}

	/** @return array<string,mixed> */
	private function decode_token( string $token ): array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], $this->signing_key() ), $parts[1] ) ) {
			throw new \RuntimeException( 'ozon_progressive_cursor_invalid' );
		}
		$encoded = strtr( $parts[0], '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$json = base64_decode( $encoded, true );
		$payload = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $payload ) ) {
			throw new \RuntimeException( 'ozon_progressive_cursor_invalid' );
		}
		return $payload;
	}

	private function signing_key(): string {
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'walls-delivery-calc-ozon-progressive';
	}
}
