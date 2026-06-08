<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class OrderQuoteRequestMapper {
	public function map( object $order ): QuoteRequest {
		$items      = $this->package_items( $order );
		$item_total = $this->items_total( $items );
		$order_total = $item_total->is_zero() ? Money::from_rubles( $this->order_number( $order, 'get_subtotal' ) ) : $item_total;
		$package   = Package::from_items( $items, 0, $order_total, $order_total );
		$address   = $this->destination_address( $order );
		$country   = '' !== trim( $address->country_code ) ? $address->country_code : 'RU';

		if ( 0 === $package->total_weight_g ) {
			$package = new Package( $items, $order_total, $order_total, $this->order_weight_g( $order ), 0, $this->order_weight_g( $order ), null, null, null, null, 'order' );
		}

		return new QuoteRequest(
			$country,
			$address,
			$package,
			$this->order_string( $order, 'get_payment_method' ),
			$order_total,
			$this->calculation_date(),
			$this->customer_context( $order, $address )
		);
	}

	/**
	 * @return array<int,PackageItem>
	 */
	private function package_items( object $order ): array {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$quantity = max( 1, (int) $this->item_value( $item, 'get_quantity', 1 ) );
			$total    = Money::from_rubles( $this->item_value( $item, 'get_total', 0 ) );
			$product  = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$unit     = $quantity > 0 ? $total->get_rubles() / $quantity : 0;

			$items[] = new PackageItem(
				$this->product_string( $product, 'get_sku' ),
				$this->product_string( $product, 'get_name' ) ?: $this->item_string( $item, 'get_name' ),
				$quantity,
				Money::from_rubles( $unit ),
				$total,
				$this->product_dimension_g( $product, 'get_weight' ),
				$this->product_dimension_cm( $product, 'get_length' ),
				$this->product_dimension_cm( $product, 'get_width' ),
				$this->product_dimension_cm( $product, 'get_height' )
			);
		}

		return $items;
	}

	/**
	 * @param array<int,PackageItem> $items
	 */
	private function items_total( array $items ): Money {
		$total = Money::from_rubles( 0 );
		foreach ( $items as $item ) {
			$total = $total->add( $item->total_price );
		}

		return $total;
	}

	private function destination_address( object $order ): Address {
		$calculation = $this->calculation_data( $order );
		$destination = is_array( $calculation['destination'] ?? null ) ? $calculation['destination'] : array();
		$country = strtoupper( $this->order_string( $order, 'get_shipping_country' ) ?: $this->order_string( $order, 'get_billing_country' ) ?: (string) ( $destination['country_code'] ?? 'RU' ) );
		$city = $this->order_string( $order, 'get_shipping_city' );
		if ( '' === $city ) {
			$city = (string) ( $destination['city_display_name'] ?? $this->meta_string( $order, '_wdc_platform_city_display_name' ) );
		}
		$postcode = $this->order_string( $order, 'get_shipping_postcode' )
			?: $this->meta_string( $order, '_wdc_platform_city_postcode' )
			?: $this->meta_string( $order, '_wdc_platform_resolved_postcode' );
		$street = $this->order_string( $order, 'get_shipping_address_1' );
		$house  = $this->order_string( $order, 'get_shipping_address_2' );
		$region = $this->order_string( $order, 'get_shipping_state' );
		if ( '' === $region ) {
			$region = $this->meta_string( $order, '_shipping_state' );
		}

		return new Address(
			country_code: '' !== $country ? $country : 'RU',
			country_name: (string) ( $destination['country_name'] ?? '' ),
			region_name: $region,
			city: $city,
			postcode: $postcode,
			street: $street,
			house: $house,
			raw_address: trim( $street . ' ' . $house ),
			fias_id: (string) ( $destination['fias_id'] ?? $this->meta_string( $order, '_wdc_platform_fias_id' ) ?: $this->meta_string( $order, '_wdc_platform_city_fias_id' ) ),
			gar_id: $this->meta_string( $order, '_wdc_platform_gar_id' ) ?: $this->meta_string( $order, '_wdc_platform_city_gar_id' ),
			normalized: (bool) $this->meta_value( $order, '_wdc_platform_normalized' ),
			fallback: (bool) $this->meta_value( $order, '_wdc_platform_address_fallback_used' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function customer_context( object $order, Address $address ): array {
		$city_display = $this->meta_string( $order, '_wdc_platform_city_display_name' );
		if ( '' === $city_display ) {
			$city_display = $address->city ?: $address->settlement;
		}

		return array_filter(
			array(
				'source'                    => 'woocommerce_order_admin_preview',
				'order_id'                  => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
				'items_quantity'            => $address instanceof Address && method_exists( $order, 'get_item_count' ) ? (int) $order->get_item_count() : 0,
				'postcode'                  => $address->postcode,
				'resolved_postcode'         => $this->meta_string( $order, '_wdc_platform_resolved_postcode' ) ?: $address->postcode,
				'city_postcode'             => $this->meta_string( $order, '_wdc_platform_city_postcode' ) ?: $address->postcode,
				'selected_location_postcode'=> $this->meta_string( $order, '_wdc_platform_city_postcode' ) ?: $address->postcode,
				'city_name'                 => $address->city ?: $address->settlement,
				'display_name'              => $city_display,
				'selected_location_fias_id' => $this->meta_string( $order, '_wdc_platform_location_fias_id' ) ?: $address->fias_id,
				'location_fias_id'          => $this->meta_string( $order, '_wdc_platform_location_fias_id' ) ?: $address->fias_id,
				'fias_id'                   => $address->fias_id,
				'gar_id'                    => $address->gar_id,
				'normalized_address'        => $address->normalized,
				'fallback_address'          => $address->fallback,
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value && 0 !== $value
		);
	}

	private function order_weight_g( object $order ): int {
		$weight = 0;
		foreach ( $this->package_items( $order ) as $item ) {
			$weight += $item->get_total_weight_g();
		}

		return $weight;
	}

	private function calculation_date(): string {
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function calculation_data( object $order ): array {
		$value = $this->meta_value( $order, OrderShippingMetaPersister::CALCULATION_META_KEY );
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	private function meta_string( object $order, string $key ): string {
		$value = $this->meta_value( $order, $key );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function meta_value( object $order, string $key ): mixed {
		return method_exists( $order, 'get_meta' ) ? $order->get_meta( $key, true ) : '';
	}

	private function order_string( object $object, string $method ): string {
		return method_exists( $object, $method ) ? trim( (string) $object->{$method}() ) : '';
	}

	private function order_number( object $object, string $method ): float {
		return method_exists( $object, $method ) && is_numeric( $object->{$method}() ) ? (float) $object->{$method}() : 0.0;
	}

	private function item_string( object $item, string $method ): string {
		return method_exists( $item, $method ) ? trim( (string) $item->{$method}() ) : '';
	}

	private function item_value( object $item, string $method, mixed $default ): mixed {
		return method_exists( $item, $method ) ? $item->{$method}() : $default;
	}

	private function product_string( mixed $product, string $method ): string {
		return is_object( $product ) && method_exists( $product, $method ) ? trim( (string) $product->{$method}() ) : '';
	}

	private function product_dimension_g( mixed $product, string $method ): int {
		return is_object( $product ) && method_exists( $product, $method ) ? (int) round( max( 0.0, (float) $product->{$method}() ) * 1000 ) : 0;
	}

	private function product_dimension_cm( mixed $product, string $method ): int {
		return is_object( $product ) && method_exists( $product, $method ) ? (int) round( max( 0.0, (float) $product->{$method}() ) ) : 0;
	}
}
