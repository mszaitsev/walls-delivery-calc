<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use Throwable;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingRunnerService {
	public const OPTION_KEY = 'wdc_yandex_delivery_geo_mapping_runner_state';
	private const LOCK_KEY = 'wdc_yandex_delivery_geo_mapping_runner_state_lock';
	private const BATCH_SIZE = 50;
	private const LOCK_TTL = 120;

	public function __construct(
		private LocationRepository $locations,
		private YandexDeliveryGeoMappingRepository $mappings,
		private YandexDeliveryGeoMappingService $mapping_service
	) {
	}

	/** @return array<string,mixed> */
	public function start_full(): array {
		$state = $this->default_state();
		$now = $this->now();
		$state['status'] = 'running';
		$state['mode'] = 'full';
		$state['session_id'] = $this->session_id();
		$state['started_at'] = $now;
		$state['updated_at'] = $now;
		$state['total_estimated'] = $this->locations->count_batch_locations( 'RU', true );
		$state['message'] = 'Полный маппинг запущен.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function retry_errors(): array {
		$state = $this->default_state();
		$now = $this->now();
		$state['status'] = 'running';
		$state['mode'] = 'retry_errors';
		$state['session_id'] = $this->session_id();
		$state['started_at'] = $now;
		$state['updated_at'] = $now;
		$state['total_estimated'] = $this->mappings->count_technical_error_markers();
		$state['message'] = 'Повторная обработка технических ошибок запущена.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step( string $session_id = '' ): array {
		$reservation = $this->reserve_batch( $session_id );
		$ids = is_array( $reservation['ids'] ?? null ) ? $reservation['ids'] : null;
		$state = is_array( $reservation['state'] ?? null ) ? $reservation['state'] : $this->current_state();
		if ( null === $ids || array() === $ids ) {
			return $state;
		}

		$delta = array(
			'processed' => 0,
			'mapped' => 0,
			'needs_review' => 0,
			'not_found' => 0,
			'tech_errors' => 0,
			'errors_last' => array(),
		);
		foreach ( $ids as $location_id ) {
			$location_id = (int) $location_id;
			if ( $location_id <= 0 ) {
				continue;
			}

			if ( 'full' === (string) $state['mode'] ) {
				$this->mappings->delete_location_mappings( $location_id );
			}

			++$delta['processed'];
			$result = $this->mapping_service->detect_for_runner( $location_id );
			$delta = $this->classify_result( $delta, $location_id, $result );
		}

		return $this->apply_step_delta( $session_id, $delta );
	}

	/** @return array<string,mixed> */
	public function pause(): array {
		if ( ! $this->acquire_lock( 10 ) ) {
			return $this->current_state();
		}
		try {
			$state = $this->current_state();
			if ( 'running' === (string) $state['status'] ) {
				$state['status'] = 'paused';
				$state['updated_at'] = $this->now();
				$state['message'] = 'Маппинг поставлен на паузу.';
				$this->save_state( $state );
			}

			return $state;
		} finally {
			$this->release_lock();
		}
	}

	/** @return array<string,mixed> */
	public function reset(): array {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_KEY );
			delete_option( self::LOCK_KEY );
		} else {
			$this->save_state( $this->default_state() );
		}

		return $this->current_state();
	}

	/** @return array<string,mixed> */
	public function current_state(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::OPTION_KEY, array() ) : array();
		return $this->normalize_state( is_array( $state ) ? $state : array() );
	}

	public function is_running(): bool {
		$state = $this->current_state();
		return 'running' === (string) $state['status'];
	}

	/** @return array{ids:?array<int,int>,state:array<string,mixed>} */
	private function reserve_batch( string $session_id = '' ): array {
		if ( ! $this->acquire_lock( 10 ) ) {
			return array( 'ids' => null, 'state' => $this->current_state() );
		}

		try {
			$state = $this->current_state();
			if ( 'running' !== (string) $state['status'] ) {
				return array( 'ids' => null, 'state' => $state );
			}
			if ( '' !== $session_id && $session_id !== (string) $state['session_id'] ) {
				$state['status'] = 'error';
				$state['updated_at'] = $this->now();
				$state['message'] = 'Неверный session_id runner.';
				$this->save_state( $state );
				return array( 'ids' => null, 'state' => $state );
			}

			try {
				$ids = 'retry_errors' === (string) $state['mode']
					? $this->mappings->find_technical_error_location_ids_after( (int) $state['next_location_id'], self::BATCH_SIZE )
					: array_map(
						static fn( Location $location ): int => (int) $location->id,
						array_filter( $this->locations->find_batch_after_id( (int) $state['next_location_id'], self::BATCH_SIZE, 'RU', true ), static fn( mixed $location ): bool => $location instanceof Location && null !== $location->id )
					);
			} catch ( Throwable $exception ) {
				$state['status'] = 'error';
				$state['updated_at'] = $this->now();
				$state['message'] = $this->truncate( $exception->getMessage(), 500 );
				$state = $this->append_error( $state, 0, $exception->getMessage() );
				$this->save_state( $state );
				return array( 'ids' => null, 'state' => $state );
			}

			$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
			if ( array() === $ids ) {
				$state = $this->finish_done( $state );
				return array( 'ids' => array(), 'state' => $state );
			}

			$state['next_location_id'] = max( $ids );
			$state['updated_at'] = $this->now();
			$state['message'] = sprintf( 'Зарезервирован batch до location_id %d.', (int) $state['next_location_id'] );
			$this->save_state( $state );

			return array( 'ids' => $ids, 'state' => $state );
		} finally {
			$this->release_lock();
		}
	}

	/** @param array<string,mixed> $delta @return array<string,mixed> */
	private function apply_step_delta( string $session_id, array $delta ): array {
		if ( ! $this->acquire_lock( 60 ) ) {
			$state = $this->current_state();
			$state['status'] = 'error';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Не удалось применить delta runner: state lock busy.';
			$this->save_state( $state );
			return $this->current_state();
		}

		try {
			$state = $this->current_state();
			if ( '' !== $session_id && $session_id !== (string) $state['session_id'] ) {
				$state['status'] = 'error';
				$state['updated_at'] = $this->now();
				$state['message'] = 'Неверный session_id runner.';
				$this->save_state( $state );
				return $this->current_state();
			}

			foreach ( array( 'processed', 'mapped', 'needs_review', 'not_found', 'tech_errors' ) as $key ) {
				$state[ $key ] = max( 0, (int) ( $state[ $key ] ?? 0 ) ) + max( 0, (int) ( $delta[ $key ] ?? 0 ) );
			}
			$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
			$delta_errors = is_array( $delta['errors_last'] ?? null ) ? $delta['errors_last'] : array();
			$state['errors_last'] = array_slice( array_merge( $errors, $delta_errors ), -10 );
			$state['updated_at'] = $this->now();
			if ( 'done' !== (string) $state['status'] ) {
				$state['message'] = 'Шаг выполнен.';
			}
			$this->save_state( $state );

			return $this->current_state();
		} finally {
			$this->release_lock();
		}
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $result @return array<string,mixed> */
	private function classify_result( array $state, int $location_id, array $result ): array {
		if ( ! empty( $result['technical_error'] ) || YandexDeliveryGeoMappingStatus::ERROR === (string) ( $result['status'] ?? '' ) ) {
			++$state['tech_errors'];
			return $this->append_error( $state, $location_id, (string) ( $result['message'] ?? 'Yandex location/detect technical error.' ) );
		}

		$saved = is_array( $result['mappings'] ?? null ) ? $result['mappings'] : $this->mappings->find_by_location_id( $location_id );
		$has_primary = false;
		foreach ( $saved as $row ) {
			$geo_id = (int) ( $row['yandex_geo_id'] ?? 0 );
			$has_primary = $has_primary || ( ! empty( $row['is_primary'] ) && ! $this->mappings->is_technical_error_geo_id( $geo_id ) );
		}
		$status = (string) ( $result['status'] ?? '' );
		if ( $has_primary || YandexDeliveryGeoMappingStatus::MAPPED === $status ) {
			++$state['mapped'];
		} elseif ( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === $status || YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $status ) {
			++$state['needs_review'];
		} elseif ( YandexDeliveryGeoMappingStatus::NOT_FOUND === $status ) {
			++$state['not_found'];
		} else {
			++$state['tech_errors'];
			$state = $this->append_error( $state, $location_id, 'Не удалось классифицировать результат маппинга.' );
		}

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function finish_done( array $state ): array {
		$state['status'] = 'done';
		$state['updated_at'] = $this->now();
		$state['message'] = 'Готово.';
		$this->save_state( $state );

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function append_error( array $state, int $location_id, string $message ): array {
		$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
		$errors[] = array(
			'location_id' => $location_id,
			'message' => $this->truncate( $message, 500 ),
			'checked_at' => $this->now(),
		);
		$state['errors_last'] = array_slice( $errors, -10 );

		return $state;
	}

	/** @return array<string,mixed> */
	private function default_state(): array {
		return array(
			'status' => 'idle',
			'mode' => 'full',
			'session_id' => '',
			'started_at' => '',
			'updated_at' => '',
			'next_location_id' => 0,
			'processed' => 0,
			'mapped' => 0,
			'needs_review' => 0,
			'not_found' => 0,
			'tech_errors' => 0,
			'total_estimated' => 0,
			'message' => '',
			'errors_last' => array(),
			'batch_size' => self::BATCH_SIZE,
		);
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function normalize_state( array $state ): array {
		$normalized = array_replace( $this->default_state(), $state );
		$normalized['status'] = in_array( (string) $normalized['status'], array( 'idle', 'running', 'paused', 'done', 'error' ), true ) ? (string) $normalized['status'] : 'idle';
		$normalized['mode'] = in_array( (string) $normalized['mode'], array( 'full', 'retry_errors' ), true ) ? (string) $normalized['mode'] : 'full';
		foreach ( array( 'next_location_id', 'processed', 'mapped', 'needs_review', 'not_found', 'tech_errors', 'total_estimated' ) as $key ) {
			$normalized[ $key ] = max( 0, (int) $normalized[ $key ] );
		}
		$normalized = array_intersect_key( $normalized, $this->default_state() );
		$normalized['batch_size'] = self::BATCH_SIZE;
		$normalized['errors_last'] = array_slice( is_array( $normalized['errors_last'] ) ? $normalized['errors_last'] : array(), -10 );

		return $normalized;
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $this->normalize_state( $state ), false );
		}
	}

	private function acquire_lock( int $attempts = 1 ): bool {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) || ! function_exists( 'get_option' ) ) {
			return true;
		}
		$attempts = max( 1, $attempts );
		for ( $attempt = 0; $attempt < $attempts; ++$attempt ) {
			$now = time();
			if ( add_option( self::LOCK_KEY, (string) $now, '', 'no' ) ) {
				return true;
			}
			$locked_at = (int) get_option( self::LOCK_KEY, 0 );
			if ( $locked_at > 0 && ( $now - $locked_at ) > self::LOCK_TTL ) {
				delete_option( self::LOCK_KEY );
				if ( add_option( self::LOCK_KEY, (string) $now, '', 'no' ) ) {
					return true;
				}
			}
			if ( $attempt + 1 < $attempts && function_exists( 'usleep' ) ) {
				usleep( 50000 );
			}
		}

		return false;
	}

	private function release_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_KEY );
		}
	}

	private function truncate( string $value, int $length ): string {
		$value = trim( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
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
