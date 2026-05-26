<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImporter {
	public const SCHEDULE_HOOK = 'wdc_russian_post_pickup_import';
	private const LOCK_KEY = 'wdc_russian_post_pickup_import_lock';

	public function __construct(
		private RussianPostOtpravkaApiSettings $settings,
		private RussianPostOtpravkaApiClient $client,
		private PickupPointRepository $repository,
		private RussianPostPassportPointNormalizer $normalizer
	) {
	}

	public function register(): void {
		add_action( self::SCHEDULE_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'sync_schedule' ) );
	}

	public function run_scheduled(): void {
		$this->import( $this->settings->unload_type() );
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
		try {
			$download = $this->client->download_passport_zip( $type );
			if ( empty( $download['success'] ) ) {
				$result['errors'][] = (string) ( $download['error'] ?? 'Download failed.' );
				return $this->finish( $result, false );
			}
			$temp_file = (string) $download['temp_file'];
			$result['downloaded'] = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;

			$payload = $this->read_first_payload_from_zip( $temp_file );
			if ( '' === $payload ) {
				$result['errors'][] = 'ZIP does not contain JSON/TXT passport payload.';
				return $this->finish( $result, false );
			}

			$data = json_decode( $payload, true );
			if ( ! is_array( $data ) ) {
				$result['errors'][] = 'Passport payload is not valid JSON.';
				return $this->finish( $result, false );
			}

			$elements = is_array( $data['passportElements'] ?? null ) ? $data['passportElements'] : $data;
			if ( ! is_array( $elements ) ) {
				$result['errors'][] = 'Passport payload does not contain passportElements.';
				return $this->finish( $result, false );
			}

			$rows = array();
			foreach ( $elements as $item ) {
				if ( ! is_array( $item ) ) {
					++$result['skipped'];
					continue;
				}
				++$result['parsed'];
				$row = $this->normalizer->normalize( $item, $type, $started );
				if ( null === $row ) {
					++$result['skipped'];
					continue;
				}
				$rows[] = $row;
			}

			$upsert = $this->repository->upsert_passport_batch( RussianPostPassportPointNormalizer::CARRIER_KEY, $rows );
			$result['inserted'] = $upsert['inserted'];
			$result['updated'] = $upsert['updated'];
			$result['skipped'] += $upsert['skipped'];
			$result['deactivated'] = $this->repository->mark_missing_inactive( RussianPostPassportPointNormalizer::CARRIER_KEY, $started );
			$result['success'] = true;

			return $this->finish( $result, true );
		} finally {
			$this->unlock();
		}
	}

	public function is_locked(): bool {
		if ( function_exists( 'get_transient' ) ) {
			return false !== get_transient( self::LOCK_KEY );
		}

		return (bool) get_option( self::LOCK_KEY, false );
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function finish( array $result, bool $success ): array {
		$result['finished_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$this->settings->save_import_result( $result, $success );

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

	private function read_first_payload_from_zip( string $temp_file ): string {
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
				$contents = $zip->getFromIndex( $i );
				return is_string( $contents ) ? $contents : '';
			}
		} finally {
			$zip->close();
		}

		return '';
	}
}
