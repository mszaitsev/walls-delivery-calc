<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekSenderWarehouseService {
	public function __construct(
		private PekApiClient $api,
		private PekSettings $settings,
		private PekSenderWarehouseSearchCache $search_cache
	) {
	}

	/** @return array{success:bool,message:string,items:array<int,array<string,mixed>>,requested:array<string,mixed>} */
	public function search( string $address ): array {
		$this->clear_last_search_for_current_user();
		$address = trim( $address );
		if ( '' === $address ) {
			return array( 'success' => false, 'message' => 'Введите адрес для поиска склада ПЭК.', 'items' => array(), 'requested' => array() );
		}
		$response = $this->api->nearest_departments( $address );
		$items = array_merge(
			$this->normalize_department_list( is_array( $response['freeDepartments'] ?? null ) ? $response['freeDepartments'] : array(), 'free' ),
			$this->normalize_department_list( is_array( $response['paidDepartments'] ?? null ) ? $response['paidDepartments'] : array(), 'paid' )
		);
		$result = array(
			'success' => true,
			'message' => array() === $items ? 'Склады ПЭК не найдены.' : 'Склады ПЭК найдены.',
			'items' => $items,
			'requested' => array(
				'address' => $address,
				'departmentOperation' => 2,
				'type' => PekSettings::LTL_PRODUCT_TYPE,
				'searchRadius' => $this->settings->warehouse_search_radius(),
				'limit' => $this->settings->warehouse_search_limit(),
			),
			'checked_at' => $this->now(),
		);
		$this->search_cache->save_for_current_user( $result );

		return $result;
	}

	public function clear_last_search_for_current_user(): void {
		$this->search_cache->clear_for_current_user();
	}

	/** @return array<string,mixed> */
	public function last_search_for_current_user(): array {
		return $this->search_cache->current_for_current_user();
	}

	/** @return array{success:bool,message:string,snapshot:array<string,mixed>} */
	public function select_from_cached_search( string $warehouse_id ): array {
		$warehouse_id = trim( $warehouse_id );
		$search = $this->search_cache->current_for_current_user();
		$requested = is_array( $search['requested'] ?? null ) ? $search['requested'] : array();
		if ( 2 !== (int) ( $requested['departmentOperation'] ?? 0 ) || PekSettings::LTL_PRODUCT_TYPE !== (int) ( $requested['type'] ?? 0 ) ) {
			return array( 'success' => false, 'message' => 'Последний серверный поиск склада ПЭК устарел или выполнен с неподходящими фильтрами.', 'snapshot' => array() );
		}
		$items = is_array( $search['items'] ?? null ) ? $search['items'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && $warehouse_id === (string) ( $item['warehouseId'] ?? '' ) ) {
				$availability = $this->availability_status( $item );
				if ( ! $availability['success'] ) {
					return array( 'success' => false, 'message' => $availability['message'], 'snapshot' => array() );
				}
				$snapshot = $this->snapshot( $item );
				$this->settings->save_sender_warehouse( $snapshot );
				return array( 'success' => true, 'message' => 'Склад самопривоза ПЭК сохранён.', 'snapshot' => $snapshot );
			}
		}

		return array( 'success' => false, 'message' => 'Выбранный склад не найден в серверном результате последнего поиска.', 'snapshot' => array() );
	}

	/** @return array{success:bool,message:string,snapshot:array<string,mixed>} */
	public function validate_and_select( string $warehouse_id ): array {
		$previous = $this->settings->sender_warehouse();
		try {
			$cached = $this->select_from_cached_search( $warehouse_id );
			if ( $cached['success'] ) {
				return $cached;
			}
			$branches = $this->api->branches_all_for_warehouse( $warehouse_id );
			$item = $this->find_warehouse_in_branches_all( $branches, $warehouse_id );
			if ( array() === $item || ! $this->supports_ltl_pickup( $item ) ) {
				$this->settings->save_sender_warehouse( $previous );
				return array( 'success' => false, 'message' => 'ПЭК не подтвердил выбранный warehouse ID как склад приёма для LTL.', 'snapshot' => $previous );
			}
			$availability = $this->availability_status( $item );
			if ( ! $availability['success'] ) {
				$this->settings->save_sender_warehouse( $previous );
				return array( 'success' => false, 'message' => $availability['message'], 'snapshot' => $previous );
			}
			$snapshot = $this->snapshot( $item );
			$this->settings->save_sender_warehouse( $snapshot );

			return array( 'success' => true, 'message' => 'Склад самопривоза ПЭК подтверждён и сохранён.', 'snapshot' => $snapshot );
		} catch ( PekApiException $exception ) {
			$this->settings->save_sender_warehouse( $previous );

			return array( 'success' => false, 'message' => 'Не удалось подтвердить склад ПЭК. Ранее выбранный склад сохранён.', 'snapshot' => $previous );
		}
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function normalize_department_list( array $rows, string $source ): array {
		$items = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = $this->normalize_department( $row, $source, $index );
			if ( '' !== $normalized['warehouseId'] ) {
				$items[] = $normalized;
			}
		}

		return $items;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize_department( array $row, string $source, int $priority ): array {
		return array(
			'warehouseId' => trim( (string) ( $row['warehouseId'] ?? ( $row['id'] ?? '' ) ) ),
			'branchId' => trim( (string) ( $row['branchId'] ?? '' ) ),
			'branchName' => trim( (string) ( $row['branchName'] ?? '' ) ),
			'divisionName' => trim( (string) ( $row['divisionName'] ?? '' ) ),
			'departmentTypeId' => (int) ( $row['departmentTypeId'] ?? 0 ),
			'departmentType' => trim( (string) ( $row['departmentType'] ?? '' ) ),
			'address' => trim( (string) ( $row['address'] ?? ( $row['Address'] ?? '' ) ) ),
			'coordinates' => array(
				'latitude' => (string) ( $row['coordinates']['latitude'] ?? ( $row['latitude'] ?? '' ) ),
				'longitude' => (string) ( $row['coordinates']['longitude'] ?? ( $row['longitude'] ?? '' ) ),
			),
			'priority' => (int) ( $row['priority'] ?? $priority ),
			'source' => $source,
			'maxWeight' => $row['maxWeight'] ?? null,
			'maxVolume' => $row['maxVolume'] ?? null,
			'maxDimension' => $row['maxDimension'] ?? null,
			'maxWeightOnePlace' => $row['maxWeightOnePlace'] ?? ( $row['maxWeightPerPlace'] ?? null ),
			'maxCount' => $row['maxCount'] ?? null,
			'branchTimezone' => $row['branchTimezone'] ?? ( $row['timezone'] ?? null ),
			'endOfAvailabilityBeforeClosing' => $row['endOfAvailabilityBeforeClosing'] ?? null,
			'endOfCostCalculationAvailability' => $row['endOfCostCalculationAvailability'] ?? null,
			'departmentClosingDate' => $row['departmentClosingDate'] ?? null,
			'kindsOfTransportation' => is_array( $row['kindsOfTransportation'] ?? null ) ? $row['kindsOfTransportation'] : array(),
			'types' => is_array( $row['types'] ?? null ) ? $row['types'] : array(),
		);
	}

	/** @param array<string,mixed> $branches @return array<string,mixed> */
	private function find_warehouse_in_branches_all( array $branches, string $warehouse_id ): array {
		foreach ( is_array( $branches['branches'] ?? null ) ? $branches['branches'] : $branches as $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}
			foreach ( is_array( $branch['divisions'] ?? null ) ? $branch['divisions'] : array() as $division ) {
				if ( ! is_array( $division ) ) {
					continue;
				}
				foreach ( is_array( $division['warehouses'] ?? null ) ? $division['warehouses'] : array() as $warehouse ) {
					if ( is_array( $warehouse ) && $warehouse_id === (string) ( $warehouse['id'] ?? '' ) ) {
						$warehouse_has_capabilities = array_key_exists( 'kindsOfTransportation', $warehouse );
						return $this->normalize_department(
							array_merge(
								$warehouse,
								array(
									'warehouseId' => (string) $warehouse['id'],
									'branchId' => (string) ( $branch['id'] ?? '' ),
									'branchName' => (string) ( $branch['title'] ?? '' ),
									'divisionName' => (string) ( $warehouse['divisionName'] ?? ( $division['name'] ?? '' ) ),
									'departmentTypeId' => (int) ( $division['departmentTypeId'] ?? 0 ),
									'departmentType' => (string) ( $division['departmentType'] ?? '' ),
									'address' => (string) ( trim( (string) ( $warehouse['addressDivision'] ?? '' ) ) !== '' ? $warehouse['addressDivision'] : ( $warehouse['address'] ?? '' ) ),
									'coordinates' => is_array( $warehouse['coordinatesobj'] ?? null ) ? $warehouse['coordinatesobj'] : array(),
									'branchTimezone' => $branch['timezone'] ?? null,
									'kindsOfTransportation' => $warehouse_has_capabilities ? ( is_array( $warehouse['kindsOfTransportation'] ?? null ) ? $warehouse['kindsOfTransportation'] : array() ) : ( is_array( $division['kindsOfTransportation'] ?? null ) ? $division['kindsOfTransportation'] : array() ),
								)
							),
							'branches_all',
							0
						);
					}
				}
			}
		}

		return array();
	}

	/** @param array<string,mixed> $item */
	private function supports_ltl_pickup( array $item ): bool {
		if ( '' === (string) ( $item['warehouseId'] ?? '' ) ) {
			return false;
		}
		$types = array_map( 'intval', is_array( $item['types'] ?? null ) ? $item['types'] : array() );
		$type_ok = array() === $types || in_array( PekSettings::LTL_PRODUCT_TYPE, $types, true );
		$kinds = is_array( $item['kindsOfTransportation'] ?? null ) ? $item['kindsOfTransportation'] : array();
		foreach ( $kinds as $kind ) {
			if ( ! is_array( $kind ) || PekSettings::LTL_PRODUCT_TYPE !== (int) ( $kind['type'] ?? 0 ) ) {
				continue;
			}
			foreach ( is_array( $kind['operations'] ?? null ) ? $kind['operations'] : array() as $operation ) {
				if ( $this->is_cargo_acceptance_operation( (string) $operation ) ) {
					return $type_ok;
				}
			}
		}

		return false;
	}

	private function is_cargo_acceptance_operation( string $operation ): bool {
		$operation = trim( preg_replace( '/\s+/u', ' ', $operation ) ?? $operation );

		return 1 === preg_match( '/^при[её]м грузов$/iu', $operation );
	}

	/** @param array<string,mixed> $item @return array{success:bool,code:string,message:string} */
	private function availability_status( array $item ): array {
		foreach ( array(
			'endOfAvailabilityBeforeClosing' => array(
				'code' => 'pek_sender_warehouse_order_unavailable',
				'message' => 'Склад ПЭК больше недоступен для подачи заявок.',
			),
			'endOfCostCalculationAvailability' => array(
				'code' => 'pek_sender_warehouse_cost_unavailable',
				'message' => 'Склад ПЭК больше недоступен для расчёта.',
			),
			'departmentClosingDate' => array(
				'code' => 'pek_sender_warehouse_closed',
				'message' => 'Отделение ПЭК закрыто.',
			),
		) as $field => $failure ) {
			if ( ! array_key_exists( $field, $item ) || null === $item[ $field ] || '' === trim( (string) $item[ $field ] ) ) {
				continue;
			}
			$parsed = $this->parse_availability_datetime( (string) $item[ $field ], $item['branchTimezone'] ?? null );
			if ( ! $parsed['success'] ) {
				return $parsed;
			}
			if ( $this->now_timestamp() >= (int) $parsed['timestamp'] ) {
				return array( 'success' => false, 'code' => $failure['code'], 'message' => $failure['message'] );
			}
		}

		return array( 'success' => true, 'code' => '', 'message' => '' );
	}

	/** @return array{success:bool,code:string,message:string,timestamp?:int} */
	private function parse_availability_datetime( string $value, mixed $branch_timezone ): array {
		$value = trim( $value );
		if ( '' === $value ) {
			return array( 'success' => false, 'code' => 'pek_sender_warehouse_invalid_availability_date', 'message' => 'ПЭК вернул некорректную дату доступности склада.' );
		}

		$explicit_timezone = 1 === preg_match( '/(?:Z|[+\-]\d{2}:\d{2})$/', $value );
		$timezone = null;
		if ( ! $explicit_timezone ) {
			$timezone = $this->branch_timezone( is_string( $branch_timezone ) ? $branch_timezone : '' );
			if ( null === $timezone ) {
				return array(
					'success' => false,
					'code' => 'pek_sender_warehouse_invalid_branch_timezone',
					'message' => 'ПЭК не вернул корректный часовой пояс филиала для даты доступности склада.',
				);
			}
		}

		$date = $this->strict_availability_datetime( $value, $timezone );
		if ( ! $date instanceof \DateTimeImmutable ) {
			return array( 'success' => false, 'code' => 'pek_sender_warehouse_invalid_availability_date', 'message' => 'ПЭК вернул некорректную дату доступности склада.' );
		}

		return array( 'success' => true, 'code' => '', 'message' => '', 'timestamp' => $date->getTimestamp() );
	}

	private function strict_availability_datetime( string $value, ?\DateTimeZone $timezone ): ?\DateTimeImmutable {
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $this->create_checked_datetime( '!Y-m-d H:i:s', $value . ' 23:59:59', $timezone, $value, 'Y-m-d' );
		}

		$pattern = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+\-]\d{2}:\d{2})?$/';
		if ( 1 !== preg_match( $pattern, $value, $matches ) ) {
			return null;
		}

		$fraction = (string) ( $matches[2] ?? '' );
		$offset = (string) ( $matches[3] ?? '' );
		if ( '' !== $offset && ! $this->is_valid_datetime_offset( $offset ) ) {
			return null;
		}

		$parse_value = $matches[1];
		$format = '!Y-m-d\TH:i:s';
		$roundtrip_format = 'Y-m-d\TH:i:s';
		if ( '' !== $fraction ) {
			$parse_value .= '.' . str_pad( $fraction, 6, '0' );
			$format .= '.u';
			$roundtrip_format .= '.u';
		}
		if ( '' !== $offset ) {
			$parse_value .= 'Z' === $offset ? '+00:00' : $offset;
			$format .= 'P';
			$roundtrip_format .= 'P';
		}

		$expected = $parse_value;

		return $this->create_checked_datetime( $format, $parse_value, $timezone, $expected, $roundtrip_format );
	}

	private function create_checked_datetime( string $format, string $value, ?\DateTimeZone $timezone, string $expected, string $roundtrip_format ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( $format, $value, $timezone );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( (int) ( $errors['warning_count'] ?? 0 ) > 0 || (int) ( $errors['error_count'] ?? 0 ) > 0 ) ) ) {
			return null;
		}
		if ( $date->format( $roundtrip_format ) !== $expected ) {
			return null;
		}

		return $date;
	}

	private function branch_timezone( string $value ): ?\DateTimeZone {
		$value = strtoupper( trim( $value ) );
		if ( 'UTC' === $value || 'Z' === $value ) {
			return new \DateTimeZone( 'UTC' );
		}
		if ( 1 !== preg_match( '/^(?:UTC)?([+\-])(\d{2}):(\d{2})$/', $value, $matches ) ) {
			return null;
		}
		$hours = (int) $matches[2];
		$minutes = (int) $matches[3];
		if ( $hours > 14 || $minutes > 59 || ( 14 === $hours && 0 !== $minutes ) ) {
			return null;
		}

		try {
			return new \DateTimeZone( $matches[1] . sprintf( '%02d:%02d', $hours, $minutes ) );
		} catch ( \Exception ) {
			return null;
		}
	}

	private function is_valid_datetime_offset( string $offset ): bool {
		if ( 'Z' === $offset ) {
			return true;
		}
		if ( 1 !== preg_match( '/^[+\-](\d{2}):(\d{2})$/', $offset, $matches ) ) {
			return false;
		}
		$hours = (int) $matches[1];
		$minutes = (int) $matches[2];

		return $hours <= 14 && $minutes <= 59 && ( 14 !== $hours || 0 === $minutes );
	}

	private function now_timestamp(): int {
		if ( function_exists( 'current_datetime' ) ) {
			return current_datetime()->getTimestamp();
		}

		return time();
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function snapshot( array $item ): array {
		return array(
			'warehouseId' => (string) ( $item['warehouseId'] ?? '' ),
			'branchId' => (string) ( $item['branchId'] ?? '' ),
			'branchName' => (string) ( $item['branchName'] ?? '' ),
			'divisionName' => (string) ( $item['divisionName'] ?? '' ),
			'departmentTypeId' => (int) ( $item['departmentTypeId'] ?? 0 ),
			'departmentType' => (string) ( $item['departmentType'] ?? '' ),
			'address' => (string) ( $item['address'] ?? '' ),
			'coordinates' => is_array( $item['coordinates'] ?? null ) ? $item['coordinates'] : array(),
			'branchTimezone' => $item['branchTimezone'] ?? null,
			'limits' => array(
				'maxWeight' => $item['maxWeight'] ?? null,
				'maxVolume' => $item['maxVolume'] ?? null,
				'maxDimension' => $item['maxDimension'] ?? null,
				'maxWeightOnePlace' => $item['maxWeightOnePlace'] ?? null,
				'maxCount' => $item['maxCount'] ?? null,
			),
			'availability' => array(
				'endOfAvailabilityBeforeClosing' => $item['endOfAvailabilityBeforeClosing'] ?? null,
				'endOfCostCalculationAvailability' => $item['endOfCostCalculationAvailability'] ?? null,
				'departmentClosingDate' => $item['departmentClosingDate'] ?? null,
			),
			'checked_at' => $this->now(),
		);
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
