<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekSenderWarehouseService {
	public function __construct(
		private PekApiClient $api,
		private PekSettings $settings
	) {
	}

	/** @return array{success:bool,message:string,items:array<int,array<string,mixed>>,requested:array<string,mixed>} */
	public function search( string $address ): array {
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
		$this->settings->save_warehouse_search_result( $result );

		return $result;
	}

	/** @return array{success:bool,message:string,snapshot:array<string,mixed>} */
	public function select_from_cached_search( string $warehouse_id ): array {
		$warehouse_id = trim( $warehouse_id );
		$search = $this->settings->last_warehouse_search();
		$items = is_array( $search['items'] ?? null ) ? $search['items'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && $warehouse_id === (string) ( $item['warehouseId'] ?? '' ) && $this->supports_ltl_pickup( $item ) ) {
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
						return $this->normalize_department(
							array_merge(
								$warehouse,
								array(
									'warehouseId' => (string) $warehouse['id'],
									'branchId' => (string) ( $branch['id'] ?? '' ),
									'branchName' => (string) ( $branch['name'] ?? '' ),
									'divisionName' => (string) ( $division['name'] ?? '' ),
									'kindsOfTransportation' => is_array( $division['kindsOfTransportation'] ?? null ) ? $division['kindsOfTransportation'] : array(),
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
		$operation_ok = array() === $kinds;
		foreach ( $kinds as $kind ) {
			if ( is_array( $kind ) && ( 2 === (int) ( $kind['departmentOperation'] ?? 0 ) || 2 === (int) ( $kind['operation'] ?? 0 ) || 2 === (int) ( $kind['id'] ?? 0 ) ) ) {
				$operation_ok = true;
			} elseif ( 2 === (int) $kind ) {
				$operation_ok = true;
			}
		}

		return $type_ok && $operation_ok;
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
			'checked_at' => $this->now(),
		);
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
