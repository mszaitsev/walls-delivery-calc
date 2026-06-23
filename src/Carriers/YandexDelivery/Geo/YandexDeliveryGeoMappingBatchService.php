<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use Throwable;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingBatchService {
	public const OPTION_KEY = 'wdc_yandex_delivery_geo_mapping_batch_state';
	private const DEFAULT_LIMIT = 1000;
	private const DEFAULT_BATCH_SIZE = 25;

	public function __construct(
		private LocationRepository $locations,
		private YandexDeliveryGeoMappingRepository $mappings,
		private YandexDeliveryGeoMappingService $mapping_service
	) {
	}

	/** @return array<string,mixed> */
	public function start( int $limit = self::DEFAULT_LIMIT, int $batch_size = self::DEFAULT_BATCH_SIZE ): array {
		$state = $this->default_state();
		$now = $this->now();
		$state['status'] = 'running';
		$state['session_id'] = $this->session_id();
		$state['started_at'] = $now;
		$state['updated_at'] = $now;
		$state['limit'] = $this->clamp_limit( $limit );
		$state['batch_size'] = $this->clamp_batch_size( $batch_size );
		$state['message'] = 'Batch started.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step(): array {
		$state = $this->current_state();
		if ( 'running' !== (string) ( $state['status'] ?? 'idle' ) ) {
			return $state;
		}

		$remaining = max( 0, (int) $state['limit'] - (int) $state['processed'] - (int) $state['skipped_existing'] );
		if ( 0 === $remaining ) {
			return $this->finish_success( $state );
		}

		$step_limit = min( (int) $state['batch_size'], $remaining );
		try {
			$locations = $this->locations->find_batch_after_id( (int) $state['last_location_id'], $step_limit, 'RU', true );
		} catch ( Throwable $exception ) {
			$state['status'] = 'error';
			$state['updated_at'] = $this->now();
			$state['message'] = $exception->getMessage();
			$state = $this->append_error( $state, 0, $exception->getMessage() );
			$this->save_state( $state );
			return $state;
		}

		if ( array() === $locations ) {
			return $this->finish_success( $state );
		}

		foreach ( $locations as $location ) {
			if ( ! $location instanceof Location || null === $location->id ) {
				continue;
			}
			$location_id = (int) $location->id;
			$state['last_location_id'] = $location_id;

			if ( null !== $this->mappings->find_primary_geo_id( $location_id ) ) {
				++$state['skipped_existing'];
				continue;
			}

			++$state['processed'];
			try {
				$result = $this->mapping_service->detect_for_location_id( $location_id );
				$saved = is_array( $result['mappings'] ?? null ) ? $result['mappings'] : $this->mappings->find_by_location_id( $location_id );
				$state = $this->classify_result( $state, $location_id, $result, $saved );
			} catch ( Throwable $exception ) {
				++$state['errors'];
				$state = $this->increment_confidence_bucket( $state, 0.0 );
				$state = $this->append_error( $state, $location_id, $exception->getMessage() );
			}
		}

		$state['updated_at'] = $this->now();
		$state['message'] = 'Step completed.';
		if ( (int) $state['processed'] + (int) $state['skipped_existing'] >= (int) $state['limit'] ) {
			$state['status'] = 'success';
			$state['message'] = 'done';
		}
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function pause(): array {
		$state = $this->current_state();
		$state['status'] = 'paused';
		$state['updated_at'] = $this->now();
		$state['message'] = 'Batch paused.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function reset(): array {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_KEY );
		} else {
			$this->save_state( $this->default_state() );
		}

		return $this->current_state();
	}

	/** @return array<string,mixed> */
	public function current_state(): array {
		$state = get_option( self::OPTION_KEY, array() );
		return $this->normalize_state( is_array( $state ) ? $state : array() );
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $result @param array<int,array<string,mixed>> $saved @return array<string,mixed> */
	private function classify_result( array $state, int $location_id, array $result, array $saved ): array {
		$status = (string) ( $result['status'] ?? '' );
		if ( YandexDeliveryGeoMappingStatus::ERROR === $status || ( false === (bool) ( $result['success'] ?? true ) && YandexDeliveryGeoMappingStatus::NOT_FOUND !== $status ) ) {
			++$state['errors'];
			$state = $this->increment_confidence_bucket( $state, $this->best_confidence( $saved ) );
			return $this->append_error( $state, $location_id, (string) ( $result['message'] ?? 'Yandex geo detect error.' ) );
		}

		$has_primary = false;
		foreach ( $saved as $row ) {
			$has_primary = $has_primary || ! empty( $row['is_primary'] );
		}
		if ( $has_primary ) {
			++$state['mapped'];
		} elseif ( array() !== $saved && YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $status ) {
			++$state['ambiguous'];
		} elseif ( YandexDeliveryGeoMappingStatus::NOT_FOUND === $status ) {
			++$state['not_found'];
		} else {
			++$state['errors'];
			$state = $this->append_error( $state, $location_id, (string) ( $result['message'] ?? 'Unable to classify geo mapping result.' ) );
		}

		return $this->increment_confidence_bucket( $state, $this->best_confidence( $saved ) );
	}

	/** @param array<int,array<string,mixed>> $saved */
	private function best_confidence( array $saved ): float {
		$best = 0.0;
		foreach ( $saved as $row ) {
			if ( is_numeric( $row['confidence'] ?? null ) ) {
				$best = max( $best, (float) $row['confidence'] );
			}
		}

		return $best;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function increment_confidence_bucket( array $state, float $confidence ): array {
		$bucket = match ( true ) {
			$confidence >= 100.0 => '100',
			$confidence >= 95.0 => '95_99',
			$confidence >= 80.0 => '80_94',
			$confidence >= 60.0 => '60_79',
			$confidence >= 40.0 => '40_59',
			$confidence > 0.0 => '1_39',
			default => '0',
		};
		$state['confidence_buckets'][ $bucket ] = (int) ( $state['confidence_buckets'][ $bucket ] ?? 0 ) + 1;

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function append_error( array $state, int $location_id, string $message ): array {
		$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
		$errors[] = array(
			'location_id' => $location_id,
			'message' => function_exists( 'mb_substr' ) ? mb_substr( trim( $message ), 0, 500 ) : substr( trim( $message ), 0, 500 ),
		);
		$state['errors_last'] = array_slice( $errors, -10 );

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function finish_success( array $state ): array {
		$state['status'] = 'success';
		$state['updated_at'] = $this->now();
		$state['message'] = 'done';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	private function default_state(): array {
		return array(
			'status' => 'idle',
			'session_id' => '',
			'started_at' => '',
			'updated_at' => '',
			'last_location_id' => 0,
			'limit' => self::DEFAULT_LIMIT,
			'batch_size' => self::DEFAULT_BATCH_SIZE,
			'processed' => 0,
			'mapped' => 0,
			'ambiguous' => 0,
			'not_found' => 0,
			'errors' => 0,
			'skipped_existing' => 0,
			'confidence_buckets' => array(
				'100' => 0,
				'95_99' => 0,
				'80_94' => 0,
				'60_79' => 0,
				'40_59' => 0,
				'1_39' => 0,
				'0' => 0,
			),
			'message' => '',
			'errors_last' => array(),
		);
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function normalize_state( array $state ): array {
		$normalized = array_replace_recursive( $this->default_state(), $state );
		$normalized['status'] = in_array( (string) $normalized['status'], array( 'idle', 'running', 'paused', 'success', 'error' ), true ) ? (string) $normalized['status'] : 'idle';
		foreach ( array( 'last_location_id', 'limit', 'batch_size', 'processed', 'mapped', 'ambiguous', 'not_found', 'errors', 'skipped_existing' ) as $key ) {
			$normalized[ $key ] = max( 0, (int) $normalized[ $key ] );
		}
		$normalized['limit'] = $this->clamp_limit( (int) $normalized['limit'] );
		$normalized['batch_size'] = $this->clamp_batch_size( (int) $normalized['batch_size'] );
		foreach ( array_keys( $this->default_state()['confidence_buckets'] ) as $bucket ) {
			$normalized['confidence_buckets'][ $bucket ] = max( 0, (int) ( $normalized['confidence_buckets'][ $bucket ] ?? 0 ) );
		}
		$normalized['errors_last'] = array_slice( is_array( $normalized['errors_last'] ) ? $normalized['errors_last'] : array(), -10 );

		return $normalized;
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		update_option( self::OPTION_KEY, $this->normalize_state( $state ), false );
	}

	private function clamp_limit( int $limit ): int {
		return max( 1, min( 10000, $limit ) );
	}

	private function clamp_batch_size( int $batch_size ): int {
		return max( 1, min( 100, $batch_size ) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function session_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return bin2hex( random_bytes( 16 ) );
	}
}