<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Coordinates;

use Throwable;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class LocationCoordinatesDadataBatchUpdater {
	private const DADATA_LIMITS_EXHAUSTED_MESSAGE = 'Суточные лимиты DaData исчерпаны. Повторите запуск позже.';

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
		foreach ( array( 'skipped_empty_query', 'skipped_no_dadata_success', 'skipped_no_coordinates', 'skipped_invalid_coordinates' ) as $counter ) {
			$job[ $counter ] = (int) ( $job[ $counter ] ?? 0 );
		}
		$resume_after_id = max( 0, (int) ( $job['resume_after_id'] ?? 0 ) );
		$priority = (string) ( $job['current_priority'] ?? 'cities' );
		$batch = $this->repository->find_locations_missing_coordinates( $limit, max( $resume_after_id, (int) ( $job['last_id'] ?? 0 ) ), $priority );

		if ( array() === $batch && 'cities' === $priority ) {
			$job['current_priority'] = 'others';
			$job['last_id'] = $resume_after_id;
			$job['cursor'] = $resume_after_id;
			$priority = 'others';
			$batch = $this->repository->find_locations_missing_coordinates( $limit, $resume_after_id, $priority );
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
			$job['last_skip_reason'] = '';
			$job['last_dadata_message'] = '';
			$job['last_id'] = max( (int) ( $job['last_id'] ?? 0 ), $location_id );
			$job['cursor'] = (int) $job['last_id'];

			if ( $location_id <= 0 ) {
				$this->skip( $job, 'empty_query', 'Location id is empty.' );
				continue;
			}

			$job['processed'] = (int) ( $job['processed'] ?? 0 ) + 1;
			try {
				$result = $this->coordinates_for_location( $location );
			} catch ( Throwable $exception ) {
				$job['failed'] = (int) ( $job['failed'] ?? 0 ) + 1;
				$job['errors'] = (int) ( $job['errors'] ?? 0 ) + 1;
				$job['last_error'] = $exception->getMessage();
				continue;
			}

			if ( 'skipped' === (string) $result['status'] ) {
				$this->skip( $job, (string) $result['reason'], (string) $result['message'] );
				continue;
			}

			if ( 'stopped' === (string) $result['status'] ) {
				$this->stop_for_dadata_limits( $job, (string) $result['reason'], (string) $result['message'] );
				break;
			}

			if ( $this->repository->update_coordinates( $location_id, (float) $result['lat'], (float) $result['lng'] ) ) {
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
	 * @return array{status:string,lat:?float,lng:?float,reason:string,message:string}
	 */
	private function coordinates_for_location( array $location ): array {
		$query = $this->query_for_location( $location );
		if ( '' === $query ) {
			return $this->skipped_result( 'empty_query', 'Location display_name is empty.' );
		}

		$response = $this->client->suggest( 'city', $query, array( 'country_code' => 'RU' ) );
		if ( empty( $response['success'] ) ) {
			if ( 'dadata_daily_limit_exhausted' === (string) ( $response['error_code'] ?? '' ) ) {
				return $this->stopped_result( 'daily_limit_exhausted', self::DADATA_LIMITS_EXHAUSTED_MESSAGE );
			}

			return $this->skipped_result( 'no_dadata_success', $this->dadata_message( $response ) );
		}

		$suggestions = (array) ( $response['suggestions'] ?? array() );
		if ( array() === $suggestions ) {
			return $this->skipped_result( 'no_coordinates', $this->dadata_message( $response ) );
		}

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = isset( $suggestion['data'] ) && is_array( $suggestion['data'] ) ? $suggestion['data'] : array();
			$lat = isset( $data['geo_lat'] ) ? (float) $data['geo_lat'] : 0.0;
			$lng = isset( $data['geo_lon'] ) ? (float) $data['geo_lon'] : 0.0;
			if ( $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0 && 0.0 !== $lat && 0.0 !== $lng ) {
				return array(
					'status' => 'updated',
					'lat' => $lat,
					'lng' => $lng,
					'reason' => '',
					'message' => '',
				);
			}
		}

		return $this->skipped_result( 'invalid_coordinates', $this->dadata_message( $response ) );
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function query_for_location( array $location ): string {
		$display_name = trim( (string) ( $location['display_name'] ?? '' ) );
		if ( '' === $display_name ) {
			return '';
		}

		$postal_code = trim( (string) ( $location['postal_code'] ?? '' ) );
		return '' !== $postal_code ? $postal_code . ', ' . $display_name : $display_name;
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private function skip( array &$job, string $reason, string $message = '' ): void {
		$reason = in_array( $reason, array( 'empty_query', 'no_dadata_success', 'no_coordinates', 'invalid_coordinates' ), true ) ? $reason : 'no_coordinates';
		$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + 1;
		$job[ 'skipped_' . $reason ] = (int) ( $job[ 'skipped_' . $reason ] ?? 0 ) + 1;
		$job['last_skip_reason'] = $reason;
		$job['last_dadata_message'] = $message;
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private function stop_for_dadata_limits( array &$job, string $reason, string $message ): void {
		$job['phase'] = 'finished';
		$job['status'] = 'finished';
		$job['reason'] = $reason;
		$job['stopped_reason'] = $reason;
		$job['tokens_exhausted'] = true;
		$job['last_error'] = $message;
		$job['message'] = $message;
		$job['last_dadata_message'] = $message;
		$job['finished_at'] = current_time( 'mysql' );
		$job['current_batch'] = array();
	}

	/**
	 * @return array{status:string,lat:?float,lng:?float,reason:string,message:string}
	 */
	private function skipped_result( string $reason, string $message = '' ): array {
		return array(
			'status' => 'skipped',
			'lat' => null,
			'lng' => null,
			'reason' => $reason,
			'message' => $message,
		);
	}

	/**
	 * @return array{status:string,lat:?float,lng:?float,reason:string,message:string}
	 */
	private function stopped_result( string $reason, string $message ): array {
		return array(
			'status' => 'stopped',
			'lat' => null,
			'lng' => null,
			'reason' => $reason,
			'message' => $message,
		);
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function dadata_message( array $response ): string {
		foreach ( array( 'error_message', 'message', 'error_code', 'reason' ) as $key ) {
			if ( isset( $response[ $key ] ) && '' !== trim( (string) $response[ $key ] ) ) {
				return trim( (string) $response[ $key ] );
			}
		}

		return '';
	}
}
