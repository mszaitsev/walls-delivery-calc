<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Coordinates;

use Throwable;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class LocationCoordinatesDadataBatchUpdater {
	public function __construct(
		private LocationRepository $repository,
		private AddressSuggestionClientInterface $client
	) {
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step( array $job, int $limit = 20 ): array {
		if ( 'running' !== (string) ( $job['phase'] ?? '' ) ) {
			return $job;
		}

		$limit = max( 1, min( 20, $limit ) );
		$priority = (string) ( $job['current_priority'] ?? 'cities' );
		$batch = $this->repository->find_locations_missing_coordinates( $limit, (int) ( $job['last_id'] ?? 0 ), $priority );

		if ( array() === $batch && 'cities' === $priority ) {
			$job['current_priority'] = 'others';
			$job['last_id'] = 0;
			$job['cursor'] = 0;
			$priority = 'others';
			$batch = $this->repository->find_locations_missing_coordinates( $limit, 0, $priority );
		}

		if ( array() === $batch ) {
			$job['phase'] = 'finished';
			$job['status'] = 'finished';
			$job['finished_at'] = current_time( 'mysql' );
			$job['updated_at'] = current_time( 'mysql' );
			$job['current_batch'] = array();
			return $job;
		}

		$job['current_batch'] = array_map( static fn( array $row ): int => (int) ( $row['id'] ?? 0 ), $batch );
		foreach ( $batch as $location ) {
			$location_id = (int) ( $location['id'] ?? 0 );
			$job['last_location_id'] = $location_id;
			$job['last_place_name'] = (string) ( $location['display_name'] ?? $location['place_name'] ?? $location['city_name'] ?? '' );
			$job['last_query'] = $this->query_for_location( $location );
			$job['last_id'] = max( (int) ( $job['last_id'] ?? 0 ), $location_id );
			$job['cursor'] = (int) $job['last_id'];

			if ( $location_id <= 0 || '' === trim( (string) $job['last_query'] ) ) {
				$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + 1;
				continue;
			}

			$job['processed'] = (int) ( $job['processed'] ?? 0 ) + 1;
			try {
				$coordinates = $this->coordinates_for_location( $location );
			} catch ( Throwable $exception ) {
				$job['failed'] = (int) ( $job['failed'] ?? 0 ) + 1;
				$job['errors'] = (int) ( $job['errors'] ?? 0 ) + 1;
				$job['last_error'] = $exception->getMessage();
				continue;
			}

			if ( null === $coordinates ) {
				$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + 1;
				continue;
			}

			if ( $this->repository->update_coordinates( $location_id, $coordinates['lat'], $coordinates['lng'] ) ) {
				$job['updated'] = (int) ( $job['updated'] ?? 0 ) + 1;
			} else {
				$job['failed'] = (int) ( $job['failed'] ?? 0 ) + 1;
				$job['errors'] = (int) ( $job['errors'] ?? 0 ) + 1;
				$job['last_error'] = 'Location coordinates update failed.';
			}
		}

		$job['status'] = (string) ( $job['phase'] ?? 'running' );
		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array{lat:float,lng:float}|null
	 */
	private function coordinates_for_location( array $location ): ?array {
		$query = $this->query_for_location( $location );
		if ( '' === $query ) {
			return null;
		}

		$response = $this->client->suggest( 'city', $query, array( 'country_code' => 'RU' ) );
		if ( empty( $response['success'] ) ) {
			return null;
		}

		foreach ( (array) ( $response['suggestions'] ?? array() ) as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = isset( $suggestion['data'] ) && is_array( $suggestion['data'] ) ? $suggestion['data'] : array();
			$lat = isset( $data['geo_lat'] ) ? (float) $data['geo_lat'] : 0.0;
			$lng = isset( $data['geo_lon'] ) ? (float) $data['geo_lon'] : 0.0;
			if ( $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0 && 0.0 !== $lat && 0.0 !== $lng ) {
				return array(
					'lat' => $lat,
					'lng' => $lng,
				);
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function query_for_location( array $location ): string {
		$parts = array(
			(string) ( $location['postal_code'] ?? '' ),
			(string) ( $location['region_name'] ?? '' ),
			(string) ( $location['district_name'] ?? '' ),
			(string) ( $location['display_name'] ?? '' ),
			(string) ( $location['city_name'] ?? '' ),
			(string) ( $location['settlement_name'] ?? '' ),
			(string) ( $location['place_name'] ?? '' ),
		);
		$parts = array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
		return trim( implode( ', ', $parts ) );
	}
}
