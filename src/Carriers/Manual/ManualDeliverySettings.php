<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class ManualDeliverySettings {
	public const CARRIER_KEY = 'manual';
	public const CARRIER_TITLE = 'Ручная доставка';
	public const PRICING_MODE_FLAT = 'flat';
	public const PRICING_MODE_PER_KG = 'per_kg';
	public const PRICING_MODE_WEIGHT_RANGES = 'weight_ranges';
	public const PRICING_SETTING_KEY = 'manual_pricing';
	public const DELIVERY_DAYS_SETTING_KEY = 'manual_delivery_days';
	public const DELIVERY_TYPE_SETTING_KEY = 'manual_delivery_type';
	public const DELIVERY_TYPE_COURIER = 'courier';
	public const DELIVERY_TYPE_PICKUP = 'pickup';
	public const DELIVERY_TYPE_CUSTOM = 'custom';
	public const BILLING_STEP_NONE_G = 1;
	public const BILLING_STEP_100_G = 100;
	public const BILLING_STEP_500_G = 500;
	public const BILLING_STEP_1_KG = 1000;

	public function __construct(
		private DeliveryServiceSettingsRepository $settings
	) {
	}

	/**
	 * @return array{pricing_mode:string,flat_price_kopecks:int,price_per_kg_kopecks:int,minimum_price_kopecks:?int,billing_weight_step_g:int}
	 */
	public function pricing( int $service_id ): array {
		$value = $this->settings->get_setting( $service_id, self::PRICING_SETTING_KEY, array() );
		if ( ! is_array( $value ) ) {
			return $this->invalid_pricing();
		}

		$mode = $this->normalize_pricing_mode( (string) ( $value['pricing_mode'] ?? self::PRICING_MODE_FLAT ) );
		$flat = array_key_exists( 'flat_price_kopecks', $value ) ? (int) $value['flat_price_kopecks'] : -1;
		$per_kg = array_key_exists( 'price_per_kg_kopecks', $value ) ? (int) $value['price_per_kg_kopecks'] : -1;
		$minimum = array_key_exists( 'minimum_price_kopecks', $value ) && null !== $value['minimum_price_kopecks'] && '' !== $value['minimum_price_kopecks'] ? max( 0, (int) $value['minimum_price_kopecks'] ) : null;

		return array(
			'pricing_mode' => $mode,
			'flat_price_kopecks' => $flat >= 0 ? $flat : -1,
			'price_per_kg_kopecks' => $per_kg,
			'minimum_price_kopecks' => $minimum,
			'billing_weight_step_g' => $this->normalize_billing_weight_step( (int) ( $value['billing_weight_step_g'] ?? $this->default_billing_weight_step( $mode ) ), $mode ),
		);
	}

	/**
	 * @param array<int,ManualDeliveryWeightRange> $ranges
	 */
	public function pricing_config( int $service_id, array $ranges = array() ): ManualDeliveryPricingConfig {
		$pricing = $this->pricing( $service_id );

		return new ManualDeliveryPricingConfig(
			$pricing['pricing_mode'],
			$pricing['flat_price_kopecks'],
			$pricing['price_per_kg_kopecks'],
			$pricing['minimum_price_kopecks'],
			$pricing['billing_weight_step_g'],
			$ranges
		);
	}

	public function save_flat_pricing( int $service_id, mixed $price_rub ): void {
		$this->settings->set_setting(
			$service_id,
			self::PRICING_SETTING_KEY,
			array(
				'pricing_mode' => self::PRICING_MODE_FLAT,
				'flat_price_kopecks' => Money::from_rubles( $price_rub )->get_kopecks(),
			),
			'json'
		);
	}

	/**
	 * @param array<string,mixed> $values
	 */
	public function save_pricing( int $service_id, array $values ): void {
		$mode = $this->normalize_pricing_mode( (string) ( $values['pricing_mode'] ?? self::PRICING_MODE_FLAT ) );
		if ( '' === $mode ) {
			throw new \InvalidArgumentException( 'manual_pricing_mode_invalid' );
		}
		$existing = $this->pricing( $service_id );
		$flat_raw = (string) ( $values['flat_price_rub'] ?? '' );
		$flat = array_key_exists( 'flat_price_rub', $values ) && '' !== trim( $flat_raw )
			? $this->required_kopecks( $flat_raw, 'manual_flat_price_invalid' )
			: max( 0, $existing['flat_price_kopecks'] );
		$per_kg_raw = (string) ( $values['price_per_kg_rub'] ?? '' );
		$per_kg = array_key_exists( 'price_per_kg_rub', $values ) && '' !== trim( $per_kg_raw )
			? $this->required_kopecks( $per_kg_raw, 'manual_price_per_kg_invalid' )
			: $existing['price_per_kg_kopecks'];
		$minimum = array_key_exists( 'minimum_price_rub', $values )
			? $this->optional_kopecks( $values['minimum_price_rub'], 'manual_tariff_minimum_invalid' )
			: $existing['minimum_price_kopecks'];
		$step = $this->billing_weight_step_from_values( $values, $mode );

		if ( self::PRICING_MODE_FLAT === $mode && $flat < 0 ) {
			throw new \InvalidArgumentException( 'manual_flat_price_invalid' );
		}
		if ( self::PRICING_MODE_PER_KG === $mode && $per_kg < 0 ) {
			throw new \InvalidArgumentException( 'manual_price_per_kg_invalid' );
		}

		$this->settings->set_setting(
			$service_id,
			self::PRICING_SETTING_KEY,
			array(
				'pricing_mode' => $mode,
				'flat_price_kopecks' => max( 0, $flat ),
				'price_per_kg_kopecks' => $per_kg,
				'minimum_price_kopecks' => $minimum,
				'billing_weight_step_g' => $step,
			),
			'json'
		);
	}

	/**
	 * @return array{min_days:?int,max_days:?int}
	 */
	public function delivery_days( int $service_id ): array {
		$value = $this->settings->get_setting( $service_id, self::DELIVERY_DAYS_SETTING_KEY, array() );
		if ( ! is_array( $value ) ) {
			return array( 'min_days' => null, 'max_days' => null );
		}

		$min = array_key_exists( 'min_days', $value ) && null !== $value['min_days'] ? max( 0, (int) $value['min_days'] ) : null;
		$max = array_key_exists( 'max_days', $value ) && null !== $value['max_days'] ? max( 0, (int) $value['max_days'] ) : null;
		if ( null !== $min && null !== $max && $min > $max ) {
			$max = $min;
		}

		return array( 'min_days' => $min, 'max_days' => $max );
	}

	public function save_delivery_days( int $service_id, mixed $min_days, mixed $max_days ): void {
		$min = '' === trim( (string) $min_days ) ? null : max( 0, (int) $min_days );
		$max = '' === trim( (string) $max_days ) ? null : max( 0, (int) $max_days );
		if ( null !== $min && null !== $max && $min > $max ) {
			$max = $min;
		}

		$this->settings->set_setting(
			$service_id,
			self::DELIVERY_DAYS_SETTING_KEY,
			array( 'min_days' => $min, 'max_days' => $max ),
			'json'
		);
	}

	/**
	 * @return array{type:string,label:string}
	 */
	public function delivery_type( int $service_id ): array {
		$value = $this->settings->get_setting( $service_id, self::DELIVERY_TYPE_SETTING_KEY, array() );
		if ( ! is_array( $value ) ) {
			$value = array( 'type' => (string) $value );
		}

		$type = $this->normalize_delivery_type( (string) ( $value['type'] ?? self::DELIVERY_TYPE_COURIER ) );
		if ( '' === $type ) {
			$type = self::DELIVERY_TYPE_COURIER;
		}

		return array(
			'type' => $type,
			'label' => sanitize_text_field( (string) ( $value['label'] ?? '' ) ),
		);
	}

	public function save_delivery_type( int $service_id, string $type, string $label = '' ): void {
		$type = $this->normalize_delivery_type( $type );
		if ( '' === $type ) {
			throw new \InvalidArgumentException( 'manual_delivery_type_invalid' );
		}
		$label = sanitize_text_field( $label );
		if ( self::DELIVERY_TYPE_CUSTOM === $type && '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'manual_delivery_type_label_required' );
		}

		$this->settings->set_setting(
			$service_id,
			self::DELIVERY_TYPE_SETTING_KEY,
			array(
				'type' => $type,
				'label' => $label,
			),
			'json'
		);
	}

	/**
	 * @return array{pricing_mode:string,flat_price_kopecks:int,price_per_kg_kopecks:int,minimum_price_kopecks:?int,billing_weight_step_g:int}
	 */
	private function invalid_pricing(): array {
		return array( 'pricing_mode' => '', 'flat_price_kopecks' => -1, 'price_per_kg_kopecks' => -1, 'minimum_price_kopecks' => null, 'billing_weight_step_g' => self::BILLING_STEP_NONE_G );
	}

	public function normalize_pricing_mode( string $mode ): string {
		return in_array( $mode, array( self::PRICING_MODE_FLAT, self::PRICING_MODE_PER_KG, self::PRICING_MODE_WEIGHT_RANGES ), true ) ? $mode : '';
	}

	public function normalize_billing_weight_step( int $step_g, string $mode = '' ): int {
		if ( 0 === $step_g ) {
			return $this->default_billing_weight_step( $mode );
		}

		return in_array( $step_g, array( self::BILLING_STEP_NONE_G, self::BILLING_STEP_100_G, self::BILLING_STEP_500_G, self::BILLING_STEP_1_KG ), true ) ? $step_g : $this->default_billing_weight_step( $mode );
	}

	public function default_billing_weight_step( string $mode ): int {
		return self::PRICING_MODE_PER_KG === $mode ? self::BILLING_STEP_1_KG : self::BILLING_STEP_NONE_G;
	}

	public function normalize_delivery_type( string $type ): string {
		return in_array( $type, array( self::DELIVERY_TYPE_COURIER, self::DELIVERY_TYPE_PICKUP, self::DELIVERY_TYPE_CUSTOM ), true ) ? $type : '';
	}

	/** @param array<string,mixed> $values */
	private function billing_weight_step_from_values( array $values, string $mode ): int {
		if ( self::PRICING_MODE_FLAT === $mode ) {
			return $this->default_billing_weight_step( $mode );
		}

		$raw = $values['billing_weight_step_g'] ?? 0;
		if ( '' === trim( (string) $raw ) || 0 === (int) $raw ) {
			return $this->default_billing_weight_step( $mode );
		}

		$step = (int) $raw;
		if ( ! in_array( $step, array( self::BILLING_STEP_NONE_G, self::BILLING_STEP_100_G, self::BILLING_STEP_500_G, self::BILLING_STEP_1_KG ), true ) ) {
			throw new \InvalidArgumentException( 'manual_billing_weight_step_invalid' );
		}

		return $step;
	}

	private function required_kopecks( mixed $value, string $error_code ): int {
		$kopecks = MoneyParser::numeric_to_kopecks( (string) $value );
		if ( null === $kopecks || $kopecks < 0 || '' === trim( (string) $value ) ) {
			throw new \InvalidArgumentException( $error_code );
		}

		return $kopecks;
	}

	private function optional_kopecks( mixed $value, string $error_code ): ?int {
		if ( '' === trim( (string) $value ) ) {
			return null;
		}
		$kopecks = MoneyParser::numeric_to_kopecks( (string) $value );
		if ( null === $kopecks || $kopecks < 0 ) {
			throw new \InvalidArgumentException( $error_code );
		}

		return $kopecks;
	}
}
