<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointImportService {
	private const LOCK_OPTION = 'wdc_dpd_pickup_import_lock';
	private const LOCK_TTL = 30 * 60;

	public function __construct(
		private DpdApiClient $api,
		private DpdPickupPointNormalizer $normalizer,
		private DpdPickupPointRepository $repository,
		private DpdSettings $settings,
		private ?Logger $logger = null
	) {
	}

	public function import_parcel_shops( string $context = 'manual' ): DpdPickupPointImportReport {
		return $this->with_lock(
			$context,
			fn(): DpdPickupPointImportReport => $this->import_source_unlocked(
				DpdPickupPointNormalizer::SOURCE_PARCEL_SHOPS,
				DpdPickupPointNormalizer::TYPE_PARCEL_SHOP,
				fn() => $this->api->getParcelShops( array( 'countryCode' => 'RU' ) )->body,
				$context
			)
		);
	}

	public function import_terminals_self_delivery( string $context = 'manual' ): DpdPickupPointImportReport {
		return $this->with_lock(
			$context,
			fn(): DpdPickupPointImportReport => $this->import_source_unlocked(
				DpdPickupPointNormalizer::SOURCE_TERMINALS_SELF_DELIVERY,
				DpdPickupPointNormalizer::TYPE_TERMINAL_SELF_DELIVERY,
				fn() => $this->api->getTerminalsSelfDelivery2()->body,
				$context
			)
		);
	}

	public function import_all( string $context = 'manual' ): DpdPickupPointImportReport {
		return $this->with_lock(
			$context,
			function () use ( $context ): DpdPickupPointImportReport {
				$reports = array(
					$this->import_source_unlocked(
						DpdPickupPointNormalizer::SOURCE_PARCEL_SHOPS,
						DpdPickupPointNormalizer::TYPE_PARCEL_SHOP,
						fn() => $this->api->getParcelShops( array( 'countryCode' => 'RU' ) )->body,
						$context
					),
					$this->import_source_unlocked(
						DpdPickupPointNormalizer::SOURCE_TERMINALS_SELF_DELIVERY,
						DpdPickupPointNormalizer::TYPE_TERMINAL_SELF_DELIVERY,
						fn() => $this->api->getTerminalsSelfDelivery2()->body,
						$context
					),
				);
				$combined = DpdPickupPointImportReport::combine( $reports );
				$this->settings->save_pickup_import_report( $combined->to_array() );

				return $combined;
			}
		);
	}

	/**
	 * @param callable():DpdPickupPointImportReport $callback
	 */
	private function with_lock( string $context, callable $callback ): DpdPickupPointImportReport {
		$token = $this->acquire_lock();
		if ( '' === $token ) {
			$report = $this->lock_busy_report( $context );
			$this->settings->save_pickup_import_report( $report->to_array() );
			$this->log( 'info', 'DPD pickup import skipped: lock busy.', array( 'context' => $context ) );

			return $report;
		}

		try {
			return $callback();
		} finally {
			$this->release_lock( $token );
		}
	}

	/**
	 * @param callable():mixed $fetch
	 */
	private function import_source_unlocked( string $source, string $type, callable $fetch, string $context ): DpdPickupPointImportReport {
		$started = $this->now();
		$errors = array();
		$fetched = 0;
		$normalized_count = 0;
		$saved = 0;
		$skipped = 0;
		$marked_inactive = 0;
		try {
			$response = $fetch();
			$normalized = $this->normalizer->normalize_response( $response, $source, $type );
			$fetched = (int) $normalized['fetched_count'];
			$points = $normalized['points'];
			$normalized_count = count( $points );
			$skipped = (int) $normalized['skipped_invalid'];
			if ( 0 === $fetched ) {
				$errors[] = 'DPD pickup import returned no rows. Existing points were left unchanged.';
			} elseif ( 0 === $normalized_count ) {
				$errors[] = 'DPD pickup import returned rows, but no valid points were normalized. Existing points were left unchanged.';
			} else {
				$save = $this->repository->replace_all_for_source( $source, $points );
				$saved = (int) $save['saved'];
				$skipped += (int) $save['skipped_invalid'];
				$marked_inactive = (int) $save['marked_inactive'];
			}
		} catch ( DpdException $exception ) {
			$errors[] = $exception->getMessage();
		} catch ( \Throwable $exception ) {
			$errors[] = 'DPD pickup import failed: ' . $exception->getMessage();
		}

		$report = new DpdPickupPointImportReport(
			$source,
			$started,
			$this->now(),
			$fetched,
			$normalized_count,
			$saved,
			$skipped,
			$marked_inactive,
			$errors,
			array() === $errors ? 'DPD pickup points imported.' : 'DPD pickup points import failed.',
			$this->sanitize_context( $context ),
			array() === $errors ? 'success' : 'error'
		);
		$this->settings->save_pickup_import_report( $report->to_array() );
		if ( array() !== $errors ) {
			$this->log( 'warning', 'DPD pickup import finished with errors.', array( 'source' => $source, 'context' => $context, 'errors' => $errors ) );
		}

		return $report;
	}

	private function lock_busy_report( string $context ): DpdPickupPointImportReport {
		$now = $this->now();

		return new DpdPickupPointImportReport(
			'lock_busy',
			$now,
			$now,
			0,
			0,
			0,
			0,
			0,
			array(),
			'DPD pickup points import skipped: another import is already running.',
			$this->sanitize_context( $context ),
			'skipped_lock_busy'
		);
	}

	private function acquire_lock(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) ) {
			return sha1( uniqid( 'dpd-pickup-import-', true ) );
		}
		$token = sha1( uniqid( 'dpd-pickup-import-', true ) );
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > time() ) {
			return '';
		}
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::LOCK_OPTION );
		}

		return add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ), '', 'no' ) ? $token : '';
	}

	private function release_lock( string $token ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && $token === (string) ( $existing['token'] ?? '' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** @param array<string,mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) {
			$this->logger->{$level}( $message, $this->sanitize_log_context( $context ) );
		}
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private function sanitize_log_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_string( $value ) && strlen( $value ) > 300 ) {
				$context[ $key ] = substr( $value, 0, 300 ) . '...';
			}
		}

		return $context;
	}

	private function sanitize_context( string $context ): string {
		$context = preg_replace( '/[^A-Za-z0-9_\-]/', '', $context ) ?? '';

		return '' !== $context ? substr( $context, 0, 64 ) : 'manual';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
