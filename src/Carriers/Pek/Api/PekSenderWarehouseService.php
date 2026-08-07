<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekSenderWarehouseService {
	public function __construct(
		private PekApiClient $api,
		private PekSettings $settings,
		private PekSenderWarehouseSearchCache $search_cache
	) {
	}

	/** @return array{success:bool,message:string,items:array<int,array<string,mixed>>,requested:array<string,mixed>} */
	public function search( string $address, ?PickupCargoConstraints $constraints = null ): array {
		$this->clear_last_search_for_current_user();
		$address = trim( $address );
		if ( '' === $address ) {
			return array( 'success' => false, 'message' => 'Введите адрес для поиска склада ПЭК.', 'items' => array(), 'requested' => array() );
		}
		$payload = $this->constraints_payload( $constraints );
		$response = $this->api->nearest_departments(
			$address,
			$payload['weight'],
			$payload['volume'],
			$payload['maxDimension'],
			$payload['maxWeightPerPlace']
		);
		$items = $this->normalize_department_list( is_array( $response['freeDepartments'] ?? null ) ? $response['freeDepartments'] : array(), 'free' );
		if ( $constraints instanceof PickupCargoConstraints ) {
			$items = array_values( array_filter( $items, fn( array $item ): bool => $this->fits_constraints( $item, $constraints ) ) );
		}
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
				'constraintsFingerprint' => $this->constraints_fingerprint( $constraints ),
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
		$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
		if ( '' === $warehouse_id ) {
			return array( 'success' => false, 'message' => 'ПЭК не вернул корректный warehouse ID для выбранного склада.', 'snapshot' => array() );
		}
		$search = $this->search_cache->current_for_current_user();
		$requested = is_array( $search['requested'] ?? null ) ? $search['requested'] : array();
		if ( 2 !== (int) ( $requested['departmentOperation'] ?? 0 ) || PekSettings::LTL_PRODUCT_TYPE !== (int) ( $requested['type'] ?? 0 ) ) {
			return array( 'success' => false, 'message' => 'Последний серверный поиск склада ПЭК устарел или выполнен с неподходящими фильтрами.', 'snapshot' => array() );
		}
		$items = is_array( $search['items'] ?? null ) ? $search['items'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && $warehouse_id === self::normalize_warehouse_id( $item['warehouseId'] ?? '' ) ) {
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
			$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
			if ( '' === $warehouse_id ) {
				return array( 'success' => false, 'message' => 'ПЭК не вернул корректный warehouse ID для выбранного склада.', 'snapshot' => $previous );
			}
			$cached = $this->select_from_cached_search( $warehouse_id );
			if ( $cached['success'] ) {
				return $cached;
			}
			$this->settings->save_sender_warehouse( $previous );

			return array( 'success' => false, 'message' => $cached['message'], 'snapshot' => $previous );
		} catch ( PekApiException $exception ) {
			$this->settings->save_sender_warehouse( $previous );

			return array( 'success' => false, 'message' => 'Не удалось подтвердить склад ПЭК. Ранее выбранный склад сохранён.', 'snapshot' => $previous );
		}
	}

	/** @return array{success:bool,message:string,snapshot:array<string,mixed>,diagnostic:array<string,mixed>} */
	public function validate_snapshot( string $warehouse_id, ?PickupCargoConstraints $constraints = null, string $trusted_address = '' ): array {
		$raw_warehouse_id = $warehouse_id;
		$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
		$diagnostic = $this->validation_diagnostic( '' === trim( $raw_warehouse_id ) ? 'empty_warehouse_id' : 'invalid_warehouse_id', 'nearest_fresh_revalidation', $warehouse_id, $trusted_address, $constraints );
		if ( '' === $warehouse_id ) {
			return array( 'success' => false, 'message' => 'Не выбран склад самопривоза ПЭК.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}
		$cached = $this->validate_cached_selection( $warehouse_id, $constraints );
		if ( null !== $cached ) {
			if ( ! empty( $cached['success'] ) || '' === trim( $trusted_address ) ) {
				return $cached;
			}
		}
		if ( '' === trim( $trusted_address ) ) {
			$diagnostic['reason'] = 'stale_modal_selection';

			return array( 'success' => false, 'message' => 'Выбранный склад ПЭК нужно подтвердить повторно. Выберите его ещё раз.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}

		return $this->validate_for_shipment( $warehouse_id, $trusted_address, $constraints );
	}

	/** @return array{success:bool,message:string,snapshot:array<string,mixed>,diagnostic:array<string,mixed>} */
	public function validate_for_shipment( string $warehouse_id, string $address, ?PickupCargoConstraints $constraints = null ): array {
		$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
		$address = trim( $address );
		$diagnostic = $this->validation_diagnostic( '' === $warehouse_id ? 'invalid_warehouse_id' : '', 'nearest_fresh_revalidation', $warehouse_id, $address, $constraints );
		if ( '' === $warehouse_id ) {
			return array( 'success' => false, 'message' => 'Не выбран склад самопривоза ПЭК.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}
		if ( '' === $address ) {
			$diagnostic['reason'] = 'missing_trusted_address';

			return array( 'success' => false, 'message' => 'Не удалось повторно подтвердить выбранный склад ПЭК. Выберите склад ещё раз.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}

		try {
			$result = $this->nearest_exact_match( $warehouse_id, $address, $constraints, $this->settings->warehouse_search_radius(), $this->settings->warehouse_search_limit() );
			if ( null === $result['item'] && ( $this->settings->warehouse_search_radius() < 500 || $this->settings->warehouse_search_limit() < 50 ) ) {
				$result = $this->nearest_exact_match( $warehouse_id, $address, $constraints, 500, 50 );
			}
			$diagnostic = $result['diagnostic'];
			if ( null === $result['item'] ) {
				$diagnostic['reason'] = 'nearest_exact_not_found';

				return array( 'success' => false, 'message' => 'Сохранённый склад ПЭК больше не подтверждается как доступный для приёма текущего груза.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
			}

			return $this->validate_nearest_item( $result['item'], $constraints, $diagnostic, 'Склад ПЭК подтверждён.' );
		} catch ( PekApiException $exception ) {
			$context = $exception->context();
			$diagnostic = $this->validation_diagnostic( is_string( $context['error_code'] ?? null ) ? $context['error_code'] : 'api_error', 'nearest_fresh_revalidation', $warehouse_id, $address, $constraints );
			$diagnostic['http_status'] = $context['http_status'] ?? $this->api->last_response_meta()['http_status'];

			return array( 'success' => false, 'message' => 'Не удалось повторно проверить склад самопривоза ПЭК.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}
	}

	/** @return ?array{success:bool,message:string,snapshot:array<string,mixed>,diagnostic:array<string,mixed>} */
	private function validate_cached_selection( string $warehouse_id, ?PickupCargoConstraints $constraints ): ?array {
		$search = $this->search_cache->current_for_current_user();
		if ( array() === $search ) {
			return null;
		}
		$requested = is_array( $search['requested'] ?? null ) ? $search['requested'] : array();
		if ( 2 !== (int) ( $requested['departmentOperation'] ?? 0 ) || PekSettings::LTL_PRODUCT_TYPE !== (int) ( $requested['type'] ?? 0 ) ) {
			return null;
		}
		if ( $this->constraints_fingerprint( $constraints ) !== (string) ( $requested['constraintsFingerprint'] ?? '' ) ) {
			return null;
		}
		$diagnostic = $this->validation_diagnostic( '', 'nearest_cached_selection', $warehouse_id, (string) ( $requested['address'] ?? '' ), $constraints );
		$items = is_array( $search['items'] ?? null ) ? $search['items'] : array();
		$matches = $this->matching_nearest_items( $items, $warehouse_id );
		$diagnostic['free_count'] = count( $items );
		$diagnostic['exact_match_count'] = count( $matches );
		if ( 1 !== count( $matches ) ) {
			$diagnostic['reason'] = 0 === count( $matches ) ? 'cached_selection_not_found' : 'cached_selection_ambiguous';

			return array( 'success' => false, 'message' => 'Выбранный склад ПЭК нужно подтвердить повторно. Выберите его ещё раз.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}

		return $this->validate_nearest_item( $matches[0], $constraints, $diagnostic, 'Склад ПЭК подтверждён.' );
	}

	/** @return array{item:?array<string,mixed>,diagnostic:array<string,mixed>} */
	private function nearest_exact_match( string $warehouse_id, string $address, ?PickupCargoConstraints $constraints, int $radius, int $limit ): array {
		$payload = $this->constraints_payload( $constraints );
		$response = $this->api->nearest_departments(
			$address,
			$payload['weight'],
			$payload['volume'],
			$payload['maxDimension'],
			$payload['maxWeightPerPlace'],
			$radius,
			$limit
		);
		$free_items = $this->normalize_department_list( is_array( $response['freeDepartments'] ?? null ) ? $response['freeDepartments'] : array(), 'free' );
		$paid_items = $this->normalize_department_list( is_array( $response['paidDepartments'] ?? null ) ? $response['paidDepartments'] : array(), 'paid' );
		$matches = $this->matching_nearest_items( $free_items, $warehouse_id );
		$diagnostic = $this->validation_diagnostic( '', 'nearest_fresh_revalidation', $warehouse_id, $address, $constraints );
		$diagnostic['http_status'] = $this->api->last_response_meta()['http_status'];
		$diagnostic['free_count'] = count( $free_items );
		$diagnostic['paid_count'] = count( $paid_items );
		$diagnostic['exact_match_count'] = count( $matches );

		return array( 'item' => 1 === count( $matches ) ? $matches[0] : null, 'diagnostic' => $diagnostic );
	}

	/** @param array<string,mixed> $item @param array<string,mixed> $diagnostic @return array{success:bool,message:string,snapshot:array<string,mixed>,diagnostic:array<string,mixed>} */
	private function validate_nearest_item( array $item, ?PickupCargoConstraints $constraints, array $diagnostic, string $success_message ): array {
		$availability = $this->availability_status( $item );
		if ( ! $availability['success'] ) {
			$diagnostic['reason'] = '' !== $availability['code'] ? $availability['code'] : 'availability_mismatch';
			$diagnostic['availability_match'] = false;

			return array( 'success' => false, 'message' => $availability['message'], 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}
		if ( $constraints instanceof PickupCargoConstraints && ! $this->fits_constraints( $item, $constraints ) ) {
			$diagnostic['reason'] = 'constraints_mismatch';
			$diagnostic['constraints_match'] = false;

			return array( 'success' => false, 'message' => 'Склад ПЭК не принимает текущие габариты или количество мест.', 'snapshot' => array(), 'diagnostic' => $diagnostic );
		}
		$snapshot = $this->snapshot( $item );
		$snapshot['source'] = (string) ( $diagnostic['source'] ?? '' );
		$diagnostic['reason'] = '';
		$diagnostic['warehouse_found'] = true;
		$diagnostic['matched_id_hash'] = '' !== (string) ( $snapshot['warehouseId'] ?? '' ) ? hash( 'sha256', (string) $snapshot['warehouseId'] ) : '';
		$diagnostic['availability_match'] = true;
		$diagnostic['constraints_match'] = true;

		return array( 'success' => true, 'message' => $success_message, 'snapshot' => $snapshot, 'diagnostic' => $diagnostic );
	}

	/** @param array<int,mixed> $items @return array<int,array<string,mixed>> */
	private function matching_nearest_items( array $items, string $warehouse_id ): array {
		$matches = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && self::normalize_warehouse_id( $item['warehouseId'] ?? '' ) === $warehouse_id ) {
				$matches[] = $item;
			}
		}

		return $matches;
	}

	/** @return array{weight:?float,volume:?float,maxDimension:?float,maxWeightPerPlace:?float,placesCount:int} */
	private function constraints_payload( ?PickupCargoConstraints $constraints ): array {
		if ( ! $constraints instanceof PickupCargoConstraints ) {
			return array( 'weight' => null, 'volume' => null, 'maxDimension' => null, 'maxWeightPerPlace' => null, 'placesCount' => 0 );
		}

		return array(
			'weight' => $constraints->weight_g > 0 ? $constraints->weight_g / 1000 : null,
			'volume' => $constraints->volume_cm3 > 0 ? $constraints->volume_cm3 / 1000000 : null,
			'maxDimension' => $constraints->max_dimension_cm > 0 ? $constraints->max_dimension_cm / 100 : null,
			'maxWeightPerPlace' => $constraints->max_place_weight_g > 0 ? $constraints->max_place_weight_g / 1000 : null,
			'placesCount' => max( 0, $constraints->places_count ),
		);
	}

	private function constraints_fingerprint( ?PickupCargoConstraints $constraints ): string {
		$payload = $this->constraints_payload( $constraints );
		$payload['departmentOperation'] = 2;
		$payload['type'] = PekSettings::LTL_PRODUCT_TYPE;

		return hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) ?: '{}' );
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
		$warehouse_id = self::normalize_warehouse_id( $row['warehouseId'] ?? ( $row['id'] ?? '' ) );

		return array(
			'warehouseId' => $warehouse_id,
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
			'branchTimezone' => $this->normalize_department_timezone( $row ),
			'endOfAvailabilityBeforeClosing' => $row['endOfAvailabilityBeforeClosing'] ?? null,
			'endOfCostCalculationAvailability' => $row['endOfCostCalculationAvailability'] ?? null,
			'departmentClosingDate' => $row['departmentClosingDate'] ?? null,
			'kindsOfTransportation' => is_array( $row['kindsOfTransportation'] ?? null ) ? $row['kindsOfTransportation'] : array(),
			'divisionCapabilitiesPresent' => true === ( $row['divisionCapabilitiesPresent'] ?? false ),
			'warehouseCapabilitiesPresent' => true === ( $row['warehouseCapabilitiesPresent'] ?? false ),
			'effectiveCapabilitySource' => in_array( (string) ( $row['effectiveCapabilitySource'] ?? '' ), array( 'division', 'warehouse', 'none' ), true ) ? (string) $row['effectiveCapabilitySource'] : 'none',
			'types' => is_array( $row['types'] ?? null ) ? $row['types'] : array(),
		);
	}

	/** @param array<string,mixed> $row */
	private function normalize_department_timezone( array $row ): ?string {
		foreach ( array( 'branchTimezone', 'timezone' ) as $key ) {
			if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] || '' === trim( (string) $row[ $key ] ) ) {
				continue;
			}
			$value = strtoupper( trim( (string) $row[ $key ] ) );
			if ( null !== $this->branch_timezone( $value ) ) {
				return $value;
			}

			return null;
		}

		return $this->normalize_nearest_department_timezone( $row['timeZone'] ?? null );
	}

	private function normalize_nearest_department_timezone( mixed $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^(\d{2}):(\d{2}):(\d{2})$/', $value, $matches ) ) {
			return null;
		}
		$hours = (int) $matches[1];
		$minutes = (int) $matches[2];
		$seconds = (int) $matches[3];
		if ( $hours > 14 || $minutes > 59 || 0 !== $seconds || ( 14 === $hours && 0 !== $minutes ) ) {
			return null;
		}

		return 'UTC+' . sprintf( '%02d:%02d', $hours, $minutes );
	}

	/** @param array<string,mixed> $branches @return array<string,mixed> */
	private function find_warehouse_in_branches_all( array $branches, string $warehouse_id ): array {
		$match = self::find_warehouse_in_branches_response( $branches, $warehouse_id );
		if ( true !== ( $match['warehouse_found'] ?? false ) ) {
			return array();
		}
		$branches_list = self::branches_from_response( $branches );
		$branch = $branches_list[ (int) ( $match['branch_index'] ?? -1 ) ] ?? null;
		$division = is_array( $branch ) ? ( $branch['divisions'][ (int) ( $match['division_index'] ?? -1 ) ] ?? null ) : null;
		$warehouse = is_array( $division ) ? ( $division['warehouses'][ (int) ( $match['warehouse_index'] ?? -1 ) ] ?? null ) : null;
		if ( ! is_array( $branch ) || ! is_array( $division ) || ! is_array( $warehouse ) ) {
			return array();
		}
		$division_has_capabilities = array_key_exists( 'kindsOfTransportation', $division ) && is_array( $division['kindsOfTransportation'] ?? null ) && array() !== $division['kindsOfTransportation'];
		$warehouse_has_capabilities = array_key_exists( 'kindsOfTransportation', $warehouse ) && is_array( $warehouse['kindsOfTransportation'] ?? null ) && array() !== $warehouse['kindsOfTransportation'];
		$effective_capabilities = array();
		$effective_capability_source = 'none';
		if ( $warehouse_has_capabilities ) {
			$effective_capabilities = is_array( $warehouse['kindsOfTransportation'] ?? null ) ? $warehouse['kindsOfTransportation'] : array();
			$effective_capability_source = 'warehouse';
		} elseif ( $division_has_capabilities ) {
			$effective_capabilities = is_array( $division['kindsOfTransportation'] ?? null ) ? $division['kindsOfTransportation'] : array();
			$effective_capability_source = 'division';
		}

		return $this->normalize_department(
			array_merge(
				$warehouse,
				array(
					'warehouseId' => self::normalize_warehouse_id( $warehouse['id'] ?? '' ),
					'branchId' => (string) ( $branch['id'] ?? '' ),
					'branchName' => (string) ( $branch['title'] ?? '' ),
					'divisionName' => (string) ( $warehouse['divisionName'] ?? ( $division['name'] ?? '' ) ),
					'departmentTypeId' => (int) ( $division['departmentTypeId'] ?? 0 ),
					'departmentType' => (string) ( $division['departmentType'] ?? '' ),
					'address' => (string) ( trim( (string) ( $warehouse['addressDivision'] ?? '' ) ) !== '' ? $warehouse['addressDivision'] : ( $warehouse['address'] ?? '' ) ),
					'coordinates' => is_array( $warehouse['coordinatesobj'] ?? null ) ? $warehouse['coordinatesobj'] : array(),
					'branchTimezone' => $branch['timezone'] ?? null,
					'kindsOfTransportation' => $effective_capabilities,
					'divisionCapabilitiesPresent' => $division_has_capabilities,
					'warehouseCapabilitiesPresent' => $warehouse_has_capabilities,
					'effectiveCapabilitySource' => $effective_capability_source,
				)
			),
			'branches_all',
			0
		);
	}

	/** @param array<string,mixed> $response @return array<string,mixed> */
	public static function find_warehouse_in_branches_response( array $response, string $warehouse_id ): array {
		$result = array(
			'warehouse_found' => false,
			'matched_id' => '',
			'matched_id_hash' => '',
			'matched_field' => '',
			'branches_checked' => 0,
			'divisions_checked' => 0,
			'warehouses_checked' => 0,
			'unexpected_structure' => false,
		);
		$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
		$branches = self::branches_from_response( $response );
		if ( null === $branches ) {
			$result['unexpected_structure'] = true;
			return $result;
		}
		foreach ( $branches as $branch_index => $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}
			++$result['branches_checked'];
			foreach ( is_array( $branch['divisions'] ?? null ) ? $branch['divisions'] : array() as $division_index => $division ) {
				if ( ! is_array( $division ) ) {
					continue;
				}
				++$result['divisions_checked'];
				foreach ( is_array( $division['warehouses'] ?? null ) ? $division['warehouses'] : array() as $warehouse_index => $warehouse ) {
					if ( ! is_array( $warehouse ) ) {
						continue;
					}
					++$result['warehouses_checked'];
					$id = self::normalize_warehouse_id( $warehouse['id'] ?? '' );
					if ( '' !== $id && $id === $warehouse_id ) {
						$result['warehouse_found'] = true;
						$result['matched_id'] = $id;
						$result['matched_id_hash'] = hash( 'sha256', $id );
						$result['matched_field'] = 'id';
						$result['branch_index'] = (int) $branch_index;
						$result['division_index'] = (int) $division_index;
						$result['warehouse_index'] = (int) $warehouse_index;
						return $result;
					}
				}
			}
		}

		return $result;
	}

	/** @param array<string,mixed> $response @return ?array<int,mixed> */
	private static function branches_from_response( array $response ): ?array {
		if ( is_array( $response['branches'] ?? null ) ) {
			return $response['branches'];
		}
		if ( array_values( $response ) === $response ) {
			return $response;
		}

		return null;
	}

	private static function normalize_warehouse_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value, " \t\n\r\0\x0B" );
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value ) ) {
			return '';
		}

		return strtolower( $value );
	}

	/** @param array<string,mixed> $item */
	private function supports_ltl_pickup( array $item ): bool {
		return $this->ltl_pickup_status( $item )['success'];
	}

	/** @param array<string,mixed> $item @return array{success:bool,reason:string,ltl_type_present:bool,acceptance_operation_present:bool} */
	private function ltl_pickup_status( array $item ): array {
		if ( '' === (string) ( $item['warehouseId'] ?? '' ) ) {
			return array( 'success' => false, 'reason' => 'warehouse_id_missing', 'ltl_type_present' => false, 'acceptance_operation_present' => false );
		}
		$types = array();
		foreach ( is_array( $item['types'] ?? null ) ? $item['types'] : array() as $type ) {
			if ( ! is_int( $type ) ) {
				return array( 'success' => false, 'reason' => 'warehouse_type_contract', 'ltl_type_present' => false, 'acceptance_operation_present' => false );
			}
			$types[] = $type;
		}
		$type_ok = array() === $types || in_array( PekSettings::LTL_PRODUCT_TYPE, $types, true );
		$kinds = is_array( $item['kindsOfTransportation'] ?? null ) ? $item['kindsOfTransportation'] : array();
		$ltl_type_present = false;
		$acceptance_operation_present = false;
		foreach ( $kinds as $kind ) {
			if ( ! is_array( $kind ) || array_is_list( $kind ) || ! array_key_exists( 'type', $kind ) || ! is_int( $kind['type'] ) ) {
				return array( 'success' => false, 'reason' => 'capability_contract', 'ltl_type_present' => false, 'acceptance_operation_present' => false );
			}
			if ( PekSettings::LTL_PRODUCT_TYPE !== $kind['type'] ) {
				continue;
			}
			$ltl_type_present = true;
			$operations = $kind['operations'] ?? null;
			if ( ! is_array( $operations ) || ! array_is_list( $operations ) ) {
				return array( 'success' => false, 'reason' => 'capability_contract', 'ltl_type_present' => true, 'acceptance_operation_present' => false );
			}
			foreach ( $operations as $operation ) {
				if ( ! is_string( $operation ) ) {
					return array( 'success' => false, 'reason' => 'capability_contract', 'ltl_type_present' => true, 'acceptance_operation_present' => false );
				}
				if ( $this->is_cargo_acceptance_operation( $operation ) ) {
					$acceptance_operation_present = true;
				}
			}
		}
		if ( $acceptance_operation_present ) {
			return array( 'success' => $type_ok, 'reason' => $type_ok ? '' : 'warehouse_type_mismatch', 'ltl_type_present' => true, 'acceptance_operation_present' => true );
		}
		if ( $ltl_type_present ) {
			return array( 'success' => false, 'reason' => 'acceptance_operation_missing', 'ltl_type_present' => true, 'acceptance_operation_present' => false );
		}

		return array( 'success' => false, 'reason' => 'ltl_type_missing', 'ltl_type_present' => false, 'acceptance_operation_present' => false );
	}

	private function is_cargo_acceptance_operation( string $operation ): bool {
		$operation = trim( preg_replace( '/\s+/u', ' ', $operation ) ?? $operation );

		return 1 === preg_match( '/^при[её]м грузов$/iu', $operation );
	}

	private function fits_constraints( array $item, PickupCargoConstraints $constraints ): bool {
		$checks = array(
			'maxWeight' => $constraints->weight_g / 1000,
			'maxVolume' => $constraints->volume_cm3 / 1000000,
			'maxDimension' => $constraints->max_dimension_cm / 100,
			'maxWeightOnePlace' => $constraints->max_place_weight_g / 1000,
			'maxCount' => $constraints->places_count,
		);
		foreach ( $checks as $key => $actual ) {
			if ( ! array_key_exists( $key, $item ) || null === $item[ $key ] || '' === trim( (string) $item[ $key ] ) ) {
				continue;
			}
			$limit = (float) str_replace( ',', '.', (string) $item[ $key ] );
			if ( $limit > 0 && $actual > $limit ) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string,mixed> */
	private function validation_diagnostic( string $reason, string $source = 'nearest_fresh_revalidation', string $warehouse_id = '', string $address = '', ?PickupCargoConstraints $constraints = null ): array {
		$warehouse_id = self::normalize_warehouse_id( $warehouse_id );
		$source = in_array( $source, array( 'nearest_cached_selection', 'nearest_fresh_revalidation' ), true ) ? $source : 'nearest_fresh_revalidation';

		return array(
			'stage' => 'sender_warehouse_validation',
			'source' => $source,
			'reason' => $reason,
			'endpoint' => '/branches/nearestdepartments/',
			'http_status' => '',
			'warehouse_id_present' => '' !== $warehouse_id,
			'warehouse_id_hash' => '' !== $warehouse_id ? hash( 'sha256', $warehouse_id ) : '',
			'requested_id_present' => '' !== $warehouse_id,
			'requested_id_hash' => '' !== $warehouse_id ? hash( 'sha256', $warehouse_id ) : '',
			'search_address_present' => '' !== trim( $address ),
			'search_address_hash' => '' !== trim( $address ) ? hash( 'sha256', trim( $address ) ) : '',
			'free_count' => 0,
			'paid_count' => 0,
			'exact_match_count' => 0,
			'constraints_fingerprint' => $this->constraints_fingerprint( $constraints ),
			'constraints_match' => true,
			'availability_match' => true,
			'lookup_source' => $source,
			'branches_checked' => 0,
			'divisions_checked' => 0,
			'warehouses_checked' => 0,
			'warehouse_found' => false,
			'matched_id_hash' => '',
			'division_capabilities_present' => false,
			'warehouse_capabilities_present' => false,
			'effective_capability_source' => 'none',
			'ltl_type_present' => false,
			'acceptance_operation_present' => false,
		);
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function diagnostic_from_item( array $item ): array {
		$diagnostic = $this->validation_diagnostic( '' );
		if ( array() === $item ) {
			return $diagnostic;
		}
		$diagnostic['http_status'] = $this->api->last_response_meta()['http_status'];
		$diagnostic['warehouse_found'] = true;
		$diagnostic['division_capabilities_present'] = true === ( $item['divisionCapabilitiesPresent'] ?? false );
		$diagnostic['warehouse_capabilities_present'] = true === ( $item['warehouseCapabilitiesPresent'] ?? false );
		$source = (string) ( $item['effectiveCapabilitySource'] ?? 'none' );
		$diagnostic['effective_capability_source'] = in_array( $source, array( 'division', 'warehouse', 'none' ), true ) ? $source : 'none';

		return $diagnostic;
	}

	/** @return array{item:array<string,mixed>,match:array<string,mixed>,lookup_source:string,http_status:int|string} */
	private function lookup_warehouse_in_branches_all( string $warehouse_id ): array {
		$branches = $this->api->branches_all_for_warehouse( $warehouse_id );
		$match = self::find_warehouse_in_branches_response( $branches, $warehouse_id );
		$item = $this->find_warehouse_in_branches_all( $branches, $warehouse_id );
		if ( true === ( $match['warehouse_found'] ?? false ) || true === ( $match['unexpected_structure'] ?? false ) ) {
			return array( 'item' => $item, 'match' => $match, 'lookup_source' => 'filtered', 'http_status' => $this->api->last_response_meta()['http_status'] );
		}

		$branches = $this->api->branches_all();
		$fallback_match = self::find_warehouse_in_branches_response( $branches, $warehouse_id );
		$fallback_item = $this->find_warehouse_in_branches_all( $branches, $warehouse_id );

		return array(
			'item' => $fallback_item,
			'match' => $fallback_match,
			'lookup_source' => true === ( $fallback_match['warehouse_found'] ?? false ) ? 'unfiltered_fallback' : 'filtered',
			'http_status' => $this->api->last_response_meta()['http_status'],
		);
	}

	/** @param array{item:array<string,mixed>,match:array<string,mixed>,lookup_source:string,http_status:int|string} $lookup @return array<string,mixed> */
	private function diagnostic_from_lookup( array $lookup, string $warehouse_id ): array {
		$item = $lookup['item'];
		$match = $lookup['match'];
		$diagnostic = $this->diagnostic_from_item( $item );
		$diagnostic['endpoint'] = '/branches/all/';
		$diagnostic['lookup_source'] = in_array( $lookup['lookup_source'], array( 'filtered', 'unfiltered_fallback' ), true ) ? $lookup['lookup_source'] : 'filtered';
		$diagnostic['http_status'] = $lookup['http_status'];
		$diagnostic['requested_id_present'] = '' !== $warehouse_id;
		$diagnostic['requested_id_hash'] = '' !== $warehouse_id ? hash( 'sha256', $warehouse_id ) : '';
		$diagnostic['branches_checked'] = (int) ( $match['branches_checked'] ?? 0 );
		$diagnostic['divisions_checked'] = (int) ( $match['divisions_checked'] ?? 0 );
		$diagnostic['warehouses_checked'] = (int) ( $match['warehouses_checked'] ?? 0 );
		$diagnostic['warehouse_found'] = true === ( $match['warehouse_found'] ?? false );
		$diagnostic['matched_id_hash'] = is_string( $match['matched_id_hash'] ?? null ) ? $match['matched_id_hash'] : '';

		return $diagnostic;
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
			'source' => (string) ( $item['source'] ?? '' ),
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
