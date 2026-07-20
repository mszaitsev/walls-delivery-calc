<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;

defined( 'ABSPATH' ) || exit;

final class DeliveryCalculationDataBuilder {
	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function build( array $rate, array $context = array() ): array {
		$rate_meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$api_base = $this->base_price( $rate, $rate_meta );
		$final = $this->nullable_float( $rate_meta['final_price_rub'] ?? null )
			?? $this->nullable_float( $rate['final_price_rub'] ?? null )
			?? $this->nullable_float( $rate['cost'] ?? null )
			?? 0.0;
		$api = $this->calculation_api_data( $rate, $rate_meta, $api_base );
		$result = $this->calculation_result_data( $rate, $rate_meta, $final );

		return array_filter(
			array(
				'carrier_key' => (string) ( $rate['carrier_key'] ?? '' ),
				'service_key' => (string) ( $rate['service_key'] ?? '' ),
				'service_title' => (string) ( $rate['service_title'] ?? $rate['label'] ?? '' ),
				'rate_id' => (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ),
				'selected_tariff_object' => (string) ( $rate['selected_tariff_object'] ?? $rate['tariff_key'] ?? '' ),
				'selected_tariff_title' => (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ),
				'delivery_type' => (string) ( $rate['delivery_type'] ?? '' ),
				'destination' => is_array( $context['destination'] ?? null ) ? $context['destination'] : array(),
				'pickup' => is_array( $context['pickup'] ?? null ) ? $context['pickup'] : array(),
				'package' => $this->calculation_package_data( $rate_meta ),
				'api' => $api,
				'rules' => $this->calculation_rules_data( $rate, $rate_meta, $api, $result, $api_base, $final ),
				'result' => $result,
			),
			static fn( mixed $value ): bool => array() !== $value && '' !== $value
		);
	}

