<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyImportService {
	private const LOCK_NAME = 'wdc_jet_logistic_geography_import';
	private \wpdb $wpdb;

	public function __construct(
		private JetLogisticCitiesCsvParser $parser,
		private JetLogisticGeographyMatcher $matcher,
		private JetLogisticGeographyRepository $repository,
		private JetLogisticCountrySyncService $country_sync,
		?\wpdb $db = null
	) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	/** @return array<string,mixed> */
	public function import_csv( string $csv ): array {
		$started = microtime( true );
		if ( ! $this->acquire_lock() ) {
			return array( 'success' => false, 'message' => 'Импорт географии Jet Logistic уже выполняется. Дождитесь его завершения или остановите зависший PHP-процесс.', 'code' => 'import_already_running' );
		}
		try {
			$this->log_stage( 'jet_geography_import_started' );
			$parsed = $this->parser->parse( $csv );
			$this->log_stage( 'jet_geography_csv_parsed', array( 'rows' => count( $parsed ), 'elapsed_ms' => $this->elapsed_ms( $started ) ) );
			if ( array() === $parsed ) {
				return array( 'success' => false, 'message' => 'В файле cities.csv не найдено строк для импорта.', 'rows' => 0, 'rows_read' => 0, 'rows_unique' => 0, 'duplicates' => 0, 'legacy_identity_conflicts' => 0 );
			}
			$deduplicated = $this->deduplicate_rows( $parsed );
			$this->log_stage( 'jet_geography_rows_deduplicated', array( 'rows_unique' => count( $deduplicated['rows'] ), 'duplicates' => $deduplicated['duplicates'] ) );
			$legacy_identities = $this->classify_legacy_identities( $deduplicated['rows'] );
			$rows_with_metadata = array_map( fn( array $row ): array => $this->with_legacy_migration_metadata( $row, $legacy_identities ), $deduplicated['rows'] );
			$match_result = $this->matcher->match_many( $rows_with_metadata );
			$matched = $match_result['rows'];
			$this->log_stage( 'jet_geography_rows_matched', array( 'rows' => count( $matched ), 'legacy_override_migration_failures' => $match_result['legacy_override_migration_failures'] ) );
			$this->repository->replace_snapshot( $matched );
			$this->log_stage( 'jet_geography_snapshot_saved', array( 'rows' => count( $matched ) ) );
			$this->country_sync->sync_discovered_countries();
			$this->log_stage( 'jet_geography_import_finished', array( 'elapsed_ms' => $this->elapsed_ms( $started ) ) );

			return array(
				'success' => true,
				'message' => 'География Jet Logistic успешно импортирована.',
				'rows' => count( $matched ),
				'rows_read' => count( $parsed ),
				'rows_unique' => count( $matched ),
				'duplicates' => $deduplicated['duplicates'],
				'duplicate_conflicts' => $deduplicated['duplicate_conflicts'],
				'legacy_identity_conflicts' => count( $legacy_identities['conflicts'] ),
				'legacy_override_migration_failures' => $match_result['legacy_override_migration_failures'],
				'stats' => $this->stats( $matched ),
			);
		} catch ( \Throwable $exception ) {
			$this->log_stage( 'jet_geography_import_failed', array( 'message' => $this->safe_error_message( $exception ), 'elapsed_ms' => $this->elapsed_ms( $started ) ) );
			return array( 'success' => false, 'message' => 'Импорт географии Jet Logistic завершился с ошибкой. Подробности записаны в журнал.', 'code' => 'import_failed' );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{rows:array<int,array<string,mixed>>,duplicates:int,duplicate_conflicts:int}
	 */
	private function deduplicate_rows( array $rows ): array {
		$unique = array();
		$duplicates = 0;
		$duplicate_conflicts = 0;
		foreach ( $rows as $row ) {
			$identity = (string) ( $row['source_identity'] ?? '' );
			if ( '' === $identity ) {
				$unique[] = $row;
				continue;
			}
			if ( ! isset( $unique[ $identity ] ) ) {
				$unique[ $identity ] = $row;
				continue;
			}
			++$duplicates;
			if ( $this->source_fingerprint( $unique[ $identity ] ) !== $this->source_fingerprint( $row ) ) {
				++$duplicate_conflicts;
				$unique[ $identity ] = array_merge( $unique[ $identity ], array( 'match_status' => 'ambiguous', 'match_source' => 'duplicate_conflict', 'active' => 0 ) );
			}
		}

		return array(
			'rows' => array_values( $unique ),
			'duplicates' => $duplicates,
			'duplicate_conflicts' => $duplicate_conflicts,
		);
	}

	/** @param array<string,mixed> $row */
	private function source_fingerprint( array $row ): string {
		return wp_json_encode(
			array(
				'source_city' => (string) ( $row['source_city'] ?? '' ),
				'source_region' => (string) ( $row['source_region'] ?? '' ),
				'source_place_type' => (string) ( $row['source_place_type'] ?? '' ),
				'country_code' => strtoupper( (string) ( $row['country_code'] ?? '' ) ),
			),
			JSON_UNESCAPED_UNICODE
		) ?: '';
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{safe:array<string,true>,conflicts:array<string,true>}
	 */
	private function classify_legacy_identities( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			foreach ( $this->legacy_identities_for_row( $row ) as $legacy_identity ) {
				$groups[ $legacy_identity ][ (string) ( $row['source_identity'] ?? '' ) ] = true;
			}
		}

		$safe = array();
		$conflicts = array();
		foreach ( $groups as $legacy_identity => $source_identities ) {
			if ( count( $source_identities ) > 1 ) {
				$conflicts[ $legacy_identity ] = true;
				continue;
			}
			$safe[ $legacy_identity ] = true;
		}

		return array( 'safe' => $safe, 'conflicts' => $conflicts );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function legacy_identities_for_row( array $row ): array {
		$identities = array_merge(
			array( (string) ( $row['legacy_source_identity'] ?? '' ) ),
			array_map( 'strval', (array) ( $row['legacy_source_identities'] ?? array() ) )
		);

		return array_values( array_filter( array_unique( $identities ), static fn( string $identity ): bool => '' !== $identity && $identity !== (string) ( $row['source_identity'] ?? '' ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{safe:array<string,true>,conflicts:array<string,true>} $legacy_identities
	 * @return array<string,mixed>
	 */
	private function with_legacy_migration_metadata( array $row, array $legacy_identities ): array {
		$row_legacy_identities = $this->legacy_identities_for_row( $row );
		$has_conflict = array() !== array_filter( $row_legacy_identities, static fn( string $identity ): bool => isset( $legacy_identities['conflicts'][ $identity ] ) );
		$allowed = $has_conflict
			? array()
			: array_values( array_filter( $row_legacy_identities, static fn( string $identity ): bool => isset( $legacy_identities['safe'][ $identity ] ) ) );

		return array_merge(
			$row,
			array(
				'legacy_override_migration_allowed' => array() !== $allowed,
				'legacy_override_migration_allowed_identities' => $allowed,
			)
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
	private function stats( array $rows ): array {
		$stats = array( 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'ignored' => 0, 'invalid' => 0 );
		foreach ( $rows as $row ) {
			$status = (string) ( $row['match_status'] ?? '' );
			if ( array_key_exists( $status, $stats ) ) {
				++$stats[ $status ];
			}
		}

		return $stats;
	}

	private function acquire_lock(): bool {
		if ( property_exists( $this->wpdb, 'jet_import_lock_busy' ) && $this->wpdb->jet_import_lock_busy ) {
			return false;
		}
		if ( property_exists( $this->wpdb, 'jet_import_lock_acquired' ) ) {
			$this->wpdb->jet_import_lock_acquired = true;
			return true;
		}
		if ( ! method_exists( $this->wpdb, 'get_var' ) ) {
			return true;
		}
		$result = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_NAME ) );
		return '1' === (string) $result;
	}

	private function release_lock(): void {
		if ( property_exists( $this->wpdb, 'jet_import_lock_acquired' ) ) {
			$this->wpdb->jet_import_lock_acquired = false;
		}
		if ( method_exists( $this->wpdb, 'get_var' ) ) {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		}
	}

	private function log_stage( string $stage, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		error_log( '[walls-delivery-calc] ' . $stage . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) );
	}

	private function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	private function safe_error_message( \Throwable $exception ): string {
		return mb_substr( preg_replace( '/\s+/', ' ', $exception->getMessage() ) ?? '', 0, 300, 'UTF-8' );
	}
}
