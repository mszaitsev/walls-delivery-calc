<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointV2RunnerService {
	private const STATE_OPTION = 'wdc_yandex_delivery_pickup_v2_runner_state';
	private const MODE = 'full_api_sync';
	private const BATCH_SIZE = 500;

	public function __construct(
		private YandexDeliveryApiClient $api,
		private YandexDeliveryPickupPointV2ImportService $importer
	) {
	}

	/** @return array<string,mixed> */
	public function start_full_api_sync(): array {
		$state = $this->base_state( 'downloading' );
		$state['last_action'] = 'start';
		$state['session_id'] = $this->new_session_id();
		$state['started_at'] = $this->now();
		$state['updated_at'] = $state['started_at'];
		$state['message'] = 'Скачиваем полный список ПВЗ Яндекс.Доставки v2 без type и geo_id.';
		$this->save_state( $state );

		return $this->download_full_json( $state );
	}

	/** @param array<string,mixed>|null $state @return array<string,mixed> */
	public function download_full_json( ?array $state = null ): array {
		$state = $state ?: $this->current_state();
		$state['last_action'] = 'start';
		try {
			$file = $this->json_file_path();
			$dir = dirname( $file );
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} elseif ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				throw new \RuntimeException( 'Не удалось создать директорию для JSON файла Яндекс ПВЗ/география.' );
			}
			$result = $this->api->pickupPointsListDownloadToFile( array(), $file );
			$this->validate_json_file_container( $file );
			$state['status'] = 'ready_to_import';
			$state['json_file_path'] = $file;
			$state['json_file_size_bytes'] = (int) $result['size_bytes'];
			$state['last_http_status'] = (string) ( $result['http_code'] ?? '' );
			$state['downloaded_at'] = $this->now();
			$state['updated_at'] = $state['downloaded_at'];
			$state['message'] = 'JSON сохранен. Можно запускать импорт.';
			$state['memory_peak_mb'] = $this->memory_peak_mb();
		} catch ( YandexDeliveryApiException|\Throwable $exception ) {
			$state = $this->fail( $state, $exception->getMessage(), array_merge( array( 'action' => 'download_to_file', 'file' => $file ?? '', 'size_bytes' => isset( $file ) && is_file( $file ) ? (int) filesize( $file ) : 0 ), $this->exception_context( $exception ) ) );
		}
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function start_import(): array {
		$state = $this->current_state();
		$state['last_action'] = 'start_import';
		if ( ! in_array( (string) ( $state['status'] ?? '' ), array( 'ready_to_import', 'paused', 'importing' ), true ) ) {
			return $state;
		}
		if ( '' === (string) ( $state['json_file_path'] ?? '' ) || ! is_readable( (string) $state['json_file_path'] ) ) {
			$state = $this->fail( $state, 'JSON файл для импорта недоступен.', array( 'action' => 'start_import' ) );
			$this->save_state( $state );
			return $state;
		}
		if ( 0 === (int) ( $state['offset'] ?? 0 ) && empty( $state['pickup_points_truncated'] ) ) {
			try {
				$this->importer->truncate_repository();
				$state['pickup_points_truncated'] = true;
			} catch ( \Throwable $exception ) {
				$state = $this->fail( $state, $exception->getMessage(), array_merge( array( 'action' => 'truncate_pickup_points_v2' ), $this->exception_context( $exception ) ) );
				$this->save_state( $state );
				return $state;
			}
		}
		$state['status'] = 'importing';
		$state['updated_at'] = $this->now();
		$state['message'] = 'Импортируем ПВЗ v2 батчами.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_import_step(): array {
		$state = $this->current_state();
		$state['last_action'] = 'step';
		if ( 'importing' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}
		try {
			$result = $this->importer->import_from_json_file_streamed( (string) $state['json_file_path'], (int) $state['offset'], (int) $state['batch_size'] );
			$state['offset'] = (int) $result['next_offset'];
			$state['processed'] = (int) $state['processed'] + (int) $result['processed'];
			$state['normalized'] = (int) $state['normalized'] + (int) $result['normalized'];
			$state['saved'] = (int) $state['saved'] + (int) $result['saved'];
			$state['skipped_invalid'] = (int) $state['skipped_invalid'] + (int) $result['skipped_invalid'];
			$state['memory_peak_mb'] = (string) $result['memory_peak_mb'];
			$state['updated_at'] = $this->now();
			if ( ! empty( $result['done'] ) ) {
				$this->cleanup_successful_import_files( $state );
				$state['status'] = 'done';
				$state['message'] = 'Импорт полного списка ПВЗ v2 завершен.';
			} else {
				$state['message'] = 'Импортирован очередной batch ПВЗ v2.';
			}
		} catch ( \Throwable $exception ) {
			$state = $this->fail( $state, $exception->getMessage(), $this->exception_context( $exception ) );
		}
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function pause(): array {
		$state = $this->current_state();
		$state['last_action'] = 'pause';
		if ( 'importing' === (string) ( $state['status'] ?? '' ) ) {
			$state['status'] = 'paused';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Импорт ПВЗ v2 поставлен на паузу.';
			$this->save_state( $state );
		}

		return $state;
	}

	/** @return array<string,mixed> */
	public function reset(): array {
		$state = $this->idle_state();
		$state['last_action'] = 'reset';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function current_state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return $this->idle_state();
		}
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) && array() !== $state ? array_merge( $this->idle_state(), $state ) : $this->idle_state();
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		$state['memory_peak_mb'] = (string) ( $state['memory_peak_mb'] ?? $this->memory_peak_mb() );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/** @return array<string,mixed> */
	private function idle_state(): array {
		return $this->base_state( 'idle' );
	}

	/** @return array<string,mixed> */
	private function base_state( string $status ): array {
		$now = $this->now();
		return array(
			'status' => $status,
			'mode' => self::MODE,
			'session_id' => '',
			'started_at' => '',
			'updated_at' => $now,
			'json_file_path' => '',
			'json_file_size_bytes' => 0,
			'downloaded_at' => '',
			'offset' => 0,
			'pickup_points_truncated' => false,
			'processed' => 0,
			'normalized' => 0,
			'saved' => 0,
			'skipped_invalid' => 0,
			'errors_count' => 0,
			'errors_last' => array(),
			'batch_size' => self::BATCH_SIZE,
			'memory_peak_mb' => $this->memory_peak_mb(),
			'last_action' => '',
			'last_http_status' => '',
			'last_error_context' => array(),
			'message' => '',
		);
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function fail( array $state, string $message, array $context = array() ): array {
		$state['status'] = 'error';
		$state['updated_at'] = $this->now();
		$state['errors_count'] = (int) ( $state['errors_count'] ?? 0 ) + 1;
		$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
		$errors[] = substr( trim( $message ), 0, 500 );
		$state['errors_last'] = array_slice( $errors, -5 );
		$state['message'] = substr( trim( $message ), 0, 500 );
		$state['last_error_context'] = $context;
		if ( isset( $context['http_code'] ) ) {
			$state['last_http_status'] = (string) $context['http_code'];
		}
		$state['memory_peak_mb'] = $this->memory_peak_mb();

		return $state;
	}

	/** @return array<string,mixed> */
	private function exception_context( \Throwable $exception ): array {
		$context = array(
			'type' => get_class( $exception ),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		);
		if ( $exception instanceof YandexDeliveryApiException ) {
			$context = array_merge( $context, $exception->details() );
		}

		return $context;
	}
	private function validate_json_file_container( string $file ): void {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			throw new \RuntimeException( 'JSON файл Яндекс ПВЗ/география недоступен для чтения.' );
		}
		$size = (int) filesize( $file );
		if ( $size <= 0 ) {
			throw new \RuntimeException( 'JSON файл Яндекс ПВЗ/география пустой.' );
		}
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Не удалось открыть JSON файл Яндекс ПВЗ/география.' );
		}
		try {
			$first = $this->first_non_whitespace_byte( $handle );
			$last = $this->last_non_whitespace_byte( $handle, $size );
		} finally {
			fclose( $handle );
		}
		if ( null === $first || null === $last ) {
			throw new \RuntimeException( 'JSON файл Яндекс ПВЗ/география не содержит данных.' );
		}
		if ( ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last ) ) {
			return;
		}

		throw new \RuntimeException( 'Яндекс.Доставка вернула файл, не похожий на JSON.' );
	}

	/** @param resource $handle */
	private function first_non_whitespace_byte( mixed $handle ): ?string {
		rewind( $handle );
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 8192 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; ++$i ) {
				$byte = $chunk[$i];
				if ( ! ctype_space( $byte ) ) {
					return $byte;
				}
			}
		}

		return null;
	}

	/** @param resource $handle */
	private function last_non_whitespace_byte( mixed $handle, int $size ): ?string {
		for ( $position = $size - 1; $position >= 0; --$position ) {
			if ( 0 !== fseek( $handle, $position ) ) {
				break;
			}
			$byte = fread( $handle, 1 );
			if ( false === $byte || '' === $byte ) {
				continue;
			}
			if ( ! ctype_space( $byte ) ) {
				return $byte;
			}
		}

		return null;
	}
	private function json_file_path(): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : sys_get_temp_dir();
		return rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'wdc-yandex-delivery' . DIRECTORY_SEPARATOR . 'pickup-v2' . DIRECTORY_SEPARATOR . 'yandex-pickup-points-v2-' . gmdate( 'Ymd-His' ) . '.json';
	}

	/** @param array<string,mixed> $state */
	private function cleanup_successful_import_files( array $state ): void {
		$file = (string) ( $state['json_file_path'] ?? '' );
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}
		$dir = dirname( $file );
		@unlink( $file );
		$this->remove_empty_temp_dirs( $dir );
	}

	private function remove_empty_temp_dirs( string $dir ): void {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : sys_get_temp_dir();
		$base = rtrim( $base, '/\\' );
		$root = $base . DIRECTORY_SEPARATOR . 'wdc-yandex-delivery';
		$dir = rtrim( $dir, '/\\' );
		while ( '' !== $dir && str_starts_with( $dir, $root ) && $dir !== $base ) {
			$items = is_dir( $dir ) ? scandir( $dir ) : false;
			if ( ! is_array( $items ) || count( array_diff( $items, array( '.', '..' ) ) ) > 0 ) {
				break;
			}
			@rmdir( $dir );
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				break;
			}
			$dir = rtrim( $parent, '/\\' );
		}
	}
	private function memory_peak_mb(): string {
		return function_exists( 'memory_get_peak_usage' ) ? (string) round( memory_get_peak_usage( true ) / 1048576, 1 ) : '0';
	}

	private function new_session_id(): string {
		return sha1( uniqid( 'yandex-pickup-v2-', true ) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
