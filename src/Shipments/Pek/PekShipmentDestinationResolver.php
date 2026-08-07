<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

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
		$point = $this->pickup_points->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $code ) );
		if ( null === $point || $point->code !== $code || ! $point->active ) {
			throw new \RuntimeException( 'Терминал ПЭК не прошёл свежую проверку.' );
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
		);
	}

	/** @return array<string,mixed> */
	private function resolve_courier( ShipmentCreateRequest $request ): array {
		if ( 'RU' !== strtoupper( $request->recipient_address->country_code ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$location_id = (int) ( $request->meta['pek_destination_location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			throw new \RuntimeException( 'Не удалось подтвердить филиал назначения ПЭК.' );
		}
		$location_evidence = $this->assert_location_matches_request( $location_id, $request );
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
			throw new \RuntimeException( 'Не удалось подтвердить филиал назначения ПЭК.' );
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

	/** @return array{source:string,level:string,parent_city_match:bool,settlement_match:bool} */
	private function assert_location_matches_request( int $location_id, ShipmentCreateRequest $request ): array {
		$location = $this->canonical_locations->find_by_id( $location_id );
		if ( ! $location instanceof Location || ! $location->active ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
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

			return array( 'source' => 'canonical_location_repository', 'level' => $level, 'parent_city_match' => true, 'settlement_match' => '' !== trim( $address->settlement ) );
		}
		if ( ! $settlement_match ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}
		if ( '' !== trim( $address->city ) && ! $parent_city_match ) {
			throw new \RuntimeException( self::LOCATION_MISMATCH_MESSAGE );
		}

		return array( 'source' => 'canonical_location_repository', 'level' => $level, 'parent_city_match' => $parent_city_match, 'settlement_match' => true );
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
