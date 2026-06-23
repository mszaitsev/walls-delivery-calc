<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use Throwable;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoCoverageService {
	public function __construct(
		private YandexDeliveryApiClient $api,
		private YandexDeliveryGeoMappingRepository $mappings,
		private YandexDeliveryGeoCoverageRepository $coverage
	) {
	}

	/** @return array<string,mixed> */
	public function check_location( int $location_id ): array {
		$location_id = max( 0, $location_id );
		$geo_id = $this->mappings->find_primary_geo_id( $location_id );
		$source_status = $this->source_status( $location_id );

		if ( null === $geo_id ) {
			return $this->coverage->save_result(
				array(
					'location_id' => $location_id,
					'yandex_geo_id' => null,
					'source_status' => $source_status,
					'coverage_status' => YandexDeliveryGeoCoverageStatus::NO_GEO_ID,
					'message' => 'No primary Yandex geo_id for this WDC location.',
					'raw_stats_json' => array( 'api_called' => false ),
				)
			);
		}

		$payload = array(
			'type' => 'pickup_point',
			'geo_id' => (int) $geo_id,
		);

		try {
			$response = $this->api->pickupPointsList( $payload );
			$normalized = $this->normalize_response( $response );
			$status = $normalized['pickup_points_count'] > 0 || $normalized['dropoff_points_count'] > 0
				? YandexDeliveryGeoCoverageStatus::COVERED
				: YandexDeliveryGeoCoverageStatus::NOT_COVERED;

			return $this->coverage->save_result(
				array(
					'location_id' => $location_id,
					'yandex_geo_id' => $geo_id,
					'source_status' => $source_status,
					'coverage_status' => $status,
					'pickup_points_count' => $normalized['pickup_points_count'],
					'dropoff_points_count' => $normalized['dropoff_points_count'],
					'operators_json' => $normalized['operators'],
					'sample_points_json' => $normalized['sample_points'],
					'message' => YandexDeliveryGeoCoverageStatus::COVERED === $status ? 'Yandex pickup/dropoff points found.' : 'Yandex returned zero pickup/dropoff points for this geo_id.',
					'raw_stats_json' => $normalized['raw_stats'],
				)
			);
		} catch ( Throwable $exception ) {
			return $this->coverage->save_result(
				array(
					'location_id' => $location_id,
					'yandex_geo_id' => $geo_id,
					'source_status' => $source_status,
					'coverage_status' => YandexDeliveryGeoCoverageStatus::ERROR,
					'message' => substr( trim( $exception->getMessage() ), 0, 500 ),
					'raw_stats_json' => array( 'api_called' => true, 'error_class' => $exception::class ),
				)
			);
		}
	}

	private function source_status( int $location_id ): ?string {
		foreach ( $this->mappings->find_by_location_id( $location_id ) as $row ) {
			if ( is_scalar( $row['status'] ?? null ) ) {
				return (string) $row['status'];
			}
		}

		return null;
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function normalize_response( array $response ): array {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$extracted = $this->extract_points( $body );
		$points = $extracted['points'];
		$operators = array();
		$sample = array();
		$dropoff_count = 0;

		foreach ( $points as $point ) {
			$operator_id = $this->scalar_string( $point['operator_id'] ?? '' );
			if ( '' !== $operator_id ) {
				$operators[ $operator_id ] = ( $operators[ $operator_id ] ?? 0 ) + 1;
			}
			$is_dropoff = ! empty( $point['available_for_dropoff'] );
			if ( $is_dropoff ) {
				++$dropoff_count;
			}
			if ( count( $sample ) < 5 ) {
				$sample[] = array(
					'id' => $this->scalar_string( $point['id'] ?? '' ),
					'operator_id' => $operator_id,
					'dropoff' => $is_dropoff,
					'address' => $this->point_address( $point ),
				);
			}
		}
		ksort( $operators );

		return array(
			'pickup_points_count' => count( $points ),
			'dropoff_points_count' => $dropoff_count,
			'operators' => $operators,
			'sample_points' => $sample,
			'raw_stats' => array(
				'api_called' => true,
				'points_source' => $extracted['source'],
				'response_keys' => array_values( array_filter( array_map( 'strval', array_keys( $body ) ), static fn( string $key ): bool => is_string( $key ) ) ),
				'total_points' => count( $points ),
				'dropoff_points_count' => $dropoff_count,
				'operators_count' => count( $operators ),
			),
		);
	}

	/** @param array<mixed> $body @return array{source:string,points:array<int,array<string,mixed>>} */
	private function extract_points( array $body ): array {
		if ( $this->is_list( $body ) ) {
			return array( 'source' => 'root', 'points' => $this->point_rows( $body ) );
		}
		foreach ( array( 'points', 'pickup_points', 'items', 'result' ) as $key ) {
			if ( ! isset( $body[ $key ] ) || ! is_array( $body[ $key ] ) ) {
				continue;
			}
			$value = $body[ $key ];
			if ( $this->is_list( $value ) ) {
				return array( 'source' => $key, 'points' => $this->point_rows( $value ) );
			}
			foreach ( array( 'points', 'pickup_points', 'items' ) as $nested_key ) {
				if ( isset( $value[ $nested_key ] ) && is_array( $value[ $nested_key ] ) ) {
					return array( 'source' => $key . '.' . $nested_key, 'points' => $this->point_rows( $value[ $nested_key ] ) );
				}
			}
		}

		return array( 'source' => 'none', 'points' => array() );
	}

	/** @param array<mixed> $rows @return array<int,array<string,mixed>> */
	private function point_rows( array $rows ): array {
		return array_values( array_filter( $rows, static fn( mixed $row ): bool => is_array( $row ) ) );
	}

	/** @param array<mixed> $value */
	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** @param array<string,mixed> $point */
	private function point_address( array $point ): string {
		$address = $point['address'] ?? null;
		if ( is_array( $address ) && is_scalar( $address['full_address'] ?? null ) ) {
			return (string) $address['full_address'];
		}
		if ( is_scalar( $address ) ) {
			return (string) $address;
		}

		return '';
	}

	private function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
