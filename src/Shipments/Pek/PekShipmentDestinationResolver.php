<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekShipmentDestinationResolver {
	private const LOCATION_MISMATCH_MESSAGE = 'Адрес заказа изменился после расчёта доставки. Повторно рассчитайте доставку ПЭК для актуального города.';
	private const LOCATION_IDENTITY_MESSAGE = 'Не удалось восстановить населённый пункт курьерской доставки ПЭК. Повторно рассчитайте доставку для заказа.';
	private const PICKUP_FALLBACK_WARNING = 'Не удалось выполнить повторную онлайн-проверку выбранного терминала ПЭК. Используются данные терминала, подтверждённые при оформлении заказа.';

	public function __construct(
		private PekPickupPointProvider $pickup_points,
		private PekLocationResolver $locations,
		private LocationRepository $canonical_locations
	) {
	}

	/** @return array<string,mixed> */
	public function resolve( ShipmentCreateRequest $request ): array {
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			return $this->resolve_pickup( $request );
		}

		return $this->resolve_courier( $request );
	}

	/** @return array<string,mixed> */
	private function resolve_pickup( ShipmentCreateRequest $request ): array {
		$code = trim( (string) ( $request->pickup_point?->point_code ?? $request->meta['pickup_point_code'] ?? '' ) );
		if ( '' === $code ) {
			throw new \RuntimeException( 'Не выбран терминал ПЭК.' );
		}
		$query_data = is_array( $request->meta['pickup_provider_query'] ?? null ) ? $request->meta['pickup_provider_query'] : array();
		$coordinates = $this->coordinate_pair( $query_data['latitude'] ?? null, $query_data['longitude'] ?? null );
		$query = new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			(int) ( $request->meta['pek_destination_location_id'] ?? $query_data['location_id'] ?? 0 ),
			'RU',
			(string) ( $query_data['fallback_address'] ?? $request->recipient_address->raw_address ),
			$coordinates['latitude'],
			$coordinates['longitude'],
			$this->constraints( $request ),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			$this->bounded_int( $query_data['radius_km'] ?? 50, 1, 500, 50 ),
			$this->bounded_int( $query_data['limit'] ?? 50, 1, 100, 50 )
		);
		try {
			$point = $this->pickup_points->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $code ) );
		} catch ( PekApiException $exception ) {
			$fallback = $this->trusted_pickup_selection_fallback( $request, $query, $code, $exception );
			if ( array() !== $fallback ) {
				return $fallback;
			}
			throw $this->with_preparation_stage( $exception, 'destination_pickup' );
		}
		if ( null === $point || $point->code !== $code || ! $point->active ) {
			throw new \RuntimeException( 'Выбранный терминал ПЭК больше не доступен. Повторно рассчитайте доставку для заказа.' );
		}
		$raw = is_array( $point->raw_reference ) ? $point->raw_reference : array();
		$branch_id = trim( (string) ( $raw['branchId'] ?? $raw['branch_id'] ?? '' ) );
		if ( '' === $branch_id ) {
			throw new \RuntimeException( 'Не подтверждён филиал назначения ПЭК для SMS.' );
		}

		return array(
			'mode' => DeliveryType::PICKUP,
			'warehouse_id' => $code,
			'branch_id' => $branch_id,
			'title' => (string) ( $raw['title'] ?? $point->city ),
			'address' => $point->address,
			'source' => (string) ( $raw['source'] ?? 'fresh_provider' ),
			'location_id' => $query->location_id,
			'provider_destination_fingerprint' => (string) ( $raw['provider_destination_fingerprint'] ?? $request->meta['provider_destination_fingerprint'] ?? '' ),
			'fresh_check' => true,
			'fallback_used' => false,
			'fallback_reason' => '',
		);
	}

	/** @return array<string,mixed> */
	private function resolve_courier( ShipmentCreateRequest $request ): array {
		if ( 'RU' !== strtoupper( $request->recipient_address->country_code ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$location_identity = $this->resolve_canonical_location_identity( $request );
		$location_id = $location_identity['location_id'];
		$location_evidence = $this->assert_location_matches_request( $location_identity['location'], $request, $location_identity['source'] );
		$mapping = $this->locations->resolve_delivery_address_for_shipment( $request->recipient_address->raw_address, 'RU' );
		$branch_id = trim( (string) ( $mapping['branch_id'] ?? '' ) );
		$country = strtoupper( trim( (string) ( $mapping['country_code'] ?? 'RU' ) ) );
		$state = (string) ( $mapping['mapping_state'] ?? '' );
		$zone_id = trim( (string) ( $mapping['zone_id'] ?? '' ) );
		$main_warehouse_id = trim( (string) ( $mapping['main_warehouse_id'] ?? '' ) );
		$precision = strtolower( trim( (string) ( $mapping['precision'] ?? '' ) ) );
		if ( 'RU' !== $country || 'unsupported' === $state || 'bad' === $precision ) {
			throw new \RuntimeException( 'ПЭК не подтвердил адрес курьерской доставки. Проверьте улицу и номер дома.' );
		}
		if ( '' === $branch_id || '' === $zone_id || '' === $main_warehouse_id || ! in_array( $state, array( 'resolved', 'near' ), true ) || ! in_array( $precision, array( 'exact', 'near' ), true ) ) {
			throw new \RuntimeException( 'Не удалось подтвердить зону курьерской доставки ПЭК.' );
		}
		$saved_branch = trim( (string) ( $request->meta['pek_receiver_branch_id'] ?? $request->meta['pek_destination_branch_id'] ?? '' ) );
		$formatted = trim( (string) ( $mapping['normalized_address'] ?? '' ) );
		$warnings = 'near' === $precision ? array( 'ПЭК распознал адрес приблизительно. Перед созданием заявки проверьте адрес получателя.' ) : array();

		return array(
			'mode' => DeliveryType::COURIER,
			'warehouse_id' => '',
			'branch_id' => $branch_id,
			'main_warehouse_id' => $main_warehouse_id,
			'zone_id' => $zone_id,
			'title' => '',
			'address' => $request->recipient_address->raw_address,
			'source' => 'fresh_address_zone',
			'location_id' => $location_id,
			'location_match' => true,
			'location_identity_source' => $location_evidence['source'],
			'location_level' => $location_evidence['level'],
			'parent_city_match' => $location_evidence['parent_city_match'],
			'settlement_match' => $location_evidence['settlement_match'],
			'provider_destination_fingerprint' => (string) ( $mapping['shipment_address_hash'] ?? $mapping['address_fingerprint'] ?? $request->meta['provider_destination_fingerprint'] ?? '' ),
			'saved_branch_id' => $saved_branch,
			'branch_mismatch' => '' !== $saved_branch && $saved_branch !== $branch_id,
			'branch_source' => 'fresh_address_zone',
			'address_precision' => $precision,
			'formatted_address_present' => '' !== $formatted,
			'formatted_address_hash' => '' !== $formatted ? hash( 'sha256', $formatted ) : '',
			'warnings' => $warnings,
		);
	}

	/** @return array{location:Location,location_id:int,source:string} */
	private function resolve_canonical_location_identity( ShipmentCreateRequest $request ): array {
		$location_id = (int) ( $request->meta['pek_destination_location_id'] ?? 0 );
		if ( $location_id > 0 ) {
			$location = $this->canonical_locations->find_by_id( $location_id );
			if ( ! $location instanceof Location || ! $location->active || null === $location->id || (int) $location->id <= 0 || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
				throw new \RuntimeException( self::LOCATION_IDENTITY_MESSAGE );
			}

			return array(
				'location' => $location,
				'location_id' => (int) $location->id,
				'source' => 'request_location_id',
			);
		}

		$evidence = is_array( $request->meta['pek_courier_address_evidence'] ?? null ) ? $request->meta['pek_courier_address_evidence'] : array();
		$selected_fias = $this->canonical_guid( $evidence['courier_selected_location_fias_id'] ?? null );
		if ( '' !== $this->scalar_string( $evidence['courier_selected_location_fias_id'] ?? null ) && '' === $selected_fias ) {
			throw new \RuntimeException( self::LOCATION_IDENTITY_MESSAGE );
		}
		if ( '' !== $selected_fias ) {
			$location = $this->canonical_locations->find_by_fias_id( $selected_fias );
			if ( ! $location instanceof Location || ! $location->active || null === $location->id || (int) $location->id <= 0 || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
				throw new \RuntimeException( self::LOCATION_IDENTITY_MESSAGE );
			}

			return array(
				'location' => $location,
				'location_id' => (int) $location->id,
				'source' => 'selected_location_fias_fallback',
			);
		}

		$order_city_fias = $this->canonical_guid( $evidence['courier_order_city_fias_id'] ?? null );
		if ( '' === $order_city_fias ) {
			throw new \RuntimeException( self::LOCATION_IDENTITY_MESSAGE );
		}
		$location = $this->canonical_locations->find_by_fias_id( $order_city_fias );
		if ( ! $location instanceof Location || ! $location->active || null === $location->id || (int) $location->id <= 0 || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			throw new \RuntimeException( self::LOCATION_IDENTITY_MESSAGE );
		}

		return array(
			'location' => $location,
			'location_id' => (int) $location->id,
			'source' => 'order_city_fias_fallback',
		);
	}

	/** @return array{source:string,level:string,parent_city_match:bool,settlement_match:bool} */
	private function assert_location_matches_request( Location $location, ShipmentCreateRequest $request, string $identity_source ): array {
		$address = $request->recipient_address;
		if ( strtoupper( trim( $location->country_code ) ) !== strtoupper( trim( $address->country_code ) ) ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
		if ( '' !== trim( $address->region_name ) && '' !== trim( $location->region_name ) && ! $this->same_region_name( $address->region_name, $location->region_name ) ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
		$evidence = is_array( $request->meta['pek_courier_address_evidence'] ?? null ) ? $request->meta['pek_courier_address_evidence'] : array();
		$request_city_fias = $this->normalize_guid( (string) ( $evidence['courier_city_fias_id'] ?? '' ) );
		$request_settlement_fias = $this->normalize_guid( (string) ( $evidence['courier_settlement_fias_id'] ?? '' ) );
		$selected_location_fias = $this->normalize_guid( (string) ( $evidence['courier_selected_location_fias_id'] ?? '' ) );
		$location_city_fias = $this->normalize_guid( $location->city_fias_id );
		$location_fias = $this->normalize_guid( $location->fias_id );
		$level = $this->location_level( $location );
		if ( ! $this->selected_location_matches( $selected_location_fias, $location, $level ) ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
		$parent_city_match = $this->city_matches_location( $address->city, $request_city_fias, $location );
		$settlement_match = $this->settlement_matches_location( $address->settlement, $request_settlement_fias, $location );

		if ( 'city' === $level ) {
			if ( ! $parent_city_match ) {
				throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
			}

			return array( 'source' => $identity_source, 'level' => $level, 'parent_city_match' => true, 'settlement_match' => '' !== trim( $address->settlement ) );
		}
		if ( ! $settlement_match ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
		if ( '' !== trim( $address->city ) && ! $parent_city_match ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}

		return array( 'source' => $identity_source, 'level' => $level, 'parent_city_match' => $parent_city_match, 'settlement_match' => true );
	}

	private function location_level( Location $location ): string {
		$settlement = trim( $location->settlement_name );
		if ( '' === $settlement || $this->same_location_name( $settlement, $location->city_name ) ) {
			return 'city';
		}

		return 'settlement';
	}

	private function city_matches_location( string $request_city, string $request_city_fias, Location $location ): bool {
		$canonical_city_fias = $this->normalize_guid( $location->city_fias_id );
		if ( '' === $canonical_city_fias && 'city' === $this->location_level( $location ) ) {
			$canonical_city_fias = $this->normalize_guid( $location->fias_id );
		}
		$id_match = $this->identifier_match( $request_city_fias, $canonical_city_fias );
		if ( false === $id_match ) {
			return false;
		}
		if ( true === $id_match ) {
			return true;
		}

		return '' !== trim( $request_city ) && $this->same_location_name( $request_city, $location->city_name );
	}

	private function settlement_matches_location( string $request_settlement, string $request_settlement_fias, Location $location ): bool {
		$id_match = $this->identifier_match( $request_settlement_fias, $this->normalize_guid( $location->fias_id ) );
		if ( false === $id_match ) {
			return false;
		}
		if ( true === $id_match ) {
			return true;
		}

		return '' !== trim( $request_settlement ) && $this->same_settlement_name( $request_settlement, $location );
	}

	private function selected_location_matches( string $selected_fias, Location $location, string $level ): bool {
		if ( '' === $selected_fias ) {
			return true;
		}
		$location_fias = $this->normalize_guid( $location->fias_id );
		$city_fias = $this->normalize_guid( $location->city_fias_id );
		if ( 'city' === $level ) {
			foreach ( array( $location_fias, $city_fias ) as $canonical ) {
				$id_match = $this->identifier_match( $selected_fias, $canonical );
				if ( true === $id_match ) {
					return true;
				}
			}

			return '' === $location_fias && '' === $city_fias;
		}
		$id_match = $this->identifier_match( $selected_fias, $location_fias );
		if ( null === $id_match ) {
			return true;
		}

		return $id_match;
	}

	private function identifier_match( string $request_id, string $canonical_id ): ?bool {
		$request_id = $this->normalize_guid( $request_id );
		$canonical_id = $this->normalize_guid( $canonical_id );
		if ( '' === $request_id || '' === $canonical_id ) {
			return null;
		}

		return $request_id === $canonical_id;
	}

	private function same_location_name( string $left, string $right ): bool {
		return '' !== trim( $right ) && $this->normalize_location_name( $left ) === $this->normalize_location_name( $right );
	}

	private function same_settlement_name( string $request_settlement, Location $location ): bool {
		$canonical = trim( $location->settlement_name );
		if ( '' === $canonical ) {
			return false;
		}
		$canonical_type = trim( $location->settlement_type );
		$candidates = array( $canonical );
		if ( '' !== $canonical_type ) {
			$candidates[] = trim( $canonical_type . ' ' . $canonical );
		}
		foreach ( $candidates as $candidate ) {
			if ( $this->normalize_settlement_name( $request_settlement ) === $this->normalize_settlement_name( $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_settlement_name( string $value ): string {
		$value = $this->lower( $value );
		$value = preg_replace( '/^\s*(?:поселение|пос\.?|п\.?|село|с\.?|деревня|д\.?|рабочий\s+пос[её]лок|рп\.?)\s+/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function same_region_name( string $left, string $right ): bool {
		return '' !== trim( $right ) && $this->normalize_region_name( $left ) === $this->normalize_region_name( $right );
	}

	private function normalize_location_name( string $value ): string {
		$value = $this->lower( $value );
		$value = preg_replace( '/\b(?:город\s+федерального\s+значения|город|г)\b\.?\s*/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_region_name( string $value ): string {
		$value = $this->lower( $value );
		$value = preg_replace( '/\b(?:город\s+федерального\s+значения|город|г|область|обл|край|республика|респ)\b\.?\s*/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_guid( string $value ): string {
		return strtolower( trim( $value ) );
	}

	private function canonical_guid( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	private function scalar_string( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/** @return array<string,mixed> */
	private function trusted_pickup_selection_fallback( ShipmentCreateRequest $request, CarrierPickupPointQuery $query, string $code, PekApiException $exception ): array {
		if ( ! $this->pickup_fallback_eligible( $exception ) ) {
			return array();
		}
		$snapshot = is_array( $request->meta['pek_pickup_selected_snapshot'] ?? null ) ? $request->meta['pek_pickup_selected_snapshot'] : array();
		if ( array() === $snapshot ) {
			return array();
		}
		$warehouse_id = $this->canonical_guid( $snapshot['point_code'] ?? $snapshot['warehouse_id'] ?? $snapshot['warehouseId'] ?? '' );
		$expected_id = $this->canonical_guid( $code );
		if ( '' === $warehouse_id || '' === $expected_id || $warehouse_id !== $expected_id ) {
			return array();
		}
		if ( PekSettings::CARRIER_KEY !== (string) ( $snapshot['carrier_key'] ?? '' ) || PekSettings::SERVICE_KEY !== (string) ( $snapshot['service_key'] ?? '' ) || PekSettings::PICKUP_FAMILY !== (string) ( $snapshot['pickup_family'] ?? '' ) ) {
			return array();
		}
		if ( 'RU' !== strtoupper( trim( (string) ( $snapshot['country_code'] ?? '' ) ) ) ) {
			return array();
		}
		$location_id = (int) ( $snapshot['location_id'] ?? 0 );
		if ( $location_id <= 0 || $location_id !== (int) $query->location_id ) {
			return array();
		}
		$fingerprint = trim( (string) ( $snapshot['provider_destination_fingerprint'] ?? '' ) );
		$request_fingerprint = trim( (string) ( $request->meta['provider_destination_fingerprint'] ?? '' ) );
		$query_fingerprint = trim( (string) ( $request->meta['pickup_provider_query']['provider_destination_fingerprint'] ?? $request->meta['pickup_provider_query']['destination_fingerprint'] ?? '' ) );
		if ( '' === $fingerprint || '' === $request_fingerprint || $fingerprint !== $request_fingerprint || ( '' !== $query_fingerprint && $fingerprint !== $query_fingerprint ) ) {
			return array();
		}
		if ( 'provider_resolve_selection' !== (string) ( $snapshot['validation_source'] ?? '' ) || ! $this->strict_timestamp( $snapshot['selected_at'] ?? null ) ) {
			return array();
		}
		$source = (string) ( $snapshot['source'] ?? '' );
		if ( ! in_array( $source, array( 'free', 'paid' ), true ) ) {
			return array();
		}
		$branch_id = trim( (string) ( $snapshot['branchId'] ?? $snapshot['branch_id'] ?? '' ) );
		if ( '' === $branch_id || ! $this->pickup_snapshot_limits_pass( $snapshot, $query ) || $this->pickup_snapshot_unavailable( $snapshot ) ) {
			return array();
		}
		$context = $exception->context();
		$reason = is_string( $context['error_code'] ?? null ) && '' !== trim( $context['error_code'] ) ? trim( $context['error_code'] ) : 'pek_terminal_recheck_unavailable';

		return array(
			'mode' => DeliveryType::PICKUP,
			'warehouse_id' => $warehouse_id,
			'branch_id' => $branch_id,
			'title' => (string) ( $snapshot['point_title'] ?? $snapshot['card_title'] ?? '' ),
			'address' => (string) ( $snapshot['point_address'] ?? $snapshot['address'] ?? '' ),
			'source' => 'persisted_checkout_selection_access_fallback',
			'location_id' => $location_id,
			'provider_destination_fingerprint' => $fingerprint,
			'fresh_check' => false,
			'fallback_used' => true,
			'fallback_reason' => $reason,
			'warnings' => array( self::PICKUP_FALLBACK_WARNING ),
		);
	}

	private function pickup_fallback_eligible( PekApiException $exception ): bool {
		$context = $exception->context();
		$status = (int) ( $context['http_status'] ?? 0 );
		if ( 403 === $status || $status >= 500 ) {
			return true;
		}

		return in_array( (string) ( $context['error_code'] ?? '' ), array( 'pek_http_403', 'pek_transport_error', 'pek_http_500' ), true );
	}

	private function strict_timestamp( mixed $value ): bool {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( \DateTimeInterface::ATOM, $value );
		$errors = \DateTimeImmutable::getLastErrors();

		return $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( \DateTimeInterface::ATOM ) === $value;
	}

	/** @param array<string,mixed> $snapshot */
	private function pickup_snapshot_limits_pass( array $snapshot, CarrierPickupPointQuery $query ): bool {
		$limits = is_array( $snapshot['limits'] ?? null ) ? $snapshot['limits'] : $snapshot;
		$checks = array(
			'maxWeight' => $query->cargo->weight_g / 1000,
			'maxVolume' => $query->cargo->volume_cm3 / 1000000,
			'maxDimension' => $query->cargo->max_dimension_cm / 100,
			'maxWeightOnePlace' => $query->cargo->max_place_weight_g / 1000,
			'maxCount' => $query->cargo->places_count,
		);
		foreach ( $checks as $key => $actual ) {
			if ( ! array_key_exists( $key, $limits ) || null === $limits[ $key ] || '' === $limits[ $key ] ) {
				continue;
			}
			if ( ! is_numeric( $limits[ $key ] ) ) {
				return false;
			}
			$limit = (float) $limits[ $key ];
			if ( $limit > 0 && $actual > $limit ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $snapshot */
	private function pickup_snapshot_unavailable( array $snapshot ): bool {
		$availability = is_array( $snapshot['availability'] ?? null ) ? $snapshot['availability'] : array();
		foreach ( array( 'available', 'is_available', 'active' ) as $key ) {
			if ( array_key_exists( $key, $availability ) && false === filter_var( $availability[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
				return true;
			}
		}

		return false;
	}

	private function with_preparation_stage( PekApiException $exception, string $stage ): PekApiException {
		$context = $exception->context();
		$context['preparation_stage'] = $stage;

		return new PekApiException( $exception->getMessage(), $context, $exception->getCode(), $exception );
	}

	private function lower( string $value ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value, 'UTF-8' );
		}

		return preg_replace_callback(
			'/[А-ЯЁ]/u',
			static function ( array $m ): string {
				$map = array( 'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я' );
				return $map[ $m[0] ] ?? $m[0];
			},
			strtolower( $value )
		) ?? strtolower( $value );
	}

	private function constraints( ShipmentCreateRequest $request ): PickupCargoConstraints {
		$weight = 0;
		$volume = 0;
		$max_dimension = 0;
		$max_place_weight = 0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$weight += max( 0, $place->weight_g );
			$volume += max( 0, $place->length_cm ) * max( 0, $place->width_cm ) * max( 0, $place->height_cm );
			$max_dimension = max( $max_dimension, $place->length_cm, $place->width_cm, $place->height_cm );
			$max_place_weight = max( $max_place_weight, $place->weight_g );
		}

		return new PickupCargoConstraints( $weight, $volume, $max_dimension, $max_place_weight, max( 1, count( $request->places ) ) );
	}

	/** @return array{latitude:?float,longitude:?float} */
	private function coordinate_pair( mixed $latitude, mixed $longitude ): array {
		$lat_empty = null === $latitude || ( is_string( $latitude ) && '' === trim( $latitude ) );
		$lon_empty = null === $longitude || ( is_string( $longitude ) && '' === trim( $longitude ) );
		if ( $lat_empty && $lon_empty ) {
			return array( 'latitude' => null, 'longitude' => null );
		}
		if ( $lat_empty || $lon_empty ) {
			throw new \RuntimeException( 'Некорректные координаты пункта ПЭК.' );
		}
		$lat = $this->strict_float( $latitude );
		$lon = $this->strict_float( $longitude );
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			throw new \RuntimeException( 'Некорректные координаты пункта ПЭК.' );
		}

		return array( 'latitude' => $lat, 'longitude' => $lon );
	}

	private function strict_float( mixed $value ): float {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			throw new \RuntimeException( 'Некорректные координаты пункта ПЭК.' );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$result = (float) $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^-?\d+(?:\.\d+)?$/', trim( $value ) ) ) {
			$result = (float) trim( $value );
		} else {
			throw new \RuntimeException( 'Некорректные координаты пункта ПЭК.' );
		}
		if ( ! is_finite( $result ) ) {
			throw new \RuntimeException( 'Некорректные координаты пункта ПЭК.' );
		}

		return $result;
	}

	private function bounded_int( mixed $value, int $min, int $max, int $default ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			return $default;
		}
		$value = (int) $value;

		return max( $min, min( $max, $value ) );
	}
}
