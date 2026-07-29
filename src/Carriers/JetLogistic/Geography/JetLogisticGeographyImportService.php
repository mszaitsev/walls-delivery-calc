<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyImportService {
	public function __construct(
		private JetLogisticCitiesCsvParser $parser,
		private JetLogisticGeographyMatcher $matcher,
		private JetLogisticGeographyRepository $repository,
		private JetLogisticCountrySyncService $country_sync
	) {
	}

	/** @return array<string,mixed> */
	public function import_csv( string $csv ): array {
		$parsed = $this->parser->parse( $csv );
		if ( array() === $parsed ) {
			return array( 'success' => false, 'message' => 'В файле cities.csv не найдено строк для импорта.', 'rows' => 0, 'rows_read' => 0, 'rows_unique' => 0, 'duplicates' => 0, 'legacy_identity_conflicts' => 0 );
		}
		$deduplicated = $this->deduplicate_rows( $parsed );
		$legacy_identities = $this->classify_legacy_identities( $deduplicated['rows'] );
		$matched = array_map( fn( array $row ): array => $this->matcher->match( $this->with_legacy_migration_metadata( $row, $legacy_identities ) ), $deduplicated['rows'] );
		$this->repository->replace_snapshot( $matched );
		$this->country_sync->sync_discovered_countries();

		return array(
			'success' => true,
			'message' => 'География Jet Logistic успешно импортирована.',
			'rows' => count( $matched ),
			'rows_read' => count( $parsed ),
			'rows_unique' => count( $matched ),
			'duplicates' => $deduplicated['duplicates'],
			'duplicate_conflicts' => $deduplicated['duplicate_conflicts'],
			'legacy_identity_conflicts' => count( $legacy_identities['conflicts'] ),
			'stats' => $this->stats( $matched ),
		);
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
}
