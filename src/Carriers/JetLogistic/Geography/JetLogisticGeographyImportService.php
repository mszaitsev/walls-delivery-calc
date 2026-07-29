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
			return array( 'success' => false, 'message' => 'В файле cities.csv не найдено строк для импорта.', 'rows' => 0 );
		}
		$matched = array_map( fn( array $row ): array => $this->matcher->match( $row ), $parsed );
		$this->repository->replace_snapshot( $matched );
		$this->country_sync->sync_discovered_countries();

		return array(
			'success' => true,
			'message' => 'География Jet Logistic успешно импортирована.',
			'rows' => count( $matched ),
			'stats' => $this->stats( $matched ),
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
