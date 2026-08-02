<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

use RuntimeException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekDestinationPickupDiagnosticService {
	public function __construct(
		private CarrierPickupPointProviderRegistry $providers,
		private LocationRepository $locations,
		private PekTerminalService $terminals,
		private PekSettings $settings
	) {
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function run( array $input ): array {
		$location_id = max( 0, (int) ( $input['pek_destination_location_id'] ?? 0 ) );
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active ) {
			throw new RuntimeException( 'Canonical location not found.' );
		}
		$country = strtoupper( trim( $location->country_code ) );
		if ( ! in_array( $country, PekSettings::PLANNED_COUNTRIES, true ) ) {
			throw new RuntimeException( 'PEK destination diagnostics supports only planned PEK countries.' );
		}
		$cargo = $this->cargo_from_input( $input );
		$query = new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			$location_id,
			$country,
			'',
			$location->latitude,
			$location->longitude,
			$cargo,
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			$this->settings->pek_destination_terminal_search_radius(),
			$this->settings->pek_destination_terminal_search_limit()
		);
		$provider = $this->providers->get( PekSettings::CARRIER_KEY );
		if ( null === $provider ) {
			throw new RuntimeException( 'PEK pickup provider is not registered.' );
		}
		$points = $provider->search( $query );
		$terminal_report = $this->terminals->last_report();
		$mapping = is_array( $terminal_report['mapping'] ?? null ) ? $terminal_report['mapping'] : array();

		return array(
			'success' => true,
			'checked_at' => $this->now(),
			'location' => array(
				'location_id' => $location_id,
				'country' => $country,
				'canonical_address' => $location->resolved_display_name(),
				'coordinates_available' => $location->has_coordinates(),
				'resolution_method' => (string) ( $mapping['resolution_method'] ?? '' ),
				'mapping_state' => (string) ( $mapping['mapping_state'] ?? '' ),
				'precision' => (string) ( $mapping['precision'] ?? '' ),
				'branch' => (string) ( $mapping['branch_title'] ?? '' ),
				'zone' => (string) ( $mapping['zone_name'] ?? '' ),
				'main_warehouse_id' => (string) ( $mapping['main_warehouse_id'] ?? '' ),
				'mapping_cache_hit' => (bool) ( $mapping['cache_hit'] ?? false ),
			),
			'terminals' => array(
				'total_returned' => count( $points ),
				'free_count' => (int) ( $terminal_report['free_count'] ?? 0 ),
				'paid_count' => (int) ( $terminal_report['paid_count'] ?? 0 ),
				'rejected_invalid' => (int) ( $terminal_report['rejected_invalid'] ?? 0 ),
				'rejected_limits' => (int) ( $terminal_report['rejected_limits'] ?? 0 ),
				'api_source' => (string) ( $terminal_report['api_source'] ?? '' ),
				'query_fingerprint' => (string) ( $terminal_report['query_fingerprint'] ?? '' ),
				'points' => array_slice( array_map( static fn( $point ): array => $point->to_array(), $points ), 0, 20 ),
			),
			'message' => 'Диагностика направления ПЭК выполнена.',
		);
	}

	/** @param array<string,mixed> $input */
	private function cargo_from_input( array $input ): PickupCargoConstraints {
		$weight_kg = $this->positive_float( $input['pek_destination_weight_kg'] ?? 1, 1, 100000 );
		$length = $this->positive_float( $input['pek_destination_length_cm'] ?? 10, 1, 2000 );
		$width = $this->positive_float( $input['pek_destination_width_cm'] ?? 10, 1, 2000 );
		$height = $this->positive_float( $input['pek_destination_height_cm'] ?? 10, 1, 2000 );
		$max_place_kg = $this->positive_float( $input['pek_destination_max_place_weight_kg'] ?? $weight_kg, 1, 100000 );
		$places = max( 1, min( 1000, (int) ( $input['pek_destination_places_count'] ?? 1 ) ) );

		return new PickupCargoConstraints(
			(int) ceil( $weight_kg * 1000 ),
			(int) ceil( $length * $width * $height ),
			(int) ceil( max( $length, $width, $height ) ),
			(int) ceil( $max_place_kg * 1000 ),
			$places
		);
	}

	private function positive_float( mixed $value, float $min, float $max ): float {
		$value = is_numeric( $value ) ? (float) $value : $min;

		return max( $min, min( $max, $value ) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
