<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyImportService {
	private const SEEN_COUNTRIES_KEY = 'jet_logistic_seen_country_codes';

	public function __construct(
		private JetLogisticCitiesCsvParser $parser,
		private JetLogisticGeographyMatcher $matcher,
		private JetLogisticGeographyRepository $repository,
		private DeliveryServiceRepository $services,
		private DeliveryServiceCountryRepository $countries,
		private SettingsRepository $settings
	) {
	}

	/** @return array<string,mixed> */
	public function import_csv( string $csv ): array {
		$parsed = $this->parser->parse( $csv );
		if ( array() === $parsed ) {
			return array( 'success' => false, 'message' => 'Jet Logistic CSV has no rows.', 'rows' => 0 );
		}
		$matched = array_map( fn( array $row ): array => $this->matcher->match( $row ), $parsed );
		$this->repository->replace_snapshot( $matched );
		$this->sync_new_countries();

		return array(
			'success' => true,
			'rows' => count( $matched ),
			'stats' => $this->stats( $matched ),
		);
	}

	private function sync_new_countries(): void {
		$service = $this->services->find_by_service_key( JetLogisticSettings::SERVICE_KEY );
		if ( null === $service || null === $service->id ) {
			return;
		}
		$discovered = $this->repository->matched_country_codes();
		$seen = $this->settings->get_array( self::SEEN_COUNTRIES_KEY, array() );
		$seen = array_values( array_unique( array_map( 'strval', $seen ) ) );
		$enabled = $this->countries->countries( (int) $service->id );
		$new = array_values( array_diff( $discovered, $seen ) );
		if ( array() !== $new ) {
			$this->countries->replace_countries( (int) $service->id, array_values( array_unique( array_merge( $enabled, $new ) ) ) );
		}
		$this->settings->set( self::SEEN_COUNTRIES_KEY, array_values( array_unique( array_merge( $seen, $discovered ) ) ) );
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
