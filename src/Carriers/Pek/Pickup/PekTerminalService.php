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
				'failure_stage' => 'destination_terminal_request',
				'message' => 'PEK provider requires canonical location ID.',
			);
			return array();
		}
		$errors = $query->validate();
		if ( array() !== $errors ) {
			$this->last_report = array( 'success' => false, 'error_code' => 'pek_invalid_pickup_query', 'failure_stage' => 'destination_terminal_request', 'errors' => $errors );
			return array();
		}
		try {
			$mapping = $this->locations->resolve( $query->location_id );
		} catch ( PekApiException $exception ) {
			$context = $exception->context();
			$this->last_report = array(
				'success' => false,
				'error_code' => (string) ( $context['error_code'] ?? 'pek_destination_location_api_failed' ),
				'failure_stage' => 'location_resolution',
				'endpoint' => (string) ( $context['endpoint'] ?? '' ),
				'method' => (string) ( $context['method'] ?? '' ),
				'http_status' => $context['http_status'] ?? '',
				'api_source' => 'api',
				'cache_hit' => false,
				'mapping' => array(),
				'total_returned' => 0,
				'free_count' => 0,
				'paid_count' => 0,
				'rejected_invalid' => 0,
				'rejected_limits' => 0,
				'rejection_reasons' => $this->empty_rejection_reasons(),
				'response_shape' => is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array(),
				'message' => 'PEK destination location API response could not be used.',
			);
			throw $exception;
		}
		if ( 'unsupported' === (string) ( $mapping['mapping_state'] ?? '' ) ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_destination_location_unsupported',
				'failure_stage' => 'location_resolution',
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
				'failure_stage' => 'destination_terminal_request',
				'mapping' => $mapping,
				'message' => 'PEK destination country does not match canonical mapping.',
			);
			return array();
		}
		$converted = $this->converter->convert( $query->cargo );
		$has_destination_coordinates = $this->has_usable_mapping_coordinates( $mapping );
		$address = trim( (string) ( $mapping['normalized_address'] ?? '' ) );
		if ( '' === $address ) {
			$this->last_report = array(
				'success' => false,
				'error_code' => 'pek_destination_address_missing',
				'failure_stage' => 'destination_terminal_request',
				'mapping' => $mapping,
				'message' => 'PEK destination mapping has no usable destination address.',
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
		$request_payload = $request->to_payload();
		$fingerprint = $this->cache->fingerprint( array( 'endpoint' => '/branches/nearestdepartments/', 'method' => 'POST', 'mapping' => $mapping['address_fingerprint'] ?? '', 'country_code' => $mapping_country, 'payload' => $request_payload ) );
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
			'rejection_reasons' => $this->empty_rejection_reasons(),
			'failure_stage' => '',
			'endpoint' => '/branches/nearestdepartments/',
			'method' => 'POST',
			'http_status' => '',
			'response_shape' => array(),
			'field_errors' => array(),
		);
		try {
			$response = $this->api->destination_nearest_departments( $request );
			$result = $this->normalize_response( $response, $query, $mapping );
		} catch ( PekApiException $exception ) {
			$context = $exception->context();
			$this->last_report = array_merge(
				$base_report,
				array(
					'error_code' => (string) ( $context['error_code'] ?? 'pek_destination_terminal_api_failed' ),
					'failure_stage' => (string) ( $context['failure_stage'] ?? 'destination_terminal_contract' ),
					'endpoint' => (string) ( $context['endpoint'] ?? $base_report['endpoint'] ),
					'method' => (string) ( $context['method'] ?? $base_report['method'] ),
					'http_status' => $context['http_status'] ?? '',
					'api_error_message' => $exception->getMessage(),
					'response_shape' => is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array(),
					'field_errors' => is_array( $context['field_errors'] ?? null ) ? $context['field_errors'] : array(),
					'input_row_count' => (int) ( $context['input_row_count'] ?? 0 ),
					'valid_structural_row_count' => (int) ( $context['valid_structural_row_count'] ?? 0 ),
					'rejected_invalid' => (int) ( $context['rejected_invalid'] ?? 0 ),
					'rejected_limits' => (int) ( $context['rejected_limits'] ?? 0 ),
					'rejection_reasons' => is_array( $context['rejection_reasons'] ?? null ) ? array_merge( $this->empty_rejection_reasons(), $context['rejection_reasons'] ) : $this->empty_rejection_reasons(),
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
			'rejection_reasons' => $result['rejection_reasons'],
			'failure_stage' => '',
			'endpoint' => '/branches/nearestdepartments/',
			'method' => 'POST',
			'http_status' => 200,
			'response_shape' => $result['response_shape'],
		);
		try {
			$this->terminals->upsert_many( $result['terminal_rows'] );
		} catch ( \RuntimeException $exception ) {
			$this->last_report = array_merge( $metadata, array( 'success' => false, 'error_code' => 'pek_terminal_persistence_failed', 'failure_stage' => 'destination_terminal_persistence', 'message' => 'PEK terminal persistence failed.' ) );
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

	/** @param array<string,mixed> $response @param array<string,mixed> $mapping @return array{points:array<int,PickupPoint>,terminal_rows:array<int,array<string,mixed>>,free_count:int,paid_count:int,rejected_invalid:int,rejected_limits:int,input_row_count:int,valid_structural_row_count:int,rejection_reasons:array<string,int>,response_shape:array<string,mixed>} */
	private function normalize_response( array $response, CarrierPickupPointQuery $query, array $mapping ): array {
		$points = array();
		$terminal_rows = array();
		$report = array(
			'free_count' => 0,
			'paid_count' => 0,
			'rejected_invalid' => 0,
			'rejected_limits' => 0,
			'input_row_count' => 0,
			'valid_structural_row_count' => 0,
			'rejection_reasons' => $this->empty_rejection_reasons(),
			'response_shape' => $this->response_shape( $response ),
		);
		foreach ( array( 'freeDepartments' => 'free', 'paidDepartments' => 'paid' ) as $key => $source ) {
			foreach ( $response[ $key ] as $row ) {
				++$report['input_row_count'];
				$invalid_reason = $this->invalid_row_reason( $row, $query, $mapping );
				if ( '' !== $invalid_reason ) {
					++$report['rejected_invalid'];
					++$report['rejection_reasons'][ $invalid_reason ];
					continue;
				}
				/** @var array<string,mixed> $row */
				$normalized = $this->normalize_row( $row, $source, $query, $mapping );
				if ( array() === $normalized ) {
					++$report['rejected_invalid'];
					++$report['rejection_reasons']['unknown'];
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
					'method' => 'POST',
					'error_code' => 'pek_destination_terminal_rows_invalid',
					'http_status' => 200,
					'failure_stage' => 'destination_terminal_normalization',
					'response_shape' => $report['response_shape'],
					'input_row_count' => $report['input_row_count'],
					'valid_structural_row_count' => $report['valid_structural_row_count'],
					'rejected_invalid' => $report['rejected_invalid'],
					'rejected_limits' => $report['rejected_limits'],
					'rejection_reasons' => $report['rejection_reasons'],
				)
			);
		}

		return array_merge( array( 'points' => $points, 'terminal_rows' => $terminal_rows ), $report );
	}

	/** @return array<string,int> */
	private function empty_rejection_reasons(): array {
		return array(
			'row_not_object' => 0,
			'warehouse_id' => 0,
			'coordinates' => 0,
			'text_fields' => 0,
			'integer_fields' => 0,
			'limits' => 0,
			'work_time' => 0,
			'schedule' => 0,
			'timezone' => 0,
			'unknown' => 0,
		);
	}

	/** @param array<string,mixed> $mapping */
	private function invalid_row_reason( mixed $row, CarrierPickupPointQuery $query, array $mapping ): string {
		unset( $query );
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			return 'row_not_object';
		}
		if ( '' === $this->required_text( $row, 'warehouseId', 64 ) ) {
			return 'warehouse_id';
		}
		$coordinates = is_array( $row['coordinates'] ?? null ) && ! array_is_list( $row['coordinates'] ) ? $row['coordinates'] : array();
		if ( null === $this->coordinate_component( $coordinates['latitude'] ?? null, -90, 90 ) || null === $this->coordinate_component( $coordinates['longitude'] ?? null, -180, 180 ) ) {
			return 'coordinates';
		}
		foreach ( array( 'branchId', 'branchName', 'divisionName', 'departmentType', 'address' ) as $key ) {
			if ( "\0" === $this->optional_text( $row, $key, 1000 ) ) {
				return 'text_fields';
			}
		}
		if ( ( array_key_exists( 'branchTimezone', $row ) && null !== $row['branchTimezone'] && ! is_string( $row['branchTimezone'] ) ) || ( array_key_exists( 'timeZone', $row ) && null !== $row['timeZone'] && ! is_string( $row['timeZone'] ) ) ) {
			return 'timezone';
		}
		if ( null === $this->optional_integer( $row['departmentTypeId'] ?? null ) || null === $this->optional_integer( $row['priority'] ?? null ) ) {
			return 'integer_fields';
		}
		foreach ( array( 'maxWeight', 'maxVolume', 'maxDimension', 'maxWeightOnePlace' ) as $key ) {
			if ( false === $this->normalize_limit( $row[ $key ] ?? null ) ) {
				return 'limits';
			}
		}
		if ( false === $this->normalize_limit( $row['maxCount'] ?? null, true ) ) {
			return 'limits';
		}
		if ( null === $this->work_time( $row ) ) {
			return 'work_time';
		}
		if ( null === $this->availability( $row, $mapping ) ) {
			return 'schedule';
		}

		return '';
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function response_shape( array $response ): array {
		return array(
			'root_type' => 'object',
			'root_keys' => array_values( array_slice( array_map( 'strval', array_keys( $response ) ), 0, 30 ) ),
			'free_departments_present' => array_key_exists( 'freeDepartments', $response ),
			'free_departments_type' => is_array( $response['freeDepartments'] ?? null ) && array_is_list( $response['freeDepartments'] ) ? 'list' : 'missing',
			'free_departments_count' => is_array( $response['freeDepartments'] ?? null ) && array_is_list( $response['freeDepartments'] ) ? count( $response['freeDepartments'] ) : 0,
			'paid_departments_present' => array_key_exists( 'paidDepartments', $response ),
			'paid_departments_type' => is_array( $response['paidDepartments'] ?? null ) && array_is_list( $response['paidDepartments'] ) ? 'list' : 'missing',
			'paid_departments_count' => is_array( $response['paidDepartments'] ?? null ) && array_is_list( $response['paidDepartments'] ) ? count( $response['paidDepartments'] ) : 0,
		);
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
		$availability = $this->availability( $row, $mapping );
		if (
			'' === $id
			|| null === $lat
			|| null === $lng
			|| null === $department_type_id
			|| null === $priority
			|| null === $work_time
			|| null === $availability
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
			'availability' => $availability,
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
		if ( count( $days ) > 14 ) {
			return null;
		}
		$parts = array();
		foreach ( $days as $day ) {
			if ( ! is_array( $day ) || array_is_list( $day ) ) {
				return null;
			}
			$day_of_week = $this->optional_text( $day, 'dayOfWeek', 16 );
			$work_from = $this->optional_text( $day, 'workFrom', 16 );
			$work_to = $this->optional_text( $day, 'workTo', 16 );
			if ( in_array( "\0", array( $day_of_week, $work_from, $work_to ), true ) ) {
				return null;
			}
			if ( '' === $day_of_week && '' === $work_from && '' === $work_to ) {
				continue;
			}
			$range = '' !== $work_from || '' !== $work_to ? trim( $work_from . '-' . $work_to, '-' ) : '';
			$parts[] = trim( '' !== $day_of_week && '' !== $range ? $day_of_week . ': ' . $range : $day_of_week . $range );
		}

		return implode( '; ', array_filter( $parts ) );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $mapping @return array<string,mixed>|null */
	private function availability( array $row, array $mapping ): ?array {
		$short_work_days = $this->schedule_short_work_days( $row['scheduleShortWorkDays'] ?? null );
		$holiday_days = $this->schedule_holiday_days( $row['scheduleHolidayDays'] ?? null );
		if ( null === $short_work_days || null === $holiday_days ) {
			return null;
		}

		return array(
			'scheduleShortWorkDays' => $short_work_days,
			'scheduleHolidayDays' => $holiday_days,
			'mapping_state' => in_array( (string) ( $mapping['mapping_state'] ?? '' ), array( 'resolved', 'near' ), true ) ? (string) $mapping['mapping_state'] : '',
			'precision' => in_array( (string) ( $mapping['precision'] ?? '' ), array( 'exact', 'near', '' ), true ) ? (string) ( $mapping['precision'] ?? '' ) : '',
		);
	}

	/** @return array<int,array<string,mixed>>|null */
	private function schedule_short_work_days( mixed $value ): ?array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			return null;
		}
		$days = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				return null;
			}
			$date = $this->machine_datetime( $item['date'] ?? null );
			if ( '' === $date ) {
				return null;
			}
			$work_time = array();
			if ( array_key_exists( 'workTime', $item ) && null !== $item['workTime'] ) {
				if ( ! is_array( $item['workTime'] ) || array_is_list( $item['workTime'] ) ) {
					return null;
				}
				$from = $this->clock_time( $item['workTime']['periodTimeFrom'] ?? null );
				$to = $this->clock_time( $item['workTime']['periodTimeTo'] ?? null );
				if ( '' === $from || '' === $to ) {
					return null;
				}
				$work_time = array( 'periodTimeFrom' => $from, 'periodTimeTo' => $to );
			}
			$break_time = '';
			if ( array_key_exists( 'breakTime', $item ) && null !== $item['breakTime'] ) {
				if ( ! is_string( $item['breakTime'] ) ) {
					return null;
				}
				$break_time = $this->safe_string( $item['breakTime'], 64 );
			}
			$days[] = array_filter(
				array(
					'date' => $date,
					'workTime' => $work_time,
					'breakTime' => $break_time,
				),
				static fn( mixed $field ): bool => array() !== $field && '' !== $field
			);
		}

		return $days;
	}

	/** @return array<int,string>|null */
	private function schedule_holiday_days( mixed $value ): ?array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			return null;
		}
		$days = array();
		foreach ( $value as $item ) {
			$date = $this->machine_datetime( $item );
			if ( '' === $date ) {
				return null;
			}
			$days[] = $date;
		}

		return $days;
	}

	private function machine_datetime( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value ) ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\TH:i:s' ) !== $value ) {
			return '';
		}

		return $value;
	}

	private function clock_time( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value ) ) {
			return '';
		}

		return $value;
	}

	private function safe_string( string $value, int $max_length ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( substr( $value, 0, $max_length ) );
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
