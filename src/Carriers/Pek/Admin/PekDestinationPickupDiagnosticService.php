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
			return $this->failure_report( 'pek_destination_location_missing', 'Canonical location not found.', 'Canonical location not found.' );
		}
		$country = strtoupper( trim( $location->country_code ) );
		if ( ! in_array( $country, PekSettings::PLANNED_COUNTRIES, true ) ) {
			return $this->failure_report( 'pek_destination_country_unsupported', 'PEK destination diagnostics supports only planned PEK countries.', 'PEK destination diagnostics supports only planned PEK countries.' );
		}
		try {
			$cargo = $this->cargo_from_input( $input );
		} catch ( RuntimeException $exception ) {
			return $this->failure_report( 'pek_invalid_pickup_query', $exception->getMessage(), 'Некорректные параметры диагностического груза.' );
		}
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
		$success = true === ( $terminal_report['success'] ?? false );
		$error_code = (string) ( $terminal_report['error_code'] ?? '' );
		$message = $success
			? ( array() === $points ? 'Диагностика направления ПЭК выполнена. Подходящие терминалы не найдены.' : 'Диагностика направления ПЭК выполнена.' )
			: $this->message_for_error_code( $error_code );

		return array(
			'success' => $success,
			'error_code' => $error_code,
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
			'message' => $message,
		);
	}

	/** @param array<string,mixed> $input */
	private function cargo_from_input( array $input ): PickupCargoConstraints {
		$weight_kg = $this->positive_float( $input, 'pek_destination_weight_kg', 0.001, 100000 );
		$length = $this->positive_float( $input, 'pek_destination_length_cm', 0.1, 2000 );
		$width = $this->positive_float( $input, 'pek_destination_width_cm', 0.1, 2000 );
		$height = $this->positive_float( $input, 'pek_destination_height_cm', 0.1, 2000 );
		$max_place_kg = $this->positive_float( $input, 'pek_destination_max_place_weight_kg', 0.001, 100000 );
		$places = $this->positive_int( $input, 'pek_destination_places_count', 1, 1000 );
		$total_volume = $length * $width * $height * $places;
		if ( $total_volume > 1000000000 ) {
			throw new RuntimeException( 'Diagnostic cargo volume is too large.' );
		}

		return new PickupCargoConstraints(
			(int) ceil( $weight_kg * 1000 ),
			(int) ceil( $total_volume ),
			(int) ceil( max( $length, $width, $height ) ),
			(int) ceil( $max_place_kg * 1000 ),
			$places
		);
	}

	/** @param array<string,mixed> $input */
	private function positive_float( array $input, string $key, float $min, float $max ): float {
		if ( ! array_key_exists( $key, $input ) || ! is_numeric( $input[ $key ] ) ) {
			throw new RuntimeException( 'Diagnostic cargo field is required and must be numeric.' );
		}
		$value = (float) $input[ $key ];
		if ( ! is_finite( $value ) || $value <= 0 || $value < $min || $value > $max ) {
			throw new RuntimeException( 'Diagnostic cargo field is outside the allowed range.' );
		}

		return $value;
	}

	/** @param array<string,mixed> $input */
	private function positive_int( array $input, string $key, int $min, int $max ): int {
		if ( ! array_key_exists( $key, $input ) || ! is_numeric( $input[ $key ] ) ) {
			throw new RuntimeException( 'Diagnostic cargo places count is required and must be numeric.' );
		}
		$value = (float) $input[ $key ];
		if ( ! is_finite( $value ) || floor( $value ) !== $value || $value < $min || $value > $max ) {
			throw new RuntimeException( 'Diagnostic cargo places count is outside the allowed range.' );
		}

		return (int) $value;
	}

	/** @return array<string,mixed> */
	private function failure_report( string $error_code, string $error, string $message ): array {
		return array(
			'success' => false,
			'error_code' => $error_code,
			'checked_at' => $this->now(),
			'location' => array(),
			'terminals' => array( 'total_returned' => 0, 'free_count' => 0, 'paid_count' => 0, 'rejected_invalid' => 0, 'rejected_limits' => 0, 'api_source' => '', 'query_fingerprint' => '', 'points' => array() ),
			'errors' => array( $error ),
			'message' => $message,
		);
	}

	private function message_for_error_code( string $error_code ): string {
		return match ( $error_code ) {
			'pek_destination_location_unsupported' => 'ПЭК не подтвердил обслуживание выбранного населённого пункта.',
			'pek_invalid_pickup_query', 'pek_canonical_location_required' => 'Некорректные параметры диагностического груза.',
			'pek_destination_country_mismatch' => 'Страна запроса не совпадает с canonical location.',
			default => 'Не удалось выполнить диагностику направления ПЭК.',
		};
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
