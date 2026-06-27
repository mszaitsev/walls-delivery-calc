<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMappingV2Runner {
	private const STATE_OPTION = 'wdc_yandex_location_mapping_v2_runner_state';
	private const BATCH_SIZE = 10;

	public function __construct( private YandexLocationMapperV2Service $mapper, private YandexLocationMappingV2Repository $repository ) {
	}

	/** @return array<string,mixed> */
	public function start(): array {
		$this->repository->truncate();
		$state = $this->base_state( 'mapping' );
		$state['session_id'] = sha1( uniqid( 'yandex-location-mapping-v2-', true ) );
		$state['started_at'] = $this->now();
		$state['updated_at'] = $state['started_at'];
		$state['message'] = 'Строим offline сопоставление geoId v2 с WDC locations.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step(): array {
		$state = $this->current_state();
		if ( 'mapping' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}
		try {
			$result = $this->mapper->build_all( (int) $state['batch_size'], (int) $state['offset'] );
			$state['offset'] = (int) $result['next_offset'];
			$state['processed'] = (int) $state['processed'] + (int) $result['processed_geo_ids'];
			$state['mapped'] = (int) $state['mapped'] + (int) $result['mapped'];
			$state['needs_review'] = (int) $state['needs_review'] + (int) $result['needs_review'];
			$state['no_match'] = (int) $state['no_match'] + (int) $result['no_match'];
			$state['errors'] = (int) $state['errors'] + (int) $result['errors'];
			$state['updated_at'] = $this->now();
			$state['memory_peak_mb'] = $this->memory_peak_mb();
			$stats = $this->repository->statistics();
			$state['avg_confidence'] = $stats['avg_confidence'] ?? null;
			$state['avg_distance'] = $stats['avg_distance'] ?? null;
			$state['region_not_mapped'] = $stats['no_match_region_not_mapped'] ?? 0;
			$state['no_locality_match'] = $stats['no_match_no_locality_match'] ?? 0;
			$state['territory_fallback'] = $stats['territory_fallback'] ?? 0;
			if ( ! empty( $result['done'] ) ) {
				$state['status'] = 'done';
				$state['message'] = 'Offline сопоставление geoId v2 завершено.';
			} else {
				$state['message'] = 'Построен очередной batch сопоставлений geoId v2.';
			}
		} catch ( \Throwable $exception ) {
			$state = $this->fail( $state, $exception->getMessage() );
		}
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function pause(): array {
		$state = $this->current_state();
		if ( 'mapping' === (string) ( $state['status'] ?? '' ) ) {
			$state['status'] = 'paused';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Offline сопоставление geoId v2 поставлено на паузу.';
			$this->save_state( $state );
		}

		return $state;
	}

	/** @return array<string,mixed> */
	public function reset(): array {
		$state = $this->base_state( 'idle' );
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function current_state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return $this->base_state( 'idle' );
		}
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) && array() !== $state ? array_merge( $this->base_state( 'idle' ), $state ) : $this->base_state( 'idle' );
	}

	/** @return array<string,mixed> */
	private function base_state( string $status ): array {
		return array(
			'status' => $status,
			'session_id' => '',
			'started_at' => '',
			'updated_at' => $this->now(),
			'offset' => 0,
			'processed' => 0,
			'mapped' => 0,
			'needs_review' => 0,
			'no_match' => 0,
			'errors' => 0,
			'batch_size' => self::BATCH_SIZE,
			'avg_confidence' => null,
			'avg_distance' => null,
			'region_not_mapped' => 0,
			'no_locality_match' => 0,
			'territory_fallback' => 0,
			'message' => '',
			'errors_count' => 0,
			'errors_last' => array(),
			'memory_peak_mb' => $this->memory_peak_mb(),
		);
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		$state['memory_peak_mb'] = (string) ( $state['memory_peak_mb'] ?? $this->memory_peak_mb() );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function fail( array $state, string $message ): array {
		$state['status'] = 'error';
		$state['updated_at'] = $this->now();
		$state['errors_count'] = (int) ( $state['errors_count'] ?? 0 ) + 1;
		$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
		$errors[] = substr( trim( $message ), 0, 500 );
		$state['errors_last'] = array_slice( $errors, -5 );
		$state['message'] = substr( trim( $message ), 0, 500 );
		$state['memory_peak_mb'] = $this->memory_peak_mb();

		return $state;
	}

	private function memory_peak_mb(): string {
		return function_exists( 'memory_get_peak_usage' ) ? (string) round( memory_get_peak_usage( true ) / 1048576, 1 ) : '0';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
