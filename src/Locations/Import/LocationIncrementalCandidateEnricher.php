<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService;

defined( 'ABSPATH' ) || exit;

/** Resolves one NEW candidate row; persistence remains with the update workflow. */
final class LocationIncrementalCandidateEnricher {
	public function __construct(
		private DaDataPostcodeClient $postcodes,
		private LocationCoordinatesDadataBatchUpdater $coordinates,
		private RussianPostCourierCalcPostcodeFillStateService $courier
	) {}

	public function resolve( string $stage, array $row, array $state = array() ): array {
		if ( 'RU' !== (string) ( $row['country_code'] ?? '' ) ) {
			throw new \RuntimeException( 'Only new RU candidate rows may be enriched.' );
		}
		if ( ! in_array( $stage, array( 'enrich_postcodes', 'enrich_coordinates', 'enrich_russianpost_courier' ), true ) ) {
			throw new \RuntimeException( 'Unknown candidate enrichment stage.' );
		}
		try {
			return $this->resolve_location( $stage, $row, $state );
		} catch ( \Throwable $error ) {
			return array( 'done' => true, 'pause' => false, 'patch' => array(), 'state' => array(), 'outcome' => 'errors', 'message' => $error->getMessage() );
		}
	}

	private function resolve_location( string $stage, array $row, array $state ): array {
		$result = array( 'done' => true, 'pause' => false, 'patch' => array(), 'state' => array(), 'outcome' => 'skipped', 'message' => '' );
		if ( 'enrich_postcodes' === $stage ) {
			if ( preg_match( '/^\d{6}$/D', trim( (string) ( $row['postal_code'] ?? '' ) ) ) ) {
				return $result;
			}
			$response = $this->postcodes->find_postal_code( $row );
			if ( ! empty( $response['tokens_exhausted'] ) || 'dadata_daily_limit_exhausted' === ( $response['error_code'] ?? '' ) ) {
				return array_replace( $result, array( 'pause' => true, 'done' => false ) );
			}
			if ( empty( $response['success'] ) ) {
				return array_replace( $result, array( 'outcome' => 'errors', 'message' => (string) ( $response['error_code'] ?? '' ) ) );
			}
			$code = trim( (string) ( $response['postal_code'] ?? '' ) );
			$result['patch']['postal_code'] = '' === $code ? '999999999' : $code;
			$result['outcome'] = '' === $code ? 'no_index' : 'updated';
			return $result;
		}
		if ( 'enrich_coordinates' === $stage ) {
			$lat = (float) ( $row['latitude'] ?? 0 );
			$lng = (float) ( $row['longitude'] ?? 0 );
			if ( 0.0 !== $lat && 0.0 !== $lng && abs( $lat ) <= 90 && abs( $lng ) <= 180 ) {
				return $result;
			}
			$response = $this->coordinates->coordinates_for_location( $row );
			if ( 'stopped' === $response['status'] ) {
				return array_replace( $result, array( 'pause' => true, 'done' => false ) );
			}
			if ( 'updated' === $response['status'] ) {
				$result['patch'] = array( 'latitude' => $response['lat'], 'longitude' => $response['lng'] );
				$result['outcome'] = 'updated';
			} else {
				$result['outcome'] = 'no_dadata_success' === $response['reason'] ? 'errors' : 'skipped';
				$result['message'] = $response['reason'];
			}
			return $result;
		}
		if ( 'enrich_russianpost_courier' !== $stage ) {
			throw new \RuntimeException( 'Unknown candidate enrichment stage.' );
		}
		$response = $this->courier->step_candidate_location( $row, $state );
		$result['done'] = (int) $response['processed'] > 0;
		$result['state'] = $result['done'] ? array() : $response;
		if ( isset( $response['resolved_postcode'] ) ) {
			$result['patch']['russianpost_courier_calc_postal_code'] = $response['resolved_postcode'];
		}
		$result['outcome'] = (int) $response['errors'] > 0 ? 'errors' : ( (int) $response['updated'] > 0 ? 'updated' : 'no_index' );
		$result['message'] = (string) ( $response['last_error_code'] ?? '' );
		return $result;
	}
}
