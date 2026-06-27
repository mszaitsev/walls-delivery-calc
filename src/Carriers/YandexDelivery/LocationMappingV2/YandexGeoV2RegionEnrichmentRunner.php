<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;

defined( 'ABSPATH' ) || exit;

final class YandexGeoV2RegionEnrichmentRunner {
	private const STATE_OPTION = 'wdc_yandex_geo_v2_region_enrichment_state';
	private const BATCH_SIZE = 10;

	public function __construct( private YandexGeoV2RegionEnrichmentService $service, private YandexDeliveryGeoV2Repository $geo_repository ) {
	}

	/** @return array<string,mixed> */
	public function start(): array {
		$state = $this->base_state( 'enriching_regions' );
		$state['session_id'] = sha1( uniqid( 'yandex-geo-v2-region-enrichment-', true ) );
		$state['started_at'] = $this->now();
		$state['updated_at'] = $state['started_at'];
		$state['empty_regions_remaining'] = $this->geo_repository->count_pending_empty_region_rows_for_enrichment();
		$state['pending_empty_regions_remaining'] = $state['empty_regions_remaining'];
		$state['message'] = 'Обогащаем пустые регионы geo_v2.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step(): array {
		$state = $this->current_state();
		if ( 'enriching_regions' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}
		try {
			$result = $this->service->enrich_batch( 0, (int) $state['batch_size'] );
			$state['processed'] = (int) $state['processed'] + (int) $result['processed'];
			$state['updated'] = (int) $state['updated'] + (int) $result['updated'];
			$state['needs_review'] = (int) $state['needs_review'] + (int) $result['needs_review'];
			$state['not_found'] = (int) $state['not_found'] + (int) $result['not_found'];
			$state['skipped'] = (int) $state['skipped'] + (int) $result['skipped'];
			$state['errors'] = (int) $state['errors'] + (int) $result['errors'];
			$state['updated_at'] = $this->now();
			$state['memory_peak_mb'] = $this->memory_peak_mb();
			$state['last_items'] = array_slice( $result['items'], -10 );
			$remaining = $this->geo_repository->count_pending_empty_region_rows_for_enrichment();
			$state['empty_regions_remaining'] = $remaining;
			$state['pending_empty_regions_remaining'] = $remaining;
			if ( ! empty( $result['done'] ) || 0 === (int) $result['processed'] || 0 === $remaining ) {
				$state['status'] = 'done';
				$state['message'] = 'Обогащение пустых регионов geo_v2 завершено.';
			} else {
				$state['message'] = 'Обработан очередной batch пустых регионов geo_v2.';
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
		if ( 'enriching_regions' === (string) ( $state['status'] ?? '' ) ) {
			$state['status'] = 'paused';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Обогащение регионов geo_v2 поставлено на паузу.';
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
			'processed' => 0,
			'updated' => 0,
			'needs_review' => 0,
			'not_found' => 0,
			'skipped' => 0,
			'errors' => 0,
			'batch_size' => self::BATCH_SIZE,
			'empty_regions_remaining' => $this->geo_repository->count_pending_empty_region_rows_for_enrichment(),
			'pending_empty_regions_remaining' => $this->geo_repository->count_pending_empty_region_rows_for_enrichment(),
			'memory_peak_mb' => $this->memory_peak_mb(),
			'message' => '',
			'last_items' => array(),
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
		$state['errors'] = (int) ( $state['errors'] ?? 0 ) + 1;
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
