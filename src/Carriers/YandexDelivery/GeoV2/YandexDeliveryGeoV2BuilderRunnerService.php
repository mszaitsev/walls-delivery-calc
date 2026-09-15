<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\GeoV2;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoV2BuilderRunnerService {
	private const STATE_OPTION = 'wdc_yandex_delivery_geo_v2_builder_state';
	private const BATCH_SIZE = 500;

	public function __construct( private YandexDeliveryGeoV2BuilderService $builder ) {
	}

	/** @return array<string,mixed> */
	public function start(): array {
		$state = $this->base_state( 'building' );
		$state['session_id'] = sha1( uniqid( 'yandex-geo-v2-', true ) );
		$state['started_at'] = $this->now();
		$state['updated_at'] = $state['started_at'];
		$state['message'] = 'Строим агрегированную таблицу geoId v2.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step(): array {
		$state = $this->current_state();
		if ( 'building' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}
		try {
			$result = $this->builder->build_all( (int) $state['batch_size'], (int) $state['offset'] );
			$state['offset'] = (int) $result['next_offset'];
			$state['processed_geo_ids'] = (int) $state['processed_geo_ids'] + (int) $result['processed_geo_ids'];
			$state['saved'] = (int) $state['saved'] + (int) $result['saved'];
			$state['updated_at'] = $this->now();
			$state['memory_peak_mb'] = $this->memory_peak_mb();
			if ( ! empty( $result['done'] ) ) {
				$state['status'] = 'done';
				$state['message'] = 'Агрегация geoId v2 завершена.';
			} else {
				$state['message'] = 'Построен очередной batch geoId v2.';
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
		if ( 'building' === (string) ( $state['status'] ?? '' ) ) {
			$state['status'] = 'paused';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Агрегация geoId v2 поставлена на паузу.';
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
			'processed_geo_ids' => 0,
			'saved' => 0,
			'batch_size' => self::BATCH_SIZE,
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
