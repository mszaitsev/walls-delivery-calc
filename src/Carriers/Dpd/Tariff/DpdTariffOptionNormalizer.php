<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;

defined( 'ABSPATH' ) || exit;

final class DpdTariffOptionNormalizer {
	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize( mixed $response ): array {
		$value = $response instanceof DpdSoapResponse ? $response->body : $response;
		$data = $this->value_to_array( $value );
		$services = $this->extract_services( $data );

		return array_values( array_map( array( $this, 'normalize_service' ), $services ) );
	}

	/**
	 * @return mixed
	 */
	public function raw_body( mixed $response ): mixed {
		return $response instanceof DpdSoapResponse ? $this->value_to_array( $response->body ) : $this->value_to_array( $response );
	}

	/**
	 * @return mixed
	 */
	private function value_to_array( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->value_to_array( $item );
			}
		}

		return $value;
	}

	/**
	 * @param mixed $data
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_services( mixed $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( $this->looks_like_service( $data ) ) {
			return array( $data );
		}

		foreach ( array( 'return', 'service', 'services', 'serviceCost', 'serviceCosts', 'result', 'results' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return $this->extract_services( $data[ $key ] );
			}
		}

		$numeric = array_filter( array_keys( $data ), 'is_int' );
		if ( count( $numeric ) === count( $data ) ) {
			$items = array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					array_push( $items, ...$this->extract_services( $item ) );
				}
			}
			return $items;
		}

		foreach ( $data as $item ) {
			if ( is_array( $item ) ) {
				$nested = $this->extract_services( $item );
				if ( array() !== $nested ) {
					return $nested;
				}
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function looks_like_service( array $data ): bool {
		foreach ( array( 'serviceCode', 'service_code', 'serviceName', 'service_name', 'cost', 'price', 'amount', 'totalCost' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function normalize_service( array $raw ): array {
		$days = $this->first( $raw, array( 'days', 'deliveryDays', 'deliveryPeriod', 'delivery_period' ) );

		return array(
			'service_code' => $this->string_value( $this->first( $raw, array( 'serviceCode', 'service_code', 'code' ) ) ),
			'service_name' => $this->string_value( $this->first( $raw, array( 'serviceName', 'service_name', 'name' ) ) ),
			'cost' => $this->number_or_null( $this->first( $raw, array( 'cost', 'price', 'amount', 'totalCost', 'total_cost' ) ) ),
			'currency' => $this->string_value( $this->first( $raw, array( 'currency', 'currencyCode', 'currency_code' ), 'RUB' ) ),
			'days' => $this->number_or_null( $days ),
			'delivery_period_min' => $this->number_or_null( $this->first( $raw, array( 'deliveryPeriodMin', 'delivery_period_min', 'minDays', 'min_days' ) ) ),
			'delivery_period_max' => $this->number_or_null( $this->first( $raw, array( 'deliveryPeriodMax', 'delivery_period_max', 'maxDays', 'max_days' ) ) ),
			'pickup_date' => $this->string_value( $this->first( $raw, array( 'pickupDate', 'pickup_date' ) ) ),
			'delivery_date' => $this->string_value( $this->first( $raw, array( 'deliveryDate', 'delivery_date' ) ) ),
			'self_pickup' => $this->bool_or_null( $this->first( $raw, array( 'selfPickup', 'self_pickup' ) ) ),
			'self_delivery' => $this->bool_or_null( $this->first( $raw, array( 'selfDelivery', 'self_delivery' ) ) ),
			'raw' => $raw,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,string> $keys
	 */
	private function first( array $data, array $keys, mixed $default = null ): mixed {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
		}

		return $default;
	}

	private function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function number_or_null( mixed $value ): int|float|null {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;

		return floor( $number ) === $number ? (int) $number : $number;
	}

	private function bool_or_null( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return null;
	}
}
