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
				$availability = $this->availability_status( $item, false );
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
			$availability = $this->availability_status( $item, true );
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

	/** @param array<string,mixed> $item @return array{success:bool,message:string} */
	private function availability_status( array $item, bool $require_valid_machine_dates ): array {
		foreach ( array(
			'endOfAvailabilityBeforeClosing' => 'Склад ПЭК больше недоступен для подачи заявок.',
			'endOfCostCalculationAvailability' => 'Склад ПЭК больше недоступен для расчёта.',
			'departmentClosingDate' => 'Отделение ПЭК закрыто.',
		) as $field => $message ) {
			if ( ! array_key_exists( $field, $item ) || null === $item[ $field ] || '' === trim( (string) $item[ $field ] ) ) {
				continue;
			}
			$expires_at = $this->parse_availability_datetime( (string) $item[ $field ] );
			if ( null === $expires_at ) {
				return array( 'success' => false, 'message' => 'ПЭК вернул некорректную дату доступности склада.' );
			}
			if ( $expires_at < $this->now_timestamp() ) {
				return array( 'success' => false, 'message' => $message );
			}
		}

		return array( 'success' => true, 'message' => '' );
	}

	private function parse_availability_datetime( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$timezone = $this->timezone();
		try {
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value . ' 23:59:59', $timezone );
				return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : null;
			}
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?$/', $value ) ) {
				return null;
			}
			$has_timezone = 1 === preg_match( '/(?:Z|[+\-]\d{2}:?\d{2})$/', $value );
			$date = $has_timezone ? new \DateTimeImmutable( $value ) : new \DateTimeImmutable( $value, $timezone );
			return $date->getTimestamp();
		} catch ( \Exception ) {
			return null;
		}
	}

	private function now_timestamp(): int {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'timestamp' );
		}

		return time();
	}

	private function timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new \DateTimeZone( 'UTC' );
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
			'limits' => array(
				'maxWeight' => $item['maxWeight'] ?? null,
				'maxVolume' => $item['maxVolume'] ?? null,
				'maxDimension' => $item['maxDimension'] ?? null,
				'maxWeightOnePlace' => $item['maxWeightOnePlace'] ?? null,
				'maxCount' => $item['maxCount'] ?? null,
			),
			'availability' => array(
				'endOfAvailabilityBeforeClosing' => (string) ( $item['endOfAvailabilityBeforeClosing'] ?? '' ),
				'endOfCostCalculationAvailability' => (string) ( $item['endOfCostCalculationAvailability'] ?? '' ),
				'departmentClosingDate' => (string) ( $item['departmentClosingDate'] ?? '' ),
			),
			'checked_at' => $this->now(),
		);
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
