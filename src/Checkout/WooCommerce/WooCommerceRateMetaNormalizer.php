<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WooCommerceRateMetaNormalizer {
	/**
	 * @return array<string,mixed>
	 */
	public static function meta( mixed $method ): array {
		if ( is_object( $method ) && method_exists( $method, 'get_meta_data' ) ) {
			$meta = $method->get_meta_data();
			return is_array( $meta ) ? self::normalize_meta_data( $meta ) : array();
		}

		if ( is_object( $method ) && isset( $method->meta_data ) && is_array( $method->meta_data ) ) {
			return self::normalize_meta_data( $method->meta_data );
		}

		if ( is_array( $method ) && isset( $method['meta_data'] ) && is_array( $method['meta_data'] ) ) {
			return self::normalize_meta_data( $method['meta_data'] );
		}

		return array();
	}

	public static function rate_id( mixed $method, string $fallback = '' ): string {
		if ( is_object( $method ) && method_exists( $method, 'get_id' ) ) {
			$id = $method->get_id();
			return is_scalar( $id ) ? (string) $id : $fallback;
		}
		if ( is_object( $method ) && isset( $method->id ) ) {
			return (string) $method->id;
		}
		if ( is_array( $method ) && isset( $method['id'] ) ) {
			return (string) $method['id'];
		}

		return $fallback;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function rate_snapshot( mixed $method, string $fallback_id = '' ): array {
		$meta = self::meta( $method );
		if ( array() === $meta ) {
			return array();
		}
		$rate_id = (string) ( $meta['rate_id'] ?? self::rate_id( $method, $fallback_id ) );
		if ( '' === $rate_id ) {
			$rate_id = $fallback_id;
		}
		$label = '';
		if ( is_object( $method ) && method_exists( $method, 'get_label' ) ) {
			$label_value = $method->get_label();
			$label = is_scalar( $label_value ) ? (string) $label_value : '';
		} elseif ( is_object( $method ) && isset( $method->label ) ) {
			$label = (string) $method->label;
		}
		$cost = '';
		if ( is_object( $method ) && method_exists( $method, 'get_cost' ) ) {
			$cost_value = $method->get_cost();
			$cost = is_scalar( $cost_value ) ? (string) $cost_value : '';
		} elseif ( is_object( $method ) && isset( $method->cost ) ) {
			$cost = (string) $method->cost;
		}

		return array_merge(
			$meta,
			array(
				'rate_id' => $rate_id,
				'label' => $label,
				'cost' => $cost,
			)
		);
	}

	/**
	 * @param array<mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function normalize_meta_data( array $meta ): array {
		if ( self::is_assoc( $meta ) && ! self::contains_meta_entries( $meta ) ) {
			return $meta;
		}

		$normalized = array();
		foreach ( $meta as $entry ) {
			if ( is_object( $entry ) && method_exists( $entry, 'get_data' ) ) {
				$entry = $entry->get_data();
			}
			if ( is_array( $entry ) && array_key_exists( 'key', $entry ) ) {
				$key = trim( (string) $entry['key'] );
				if ( '' !== $key ) {
					$normalized[ $key ] = $entry['value'] ?? null;
				}
				continue;
			}
			if ( is_object( $entry ) && isset( $entry->key ) ) {
				$key = trim( (string) $entry->key );
				if ( '' !== $key ) {
					$normalized[ $key ] = $entry->value ?? null;
				}
			}
		}

		return $normalized;
	}

	/** @param array<mixed> $meta */
	private static function contains_meta_entries( array $meta ): bool {
		foreach ( $meta as $entry ) {
			if ( is_object( $entry ) && ( method_exists( $entry, 'get_data' ) || isset( $entry->key ) ) ) {
				return true;
			}
			if ( is_array( $entry ) && array_key_exists( 'key', $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<mixed> $array */
	private static function is_assoc( array $array ): bool {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