	/**
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_package_data( array $rate_meta ): array {
		$package = is_array( $rate_meta['package'] ?? null ) ? $rate_meta['package'] : array();
		$final_weight = (int) ( $rate_meta['package_weight_with_packaging_g'] ?? $package['total_weight_g'] ?? $package['final_weight_g'] ?? $package['weight_g'] ?? $rate_meta['final_weight_g'] ?? $rate_meta['package_weight_g'] ?? 0 );

		return $this->drop_empty_values(
			array(
				'products_weight_g' => (int) ( $rate_meta['products_weight_g'] ?? $package['products_weight_g'] ?? $package['items_weight_g'] ?? $package['weight_g'] ?? $final_weight ),
				'packaging_weight_g' => (int) ( $rate_meta['packaging_weight_g'] ?? $package['packaging_weight_g'] ?? 0 ),
				'final_weight_g' => $final_weight,
				'include_packaging_weight' => ! empty( $rate_meta['include_packaging_weight'] ) || ! empty( $package['include_packaging_weight'] ),
				'packaging_weight_mode' => (string) ( $rate_meta['packaging_weight_mode'] ?? $package['packaging_weight_mode'] ?? '' ),
				'dimensions_cm' => is_array( $package['dimensions_cm'] ?? null ) ? $package['dimensions_cm'] : array(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_api_data( array $rate, array $rate_meta, float $api_base ): array {
		$api = is_array( $rate_meta['api'] ?? null ) ? $rate_meta['api'] : array();
		$api_result = is_array( $rate_meta['api_result'] ?? null ) ? $rate_meta['api_result'] : array();
		$country = is_array( $rate_meta['country_mapping'] ?? null ) ? $rate_meta['country_mapping'] : array();
		$location = is_array( $rate_meta['location'] ?? null ) ? $rate_meta['location'] : array();
		$min_days = $rate_meta['delivery_min_days'] ?? $api['delivery_min_days'] ?? $api['api_delivery_min_days'] ?? $api['delivery_days'] ?? null;
		$max_days = $rate_meta['delivery_max_days'] ?? $api['delivery_max_days'] ?? $api['api_delivery_max_days'] ?? $api['delivery_days'] ?? null;

		$data = array(
			'api_base_price_rub' => $api_base,
			'api_price_has_vat' => array_key_exists( 'api_price_has_vat', $rate_meta ) ? (bool) $rate_meta['api_price_has_vat'] : ( $api['api_price_has_vat'] ?? null ),
			'api_price_with_vat_rub' => $api['api_price_with_vat_rub'] ?? $rate_meta['api_price_with_vat_rub'] ?? $api_result['paynds'] ?? null,
			'pay' => $api['pay'] ?? $rate_meta['pay'] ?? $api_result['pay'] ?? null,
			'nds' => $api['nds'] ?? $rate_meta['nds'] ?? $api_result['nds'] ?? null,
			'paynds' => $api['paynds'] ?? $rate_meta['paynds'] ?? $api_result['paynds'] ?? null,
			'delivery_min_days' => is_numeric( $min_days ) ? (int) $min_days : null,
			'delivery_max_days' => is_numeric( $max_days ) ? (int) $max_days : null,
			'api_delivery_min_days' => is_numeric( $min_days ) ? (int) $min_days : null,
			'api_delivery_max_days' => is_numeric( $max_days ) ? (int) $max_days : null,
			'api_delivery_text' => (string) ( $api['api_delivery_text'] ?? $api['delivery_text'] ?? DeliveryDaysFormatter::format_values( $min_days, $max_days ) ),
			'api_delivery_days_text' => (string) ( $rate_meta['api_delivery_days_text'] ?? $api['api_delivery_days_text'] ?? '' ),
			'request_payload_sanitized' => is_array( $rate_meta['request_payload_sanitized'] ?? null ) ? $rate_meta['request_payload_sanitized'] : ( is_array( $api['request_payload_sanitized'] ?? null ) ? $api['request_payload_sanitized'] : array() ),
			'response_tariff_sanitized' => is_array( $rate_meta['response_tariff_sanitized'] ?? null ) ? $rate_meta['response_tariff_sanitized'] : ( is_array( $api['response_tariff_sanitized'] ?? null ) ? $api['response_tariff_sanitized'] : array() ),
			'cdek_from_city_code' => $location['cdek_from_city_code'] ?? null,
			'cdek_to_city_code' => $location['cdek_to_city_code'] ?? $this->cdek_city_code_from_rate_meta( $rate_meta ) ?: null,
			'cdek_location_source' => (string) ( $location['cdek_location_source'] ?? '' ),
			'transtype' => array_key_exists( 'transtype', $rate_meta ) ? (int) $rate_meta['transtype'] : null,
			'delivery_to' => (string) ( $rate_meta['delivery_to'] ?? $api['delivery_to'] ?? '' ),
			'items_summary' => is_array( $rate_meta['items_summary'] ?? null ) ? $rate_meta['items_summary'] : ( is_array( $api['items_summary'] ?? null ) ? $api['items_summary'] : array() ),
			'vat_rate' => $this->nullable_float( $rate_meta['vat_rate'] ?? null ),
			'request_params' => is_array( $api['request_params'] ?? null ) ? $this->sanitize_request_params( $api['request_params'] ) : ( is_array( $rate_meta['request_params'] ?? null ) ? $this->sanitize_request_params( $rate_meta['request_params'] ) : array() ),
			'cache_hit' => $api['cache_hit'] ?? $rate_meta['cache_hit'] ?? null,
			'http_code' => $api['http_code'] ?? $rate_meta['http_code'] ?? $api_result['http_code'] ?? null,
			'carrier_country_id' => (string) ( $country['carrier_country_id'] ?? '' ),
			'country_name' => (string) ( $country['country_name'] ?? '' ),
		);
		if ( DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? $rate_meta['carrier_key'] ?? '' ) ) {
			foreach ( array( 'dpd_service_code', 'dpd_sender_city_id', 'dpd_receiver_city_id', 'dpd_pickup_terminal_code', 'dpd_delivery_terminal_code', 'dpd_delivery_terminal_source', 'dpd_tariff_method' ) as $key ) {
				if ( array_key_exists( $key, $rate_meta ) ) {
					$data[ $key ] = $rate_meta[ $key ];
				}
			}
		}

		return $this->drop_empty_values( $data );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @param array<string,mixed> $api
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function calculation_rules_data( array $rate, array $rate_meta, array $api, array $result, float $api_base, float $final ): array {
		$source = (string) ( $rate_meta['rules']['rules_source'] ?? $rate_meta['rules']['source'] ?? $rate['rules_source'] ?? $rate_meta['rules_source'] ?? 'none' );
		$is_fallback = ! empty( $result['fallback'] ) || ! empty( $rate_meta['terminal_fallback'] );
		if ( $is_fallback ) {
			return array(
				'rules_source' => 'skipped_fallback' === $source ? $source : 'none',
				'applied_rules' => array(),
				'formula_visualization' => array(),
			);
		}

		$rules = is_array( $rate_meta['rules'] ?? null ) ? $rate_meta['rules'] : array();
		$audit = is_array( $rules['applied_rules'] ?? null ) ? array_values( $rules['applied_rules'] ) : ( is_array( $rate_meta['rules_audit'] ?? null ) ? array_values( $rate_meta['rules_audit'] ) : ( is_array( $rate['rules_audit'] ?? null ) ? array_values( $rate['rules_audit'] ) : ( is_array( $rate_meta['applied_rules'] ?? null ) ? array_values( $rate_meta['applied_rules'] ) : array() ) ) );
		$round = ! empty( $rules['round_up_applied'] ) || ! empty( $rate['round_up_applied'] ) || ! empty( $rate_meta['round_up_applied'] ) || ! empty( $result['round_up_applied'] );
		$minimum = ! empty( $rules['minimum_price_applied'] ) || ! empty( $rate['minimum_price_applied'] ) || ! empty( $rate_meta['minimum_price_applied'] ) || ! empty( $result['minimum_price_applied'] );
		$formula = is_array( $rules['formula_visualization'] ?? null ) ? $rules['formula_visualization'] : ( is_array( $rate_meta['formula_visualization'] ?? null ) ? $rate_meta['formula_visualization'] : array() );
		if ( array() === $formula && ( array() !== $audit || $round || $minimum ) ) {
			$formula = ( new RuleFormulaFormatter() )->lines(
				$this->nullable_float( $api['api_base_price_rub'] ?? null ) ?? $api_base,
				$audit,
				$final,
				array(
					'round_up_applied' => $round && $final > 0,
					'minimum_price_applied' => $minimum && $final > 0,
				)
			);
		}
		$formula = $this->append_unique_lines( $formula, $this->lead_time_audit_lines( $rate, $rate_meta ) );

		return array(
			'rules_source' => $source,
			'applied_rules' => $audit,
			'formula_visualization' => $formula,
			'round_up_applied' => $round,
			'minimum_price_applied' => $minimum,
			'price_delta_rub' => $final - $api_base,
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_result_data( array $rate, array $rate_meta, float $final ): array {
		$result = is_array( $rate_meta['result'] ?? null ) ? $rate_meta['result'] : array();
		$is_fallback = ! empty( $result['fallback'] ) || ! empty( $rate_meta['fallback'] ) || ! empty( $rate['fallback_used'] );
		$delivery_days = is_array( $rate['delivery_days'] ?? null ) ? $rate['delivery_days'] : array();
		$min_days = $delivery_days['min_days'] ?? $delivery_days['min'] ?? $rate_meta['delivery_min_days'] ?? null;
		$max_days = $delivery_days['max_days'] ?? $delivery_days['max'] ?? $rate_meta['delivery_max_days'] ?? null;

		return $this->drop_null_values(
			array(
				'final_price_rub' => $final,
				'final_delivery_days_min' => is_numeric( $min_days ) && (int) $min_days > 0 ? (int) $min_days : null,
				'final_delivery_min_days' => is_numeric( $min_days ) && (int) $min_days > 0 ? (int) $min_days : null,
				'final_delivery_days_max' => is_numeric( $max_days ) && (int) $max_days > 0 ? (int) $max_days : null,
				'final_delivery_max_days' => is_numeric( $max_days ) && (int) $max_days > 0 ? (int) $max_days : null,
				'final_delivery_text' => (string) ( $result['final_delivery_text'] ?? DeliveryDaysFormatter::format_values( $min_days, $max_days ) ?: ( $rate['delivery_comment'] ?? '' ) ),
				'planned_delivery_date' => (string) ( $rate['planned_delivery_date'] ?? $result['planned_delivery_date'] ?? '' ),
				'planned_delivery_comment' => (string) ( $rate['planned_delivery_comment'] ?? $result['planned_delivery_comment'] ?? '' ),
				'round_up_applied' => ! empty( $rate['round_up_applied'] ) || ! empty( $rate_meta['round_up_applied'] ),
				'minimum_price_applied' => ! empty( $rate['minimum_price_applied'] ) || ! empty( $rate_meta['minimum_price_applied'] ),
				'crossed_price_rub' => $result['crossed_price_rub'] ?? $rate['crossed_price'] ?? null,
				'old_price_rub' => $result['old_price_rub'] ?? $rate['old_price'] ?? null,
				'fallback' => $is_fallback,
				'fallback_reason' => (string) ( $result['fallback_reason'] ?? $rate_meta['fallback_reason'] ?? $rate['fallback_reason'] ?? '' ),
				'fallback_text' => (string) ( $result['fallback_text'] ?? $rate_meta['fallback_text'] ?? $rate['fallback_text'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<int,mixed> $lines
	 * @param array<int,string> $extra
	 * @return array<int,string>
	 */
	private function append_unique_lines( array $lines, array $extra ): array {
		$output = array_values(
			array_filter(
				array_map( static fn( mixed $line ): string => (string) $line, $lines ),
				static fn( string $line ): bool => '' !== trim( $line )
			)
		);
		foreach ( $extra as $line ) {
			if ( ! in_array( $line, $output, true ) ) {
				$output[] = $line;
			}
		}

		return $output;
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<int,string>
	 */
	private function lead_time_audit_lines( array $rate, array $rate_meta ): array {
		$lines = array();
		$raw = $this->delivery_days_from_value( $rate_meta['carrier_delivery_days_original'] ?? $rate['original_delivery_days'] ?? null );
		if ( '' !== $raw ) {
			$lines[] = 'Базовый срок API: ' . $raw;
		}

		$processing_working_days = $this->nullable_int( $rate_meta['shop_processing_working_days'] ?? null ) ?? 0;
		$processing_calendar_days = $this->nullable_int( $rate_meta['shop_processing_calendar_days'] ?? null ) ?? 0;
		if ( $processing_working_days > 0 && $processing_calendar_days > 0 ) {
			$lines[] = 'Время обработки магазином: ' . DeliveryDaysFormatter::format_values( $processing_calendar_days, $processing_calendar_days );
		}

		if ( ! empty( $rate_meta['carrier_days_are_working'] ) ) {
			$raw_range = $this->delivery_days_range_value( $rate_meta['carrier_delivery_days_original'] ?? $rate['original_delivery_days'] ?? null );
			$carrier_calendar = $this->delivery_days_from_value( $rate_meta['carrier_delivery_calendar_days'] ?? null );
			if ( '' === $carrier_calendar ) {
				$carrier_calendar = DeliveryDaysFormatter::format_values( $rate_meta['carrier_delivery_calendar_min_days'] ?? null, $rate_meta['carrier_delivery_calendar_max_days'] ?? null );
			}
			if ( '' !== $raw_range && '' !== $carrier_calendar && $raw !== $carrier_calendar ) {
				$lines[] = 'Доставка: рабочие в календарные ' . $raw_range . ' → ' . $carrier_calendar;
			}
		}

		$final = $this->delivery_days_from_value( $rate['delivery_days'] ?? null );
		if ( '' !== $final ) {
			$lines[] = 'Итог: ' . $final;
		}

		return $lines;
	}

	private function delivery_days_from_value( mixed $value ): string {
		return is_array( $value ) ? DeliveryDaysFormatter::format_array( $value ) : '';
	}

	private function delivery_days_range_value( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		$min = $value['min_days'] ?? $value['min'] ?? null;
		$max = $value['max_days'] ?? $value['max'] ?? null;
		if ( ! is_numeric( $min ) && ! is_numeric( $max ) ) {
			return '';
		}
		$min = is_numeric( $min ) ? max( 0, (int) $min ) : max( 0, (int) $max );
		$max = is_numeric( $max ) ? max( 0, (int) $max ) : $min;

		return $min === $max ? (string) $min : $min . '-' . $max;
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 */
	private function base_price( array $rate, array $rate_meta ): float {
		$api = is_array( $rate_meta['api'] ?? null ) ? $rate_meta['api'] : array();
		foreach ( array(
			$this->nullable_float( $rate['api_base_price_rub'] ?? null ),
			$this->nullable_float( $rate_meta['api_base_price_rub'] ?? null ),
			$this->kopecks_value( $rate_meta['pricing_total_kopecks'] ?? null ),
			$this->kopecks_value( $rate['pricing_total_kopecks'] ?? null ),
			$this->money_value( $rate['original_cost'] ?? null ),
			$this->money_value( $rate_meta['original_cost'] ?? null ),
			$this->nullable_float( $api['api_base_price_rub'] ?? null ),
			$this->nullable_float( $rate_meta['api_price_with_vat_rub'] ?? null ),
			$this->nullable_float( $api['api_price_with_vat_rub'] ?? null ),
			$this->nullable_float( $rate['cost'] ?? null ),
		) as $value ) {
			if ( null !== $value ) {
				return $value;
			}
		}

		return 0.0;
	}

	/**
	 * @param array<string,mixed> $rate_meta
	 */
	private function cdek_city_code_from_rate_meta( array $rate_meta ): int {
		$location = is_array( $rate_meta['location'] ?? null ) ? $rate_meta['location'] : array();
		$api = is_array( $rate_meta['api'] ?? null ) ? $rate_meta['api'] : array();
		$payload = is_array( $rate_meta['request_payload_sanitized'] ?? null ) ? $rate_meta['request_payload_sanitized'] : ( is_array( $api['request_payload_sanitized'] ?? null ) ? $api['request_payload_sanitized'] : array() );
		foreach ( array( $api['cdek_to_city_code'] ?? null, $location['cdek_to_city_code'] ?? null, $payload['to_location']['code'] ?? null ) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function kopecks_value( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value / 100 : null;
	}

	private function money_value( mixed $value ): ?float {
		if ( is_array( $value ) ) {
			if ( is_numeric( $value['amount_kopecks'] ?? null ) ) {
				return (float) $value['amount_kopecks'] / 100;
			}
			if ( is_numeric( $value['amount'] ?? null ) ) {
				return (float) $value['amount'] / 100;
			}
			if ( is_numeric( $value['rubles'] ?? null ) ) {
				return (float) $value['rubles'];
			}
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,scalar>
	 */
	private function sanitize_request_params( array $params ): array {
		$sanitized = array();
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$sanitized[ (string) $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function drop_empty_values( array $data ): array {
		return array_filter(
			$data,
			static fn( mixed $value ): bool => null !== $value && array() !== $value && '' !== $value && 0 !== $value
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function drop_null_values( array $data ): array {
		return array_filter(
			$data,
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	private function nullable_float( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function nullable_int( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}
}
