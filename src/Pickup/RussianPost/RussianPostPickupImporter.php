<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImporter {
	public const SCHEDULE_HOOK = 'wdc_russian_post_pickup_import';
	private const LOCK_KEY = 'wdc_russian_post_pickup_import_lock';
	private const BATCH_SIZE = 250;
	private const MAX_STORED_ERRORS = 10;

	public function __construct(
		private RussianPostOtpravkaApiSettings $settings,
		private RussianPostOtpravkaApiClient $client,
		private PickupPointRepository $repository,
		private RussianPostPassportPointNormalizer $normalizer,
		private ?RussianPostPickupImportStateService $state = null,
		private ?ActionScheduler $scheduler = null
	) {
	}

	public function register(): void {
		add_action( self::SCHEDULE_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
	}

	public function run_scheduled( string $type = '' ): void {
		$this->import( '' !== trim( $type ) ? $type : $this->settings->unload_type() );
	}

	public function sync_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$scheduled = wp_next_scheduled( self::SCHEDULE_HOOK );
		if ( $this->settings->schedule_enabled() && false === $scheduled ) {
			wp_schedule_event( time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ), 'daily', self::SCHEDULE_HOOK );
		}
		if ( ! $this->settings->schedule_enabled() && false !== $scheduled && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SCHEDULE_HOOK );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function import( string $type = 'ALL' ): array {
		$started = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$result = array(
			'success' => false,
			'downloaded' => 0,
			'parsed' => 0,
			'inserted' => 0,
			'updated' => 0,
			'deactivated' => 0,
			'skipped' => 0,
			'errors' => array(),
			'started_at' => $started,
			'finished_at' => '',
			'type' => strtoupper( $type ),
		);

		if ( $this->is_locked() ) {
			$result['errors'][] = 'Import is already running.';
			$result['finished_at'] = $started;
			$this->settings->save_import_result( $result, false );
			return $result;
		}

		$this->lock();
		$temp_file = '';
		$payload_file = '';
		try {
			$this->state?->start( $type );
			$this->state?->update( 'download', $result );
			$download = $this->client->download_passport_zip( $type );
			if ( empty( $download['success'] ) ) {
				$result['errors'][] = (string) ( $download['error'] ?? 'Download failed.' );
				return $this->finish( $result, false );
			}
			$temp_file = (string) $download['temp_file'];
			$result['downloaded'] = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;
			$this->state?->update( 'extract', $result );

			$payload_file = $this->extract_first_payload_from_zip( $temp_file );
			if ( '' === $payload_file ) {
				$result['errors'][] = 'ZIP does not contain JSON/TXT passport payload.';
				return $this->finish( $result, false );
			}
			$this->state?->update( 'parse', $result );

			if ( ! $this->process_passport_payload_stream( $payload_file, $type, $started, $result ) ) {
				$result['errors'][] = 'Passport payload does not contain passportElements.';
				return $this->finish( $result, false );
			}

			$this->state?->update( 'deactivate', $result );
			$result['deactivated'] = $this->repository->mark_missing_inactive( RussianPostPassportPointNormalizer::CARRIER_KEY, $started );
			$result['success'] = true;

			return $this->finish( $result, true );
		} finally {
			$this->delete_temp_file( $payload_file );
			$this->delete_temp_file( $temp_file );
			$this->unlock();
		}
	}

	public function is_locked(): bool {
		$state = $this->state?->reset_stale_if_needed();
		if ( is_array( $state ) && 'failed' === (string) ( $state['status'] ?? '' ) && str_contains( strtolower( implode( ';', array_map( 'strval', is_array( $state['errors'] ?? null ) ? $state['errors'] : array() ) ) ), 'stale' ) ) {
			$this->unlock();
			return false;
		}
		if ( function_exists( 'get_transient' ) ) {
			return false !== get_transient( self::LOCK_KEY );
		}

		return (bool) get_option( self::LOCK_KEY, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function reset_stale_or_running_import(): array {
		$this->unlock();

		return $this->state instanceof RussianPostPickupImportStateService ? $this->state->cancel_by_admin() : array();
	}

	public function queue_background_import( string $type ): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		$current = $this->state?->reset_stale_if_needed() ?? array();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$type = $this->normalize_type( $type );
		$scheduled = false;
		if ( $this->scheduler instanceof ActionScheduler && null !== $this->scheduler->schedule_single( time() + 5, self::SCHEDULE_HOOK, array( $type ) ) ) {
			$scheduled = true;
		}
		if ( ! $scheduled && function_exists( 'wp_schedule_single_event' ) ) {
			$scheduled = (bool) wp_schedule_single_event( time() + 5, self::SCHEDULE_HOOK, array( $type ) );
		}

		if ( $scheduled ) {
			$this->state?->queue( $type );
			return true;
		}

		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$this->state?->failed(
			array(
				'type' => $type,
				'finished_at' => $now,
				'errors' => array( 'Unable to schedule background import job.' ),
			)
		);

		return false;
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function finish( array $result, bool $success ): array {
		$result['finished_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$this->settings->save_import_result( $result, $success );
		if ( $success ) {
			$this->state?->success( $result );
		} else {
			$this->state?->failed( $result );
		}

		return $result;
	}

	private function lock(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK_KEY, 1, 30 * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ) );
			return;
		}
		update_option( self::LOCK_KEY, 1, false );
	}

	private function unlock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_KEY );
			return;
		}
		delete_option( self::LOCK_KEY );
	}

	private function delete_temp_file( string $temp_file ): void {
		if ( '' === $temp_file || ! is_file( $temp_file ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $temp_file );
			return;
		}
		@unlink( $temp_file );
	}

	private function extract_first_payload_from_zip( string $temp_file ): string {
		if ( ! class_exists( \ZipArchive::class ) || ! is_file( $temp_file ) ) {
			return '';
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $temp_file ) ) {
			return '';
		}

		try {
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$name = (string) $zip->getNameIndex( $i );
				$lower = strtolower( $name );
				if ( ! str_ends_with( $lower, '.json' ) && ! str_ends_with( $lower, '.txt' ) ) {
					continue;
				}
				$stream = $zip->getStream( $name );
				if ( ! is_resource( $stream ) ) {
					return '';
				}
				$payload_file = wp_tempnam( 'wdc-russian-post-passport-payload.json' );
				if ( ! is_string( $payload_file ) || '' === $payload_file ) {
					fclose( $stream );
					return '';
				}
				$out = fopen( $payload_file, 'wb' );
				if ( ! is_resource( $out ) ) {
					fclose( $stream );
					$this->delete_temp_file( $payload_file );
					return '';
				}
				try {
					stream_copy_to_stream( $stream, $out );
				} finally {
					fclose( $out );
					fclose( $stream );
				}

				return $payload_file;
			}
		} finally {
			$zip->close();
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function process_passport_payload_stream( string $payload_file, string $type, string $started, array &$result ): bool {
		$handle = fopen( $payload_file, 'rb' );
		if ( ! is_resource( $handle ) ) {
			$this->add_limited_error( $result, 'Unable to open passport payload file.' );
			return false;
		}

		$found_array = false;
		$done = false;
		$search = '';
		$object = '';
		$depth = 0;
		$in_string = false;
		$escaped = false;
		$batch = array();

		try {
			while ( ! feof( $handle ) && ! $done ) {
				$chunk = fread( $handle, 8192 );
				if ( false === $chunk || '' === $chunk ) {
					continue;
				}

				if ( ! $found_array ) {
					$search .= $chunk;
					if ( preg_match( '/"passportElements"\s*:\s*\[/s', $search, $matches, PREG_OFFSET_CAPTURE ) ) {
						$found_array = true;
						$offset = (int) $matches[0][1] + strlen( $matches[0][0] );
						$chunk = substr( $search, $offset );
						$search = '';
					} else {
						$search = strlen( $search ) > 1048576 ? substr( $search, -1048576 ) : $search;
						continue;
					}
				}

				$length = strlen( $chunk );
				for ( $i = 0; $i < $length; ++$i ) {
					$char = $chunk[ $i ];
					if ( 0 === $depth ) {
						if ( '{' === $char ) {
							$object = '{';
							$depth = 1;
							$in_string = false;
							$escaped = false;
							continue;
						}
						if ( ']' === $char ) {
							$done = true;
							break;
						}
						continue;
					}

					$object .= $char;
					if ( $in_string ) {
						if ( $escaped ) {
							$escaped = false;
							continue;
						}
						if ( '\\' === $char ) {
							$escaped = true;
							continue;
						}
						if ( '"' === $char ) {
							$in_string = false;
						}
						continue;
					}

					if ( '"' === $char ) {
						$in_string = true;
						continue;
					}
					if ( '{' === $char ) {
						++$depth;
						continue;
					}
					if ( '}' === $char ) {
						--$depth;
						if ( 0 === $depth ) {
							$this->process_passport_object( $object, $type, $started, $batch, $result );
							$object = '';
							if ( count( $batch ) >= self::BATCH_SIZE ) {
								$this->flush_batch( $batch, $result );
							}
						}
					}
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( ! $found_array ) {
			return false;
		}
		if ( '' !== $object ) {
			++$result['skipped'];
			$this->add_limited_error( $result, 'Incomplete JSON object in passportElements.' );
		}
		$this->flush_batch( $batch, $result );

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $batch
	 * @param array<string,mixed> $result
	 */
	private function process_passport_object( string $json, string $type, string $started, array &$batch, array &$result ): void {
		$item = json_decode( $json, true );
		if ( ! is_array( $item ) ) {
			++$result['skipped'];
			$this->add_limited_error( $result, 'Invalid passport item JSON: ' . json_last_error_msg() );
			return;
		}

		++$result['parsed'];
		$row = $this->normalizer->normalize( $item, $type, $started );
		if ( null === $row ) {
			++$result['skipped'];
			return;
		}

		$batch[] = $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $batch
	 * @param array<string,mixed> $result
	 */
	private function flush_batch( array &$batch, array &$result ): void {
		if ( array() === $batch ) {
			return;
		}

		$upsert = $this->repository->upsert_passport_batch( RussianPostPassportPointNormalizer::CARRIER_KEY, $batch );
		$result['inserted'] += $upsert['inserted'];
		$result['updated'] += $upsert['updated'];
		$result['skipped'] += $upsert['skipped'];
		$batch = array();
		$this->state?->update( 'upsert', $result );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function add_limited_error( array &$result, string $message ): void {
		if ( count( is_array( $result['errors'] ?? null ) ? $result['errors'] : array() ) >= self::MAX_STORED_ERRORS ) {
			return;
		}
		$result['errors'][] = $message;
	}

	private function normalize_type( string $type ): string {
		$type = strtoupper( trim( $type ) );

		return in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
	}
}
