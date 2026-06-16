<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportService {
	public function __construct(
		private DpdGeographyCsvParser $parser,
		private DpdGeographyMatcher $matcher,
		private LocationDeliveryCodeRepository $delivery_codes,
		private ?DpdSettings $settings = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function import_file( string $path, string $source, string $source_file ): array {
		$report = new DpdGeographyImportReport( $source, $source_file );
		$pending = array();
		$blocked = array();
		$matched_methods = array();

		try {
			foreach ( $this->parser->rows( $path ) as $row ) {
				$report->increment( 'total_rows' );
				if ( 'RU' !== strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) ) ) {
					$report->increment( 'skipped_non_ru' );
					continue;
				}
				$report->increment( 'ru_rows' );
				$dpd_city_id = preg_replace( '/\D+/', '', (string) ( $row['dpd_city_id'] ?? '' ) ) ?? '';
				if ( '' === $dpd_city_id ) {
					$report->increment( 'skipped_invalid' );
					continue;
				}

				$match = $this->matcher->match( $row );
				if ( 'ambiguous' === $match['status'] ) {
					$report->increment( 'ambiguous' );
					continue;
				}
				if ( 'matched' !== $match['status'] || ! $match['location'] instanceof Location || null === $match['location']->id ) {
					$report->increment( 'unmatched' );
					continue;
				}

				$location_id = (int) $match['location']->id;
				if ( isset( $blocked[ $location_id ] ) ) {
					continue;
				}
				if ( isset( $pending[ $location_id ] ) && $pending[ $location_id ] !== $dpd_city_id ) {
					unset( $pending[ $location_id ], $matched_methods[ $location_id ] );
					$blocked[ $location_id ] = true;
					$report->increment( 'conflicts' );
					continue;
				}

				$pending[ $location_id ] = $dpd_city_id;
				$matched_methods[ $location_id ] = (string) $match['method'];
			}

			foreach ( $pending as $location_id => $dpd_city_id ) {
				if ( isset( $blocked[ $location_id ] ) ) {
					continue;
				}
				$method = $matched_methods[ $location_id ] ?? '';
				if ( '' !== $method ) {
					$report->increment( 'matched_by_' . $method );
				}
				$current = $this->delivery_codes->get_dpd_city_id( (int) $location_id );
				if ( $current === $dpd_city_id ) {
					$report->increment( 'unchanged_mappings' );
					continue;
				}
				if ( $this->delivery_codes->save_dpd_city_id( (int) $location_id, $dpd_city_id ) ) {
					$report->increment( 'saved_mappings' );
				} else {
					$report->add_error( 'Failed to save mapping for location_id=' . (int) $location_id );
				}
			}
		} catch ( Throwable $throwable ) {
			$report->add_error( $throwable->getMessage() );
		}

		$report->finish();
		$data = $report->to_array();
		$this->settings?->save_geography_import_report( $data );

		return $data;
	}
}
