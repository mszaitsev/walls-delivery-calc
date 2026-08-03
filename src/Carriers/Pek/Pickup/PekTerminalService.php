<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;

defined( 'ABSPATH' ) || exit;

final class PekTerminalService {
	/** @var array<string,mixed> */
	private array $last_report = array();

	public function __construct(
		private PekLocationResolver $locations,
		private PekApiClient $api,
		private PekCargoConstraintsConverter $converter,
		private PekDestinationTerminalSearchCache $cache,
		private PekTerminalRepository $terminals,
		private PekSettings $settings
	) {
	}

	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query, bool $use_cache = true ): array {
		$this->last_report = array();
		if ( $query->location_id <= 0 ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_canonical_location_required',
				'message' => 'PEK provider requires canonical location ID.',
			);
			return array();
		}
		$errors = $query->validate();
		if ( array() !== $errors ) {
			$this->last_report = array( 'success' => false, 'error_code' => 'pek_invalid_pickup_query', 'errors' => $errors );
			return array();
		}
		$mapping = $this->locations->resolve( $query->location_id );
		if ( 'unsupported' === (string) ( $mapping['mapping_state'] ?? '' ) ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_destination_location_unsupported',
				'mapping' => $mapping,
				'message' => 'PEK location is unsupported.',
			);
			return array();
		}
		$mapping_country = strtoupper( trim( (string) ( $mapping['country_code'] ?? '' ) ) );
		if ( $query->normalized_country_code() !== $mapping_country ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_destination_country_mismatch',
				'mapping' => $mapping,
				'message' => 'PEK destination country does not match canonical mapping.',
			);
			return array();
		}
		$converted = $this->converter->convert( $query->cargo );
		$has_destination_coordinates = $this->has_usable_mapping_coordinates( $mapping );
		$address = $has_destination_coordinates ? '' : trim( (string) ( $mapping['normalized_address'] ?? '' ) );
		if ( ! $has_destination_coordinates && '' === $address ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_destination_mapping_incomplete',
				'mapping' => $mapping,
				'message' => 'PEK destination mapping has neither usable destination coordinates nor address.',
			);
			return array();
		}
		$request = new PekDestinationTerminalRequest(
			$address,
			$has_destination_coordinates ? (float) $mapping['latitude'] : null,
			$has_destination_coordinates ? (float) $mapping['longitude'] : null,
			$converted['weight_kg'],
			$converted['volume_m3'],
			$converted['max_dimension_m'],
			$converted['max_weight_per_place_kg'],
			$converted['places_count'],
			max( 1, min( 500, $query->radius_km ) ),
			max( 1, min( 100, $query->limit ) )
		);
		$fingerprint = $this->cache->fingerprint( array( 'mapping' => $mapping['address_fingerprint'] ?? '', 'country_code' => $mapping_country, 'cargo' => $query->cargo->to_array(), 'operation' => 3, 'type' => PekSettings::LTL_PRODUCT_TYPE, 'radius' => $request->radius_km, 'limit' => $request->limit ) );
		if ( $use_cache ) {
			$cached = $this->cache->get( $fingerprint );
			if ( $cached['hit'] ) {
				$this->last_report = array_merge( $cached['metadata'], array( 'success' => true, 'error_code' => '', 'api_source' => 'cache', 'cache_hit' => true, 'mapping' => $mapping, 'total_returned' => count( $cached['points'] ) ) );
				return $cached['points'];
			}
		}
		$base_report = array(
			'success' => false,
			'error_code' => '',
			'api_source' => 'api',
			'cache_hit' => false,
			'query_fingerprint' => $fingerprint,
			'mapping' => $mapping,
			'total_returned' => 0,
			'free_count' => 0,
			'paid_count' => 0,
			'rejected_invalid' => 0,
			'rejected_limits' => 0,
		);
		try {
			$response = $this->api->destination_nearest_departments( $request );
			$result = $this->normalize_response( $response, $query, $mapping );
		} catch ( PekApiException $exception ) {
			$this->last_report = array_merge(
				$base_report,
				array(
					'error_code' => (string) ( $exception->context()['error_code'] ?? 'pek_destination_terminal_api_failed' ),
					'message' => 'PEK destination terminal API response could not be used.',
				)
			);
			throw $exception;
		}
		$metadata = array(
			'success' => true,
			'error_code' => '',
			'api_source' => 'api',
			'cache_hit' => false,
			'query_fingerprint' => $fingerprint,
			'mapping' => $mapping,
			'total_returned' => count( $result['points'] ),
			'free_count' => $result['free_count'],
			'paid_count' => $result['paid_count'],
			'rejected_invalid' => $result['rejected_invalid'],
			'rejected_limits' => $result['rejected_limits'],
		);
		try {
			$this->terminals->upsert_many( $result['terminal_rows'] );
		} catch ( \RuntimeException $exception ) {
			$this->last_report = array_merge( $metadata, array( 'success' => false, 'error_code' => 'pek_terminal_persistence_failed', 'message' => 'PEK terminal persistence failed.' ) );
			throw $exception;
		}
		$this->cache->save( $fingerprint, $metadata, $result['points'], $this->settings->pek_destination_terminal_cache_ttl() );
		$this->last_report = $metadata;

		return $result['points'];
	}

	public function resolve_selection( CarrierPickupPointQuery $query, string $warehouse_id ): ?PickupPoint {
		$points = $this->search( $query, false );
		$warehouse_id = trim( $warehouse_id );
		foreach ( $points as $point ) {
			if ( $point->code === $warehouse_id ) {
				return $point;
			}
		}

		return null;
	}

	/** @return array<string,mixed> */
	public function last_report(): array {
		return $this->last_report;
	}

	/** @param array<string,mixed> $response @param array<string,mixed> $mapping @return array{points:array<int,PickupPoint>,terminal_rows:array<int,array<string,mixed>>,free_count:int,paid_count:int,rejected_invalid:int,rejected_limits:int,input_row_count:int,valid_structural_row_count:int} */
	private function normalize_response( array $response, CarrierPickupPointQuery $query, array $mapping ): array {
		$points = array();
		$terminal_rows = array();
		$report = array( 'free_count' => 0, 'paid_count' => 0, 'rejected_invalid' => 0, 'rejected_limits' => 0, 'input_row_count' => 0, 'valid_structural_row_count' => 0 );
		foreach ( array( 'freeDepartments' => 'free', 'paidDepartments' => 'paid' ) as $key => $source ) {
			foreach ( $response[ $key ] as $row ) {
				++$report['input_row_count'];
				if ( ! is_array( $row ) ) {
					++$report['rejected_invalid'];
					continue;
				}
				$normalized = $this->normalize_row( $row, $source, $query, $mapping );
				if ( array() === $normalized ) {
					++$report['rejected_invalid'];
					continue;
				}
				++$report['valid_structural_row_count'];
				if ( ! $this->passes_limits( $normalized, $query ) ) {
					++$report['rejected_limits'];
					continue;
				}
				'free' === $source ? ++$report['free_count'] : ++$report['paid_count'];
				$terminal_rows[] = $normalized;
				$points[] = $this->point_from_row( $normalized, $mapping );
			}
		}
		if ( $report['input_row_count'] > 0 && 0 === $report['valid_structural_row_count'] ) {
			throw new PekApiException(
				'ПЭК вернул некорректные строки терминалов назначения.',
				array(
					'endpoint' => '/branches/nearestdepartments/',
					'error_code' => 'pek_destination_terminal_rows_invalid',
				)
			);
		}

		return array_merge( array( 'points' => $points, 'terminal_rows' => $terminal_rows ), $report );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $mapping @return array<string,mixed> */
	private function normalize_row( array $row, string $source, CarrierPickupPointQuery $query, array $mapping ): array {
		$id = $this->required_text( $row, 'warehouseId', 64 );
		$coordinates = is_array( $row['coordinates'] ?? null ) && ! array_is_list( $row['coordinates'] ) ? $row['coordinates'] : array();
		$lat = $this->coordinate_component( $coordinates['latitude'] ?? null, -90, 90 );
		$lng = $this->coordinate_component( $coordinates['longitude'] ?? null, -180, 180 );
		$department_type_id = $this->optional_integer( $row['departmentTypeId'] ?? null );
		$priority = $this->optional_integer( $row['priority'] ?? null );
		$timezone = $this->optional_text( $row, 'branchTimezone', 32 );
		if ( '' === $timezone ) {
			$timezone = $this->optional_text( $row, 'timeZone', 32 );
		}
		$branch_id = $this->optional_text( $row, 'branchId', 64 );
		$branch_name = $this->optional_text( $row, 'branchName', 191 );
		$division_name = $this->optional_text( $row, 'divisionName', 191 );
		$department_type = $this->optional_text( $row, 'departmentType', 191 );
		$address = $this->optional_text( $row, 'address', 1000 );
		$limits = array(
			'maxWeight' => $this->normalize_limit( $row['maxWeight'] ?? null ),
			'maxVolume' => $this->normalize_limit( $row['maxVolume'] ?? null ),
			'maxDimension' => $this->normalize_limit( $row['maxDimension'] ?? null ),
			'maxWeightOnePlace' => $this->normalize_limit( $row['maxWeightOnePlace'] ?? null ),
			'maxCount' => $this->normalize_limit( $row['maxCount'] ?? null, true ),
		);
		$work_time = $this->work_time( $row );
		if (
			'' === $id
			|| null === $lat
			|| null === $lng
			|| null === $department_type_id
			|| null === $priority
			|| null === $work_time
			|| in_array( "\0", array( $branch_id, $branch_name, $division_name, $department_type, $address, $timezone ), true )
			|| in_array( false, $limits, true )
		) {
			return array();
		}

		return array(
			'warehouse_id' => $id,
			'branch_id' => $branch_id,
			'branch_name' => $branch_name,
			'division_name' => $division_name,
			'department_type_id' => $department_type_id,
			'department_type' => $department_type,
			'source' => $source,
			'country_code' => strtoupper( trim( (string) ( $mapping['country_code'] ?? '' ) ) ),
			'address' => $address,
			'latitude' => $lat,
			'longitude' => $lng,
			'timezone' => $this->normalize_timezone( $timezone ),
			'priority' => $priority,
			'maxWeight' => $limits['maxWeight'],
			'maxVolume' => $limits['maxVolume'],
			'maxDimension' => $limits['maxDimension'],
			'maxWeightOnePlace' => $limits['maxWeightOnePlace'],
			'maxCount' => $limits['maxCount'],
			'work_time' => $work_time,
			'availability' => array(
				'scheduleShortWorkDays' => is_array( $row['scheduleShortWorkDays'] ?? null ) ? $row['scheduleShortWorkDays'] : array(),
				'scheduleHolidayDays' => is_array( $row['scheduleHolidayDays'] ?? null ) ? $row['scheduleHolidayDays'] : array(),
				'mapping_state' => (string) ( $mapping['mapping_state'] ?? '' ),
				'precision' => (string) ( $mapping['precision'] ?? '' ),
			),
		);
	}

	/** @param array<string,mixed> $row */
	private function passes_limits( array $row, CarrierPickupPointQuery $query ): bool {
		$limits = array(
			'maxWeight' => $query->cargo->weight_g / 1000,
			'maxVolume' => $query->cargo->volume_cm3 / 1000000,
			'maxDimension' => $query->cargo->max_dimension_cm / 100,
			'maxWeightOnePlace' => $query->cargo->max_place_weight_g / 1000,
			'maxCount' => $query->cargo->places_count,
		);
		foreach ( $limits as $field => $actual ) {
			$limit = $row[ $field ] ?? null;
			if ( is_numeric( $limit ) && (float) $limit > 0 && $actual > (float) $limit ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $mapping */
	private function point_from_row( array $row, array $mapping ): PickupPoint {
		return new PickupPoint(
			PekSettings::CARRIER_KEY,
			(string) $row['warehouse_id'],
			(string) $row['address'],
			'',
			'',
			'',
			(float) $row['latitude'],
			(float) $row['longitude'],
			'free' === $row['source'] ? 'terminal' : 'pvz',
			(string) $row['work_time'],
			'',
			null,
			true,
			array(
				'warehouse_id' => $row['warehouse_id'],
				'branch_id' => $row['branch_id'],
				'branch_name' => $row['branch_name'],
				'division_name' => $row['division_name'],
				'department_type_id' => $row['department_type_id'],
				'department_type' => $row['department_type'],
				'source' => $row['source'],
				'priority' => $row['priority'],
				'limits' => array(
					'maxWeight' => $row['maxWeight'],
					'maxVolume' => $row['maxVolume'],
					'maxDimension' => $row['maxDimension'],
					'maxWeightOnePlace' => $row['maxWeightOnePlace'],
					'maxCount' => $row['maxCount'],
				),
				'timezone' => $row['timezone'],
				'availability' => $row['availability'],
				'mapping_state' => $mapping['mapping_state'] ?? '',
				'mapping_precision' => $mapping['precision'] ?? '',
			)
		);
	}

	/** @param array<string,mixed> $row */
	private function work_time( array $row ): ?string {
		if ( ! array_key_exists( 'divisionTimeOfWork', $row ) || null === $row['divisionTimeOfWork'] ) {
			return '';
		}
		if ( ! is_array( $row['divisionTimeOfWork'] ) || ! array_is_list( $row['divisionTimeOfWork'] ) ) {
			return null;
		}
		$days = $row['divisionTimeOfWork'];
		if ( array() === $days ) {
			return '';
		}
		$parts = array();
		foreach ( array_slice( $days, 0, 7 ) as $day ) {
			if ( ! is_array( $day ) || array_is_list( $day ) ) {
				return null;
			}
			$day_of_week = $this->optional_text( $day, 'dayOfWeek', 16 );
			$work_from = $this->optional_text( $day, 'workFrom', 16 );
			$work_to = $this->optional_text( $day, 'workTo', 16 );
			if ( in_array( "\0", array( $day_of_week, $work_from, $work_to ), true ) ) {
				return null;
			}
			$parts[] = trim( $day_of_week . ': ' . $work_from . '-' . $work_to );
		}

		return implode( '; ', array_filter( $parts ) );
	}

	/** @param array<string,mixed> $row */
	private function required_text( array $row, string $key, int $max_length ): string {
		if ( ! array_key_exists( $key, $row ) || ! is_string( $row[ $key ] ) ) {
			return '';
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $row[ $key ] ) ?? $row[ $key ];
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > $max_length ) {
			return '';
		}

		return $value;
	}

	/** @param array<string,mixed> $row */
	private function optional_text( array $row, string $key, int $max_length ): string {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
			return '';
		}
		if ( ! is_string( $row[ $key ] ) ) {
			return "\0";
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $row[ $key ] ) ?? $row[ $key ];

		return trim( substr( $value, 0, $max_length ) );
	}

	private function optional_integer( mixed $value ): ?int {
		if ( null === $value ) {
			return 0;
		}
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}

		return null;
	}

	private function coordinate_component( mixed $value, float $min, float $max ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < $min || $number > $max ) {
			return null;
		}

		return $number;
	}

	private function normalize_limit( mixed $value, bool $integer = false ): null|false|int|float {
		if ( null === $value ) {
			return null;
		}
		if ( $integer ) {
			if ( is_int( $value ) ) {
				return $value >= 0 ? $value : false;
			}
			if ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) {
				return (int) trim( $value );
			}

			return false;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return false;
		}
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < 0 ) {
			return false;
		}

		return $number;
	}

	/** @param array<string,mixed> $mapping */
	private function has_usable_mapping_coordinates( array $mapping ): bool {
		if ( ! is_numeric( $mapping['latitude'] ?? null ) || ! is_numeric( $mapping['longitude'] ?? null ) ) {
			return false;
		}
		$latitude = (float) $mapping['latitude'];
		$longitude = (float) $mapping['longitude'];

		return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
	}

	private function normalize_timezone( mixed $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^UTC[+\-](\d{2}):(\d{2})$/', strtoupper( $value ), $match ) ) {
			$hours = (int) $match[1];
			$minutes = (int) $match[2];
			if ( $hours <= 14 && $minutes <= 59 && ( 14 !== $hours || 0 === $minutes ) ) {
				return strtoupper( $value );
			}
		}
		if ( preg_match( '/^(\d{2}):(\d{2}):(\d{2})$/', $value, $match ) ) {
			$hours = (int) $match[1];
			$minutes = (int) $match[2];
			$seconds = (int) $match[3];
			if ( $hours <= 14 && $minutes <= 59 && 0 === $seconds && ( 14 !== $hours || 0 === $minutes ) ) {
				return sprintf( 'UTC+%02d:%02d', $hours, $minutes );
			}
		}

		return '';
	}
}
