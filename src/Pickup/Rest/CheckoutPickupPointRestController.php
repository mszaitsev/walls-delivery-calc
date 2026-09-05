<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointScheduleFormatter;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\PickupFamilyResolver;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMetaNormalizer;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\Services\PickupPointLocationResolver;

defined( 'ABSPATH' ) || exit;

final class CheckoutPickupPointRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct(
		private RussianPostPickupPointRepository $repository,
		private CheckoutSessionManager $session_manager,
		private ?PickupPointLocationResolver $location_resolver = null,
		private ?CdekDeliveryPointService $cdek_points = null,
		private ?DpdPickupPointService $dpd_points = null,
		private ?YandexDeliveryPickupPointV2Repository $yandex_points = null,
		private ?YandexDeliveryCheckoutPickupPointFormatter $yandex_formatter = null,
		private ?CarrierPickupPointProviderRegistry $provider_registry = null,
		private ?CheckoutPickupPointProviderQueryResolver $provider_query_resolver = null,
		private ?WooCommerceSessionBootstrapper $session_bootstrapper = null
	) {
		$this->yandex_formatter ??= new YandexDeliveryCheckoutPickupPointFormatter();
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
		if ( ! $this->session_bootstrapper instanceof WooCommerceSessionBootstrapper || ! $this->session_bootstrapper->ensure() ) {
			return $this->error( 'provider_session_unavailable', 'Checkout session is unavailable.', 503 );
		}
		$point_id_raw = $this->param( $request, 'point_id' );
		$point_id = (int) $point_id_raw;
		$method_id = $this->normalize_shipping_method_id( $this->param( $request, 'shipping_method_id' ) );
		$carrier = $this->carrier_from_request( $request, $method_id );
		$selection_intent = $this->selection_intent( $request );
		if ( $this->is_registry_backed_carrier( $carrier ) ) {
			return $this->save_registry_backed_selection( $request, $method_id, $carrier, $selection_intent );
		}
		if ( ! $this->is_supported_shipping_method( $method_id, $carrier ) ) {
			return $this->error( 'unsupported_shipping_method', 'Pickup point can only be saved for supported pickup rates.', 400 );
		}

		if ( 'cdek' === $carrier && $this->cdek_points instanceof CdekDeliveryPointService ) {
			$rate = $this->rate_for_shipping_method( $method_id );
			if ( ! $this->is_cdek_pickup_rate( $rate, $method_id ) ) {
				return $this->error( 'unsupported_shipping_method', 'Pickup point can only be saved for a CDEK pickup rate.', 400 );
			}
			$destination = $this->cdek_expected_destination( $rate );
			if ( '' === $destination['country_code'] || ! in_array( $destination['country_code'], CdekSettings::SUPPORTED_COUNTRIES, true ) ) {
				return $this->error( 'unsupported_country', 'CDEK pickup is not available for the selected country.', 400 );
			}
			if ( $destination['city_code'] <= 0 ) {
				return $this->error( 'missing_city_code', 'CDEK pickup city is not resolved for the selected rate.', 400 );
			}
			$point = $this->cdek_point_from_request( $request, $point_id_raw, $destination );
			if ( array() === $point ) {
				return $this->error( 'not_found', 'Pickup point not found.', 404 );
			}
			if ( true !== ( $point['is_handout'] ?? false ) ) {
				return $this->error( 'unsupported_pickup_point', 'Selected CDEK point does not support handout.', 400 );
			}
			$selection = $this->cdek_selection( $point );
			$this->save_selection( $selection, 'cdek', $method_id, $selection_intent );

			return $this->selection_response( $selection, $method_id );
		}

		if ( DpdSettings::CARRIER_KEY === $carrier ) {
			$point = $this->dpd_point_from_request( $request, $point_id_raw );
			if ( array() === $point ) {
				return $this->error( 'not_found', 'Pickup point not found.', 404 );
			}
			$selection = $this->dpd_selection( $point );
			$this->save_selection( $selection, DpdSettings::CARRIER_KEY, $method_id, $selection_intent );

			return $this->selection_response( $selection, $method_id );
		}

		if ( YandexDeliverySettings::CARRIER_KEY === $carrier ) {
			$point = $this->yandex_point_from_request( $request, $point_id_raw );
			if ( array() === $point ) {
				return $this->error( 'not_found', 'Pickup point not found.', 404 );
			}
			$selection = $this->yandex_selection( $point );
			$this->save_selection( $selection, YandexDeliverySettings::CARRIER_KEY, $method_id, $selection_intent );

			return $this->selection_response( $selection, $method_id );
		}

		if ( RussianPostDomesticSettings::CARRIER_KEY !== $carrier ) {
			$point = $this->array_param( $request, 'point' );
			if ( array() === $point ) {
				return $this->error( 'invalid_point', 'Pickup point payload is required.', 400 );
			}
			$selection = $this->selection_from_generic_point( $point, $carrier, $method_id );
			$this->save_selection( $selection, $carrier, $method_id, $selection_intent );

			return $this->selection_response( $selection, $method_id );
		}

		$row = $this->repository->find_row_by_id( $point_id );
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}

		$selection = $this->selection_from_row( $row );
		$this->save_selection( $selection, RussianPostDomesticSettings::CARRIER_KEY, $method_id, $selection_intent );

		return $this->selection_response( $selection, $method_id );
	}

	public function delete( mixed $request = null ): mixed {
		if ( $this->session_bootstrapper instanceof WooCommerceSessionBootstrapper ) {
			$this->session_bootstrapper->ensure();
		}
		$method_id = $this->normalize_shipping_method_id( $this->param( $request, 'shipping_method_id' ) );
		$family = $this->session_manager->normalize_pickup_family( $this->param( $request, 'pickup_family' ) );
		$carrier = $this->carrier_from_request_or_rate( $request, $method_id, $family );
		if ( $this->is_registry_backed_carrier( $carrier ) ) {
			try {
				$context = $this->provider_query_resolver->resolve_context( $method_id, $carrier, $family );
			} catch ( \RuntimeException $exception ) {
				$code = in_array( $exception->getMessage(), array( 'provider_rate_context_missing', 'provider_rate_context_mismatch' ), true ) ? $exception->getMessage() : 'provider_rate_context_missing';
				return $this->error( $code, 'Pickup rate context is invalid.', 400 );
			} catch ( \Throwable ) {
				return $this->error( 'provider_rate_context_missing', 'Pickup rate context is invalid.', 400 );
			}
			$family = (string) ( $context['pickup_family'] ?? '' );
			$this->session_manager->clear_pickup_selection_for_family( $family, 'rest_reset' );
		} else {
			if ( '' === $family ) {
				$family = '' !== $method_id ? $this->session_manager->shipping_method_family( $method_id ) : '';
			}
			if ( '' !== $family && str_ends_with( $family, ':pickup' ) ) {
				$this->session_manager->clear_pickup_selection_for_family( $family, 'rest_reset' );
			} else {
				$this->session_manager->clear_pickup_selection( 'rest_reset' );
			}
		}

		return $this->response(
			array(
				'pickup_point' => null,
				'pickup_selections' => $this->session_manager->pickup_selections(),
				'pickupSelections' => $this->session_manager->pickup_selections(),
				'active_pickup_family' => '' !== $family ? $family : null,
				'activePickupFamily' => '' !== $family ? $family : null,
				'active_pickup_country_code' => $this->active_pickup_country_code(),
				'activePickupCountryCode' => $this->active_pickup_country_code(),
			)
		);
	}

	public function state( mixed $request = null ): mixed {
		if ( $this->session_bootstrapper instanceof WooCommerceSessionBootstrapper ) {
			$this->session_bootstrapper->ensure();
		}
		$family = $this->param( $request, 'pickup_family' );
		$active_family = '' !== $family ? $family : $this->active_pickup_family();
		$point = '' !== $active_family ? $this->session_manager->pickup_selection_for_family_current_destination( $active_family ) : $this->session_manager->pickup_selection_current_destination();
		$pickup_selections = $this->session_manager->pickup_selections_for_current_destination();
		$pickup_rate_capabilities = $this->pickup_rate_capabilities();

		return $this->response(
			array(
				'pickup_point' => array() !== $point ? $point : null,
				'selected_pickup_point' => array() !== $point ? $point : null,
				'pickup_selections' => $pickup_selections,
				'pickupSelections' => $pickup_selections,
				'active_pickup_family' => $active_family,
				'activePickupFamily' => $active_family,
				'active_pickup_country_code' => $this->active_pickup_country_code(),
				'activePickupCountryCode' => $this->active_pickup_country_code(),
				'city_context' => $this->city_context(),
				'pickup_rate_capabilities' => $pickup_rate_capabilities,
				'pickupRateCapabilities' => $pickup_rate_capabilities,
			)
		);
	}

	public function resolve_location( mixed $request ): mixed {
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
		if ( DpdSettings::CARRIER_KEY === (string) ( $point['carrier_key'] ?? $point['carrier'] ?? '' ) ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
				)
			);
		}
		if ( 'cdek' === (string) ( $point['carrier_key'] ?? $point['carrier'] ?? '' ) ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
				)
			);
		}
		if ( YandexDeliverySettings::CARRIER_KEY === (string) ( $point['carrier_key'] ?? $point['carrier'] ?? '' ) ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
				)
			);
		}

		if ( ! $this->location_resolver instanceof PickupPointLocationResolver ) {
			return $this->response(
				array(
					'requires_location_change' => false,
					'location' => null,
					'message' => 'Pickup point location resolver is unavailable.',
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
	private function save_selection( array $selection, string $carrier, string $method_id, string $selection_intent ): void {
		$carrier = $this->session_manager->normalize_carrier_key_for_pickup( $carrier );
		$family = $this->session_manager->normalize_pickup_family( (string) ( $selection['pickup_family'] ?? $selection['snapshot']['pickup_family'] ?? $this->session_manager->shipping_method_family( $method_id ) ) );
		if ( ! str_ends_with( $family, ':pickup' ) ) {
			$family = $carrier . ':pickup';
		}
		$service_key = $this->session_manager->normalize_carrier_key_for_pickup( (string) ( $selection['service_key'] ?? $selection['snapshot']['service_key'] ?? $carrier ) );
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$existing = $this->session_manager->pickup_selection_for_family( $family );
		$existing_snapshot = is_array( $existing['snapshot'] ?? null ) ? $existing['snapshot'] : array();
		$operator_id = $this->first_text_value( $selection['operator_id'] ?? null, $snapshot['operator_id'] ?? null, $existing['operator_id'] ?? null, $existing_snapshot['operator_id'] ?? null );
		$platform_station_id = $this->first_text_value( $selection['platform_station_id'] ?? null, $snapshot['platform_station_id'] ?? null, $existing['platform_station_id'] ?? null, $existing_snapshot['platform_station_id'] ?? null );
		$selected_at = 'explicit' === $selection_intent ? gmdate( 'c' ) : $this->first_text_value( $existing['selected_at'] ?? null, $existing_snapshot['selected_at'] ?? null, $selection['selected_at'] ?? null, $snapshot['selected_at'] ?? null );
		$requires_rate_refresh = true;
		if ( array_key_exists( 'requires_rate_refresh', $selection ) ) {
			$requires_rate_refresh = $this->payload_boolean_value( $selection['requires_rate_refresh'] );
		} elseif ( array_key_exists( 'requires_rate_refresh', $snapshot ) ) {
			$requires_rate_refresh = $this->payload_boolean_value( $snapshot['requires_rate_refresh'] );
		}
		$payload = array(
			'carrier_key' => $carrier,
			'carrier' => $carrier,
			'service_key' => $service_key,
			'pickup_family' => $family,
			'operator_id' => $operator_id,
			'rate_id' => $method_id,
			'point_id' => $selection['id'] ?? '',
			'point_code' => $selection['point_code'] ?? '',
			'terminal_code' => $selection['terminal_code'] ?? ( $selection['snapshot']['terminal_code'] ?? '' ),
			'platform_station_id' => $platform_station_id,
			'point_type' => $selection['point_type'] ?? '',
			'point_type_label' => $selection['point_type_label'] ?? ( $selection['snapshot']['point_type_label'] ?? '' ),
			'point_title' => $selection['point_title'] ?? ( $selection['snapshot']['point_title'] ?? '' ),
			'display_code' => $selection['display_code'] ?? ( $selection['snapshot']['display_code'] ?? '' ),
			'display_title' => $selection['display_title'] ?? ( $selection['snapshot']['display_title'] ?? '' ),
			'presentation_comment' => $selection['presentation_comment'] ?? ( $selection['snapshot']['presentation_comment'] ?? '' ),
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
			'provider_destination_fingerprint' => $selection['provider_destination_fingerprint'] ?? $snapshot['provider_destination_fingerprint'] ?? '',
			'destination_fingerprint' => $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '',
			'country_code' => $selection['country_code'] ?? $snapshot['country_code'] ?? '',
			'cdek_city_code' => $selection['cdek_city_code'] ?? $snapshot['cdek_city_code'] ?? 0,
			'is_handout' => $selection['is_handout'] ?? $snapshot['is_handout'] ?? false,
			'description' => (string) ( $selection['description'] ?? $selection['snapshot']['description'] ?? '' ),
			'point_comment' => (string) ( $selection['point_comment'] ?? $selection['snapshot']['point_comment'] ?? $selection['description'] ?? $selection['snapshot']['description'] ?? '' ),
			'work_time' => (string) ( $selection['work_time'] ?? $selection['point_work_time'] ?? $selection['snapshot']['work_time'] ?? '' ),
			'point_work_time' => (string) ( $selection['point_work_time'] ?? $selection['work_time'] ?? $selection['snapshot']['work_time'] ?? '' ),
			'storage_notice' => (string) ( $selection['storage_notice'] ?? $selection['snapshot']['storage_notice'] ?? '' ),
			'cdek_code' => (string) ( $selection['cdek_code'] ?? $selection['snapshot']['cdek_code'] ?? $selection['point_code'] ?? '' ),
			'cdek_type' => (string) ( $selection['cdek_type'] ?? $selection['snapshot']['cdek_type'] ?? $selection['point_type'] ?? '' ),
			'dpd_source' => (string) ( $selection['dpd_source'] ?? $selection['snapshot']['dpd_source'] ?? '' ),
			'lat' => $selection['lat'] ?? null,
			'lng' => $selection['lng'] ?? null,
			'requires_rate_refresh' => $requires_rate_refresh,
			'snapshot' => $snapshot ?: $selection,
		);
		$payload['snapshot']['requires_rate_refresh'] = $requires_rate_refresh;
		if ( '' !== $selected_at ) {
			$payload['selected_at'] = $selected_at;
		}
		$this->session_manager->save_pickup_selection_for_family( $family, $payload );
	}

	private function selection_intent( mixed $request ): string {
		$intent = sanitize_key( wp_unslash( $this->param( $request, 'selection_intent' ) ) );

		return in_array( $intent, array( 'explicit', 'technical' ), true ) ? $intent : 'explicit';
	}

	private function first_text_value( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	private function is_registry_backed_carrier( string $carrier ): bool {
		return $this->provider_registry instanceof CarrierPickupPointProviderRegistry
			&& $this->provider_query_resolver instanceof CheckoutPickupPointProviderQueryResolver
			&& $this->session_bootstrapper instanceof WooCommerceSessionBootstrapper
			&& $this->provider_registry->has( $carrier );
	}

	private function save_registry_backed_selection( mixed $request, string $method_id, string $carrier, string $selection_intent ): mixed {
		$family = $this->session_manager->normalize_pickup_family( $this->param( $request, 'pickup_family' ) );
		try {
			$context = $this->provider_query_resolver->resolve_context( $method_id, $carrier, $family );
		} catch ( \RuntimeException $exception ) {
			$code = in_array( $exception->getMessage(), array( 'provider_rate_context_missing', 'provider_rate_context_mismatch' ), true ) ? $exception->getMessage() : 'provider_rate_context_missing';
			return $this->error( $code, 'Pickup rate context is invalid.', 400 );
		} catch ( \Throwable ) {
			return $this->error( 'provider_rate_context_missing', 'Pickup rate context is invalid.', 400 );
		}
		$query = $context['query'];
		$family = $context['pickup_family'];
		$provider = $this->provider_registry?->get( $carrier );
		if ( null === $provider ) {
			return $this->error( 'pickup_provider_unavailable', 'Pickup provider is unavailable.', 503 );
		}
		$code = trim( $this->param( $request, 'point_code' ) );
		if ( '' === $code ) {
			$code = trim( $this->param( $request, 'point_id' ) );
		}
		if ( '' === $code ) {
			return $this->error( 'invalid_point', 'Pickup point code is required.', 400 );
		}
		$point = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $code ) );
		if ( ! $point instanceof PickupPoint || $point->code !== $code ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}
		$fingerprint = (string) $context['destination_fingerprint'];
		if ( '' === trim( $fingerprint ) ) {
			return $this->error( 'provider_rate_context_missing', 'Pickup rate context is missing.', 400 );
		}
		$selection = $this->selection_from_provider_point( $point, $carrier, $family, $fingerprint, $query->location_id, $query->country_code, $query->service_key );
		$this->save_selection( $selection, $carrier, $method_id, $selection_intent );

		return $this->selection_response( $selection, $method_id );
	}

	private function selection_from_provider_point( PickupPoint $point, string $carrier, string $family, string $destination_fingerprint, int $location_id, string $country_code, string $service_key = '' ): array {
		$raw = is_array( $point->raw_reference ) ? $point->raw_reference : array();
		$source = (string) ( $raw['source'] ?? '' );
		$type = 'paid' === $source ? 'pvz' : ( 'free' === $source || 'terminal' === $point->type ? 'terminal' : 'pvz' );
		$title = 'terminal' === $type ? 'Собственный пункт выдачи ПЭК' : 'Партнерский пункт выдачи ПЭК';
		$presentation_type = $this->provider_presentation_value( $raw, 'presentation_type' );
		if ( in_array( $presentation_type, array( 'pvz', 'postamat', 'terminal', 'warehouse', 'unknown' ), true ) ) {
			$type = $presentation_type;
		}
		$presentation_title = $this->provider_presentation_value( $raw, 'presentation_title' );
		if ( '' !== $presentation_title ) {
			$title = $presentation_title;
		}
		$presentation_comment = $this->provider_presentation_value( $raw, 'presentation_comment' );
		if ( '' === $presentation_comment && 'paid' === $source ) {
			$presentation_comment = 'Возможна небольшая доплата за доставку в этот пункт';
		}
		$marker_type = $this->provider_presentation_value( $raw, 'marker_type' );
		if ( ! in_array( $marker_type, array( 'pickup', 'postamat', 'terminal' ), true ) ) {
			$marker_type = 'terminal' === $type ? 'terminal' : 'pickup';
		}
		$point_name = $this->public_provider_point_name( $point, $raw );
		$point_title = $this->provider_presentation_value( $raw, 'point_title' );
		if ( '' === $point_title ) {
			$point_title = $title;
		}
		$card_title = $this->provider_presentation_value( $raw, 'card_title' );
		if ( '' === $card_title ) {
			$card_title = $point_title;
		}
		$display_code = $this->provider_presentation_value( $raw, 'display_code' );
		$display_title = $this->provider_presentation_value( $raw, 'display_title' );
		if ( '' === $display_title ) {
			$display_title = trim( $card_title . ( '' !== $display_code ? ' ' . $display_code : '' ) );
		}
		$point_comment = trim( (string) $point->comment );
		$requires_rate_refresh = true;
		if ( array_key_exists( 'requires_rate_refresh', $raw ) ) {
			$requires_rate_refresh = $this->provider_boolean_value( $raw, 'requires_rate_refresh' );
		}
		$snapshot = array(
			'carrier_key' => $carrier,
			'service_key' => '' !== trim( $service_key ) ? $service_key : $carrier,
			'pickup_family' => $family,
			'point_code' => $point->code,
			'point_id' => $point->code,
			'point_type' => $type,
			'point_type_label' => $title,
			'point_title' => $point_title,
			'card_title' => $card_title,
			'point_name' => $point_name,
			'point_address' => $point->address,
			'address' => $point->address,
			'city_name' => $point->city,
			'region_name' => $point->region,
			'lat' => $point->latitude,
			'lng' => $point->longitude,
			'latitude' => $point->latitude,
			'longitude' => $point->longitude,
			'work_time' => $point->work_time,
			'description' => $point_comment,
			'point_comment' => $point_comment,
			'presentation_comment' => $presentation_comment,
			'marker_type' => $marker_type,
			'display_code' => $display_code,
			'display_title' => $display_title,
			'source' => in_array( $source, array( 'free', 'paid' ), true ) ? $source : '',
			'location_id' => $location_id,
			'country_code' => strtoupper( trim( $country_code ) ),
			'destination_fingerprint' => $destination_fingerprint,
			'provider_destination_fingerprint' => $destination_fingerprint,
			'requires_rate_refresh' => $requires_rate_refresh,
			'validation_source' => 'provider_resolve_selection',
			'selected_at' => gmdate( 'c' ),
		);

		return array_merge( $snapshot, array( 'id' => $point->code, 'snapshot' => $snapshot ) );
	}

	/** @param array<string,mixed> $raw */
	private function provider_boolean_value( array $raw, string $key ): bool {
		$value = $raw[ $key ] ?? false;
		return $this->payload_boolean_value( $value );
	}

	private function payload_boolean_value( mixed $value ): bool {
		return true === $value || '1' === $value || 1 === $value || 'true' === $value;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function public_provider_point_name( PickupPoint $point, array $raw ): string {
		foreach ( array( $raw['point_name'] ?? null, $raw['division_name'] ?? null, $raw['branch_name'] ?? null ) as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$name = trim( (string) $candidate );
			if ( '' === $name || $name === $point->code || $this->looks_like_internal_point_identifier( $name ) ) {
				continue;
			}
			return $name;
		}

		return '';
	}

	/** @param array<string,mixed> $raw */
	private function provider_presentation_value( array $raw, string $key ): string {
		$value = $raw[ $key ] ?? null;
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function looks_like_internal_point_identifier( string $value ): bool {
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return true;
		}

		return 1 === preg_match( '/^[a-z0-9_-]{16,}$/i', $value )
			&& 1 === preg_match( '/[0-9]/', $value )
			&& 1 === preg_match( '/[a-z]/i', $value );
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
		if ( str_starts_with( $method_id, DpdSettings::CARRIER_KEY . ':' ) ) {
			return DpdSettings::CARRIER_KEY;
		}
		if ( str_starts_with( $method_id, YandexDeliverySettings::CARRIER_KEY . ':' ) || 'yandex_pickup' === $method_id ) {
			return YandexDeliverySettings::CARRIER_KEY;
		}
		if ( str_starts_with( $method_id, PekSettings::CARRIER_KEY . ':' ) ) {
			return PekSettings::CARRIER_KEY;
		}

		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	private function carrier_from_request_or_rate( mixed $request, string $method_id, string $pickup_family = '' ): string {
		$carrier = sanitize_key( wp_unslash( $this->param( $request, 'carrier' ) ) );
		if ( 'russian_post' === $carrier ) {
			$carrier = RussianPostDomesticSettings::CARRIER_KEY;
		}
		if ( '' !== $carrier ) {
			return $carrier;
		}
		$rate = $this->rate_for_shipping_method( $method_id );
		if ( array() !== $rate ) {
			$meta = $this->rate_meta( $rate );
			$carrier = sanitize_key( (string) ( $rate['carrier_key'] ?? $meta['carrier_key'] ?? '' ) );
			if ( '' !== $carrier ) {
				return $carrier;
			}
		}
		if ( $this->provider_registry instanceof CarrierPickupPointProviderRegistry ) {
			foreach ( array_keys( $this->provider_registry->all() ) as $registry_carrier ) {
				if ( str_starts_with( $method_id, $registry_carrier . ':' ) || str_starts_with( $pickup_family, $registry_carrier . ':' ) ) {
					return $registry_carrier;
				}
			}
		}

		return $this->carrier_from_request( $request, $method_id );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cdek_point_from_request( mixed $request, string $point_id_raw, array $destination ): array {
		if ( ! $this->cdek_points instanceof CdekDeliveryPointService ) {
			return array();
		}
		$code = $this->cdek_point_code_from_request( $request, $point_id_raw );
		if ( '' === $code ) {
			return array();
		}
		foreach ( $this->cdek_points->pointsByCityCode( $destination['city_code'], array( 'country_code' => $destination['country_code'], 'handout_only' => true ) ) as $candidate ) {
			if ( $code === (string) ( $candidate['point_code'] ?? '' ) ) {
				$point_country = strtoupper( trim( (string) ( $candidate['country_code'] ?? '' ) ) );
				$point_city = (int) ( $candidate['cdek_city_code'] ?? 0 );
				if ( '' !== $point_country && $point_country !== $destination['country_code'] ) {
					return array();
				}
				if ( $point_city > 0 && $point_city !== $destination['city_code'] ) {
					return array();
				}
				if ( '' === $point_country ) {
					$candidate['country_code'] = $destination['country_code'];
				}
				if ( $point_city <= 0 ) {
					$candidate['cdek_city_code'] = $destination['city_code'];
				}
				return $candidate;
			}
		}

		return array();
	}

	private function cdek_point_code_from_request( mixed $request, string $point_id_raw ): string {
		$code = $this->param( $request, 'point_code' );
		if ( str_starts_with( $code, 'cdek:' ) ) {
			$code = substr( $code, 5 );
		}
		if ( '' === $code && str_starts_with( $point_id_raw, 'cdek:' ) ) {
			$code = substr( $point_id_raw, 5 );
		}
		if ( '' === $code ) {
			$code = $point_id_raw;
		}

		return preg_replace( '/[^A-Za-z0-9_\\-]/', '', $code ) ?? '';
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
			'display_code' => (string) ( $point['display_code'] ?? $point['cdek_code'] ?? $point['point_code'] ?? '' ),
			'display_title' => (string) ( $point['display_title'] ?? '' ),
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
			'country_code' => (string) ( $point['country_code'] ?? '' ),
			'cdek_city_code' => (int) ( $point['cdek_city_code'] ?? 0 ),
			'is_handout' => array_key_exists( 'is_handout', $point ) && filter_var( $point['is_handout'], FILTER_VALIDATE_BOOLEAN ),
			'raw_sanitized' => is_array( $point['raw_sanitized'] ?? null ) ? $point['raw_sanitized'] : ( is_array( $point['raw'] ?? null ) ? $point['raw'] : array() ),
		);
		if ( '' === $snapshot['display_title'] ) {
			$snapshot['display_title'] = trim( $snapshot['point_title'] . ' ' . $snapshot['display_code'] );
		}

		return array(
			'id' => $snapshot['id'],
			'carrier_key' => 'cdek',
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'display_code' => $snapshot['display_code'],
			'display_title' => $snapshot['display_title'],
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
			'country_code' => $snapshot['country_code'],
			'cdek_city_code' => $snapshot['cdek_city_code'],
			'is_handout' => $snapshot['is_handout'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function yandex_point_from_request( mixed $request, string $point_id_raw ): array {
		if ( ! $this->yandex_points instanceof YandexDeliveryPickupPointV2Repository ) {
			return array();
		}
		$code = $this->param( $request, 'point_code' );
		if ( '' === $code && str_starts_with( $point_id_raw, YandexDeliverySettings::CARRIER_KEY . ':' ) ) {
			$code = substr( $point_id_raw, strlen( YandexDeliverySettings::CARRIER_KEY . ':' ) );
		}
		if ( '' === $code ) {
			$point = $this->array_param( $request, 'point' );
			$code = (string) ( $point['platform_station_id'] ?? $point['point_code'] ?? '' );
		}
		if ( '' === trim( $code ) ) {
			return array();
		}
		$row = $this->yandex_points->destination_pickup_point_by_platform_station_id( $code );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function yandex_selection( array $row ): array {
		$point = $this->yandex_formatter->format( $row );
		$snapshot = is_array( $point['snapshot'] ?? null ) ? $point['snapshot'] : array();

		return array_merge(
			$point,
			array(
				'point_id' => (string) $snapshot['id'],
				'point_work_time' => (string) ( $point['work_time'] ?? '' ),
				'snapshot' => $snapshot,
			)
		);
	}
	/**
	 * @return array<string,mixed>
	 */
	private function dpd_point_from_request( mixed $request, string $point_id_raw ): array {
		if ( ! $this->dpd_points instanceof DpdPickupPointService ) {
			return array();
		}
		$code = $this->param( $request, 'point_code' );
		if ( '' === $code && str_starts_with( $point_id_raw, DpdSettings::CARRIER_KEY . ':' ) ) {
			$code = substr( $point_id_raw, strlen( DpdSettings::CARRIER_KEY . ':' ) );
		}
		if ( '' === $code ) {
			$point = $this->array_param( $request, 'point' );
			$code = (string) ( $point['terminal_code'] ?? $point['point_code'] ?? '' );
		}
		if ( '' === $code ) {
			return array();
		}

		return $this->dpd_points->get_point_by_terminal_code( $code ) ?? array();
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	private function dpd_selection( array $point ): array {
		$type = (string) ( $point['type'] ?? '' );
		$type_label = 'terminal_self_delivery' === $type ? 'Терминал' : 'Пункт выдачи';
		$point_title = 'terminal_self_delivery' === $type ? 'Терминал DPD' : 'Пункт выдачи DPD';
		$marker_type = 'terminal_self_delivery' === $type ? 'terminal' : 'pickup';
		$code = (string) ( $point['terminal_code'] ?? '' );
		$work_time = ( new DpdPickupPointScheduleFormatter() )->format( $point['schedule'] ?? '' );
		$snapshot = array(
			'id' => DpdSettings::CARRIER_KEY . ':' . $code,
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => DpdSettings::CARRIER_KEY . ':pickup',
			'point_code' => $code,
			'terminal_code' => $code,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => $code,
			'display_title' => trim( $point_title . ' ' . $code ),
			'marker_type' => $marker_type,
			'point_name' => (string) ( $point['name'] ?? '' ),
			'address' => (string) ( $point['address'] ?? '' ),
			'city' => (string) ( $point['city_name'] ?? '' ),
			'region' => (string) ( $point['region_name'] ?? '' ),
			'lat' => $point['latitude'] ?? null,
			'lng' => $point['longitude'] ?? null,
			'work_time' => $work_time,
			'description' => '',
			'dpd_source' => (string) ( $point['source'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $code,
			'terminal_code' => $code,
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'display_code' => $snapshot['display_code'],
			'display_title' => $snapshot['display_title'],
			'marker_type' => $snapshot['marker_type'],
			'point_name' => $snapshot['point_name'],
			'point_address' => $snapshot['address'],
			'city_name' => $snapshot['city'],
			'region_name' => $snapshot['region'],
			'point_work_time' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'dpd_source' => $snapshot['dpd_source'],
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
			'point_comment' => (string) ( $point['point_comment'] ?? '' ),
			'description' => (string) ( $point['description'] ?? '' ),
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
			'point_comment' => $snapshot['point_comment'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	private function active_pickup_family(): string {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) || ! method_exists( WC()->session, 'get' ) ) {
			return '';
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! is_array( $chosen ) ) {
			return '';
		}
		foreach ( $chosen as $method ) {
			$method_id = $this->normalize_shipping_method_id( (string) $method );
			$rate = $this->rate_for_shipping_method( $method_id );
			$family = array() !== $rate ? PickupFamilyResolver::from_meta( array_replace( $this->rate_meta( $rate ), $rate ), $method_id ) : '';
			if ( '' === $family ) {
				$family = $this->session_manager->shipping_method_family( $method_id );
			}
			if ( str_ends_with( $family, ':pickup' ) ) {
				return $family;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function selection_response( array $selection, string $method_id ): mixed {
		$family = $this->session_manager->shipping_method_family( $method_id );
		if ( ! str_ends_with( $family, ':pickup' ) ) {
			$family = (string) ( $selection['pickup_family'] ?? $selection['snapshot']['pickup_family'] ?? '' );
		}
		$point = '' !== $family ? $this->session_manager->checkout_pickup_point_for_family( $family ) : $selection;
		$selections = $this->session_manager->pickup_selections();

		return $this->response(
			array(
				'pickup_point' => array() !== $point ? $point : $selection,
				'selected_pickup_point' => array() !== $point ? $point : $selection,
				'pickup_selections' => $selections,
				'pickupSelections' => $selections,
				'active_pickup_family' => $family,
				'activePickupFamily' => $family,
				'active_pickup_country_code' => $this->active_pickup_country_code(),
				'activePickupCountryCode' => $this->active_pickup_country_code(),
			)
		);
	}

	private function active_shipping_method_id(): string {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) || ! method_exists( WC()->session, 'get' ) ) {
			return '';
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! is_array( $chosen ) ) {
			return '';
		}
		foreach ( $chosen as $method ) {
			$method = trim( (string) $method );
			if ( '' !== $method ) {
				return $this->normalize_shipping_method_id( $method );
			}
		}

		return '';
	}

	/**
	 * @return array<string,array<string,bool>>
	 */
	private function pickup_rate_capabilities(): array {
		$capabilities = array();
		foreach ( $this->session_manager->rates() as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}
			$meta = $this->rate_meta( $rate );
			if ( 'pickup' !== (string) ( $rate['delivery_type'] ?? $meta['delivery_type'] ?? '' ) || empty( $rate['requires_pickup_point'] ) ) {
				continue;
			}
			$rate_id = $this->normalize_shipping_method_id( (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ) );
			if ( '' === $rate_id ) {
				continue;
			}
			$snapshot = is_array( $meta['pickup_provider_query'] ?? null ) ? $meta['pickup_provider_query'] : array();
			$rate_capabilities = array();
			foreach ( array( 'reload_on_viewport_change', 'prefetch_points' ) as $key ) {
				if ( array_key_exists( $key, $snapshot ) ) {
					$rate_capabilities[ $key ] = (bool) $snapshot[ $key ];
				}
			}
			if ( array() !== $rate_capabilities ) {
				$capabilities[ $rate_id ] = $rate_capabilities;
			}
		}

		return $capabilities;
	}

	private function active_pickup_country_code(): string {
		$rate = $this->rate_for_shipping_method( $this->active_shipping_method_id() );
		if ( array() === $rate || 'pickup' !== (string) ( $rate['delivery_type'] ?? $this->rate_meta( $rate )['delivery_type'] ?? '' ) || empty( $rate['requires_pickup_point'] ) ) {
			return '';
		}
		$meta = $this->rate_meta( $rate );
		$location = is_array( $meta['location'] ?? null ) ? $meta['location'] : array();
		$request_payload = is_array( $meta['request_payload_sanitized'] ?? null ) ? $meta['request_payload_sanitized'] : array();
		$api = is_array( $meta['api'] ?? null ) ? $meta['api'] : array();
		if ( array() === $request_payload && is_array( $api['request_payload_sanitized'] ?? null ) ) {
			$request_payload = $api['request_payload_sanitized'];
		}
		$to_location = is_array( $request_payload['to_location'] ?? null ) ? $request_payload['to_location'] : array();
		$country = strtoupper(
			trim(
				$this->first_text_value(
					$location['cdek_to_country_code'] ?? null,
					$location['country_code'] ?? null,
					$meta['country_code'] ?? null,
					$rate['country_code'] ?? null,
					$to_location['country_code'] ?? null
				)
			)
		);

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
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
			'display_code' => (string) ( $row['postcode'] ?? '' ),
			'display_title' => trim( $point_title . ' ' . (string) ( $row['postcode'] ?? '' ) ),
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
			'display_code' => $snapshot['display_code'],
			'display_title' => $snapshot['display_title'],
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
			if ( ! $this->cdek_points instanceof CdekDeliveryPointService ) {
				$rate = $this->rate_for_shipping_method( $method_id );
				$family = array() !== $rate ? PickupFamilyResolver::from_meta( array_replace( $this->rate_meta( $rate ), $rate ), $method_id ) : $this->session_manager->shipping_method_family( $method_id );
				return str_ends_with( $family, ':pickup' );
			}
			return $this->is_cdek_pickup_rate( $this->rate_for_shipping_method( $method_id ), $method_id );
		}
		if ( RussianPostDomesticSettings::CARRIER_KEY === $carrier ) {
			return RussianPostDomesticSettings::is_pickup_rate_id( $method_id );
		}

		$rate = $this->rate_for_shipping_method( $method_id );
		$family = array() !== $rate ? PickupFamilyResolver::from_meta( array_replace( $this->rate_meta( $rate ), $rate ), $method_id ) : $this->session_manager->shipping_method_family( $method_id );
		return str_ends_with( $family, ':pickup' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rate_for_shipping_method( string $method_id ): array {
		$method_id = $this->session_manager->normalize_rate_id( $method_id );
		$woocommerce_rate = $this->woocommerce_rate( $method_id );
		if ( array() !== $woocommerce_rate ) {
			return $woocommerce_rate;
		}
		$rates = $this->session_manager->rates();
		if ( isset( $rates[ $method_id ] ) && is_array( $rates[ $method_id ] ) ) {
			return $rates[ $method_id ];
		}
		foreach ( $rates as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}
			$rate_id = $this->session_manager->normalize_rate_id( (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ) );
			if ( $rate_id === $method_id ) {
				return $rate;
			}
		}

		return array();
	}

	/** @return array<string,mixed> */
	private function woocommerce_rate( string $normalized_rate_id ): array {
		if ( '' === $normalized_rate_id || ! function_exists( 'WC' ) || ! is_object( WC() ) || ! method_exists( WC(), 'shipping' ) ) {
			return array();
		}
		$shipping = WC()->shipping();
		if ( ! is_object( $shipping ) || ! method_exists( $shipping, 'get_packages' ) ) {
			return array();
		}
		$packages = $shipping->get_packages();
		if ( ! is_array( $packages ) ) {
			return array();
		}
		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) || ! is_array( $package['rates'] ?? null ) ) {
				continue;
			}
			foreach ( $package['rates'] as $key => $rate ) {
				$rate_id = $this->session_manager->normalize_rate_id( WooCommerceRateMetaNormalizer::rate_id( $rate, (string) $key ) );
				if ( $rate_id === $normalized_rate_id ) {
					return WooCommerceRateMetaNormalizer::rate_snapshot( $rate, (string) $key );
				}
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_cdek_pickup_rate( array $rate, string $method_id ): bool {
		if ( array() === $rate ) {
			return false;
		}
		$meta = $this->rate_meta( $rate );

		return CdekSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? $meta['carrier_key'] ?? '' )
			&& CdekSettings::SERVICE_KEY === (string) ( $rate['service_key'] ?? $meta['service_key'] ?? CdekSettings::SERVICE_KEY )
			&& 'pickup' === (string) ( $rate['delivery_type'] ?? $meta['delivery_type'] ?? '' )
			&& ! empty( $rate['requires_pickup_point'] )
			&& CdekSettings::CARRIER_KEY . ':pickup' === PickupFamilyResolver::from_meta( array_replace( $meta, $rate ), $method_id );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array{country_code:string,city_code:int}
	 */
	private function cdek_expected_destination( array $rate ): array {
		$meta = $this->rate_meta( $rate );
		$location = is_array( $meta['location'] ?? null ) ? $meta['location'] : array();
		$request_payload = is_array( $meta['request_payload_sanitized'] ?? null ) ? $meta['request_payload_sanitized'] : array();
		$api = is_array( $meta['api'] ?? null ) ? $meta['api'] : array();
		if ( array() === $request_payload && is_array( $api['request_payload_sanitized'] ?? null ) ) {
			$request_payload = $api['request_payload_sanitized'];
		}
		$to_location = is_array( $request_payload['to_location'] ?? null ) ? $request_payload['to_location'] : array();
		$city_context = $this->city_context() ?? array();
		$country_code = strtoupper(
			trim(
				$this->first_text_value(
					$location['cdek_to_country_code'] ?? null,
					$meta['country_code'] ?? null,
					$rate['country_code'] ?? null,
					$to_location['country_code'] ?? null,
					$city_context['country_code'] ?? null
				)
			)
		);
		if ( '' === $country_code ) {
			$country_code = 'RU';
		}
		$city_code = $this->first_positive_int(
			$location['cdek_to_city_code'] ?? null,
			$api['cdek_to_city_code'] ?? null,
			$meta['cdek_to_city_code'] ?? null,
			$to_location['code'] ?? null,
			$city_context['cdek_city_code'] ?? null,
			$city_context['city_code'] ?? null
		);

		return array( 'country_code' => $country_code, 'city_code' => $city_code );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function rate_meta( array $rate ): array {
		$meta = is_array( $rate['meta'] ?? null ) ? $rate['meta'] : array();
		$legacy_meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();

		return array_replace_recursive( $legacy_meta, $meta );
	}

	private function first_positive_int( mixed ...$values ): int {
		foreach ( $values as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
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
