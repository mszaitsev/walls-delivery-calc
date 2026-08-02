<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
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
		$errors = $query->validate();
		if ( array() !== $errors ) {
			$this->last_report = array( 'success' => false, 'errors' => $errors );
			return array();
		}
		$mapping = $this->locations->resolve( $query->location_id );
		if ( 'unsupported' === (string) ( $mapping['mapping_state'] ?? '' ) ) {
			$this->last_report = array( 'success' => false, 'mapping' => $mapping, 'message' => 'PEK location is unsupported.' );
			return array();
		}
		$converted = $this->converter->convert( $query->cargo );
		$request = new PekDestinationTerminalRequest(
			(string) ( $mapping['normalized_address'] ?? $query->fallback_address ),
			null !== $query->latitude ? $query->latitude : ( is_numeric( $mapping['latitude'] ?? null ) ? (float) $mapping['latitude'] : null ),
			null !== $query->longitude ? $query->longitude : ( is_numeric( $mapping['longitude'] ?? null ) ? (float) $mapping['longitude'] : null ),
			$converted['weight_kg'],
			$converted['volume_m3'],
			$converted['max_dimension_m'],
			$converted['max_weight_per_place_kg'],
			$converted['places_count'],
			max( 1, min( 500, $query->radius_km ) ),
			max( 1, min( 100, $query->limit ) )
		);
		$fingerprint = $this->cache->fingerprint( array( 'mapping' => $mapping['address_fingerprint'] ?? '', 'country_code' => $query->normalized_country_code(), 'cargo' => $query->cargo->to_array(), 'operation' => 3, 'type' => PekSettings::LTL_PRODUCT_TYPE, 'radius' => $request->radius_km, 'limit' => $request->limit ) );
		if ( $use_cache ) {
			$cached = $this->cache->get( $fingerprint );
			if ( array() !== $cached['points'] ) {
				$this->last_report = array_merge( $cached['metadata'], array( 'api_source' => 'cache', 'cache_hit' => true, 'mapping' => $mapping ) );
				return $cached['points'];
			}
		}
		$response = $this->api->destination_nearest_departments( $request );
		$result = $this->normalize_response( $response, $query, $mapping );
		$this->terminals->upsert_many( $result['terminal_rows'] );
		$metadata = array(
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

	/** @param array<string,mixed> $response @param array<string,mixed> $mapping @return array{points:array<int,PickupPoint>,terminal_rows:array<int,array<string,mixed>>,free_count:int,paid_count:int,rejected_invalid:int,rejected_limits:int} */
	private function normalize_response( array $response, CarrierPickupPointQuery $query, array $mapping ): array {
		$points = array();
		$terminal_rows = array();
		$report = array( 'free_count' => 0, 'paid_count' => 0, 'rejected_invalid' => 0, 'rejected_limits' => 0 );
		foreach ( array( 'freeDepartments' => 'free', 'paidDepartments' => 'paid' ) as $key => $source ) {
			foreach ( is_array( $response[ $key ] ?? null ) ? $response[ $key ] : array() as $row ) {
				if ( ! is_array( $row ) ) {
					++$report['rejected_invalid'];
					continue;
				}
				$normalized = $this->normalize_row( $row, $source, $query, $mapping );
				if ( array() === $normalized ) {
					++$report['rejected_invalid'];
					continue;
				}
				if ( ! $this->passes_limits( $normalized, $query ) ) {
					++$report['rejected_limits'];
					continue;
				}
				'free' === $source ? ++$report['free_count'] : ++$report['paid_count'];
				$terminal_rows[] = $normalized;
				$points[] = $this->point_from_row( $normalized, $mapping );
			}
		}

		return array_merge( array( 'points' => $points, 'terminal_rows' => $terminal_rows ), $report );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $mapping @return array<string,mixed> */
	private function normalize_row( array $row, string $source, CarrierPickupPointQuery $query, array $mapping ): array {
		$id = trim( (string) ( $row['warehouseId'] ?? '' ) );
		$coordinates = is_array( $row['coordinates'] ?? null ) ? $row['coordinates'] : array();
		$lat = is_numeric( $coordinates['latitude'] ?? null ) ? (float) $coordinates['latitude'] : null;
		$lng = is_numeric( $coordinates['longitude'] ?? null ) ? (float) $coordinates['longitude'] : null;
		if ( '' === $id || null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return array();
		}

		return array(
			'warehouse_id' => $id,
			'branch_id' => trim( (string) ( $row['branchId'] ?? '' ) ),
			'branch_name' => trim( (string) ( $row['branchName'] ?? '' ) ),
			'division_name' => trim( (string) ( $row['divisionName'] ?? '' ) ),
			'department_type_id' => (int) ( $row['departmentTypeId'] ?? 0 ),
			'department_type' => trim( (string) ( $row['departmentType'] ?? '' ) ),
			'source' => $source,
			'country_code' => $query->normalized_country_code(),
			'address' => trim( (string) ( $row['address'] ?? '' ) ),
			'latitude' => $lat,
			'longitude' => $lng,
			'timezone' => $this->normalize_timezone( $row['branchTimezone'] ?? $row['timeZone'] ?? '' ),
			'priority' => (int) ( $row['priority'] ?? 0 ),
			'maxWeight' => $row['maxWeight'] ?? null,
			'maxVolume' => $row['maxVolume'] ?? null,
			'maxDimension' => $row['maxDimension'] ?? null,
			'maxWeightOnePlace' => $row['maxWeightOnePlace'] ?? null,
			'maxCount' => $row['maxCount'] ?? null,
			'work_time' => $this->work_time( $row ),
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
			(string) $row['branch_name'],
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
	private function work_time( array $row ): string {
		$days = is_array( $row['divisionTimeOfWork'] ?? null ) ? $row['divisionTimeOfWork'] : array();
		if ( array() === $days ) {
			return '';
		}
		$parts = array();
		foreach ( array_slice( $days, 0, 7 ) as $day ) {
			if ( is_array( $day ) ) {
				$parts[] = trim( (string) ( $day['dayOfWeek'] ?? '' ) . ': ' . (string) ( $day['workFrom'] ?? '' ) . '-' . (string) ( $day['workTo'] ?? '' ) );
			}
		}

		return implode( '; ', array_filter( $parts ) );
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
