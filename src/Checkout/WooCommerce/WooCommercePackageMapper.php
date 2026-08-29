<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
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
		private ?RussianPhoneNormalizer $phones = null
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

		return new QuoteRequest(
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
				$customer_context
			)
		);
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
		if ( ! isset( $_POST['post_data'] ) || ! is_string( $_POST['post_data'] ) ) {
			return '';
		}
		$parsed = array();
		parse_str( wp_unslash( $_POST['post_data'] ), $parsed );

		return $this->phones->normalize( $parsed['billing_phone'] ?? '' );
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

	private function dpd_selected_terminal_code(): string {
		if ( ! $this->session_manager instanceof CheckoutSessionManager ) {
			return '';
		}
		$selection = $this->session_manager->pickup_selection_for_family( 'dpd:pickup' );

		return trim( (string) ( $selection['terminal_code'] ?? $selection['point_code'] ?? '' ) );
	}

}
