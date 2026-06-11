<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\Services\PickupPointLocationResolver;

defined( 'ABSPATH' ) || exit;

final class CheckoutPickupPointRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct(
		private RussianPostPickupPointRepository $repository,
		private CheckoutSessionManager $session_manager,
		private ?PickupPointLocationResolver $location_resolver = null,
		private ?CdekDeliveryPointService $cdek_points = null
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/checkout/pickup-point',
			array(
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'save' ),
					'permission_callback' => array( $this, 'check_nonce' ),
				),
				array(
					'methods' => 'DELETE',
					'callback' => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'check_nonce' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/checkout/state',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'state' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/checkout/pickup-point/resolve-location',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'resolve_location' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
	}

	public function check_nonce( mixed $request ): bool {
		if ( ! function_exists( 'wp_verify_nonce' ) ) {
			return true;
		}

		$nonce = $this->header( $request, 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = $this->param( $request, '_wpnonce' );
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public function save( mixed $request ): mixed {
		$point_id_raw = $this->param( $request, 'point_id' );
		$point_id = (int) $point_id_raw;
		$method_id = $this->normalize_shipping_method_id( $this->param( $request, 'shipping_method_id' ) );
		$carrier = $this->carrier_from_request( $request, $method_id );
		if ( ! $this->is_supported_shipping_method( $method_id, $carrier ) ) {
			return $this->error( 'unsupported_shipping_method', 'Pickup point can only be saved for supported pickup rates.', 400 );
		}

		if ( 'cdek' === $carrier ) {
			$point = $this->cdek_point_from_request( $request, $point_id_raw );
			if ( array() === $point ) {
				return $this->error( 'not_found', 'Pickup point not found.', 404 );
			}
			$selection = $this->cdek_selection( $point );
			$this->save_selection( $selection, 'cdek', $method_id );

			return $this->response( array( 'pickup_point' => $selection ) );
		}

		if ( RussianPostDomesticSettings::CARRIER_KEY !== $carrier ) {
			$point = $this->array_param( $request, 'point' );
			if ( array() === $point ) {
				return $this->error( 'invalid_point', 'Pickup point payload is required.', 400 );
			}
			$selection = $this->selection_from_generic_point( $point, $carrier, $method_id );
			$this->save_selection( $selection, $carrier, $method_id );

			return $this->response( array( 'pickup_point' => $selection ) );
		}

		$row = $this->repository->find_row_by_id( $point_id );
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}

		$selection = $this->selection_from_row( $row );
		$this->save_selection( $selection, RussianPostDomesticSettings::CARRIER_KEY, $method_id );

		return $this->response( array( 'pickup_point' => $selection ) );
	}

	public function delete( mixed $request = null ): mixed {
		$family = $this->param( $request, 'pickup_family' );
		if ( '' === $family ) {
			$method_id = $this->normalize_shipping_method_id( $this->param( $request, 'shipping_method_id' ) );
			$family = '' !== $method_id ? $this->session_manager->shipping_method_family( $method_id ) : '';
		}
		if ( '' !== $family && str_ends_with( $family, ':pickup' ) ) {
			$this->session_manager->clear_pickup_selection_for_family( $family, 'rest_reset' );
		} else {
			$this->session_manager->clear_pickup_selection( 'rest_reset' );
		}

		return $this->response( array( 'pickup_point' => null ) );
	}

	public function state( mixed $request = null ): mixed {
		$family = $this->param( $request, 'pickup_family' );
		$point = '' !== $family ? $this->session_manager->checkout_pickup_point_for_family( $family ) : $this->session_manager->checkout_pickup_point();

		return $this->response(
			array(
				'pickup_point' => array() !== $point ? $point : null,
				'pickup_selections' => $this->session_manager->pickup_selections(),
				'city_context' => $this->city_context(),
			)
		);
	}

	public function resolve_location( mixed $request ): mixed {
		if ( ! $this->location_resolver instanceof PickupPointLocationResolver ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
					'message' => 'Pickup point location resolver is unavailable.',
				)
			);
		}

		$point = $this->array_param( $request, 'point' );
		if ( array() === $point ) {
			$point_id = (int) $this->param( $request, 'point_id' );
			if ( $point_id > 0 ) {
				$row = $this->repository->find_row_by_id( $point_id );
				if ( is_array( $row ) ) {
					$point = $this->point_payload_from_row( $row );
				}
			}
		}

		if ( array() === $point ) {
			return $this->error( 'invalid_point', 'Pickup point payload is required.', 400 );
		}
		if ( 'cdek' === (string) ( $point['carrier_key'] ?? $point['carrier'] ?? '' ) ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
				)
			);
		}

		$checkout_context = $this->array_param( $request, 'checkout_context' );
		if ( array() === $checkout_context ) {
			$checkout_context = $this->session_manager->city_context();
		}

		return $this->response( $this->location_resolver->resolve( $point, $checkout_context ) );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function save_selection( array $selection, string $carrier, string $method_id ): void {
		$family = (string) ( $selection['pickup_family'] ?? $selection['snapshot']['pickup_family'] ?? $this->session_manager->shipping_method_family( $method_id ) );
		if ( ! str_ends_with( $family, ':pickup' ) ) {
			$family = $carrier . ':pickup';
		}
		$service_key = (string) ( $selection['service_key'] ?? $selection['snapshot']['service_key'] ?? $carrier );
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$this->session_manager->save_checkout_pickup_point( $selection );
		$this->session_manager->save_pickup_selection(
			array(
				'carrier_key' => $carrier,
				'carrier' => $carrier,
				'service_key' => $service_key,
				'pickup_family' => $family,
				'rate_id' => $method_id,
				'point_id' => $selection['id'] ?? '',
				'point_code' => $selection['point_code'] ?? '',
				'point_type' => $selection['point_type'] ?? '',
				'point_type_label' => $selection['point_type_label'] ?? ( $selection['snapshot']['point_type_label'] ?? '' ),
				'point_title' => $selection['point_title'] ?? ( $selection['snapshot']['point_title'] ?? '' ),
				'marker_type' => $selection['marker_type'] ?? ( $selection['snapshot']['marker_type'] ?? '' ),
				'point_name' => $selection['point_name'] ?? ( $selection['snapshot']['point_name'] ?? '' ),
				'point_address' => $selection['address'] ?? $selection['point_address'] ?? '',
				'address' => $selection['address'] ?? $selection['point_address'] ?? '',
				'point_postcode' => $selection['postcode'] ?? $selection['point_postcode'] ?? '',
				'postcode' => $selection['postcode'] ?? $selection['point_postcode'] ?? '',
				'city_name' => $selection['snapshot']['city'] ?? $selection['city_name'] ?? '',
				'city' => $selection['snapshot']['city'] ?? $selection['city_name'] ?? '',
				'region_name' => $selection['snapshot']['region'] ?? $selection['region_name'] ?? '',
				'region' => $selection['snapshot']['region'] ?? $selection['region_name'] ?? '',
				'location_id' => $selection['location_id'] ?? $snapshot['location_id'] ?? '',
				'fias_id' => $selection['fias_id'] ?? $snapshot['fias_id'] ?? '',
				'gar_object_id' => $selection['gar_object_id'] ?? $snapshot['gar_object_id'] ?? '',
				'destination_fingerprint' => $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '',
				'description' => (string) ( $selection['description'] ?? $selection['snapshot']['description'] ?? '' ),
				'point_comment' => (string) ( $selection['description'] ?? $selection['snapshot']['description'] ?? '' ),
				'work_time' => (string) ( $selection['work_time'] ?? $selection['point_work_time'] ?? $selection['snapshot']['work_time'] ?? '' ),
				'point_work_time' => (string) ( $selection['point_work_time'] ?? $selection['work_time'] ?? $selection['snapshot']['work_time'] ?? '' ),
				'storage_notice' => (string) ( $selection['storage_notice'] ?? $selection['snapshot']['storage_notice'] ?? '' ),
				'cdek_code' => (string) ( $selection['cdek_code'] ?? $selection['snapshot']['cdek_code'] ?? $selection['point_code'] ?? '' ),
				'cdek_type' => (string) ( $selection['cdek_type'] ?? $selection['snapshot']['cdek_type'] ?? $selection['point_type'] ?? '' ),
				'lat' => $selection['lat'] ?? null,
				'lng' => $selection['lng'] ?? null,
				'snapshot' => $snapshot ?: $selection,
				'selected_at' => gmdate( 'c' ),
			)
		);
	}

	private function carrier_from_request( mixed $request, string $method_id ): string {
		$carrier = sanitize_key( wp_unslash( $this->param( $request, 'carrier' ) ) );
		if ( 'russian_post' === $carrier ) {
			$carrier = RussianPostDomesticSettings::CARRIER_KEY;
		}
		if ( '' !== $carrier ) {
			return $carrier;
		}
		if ( str_starts_with( $method_id, 'cdek:' ) ) {
			return 'cdek';
		}

		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cdek_point_from_request( mixed $request, string $point_id_raw ): array {
		$point = $this->array_param( $request, 'point' );
		if ( array() !== $point && 'cdek' === (string) ( $point['carrier_key'] ?? $point['carrier'] ?? '' ) ) {
			return $point;
		}
		if ( ! $this->cdek_points instanceof CdekDeliveryPointService ) {
			return array();
		}
		$code = $this->param( $request, 'point_code' );
		if ( '' === $code && str_starts_with( $point_id_raw, 'cdek:' ) ) {
			$code = substr( $point_id_raw, 5 );
		}
		if ( '' === $code ) {
			return array();
		}
		foreach ( $this->cdek_points->pointsForLocation( $this->city_context() ?? array() ) as $candidate ) {
			if ( $code === (string) ( $candidate['point_code'] ?? '' ) ) {
				return $candidate;
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	private function cdek_selection( array $point ): array {
		$type = strtoupper( (string) ( $point['point_type'] ?? '' ) );
		$snapshot = array(
			'id' => (string) ( $point['id'] ?? ( 'cdek:' . (string) ( $point['point_code'] ?? '' ) ) ),
			'carrier_key' => 'cdek',
			'service_key' => (string) ( $point['service_key'] ?? 'cdek' ),
			'pickup_family' => (string) ( $point['pickup_family'] ?? 'cdek:pickup' ),
			'point_code' => (string) ( $point['point_code'] ?? '' ),
			'point_type' => $type,
			'point_type_label' => (string) ( $point['point_type_label'] ?? ( 'POSTAMAT' === $type ? 'Постамат' : 'Пункт выдачи' ) ),
			'point_title' => (string) ( $point['point_title'] ?? $point['card_title'] ?? ( 'POSTAMAT' === $type ? 'Постамат СДЭК' : 'Пункт выдачи СДЭК' ) ),
			'marker_type' => (string) ( $point['marker_type'] ?? ( 'POSTAMAT' === $type ? 'postamat' : 'pickup' ) ),
			'point_name' => (string) ( $point['point_name'] ?? '' ),
			'postcode' => (string) ( $point['point_postcode'] ?? $point['postcode'] ?? '' ),
			'address' => (string) ( $point['point_address'] ?? $point['address'] ?? '' ),
			'city' => (string) ( $point['city_name'] ?? $point['city'] ?? '' ),
			'region' => (string) ( $point['region_name'] ?? $point['region'] ?? '' ),
			'location_id' => (string) ( $point['location_id'] ?? '' ),
			'fias_id' => (string) ( $point['fias_id'] ?? $point['fias_location_guid'] ?? '' ),
			'gar_object_id' => (string) ( $point['gar_object_id'] ?? $point['gar_id'] ?? '' ),
			'destination_fingerprint' => (string) ( $point['destination_fingerprint'] ?? '' ),
			'lat' => null !== ( $point['lat'] ?? null ) ? (float) $point['lat'] : null,
			'lng' => null !== ( $point['lng'] ?? null ) ? (float) $point['lng'] : null,
			'work_time' => (string) ( $point['work_time'] ?? '' ),
			'description' => (string) ( $point['description'] ?? $point['cdek_note'] ?? '' ),
			'storage_notice' => (string) ( $point['storage_notice'] ?? ( 'POSTAMAT' === strtoupper( (string) ( $point['point_type'] ?? '' ) ) ? 'Срок хранения 3 дня' : '' ) ),
			'cdek_code' => (string) ( $point['cdek_code'] ?? $point['point_code'] ?? '' ),
			'cdek_uuid' => (string) ( $point['cdek_uuid'] ?? '' ),
			'cdek_type' => (string) ( $point['cdek_type'] ?? $point['point_type'] ?? '' ),
			'cdek_owner_code' => (string) ( $point['cdek_owner_code'] ?? '' ),
			'cdek_nearest_station' => (string) ( $point['cdek_nearest_station'] ?? '' ),
			'cdek_note' => (string) ( $point['cdek_note'] ?? '' ),
			'raw_sanitized' => is_array( $point['raw_sanitized'] ?? null ) ? $point['raw_sanitized'] : ( is_array( $point['raw'] ?? null ) ? $point['raw'] : array() ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier_key' => 'cdek',
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'marker_type' => $snapshot['marker_type'],
			'point_name' => $snapshot['point_name'],
			'point_address' => $snapshot['address'],
			'point_postcode' => $snapshot['postcode'],
			'city_name' => $snapshot['city'],
			'region_name' => $snapshot['region'],
			'location_id' => $snapshot['location_id'],
			'fias_id' => $snapshot['fias_id'],
			'gar_object_id' => $snapshot['gar_object_id'],
			'destination_fingerprint' => $snapshot['destination_fingerprint'],
			'point_work_time' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'cdek_code' => $snapshot['cdek_code'],
			'cdek_uuid' => $snapshot['cdek_uuid'],
			'cdek_type' => $snapshot['cdek_type'],
			'cdek_owner_code' => $snapshot['cdek_owner_code'],
			'cdek_nearest_station' => $snapshot['cdek_nearest_station'],
			'cdek_note' => $snapshot['cdek_note'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	private function selection_from_generic_point( array $point, string $carrier, string $method_id ): array {
		$family = (string) ( $point['pickup_family'] ?? $this->session_manager->shipping_method_family( $method_id ) );
		if ( ! str_ends_with( $family, ':pickup' ) ) {
			$family = $carrier . ':pickup';
		}
		$snapshot = array(
			'id' => (string) ( $point['id'] ?? $point['point_code'] ?? '' ),
			'carrier_key' => $carrier,
			'service_key' => (string) ( $point['service_key'] ?? $carrier ),
			'pickup_family' => $family,
			'point_code' => (string) ( $point['point_code'] ?? '' ),
			'point_type' => (string) ( $point['point_type'] ?? '' ),
			'point_type_label' => (string) ( $point['point_type_label'] ?? '' ),
			'point_title' => (string) ( $point['point_title'] ?? $point['card_title'] ?? '' ),
			'marker_type' => (string) ( $point['marker_type'] ?? '' ),
			'point_name' => (string) ( $point['point_name'] ?? '' ),
			'postcode' => (string) ( $point['point_postcode'] ?? $point['postcode'] ?? '' ),
			'address' => (string) ( $point['point_address'] ?? $point['address'] ?? '' ),
			'city' => (string) ( $point['city_name'] ?? $point['city'] ?? '' ),
			'region' => (string) ( $point['region_name'] ?? $point['region'] ?? '' ),
			'location_id' => (string) ( $point['location_id'] ?? '' ),
			'fias_id' => (string) ( $point['fias_id'] ?? $point['fias_location_guid'] ?? '' ),
			'gar_object_id' => (string) ( $point['gar_object_id'] ?? $point['gar_id'] ?? '' ),
			'destination_fingerprint' => (string) ( $point['destination_fingerprint'] ?? '' ),
			'lat' => $point['lat'] ?? null,
			'lng' => $point['lng'] ?? null,
			'work_time' => (string) ( $point['work_time'] ?? '' ),
			'description' => (string) ( $point['description'] ?? $point['point_comment'] ?? '' ),
			'storage_notice' => (string) ( $point['storage_notice'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier_key' => $snapshot['carrier_key'],
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'marker_type' => $snapshot['marker_type'],
			'point_name' => $snapshot['point_name'],
			'point_address' => $snapshot['address'],
			'point_postcode' => $snapshot['postcode'],
			'city_name' => $snapshot['city'],
			'region_name' => $snapshot['region'],
			'location_id' => $snapshot['location_id'],
			'fias_id' => $snapshot['fias_id'],
			'gar_object_id' => $snapshot['gar_object_id'],
			'destination_fingerprint' => $snapshot['destination_fingerprint'],
			'work_time' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function city_context(): ?array {
		$context = $this->session_manager->city_context();
		if ( array() === $context ) {
			return null;
		}

		return array_filter(
			array(
				'lat'          => $context['lat'] ?? $context['latitude'] ?? null,
				'lng'          => $context['lng'] ?? $context['longitude'] ?? null,
				'city_code'    => $context['city_code'] ?? $context['cdek_city_code'] ?? null,
				'cdek_city_code' => $context['cdek_city_code'] ?? $context['city_code'] ?? null,
				'postcode'     => $context['postcode'] ?? $context['postal_code'] ?? '',
				'display_name' => $context['display_name'] ?? $context['city_name'] ?? $context['settlement_name'] ?? '',
				'city_name'    => $context['city_name'] ?? $context['settlement_name'] ?? $context['display_name'] ?? '',
				'city_value'   => $context['city_value'] ?? $context['settlement_name'] ?? $context['city_name'] ?? $context['display_name'] ?? '',
				'region_name'  => $context['region_name'] ?? '',
				'region_code'  => $context['region_code'] ?? '',
				'region_type'  => $context['region_type'] ?? '',
				'state_value'  => $context['state_value'] ?? $context['region_name'] ?? '',
				'district_name' => $context['district_name'] ?? '',
				'district_type' => $context['district_type'] ?? '',
				'city_type'    => $context['city_type'] ?? '',
				'place_name'   => $context['place_name'] ?? $context['settlement_name'] ?? '',
				'place_type'   => $context['place_type'] ?? $context['settlement_type'] ?? '',
				'country_code' => $context['country_code'] ?? 'RU',
				'location_id'  => $context['location_id'] ?? '',
				'fias_id'      => $context['fias_id'] ?? '',
				'gar_object_id' => $context['gar_object_id'] ?? $context['gar_id'] ?? '',
				'gar_id'       => $context['gar_id'] ?? $context['gar_object_id'] ?? '',
				'kladr_id'     => $context['kladr_id'] ?? '',
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function point_payload_from_row( array $row ): array {
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'postal_code' => (string) ( $row['postcode'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'fias_location_guid' => (string) ( $row['fias_location_guid'] ?? '' ),
			'lat' => $row['latitude'] ?? null,
			'lng' => $row['longitude'] ?? null,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function selection_from_row( array $row ): array {
		$type = strtoupper( (string) ( $row['point_type'] ?? '' ) );
		$type_label = 'APS' === $type ? 'Почтомат' : 'Пункт выдачи';
		$point_title = 'APS' === $type ? 'Почтомат Почты России' : 'Отделение Почты России';
		$marker_type = 'APS' === $type ? 'postamat' : 'pickup';
		$snapshot = array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'pickup_family' => RussianPostDomesticSettings::CARRIER_KEY . ':pickup',
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'marker_type' => $marker_type,
			'point_name' => $point_title,
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'fias_location_guid' => (string) ( $row['fias_location_guid'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier_key' => $snapshot['carrier_key'],
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'card_title' => $snapshot['point_title'],
			'marker_type' => $snapshot['marker_type'],
			'point_name' => $snapshot['point_name'],
			'point_address' => $snapshot['address'],
			'point_postcode' => $snapshot['postcode'],
			'city_name' => $snapshot['city'],
			'region_name' => $snapshot['region'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	private function is_supported_shipping_method( string $method_id, string $carrier ): bool {
		if ( 'cdek' === $carrier ) {
			return str_starts_with( $method_id, 'cdek:' );
		}
		if ( RussianPostDomesticSettings::CARRIER_KEY === $carrier ) {
			return RussianPostDomesticSettings::is_pickup_rate_id( $method_id );
		}

		return str_ends_with( $this->session_manager->shipping_method_family( $method_id ), ':pickup' );
	}

	private function normalize_shipping_method_id( string $method_id ): string {
		$method_id = preg_replace( '/[^A-Za-z0-9_:\\-]/', '', $method_id ) ?? '';
		$prefix = 'wdc_platform:';
		if ( str_starts_with( $method_id, $prefix ) ) {
			return substr( $method_id, strlen( $prefix ) );
		}

		return $method_id;
	}

	private function param( mixed $request, string $key ): string {
		$value = '';
		if ( is_array( $request ) ) {
			$value = $request[ $key ] ?? '';
		} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $key );
		}

		return is_array( $value ) ? '' : sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function array_param( mixed $request, string $key ): array {
		$value = array();
		if ( is_array( $request ) ) {
			$value = $request[ $key ] ?? array();
		} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $key );
		}

		return is_array( $value ) ? $value : array();
	}

	private function header( mixed $request, string $key ): string {
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			return sanitize_text_field( wp_unslash( (string) $request->get_header( $key ) ) );
		}

		return '';
	}

	private function response( mixed $data ): mixed {
		return function_exists( 'rest_ensure_response' ) ? rest_ensure_response( $data ) : $data;
	}

	private function error( string $code, string $message, int $status = 400 ): mixed {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( $code, $message, array( 'status' => $status ) );
		}

		return array( 'code' => $code, 'message' => $message, 'status' => $status );
	}
}
