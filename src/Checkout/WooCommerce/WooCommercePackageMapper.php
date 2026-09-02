<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class WooCommercePackageMapper {
	public function __construct(
		private ?CheckoutAddressRuntime $address_runtime = null,
		private ?CheckoutSessionManager $session_manager = null,
		private ?SettingsRepository $settings = null,
		private ?LocationRepository $location_repository = null,
		private ?RussianPhoneNormalizer $phones = null,
		private ?CheckoutLogger $logger = null
	) {
		$this->phones = $phones ?? new RussianPhoneNormalizer();
	}

	/**
	 * @param array<string,mixed> $package
	 * @param array<string,mixed> $customer_context
	 */
	public function map( array $package, array $customer_context = array() ): QuoteRequest {
		$destination = is_array( $package['destination'] ?? null ) ? $package['destination'] : array();
		$country     = strtoupper( trim( (string) ( $destination['country'] ?? 'RU' ) ) );
		$address     = $this->destination_address( $destination, $country );
		$request_country = '' !== trim( $address->country_code ) ? $address->country_code : ( '' !== $country ? $country : 'RU' );
		$total       = Money::from_rubles( (float) ( $package['contents_cost'] ?? 0 ) );
		$items       = $this->items_from_contents( is_array( $package['contents'] ?? null ) ? $package['contents'] : array() );
		$weight_g    = (int) round( max( 0.0, (float) ( $package['contents_weight'] ?? 0 ) ) * 1000 );
		$location_id = $this->selected_location_id();
		$coordinates = $this->destination_coordinates( $location_id );

		$domain_package = Package::from_items( $items, 0, $total, $total );
		if ( 0 === $domain_package->total_weight_g && $weight_g > 0 ) {
			$domain_package = new Package( $items, $total, $total, $weight_g, 0, $weight_g, null, null, null, null, 'cart' );
		}

		$request = new QuoteRequest(
			$request_country,
			$address,
			$domain_package,
			$this->payment_method(),
			$total,
			gmdate( 'Y-m-d' ),
			array_merge(
				array(
					'items_quantity' => $domain_package->get_total_quantity(),
					'source'         => 'woocommerce_checkout',
					'normalized_address' => $address->normalized,
					'fallback_address'   => $address->fallback,
					'selected_location_id' => $location_id,
					'selected_location_fias_id' => $this->selected_location_fias_id( $address ),
					'recipient_phone' => $this->recipient_phone(),
					'destination_latitude' => $coordinates['latitude'],
					'destination_longitude' => $coordinates['longitude'],
					'dpd_selected_terminal_code' => $this->dpd_selected_terminal_code(),
				),
				$this->strip_untrusted_dadata_context( $customer_context ),
				$this->trusted_dadata_address_context( $address )
			)
		);
		$this->log_quote_request_context( $request, $location_id );

		return $request;
	}

	/**
	 * @param array<string,mixed> $destination
	 */
	private function destination_address( array $destination, string $country ): Address {
		if ( $this->address_runtime instanceof CheckoutAddressRuntime && $this->has_destination_data( $destination ) ) {
			return $this->address_runtime->resolve_checkout_address( $destination )->address;
		}

		$session_result = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->normalized_address_result() : null;
		if ( null !== $session_result ) {
			return $session_result->address;
		}

		return new Address(
			country_code: '' !== $country ? $country : 'RU',
			city: trim( (string) ( $destination['city'] ?? '' ) ),
			postcode: (string) ( $destination['postcode'] ?? '' ),
			street: (string) ( $destination['address'] ?? $destination['address_1'] ?? '' ),
			house: (string) ( $destination['address_2'] ?? '' ),
			raw_address: trim( (string) ( $destination['address'] ?? $destination['address_1'] ?? '' ) . ' ' . (string) ( $destination['address_2'] ?? '' ) )
		);
	}

	/**
	 * @param array<string,mixed> $destination
	 */
	private function has_destination_data( array $destination ): bool {
		foreach ( array( 'city', 'postcode', 'address', 'address_1', 'address_2' ) as $key ) {
			if ( '' !== trim( (string) ( $destination[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int|string,mixed> $contents
	 * @return array<int,PackageItem>
	 */
	private function items_from_contents( array $contents ): array {
		$items = array();

		foreach ( $contents as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$product  = $cart_item['data'] ?? null;
			$quantity = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
			$total    = Money::from_rubles( (float) ( $cart_item['line_total'] ?? 0 ) );

			$items[] = new PackageItem(
				$this->product_sku( $product ),
				$this->product_name( $product ),
				$quantity,
				Money::from_rubles( $quantity > 0 ? $total->get_rubles() / $quantity : 0 ),
				$total,
				$this->product_weight_g( $product ),
				$this->product_dimension_cm( $product, 'get_length' ),
				$this->product_dimension_cm( $product, 'get_width' ),
				$this->product_dimension_cm( $product, 'get_height' )
			);
		}

		return $items;
	}

	private function product_sku( mixed $product ): string {
		return is_object( $product ) && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
	}

	private function product_name( mixed $product ): string {
		return is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
	}

	private function product_weight_g( mixed $product ): int {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_weight' ) ) {
			return 0;
		}

		return (int) round( max( 0.0, (float) $product->get_weight() ) * 1000 );
	}

	private function product_dimension_cm( mixed $product, string $method ): int {
		if ( ! is_object( $product ) || ! method_exists( $product, $method ) ) {
			return 0;
		}

		return (int) round( max( 0.0, (float) $product->{$method}() ) );
	}

	private function payment_method(): string {
		return isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['payment_method'] ) ) : '';
	}

	private function recipient_phone(): string {
		$post_data_phone = $this->post_data_billing_phone();
		if ( '' !== $post_data_phone ) {
			return $post_data_phone;
		}

		return $this->phones->normalize( isset( $_POST['billing_phone'] ) ? wp_unslash( (string) $_POST['billing_phone'] ) : '' );
	}

	private function post_data_billing_phone(): string {
		$parsed = $this->checkout_post_data();

		return $this->phones->normalize( $parsed['billing_phone'] ?? '' );
	}

	/** @return array<string,mixed> */
	private function checkout_post_data(): array {
		if ( ! isset( $_POST['post_data'] ) || ! is_string( $_POST['post_data'] ) ) {
			return array();
		}
		$parsed = array();
		parse_str( wp_unslash( $_POST['post_data'] ), $parsed );

		return is_array( $parsed ) ? $parsed : array();
	}

	/** @return array<string,mixed> */
	private function trusted_dadata_address_context( Address $address ): array {
		if ( ! $this->session_manager instanceof CheckoutSessionManager ) {
			return array();
		}
		$evidence = $this->session_manager->trusted_dadata_address_evidence();
		if ( array() === $evidence ) {
			return array();
		}
		$parsed = $this->checkout_post_data();
		$prefix = $this->active_checkout_prefix( $parsed );
		if ( $prefix !== (string) ( $evidence['prefix'] ?? '' ) ) {
			return array();
		}
		$location_id = $this->selected_location_id();
		$evidence_location_id = trim( (string) ( $evidence['selected_location_id'] ?? '' ) );
		if ( '' === $location_id || '' === $evidence_location_id || $location_id !== $evidence_location_id ) {
			return array();
		}
		if ( ! $this->dadata_location_fias_matches( $evidence, $this->selected_location_fias_id( $address ) ) ) {
			return array();
		}
		if ( ! $this->trusted_address_line_matches_current( $evidence, $parsed, $prefix, $address ) ) {
			return array();
		}

		$context = array(
			'dadata_trusted' => true,
			'dadata_status' => (string) ( $evidence['status'] ?? 'resolved' ),
			'dadata_prefix' => $prefix,
			'dadata_selected_location_id' => $evidence_location_id,
			'dadata_selected_location_fias_id' => (string) ( $evidence['selected_location_fias_id'] ?? '' ),
			'dadata_geo_lat' => (string) ( $evidence['geo_lat'] ?? '' ),
			'dadata_geo_lon' => (string) ( $evidence['geo_lon'] ?? '' ),
			'dadata_value_hash' => (string) ( $evidence['value_hash'] ?? '' ),
			'dadata_confirmed_at' => (string) ( $evidence['confirmed_at'] ?? '' ),
		);
		foreach ( array( 'region_fias_id', 'city_fias_id', 'settlement_fias_id', 'street', 'street_with_type', 'street_fias_id', 'house', 'stead', 'house_fias_id', 'flat' ) as $field ) {
			$value = trim( (string) ( $evidence[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$context[ 'dadata_' . $field ] = $value;
			}
		}

		return $context;
	}

	/** @param array<string,mixed> $context */
	private function strip_untrusted_dadata_context( array $context ): array {
		foreach ( array_keys( $context ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, 'dadata_' ) ) {
				unset( $context[ $key ] );
			}
		}

		return $context;
	}

	/** @param array<string,mixed> $parsed */
	private function active_checkout_prefix( array $parsed ): string {
		return $this->truthy( $parsed['ship_to_different_address'] ?? $_POST['ship_to_different_address'] ?? false ) ? 'shipping' : 'billing';
	}

	/** @param array<string,mixed> $evidence */
	private function dadata_location_fias_matches( array $evidence, string $selected_fias_id ): bool {
		$selected = $this->normalize_text( $selected_fias_id );
		if ( '' === $selected ) {
			return true;
		}
		$evidence_selected = $this->normalize_text( (string) ( $evidence['selected_location_fias_id'] ?? '' ) );
		if ( '' !== $evidence_selected && $selected !== $evidence_selected ) {
			return false;
		}
		$locality = array_filter(
			array(
				$this->normalize_text( (string) ( $evidence['city_fias_id'] ?? '' ) ),
				$this->normalize_text( (string) ( $evidence['settlement_fias_id'] ?? '' ) ),
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( array() === $locality ) {
			return true;
		}

		return in_array( $selected, $locality, true );
	}

	/**
	 * @param array<string,mixed> $evidence
	 * @param array<string,mixed> $parsed
	 */
	private function trusted_address_line_matches_current( array $evidence, array $parsed, string $prefix, Address $address ): bool {
		$current = $this->normalize_text( $this->post_scalar( $parsed, $prefix . '_address_1' ) );
		if ( '' === $current ) {
			$current = $this->normalize_text( $address->street );
		}
		if ( '' === $current ) {
			return false;
		}

		return in_array( $current, $this->trusted_address_candidates( $evidence ), true );
	}

	/** @param array<string,mixed> $evidence @return array<int,string> */
	private function trusted_address_candidates( array $evidence ): array {
		$street = trim( (string) ( $evidence['street_with_type'] ?? $evidence['street'] ?? '' ) );
		$house = $this->house_with_type( $evidence );
		$parts = array_filter(
			array(
				$street,
				$house,
				trim( (string) ( $evidence['flat'] ?? '' ) ),
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
		$candidates = array( implode( ', ', $parts ) );
		if ( '' !== $street ) {
			$candidates[] = $street;
		}

		return array_values( array_unique( array_filter( array_map( array( $this, 'normalize_text' ), $candidates ) ) ) );
	}

	/** @param array<string,mixed> $evidence */
	private function house_with_type( array $evidence ): string {
		$house = trim( (string) ( $evidence['house'] ?? '' ) );
		if ( '' !== $house ) {
			return 'д ' . $house;
		}
		$stead = trim( (string) ( $evidence['stead'] ?? '' ) );
		return '' !== $stead ? 'уч ' . $stead : '';
	}

	private function normalize_text( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'е' ), $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/** @param array<string,mixed> $source */
	private function post_scalar( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
	}

	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
	}

	/** @return array{latitude:?float,longitude:?float} */
	private function destination_coordinates( string $location_id ): array {
		$city = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->selected_city() : array();
		$context = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->city_context() : array();
		$session = $this->coordinate_pair_from_contexts( $city, $context );
		if ( null !== $session ) {
			return $session;
		}

		$id = is_numeric( $location_id ) ? (int) $location_id : 0;
		if ( $id <= 0 || ! $this->location_repository instanceof LocationRepository ) {
			return array( 'latitude' => null, 'longitude' => null );
		}

		$location = $this->location_repository->find_by_id( $id );
		if ( null === $location || ! $location->active ) {
			return array( 'latitude' => null, 'longitude' => null );
		}

		return $this->valid_coordinate_pair( $location->latitude, $location->longitude ) ?? array( 'latitude' => null, 'longitude' => null );
	}

	/**
	 * @param array<string,mixed> $city
	 * @param array<string,mixed> $context
	 * @return array{latitude:float,longitude:float}|null
	 */
	private function coordinate_pair_from_contexts( array $city, array $context ): ?array {
		foreach ( array( $city, $context ) as $source ) {
			$pair = $this->valid_coordinate_pair( $source['latitude'] ?? $source['lat'] ?? null, $source['longitude'] ?? $source['lng'] ?? $source['lon'] ?? null );
			if ( null !== $pair ) {
				return $pair;
			}
		}

		return null;
	}

	/** @return array{latitude:float,longitude:float}|null */
	private function valid_coordinate_pair( mixed $latitude, mixed $longitude ): ?array {
		if ( ! is_numeric( $latitude ) || ! is_numeric( $longitude ) ) {
			return null;
		}
		$latitude = (float) $latitude;
		$longitude = (float) $longitude;
		if ( ! is_finite( $latitude ) || ! is_finite( $longitude ) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			return null;
		}

		return array( 'latitude' => $latitude, 'longitude' => $longitude );
	}

	private function selected_location_fias_id( Address $address ): string {
		if ( '' !== trim( $address->fias_id ) ) {
			return $address->fias_id;
		}

		$city = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->selected_city() : array();
		if ( '' !== trim( (string) ( $city['fias_id'] ?? '' ) ) ) {
			return (string) $city['fias_id'];
		}

		$context = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->city_context() : array();

		return (string) ( $context['fias_id'] ?? '' );
	}

	private function selected_location_id(): string {
		$city = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->selected_city() : array();
		if ( '' !== trim( (string) ( $city['id'] ?? '' ) ) ) {
			return (string) $city['id'];
		}

		$context = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->city_context() : array();

		return (string) ( $context['location_id'] ?? $context['id'] ?? '' );
	}

	private function log_quote_request_context( QuoteRequest $request, string $selected_location_id ): void {
		if ( ! $this->logger instanceof CheckoutLogger ) {
			return;
		}
		$city = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->selected_city() : array();
		$context = $this->session_manager instanceof CheckoutSessionManager ? $this->session_manager->city_context() : array();
		$location_id = (string) ( $request->customer_context['location_id'] ?? $context['location_id'] ?? $context['id'] ?? '' );
		$this->logger->debug(
			'Checkout quote request location context resolved.',
			array(
				'checkout_country_code' => $request->country_code,
				'checkout_city_text' => $request->destination->city,
				'selected_location_id' => $selected_location_id,
				'location_id' => $location_id,
				'resolved_location_id' => '' !== trim( $selected_location_id ) ? $selected_location_id : $location_id,
				'resolved_display_name' => (string) ( $city['display_name'] ?? $context['display_name'] ?? '' ),
				'resolved_place_name' => (string) ( $city['place_name'] ?? $city['settlement_name'] ?? $context['settlement_name'] ?? $context['city_name'] ?? '' ),
				'resolved_place_type' => (string) ( $city['place_type'] ?? $city['settlement_type'] ?? '' ),
				'resolved_place_level' => (string) ( $city['place_level'] ?? '' ),
			)
		);
	}

	private function dpd_selected_terminal_code(): string {
		if ( ! $this->session_manager instanceof CheckoutSessionManager ) {
			return '';
		}
		$selection = $this->session_manager->pickup_selection_for_family( 'dpd:pickup' );

		return trim( (string) ( $selection['terminal_code'] ?? $selection['point_code'] ?? '' ) );
	}

}
