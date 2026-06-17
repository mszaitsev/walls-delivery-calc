<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class DpdTerminalCodeTariffDiagnosticService {
	public function __construct(
		private DpdApiClient $api,
		private DpdSettings $settings,
		private DpdTerminalCodeTariffDiagnosticRequestBuilder $builder,
		private DpdTariffOptionNormalizer $normalizer,
		private ?DpdCityResolver $city_resolver = null,
		private ?LocationRepository $locations = null
	) {
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function calculate( array $params ): DpdTerminalCodeTariffDiagnosticResult {
		$terminal_selection = is_array( $params['terminal_selection'] ?? null ) ? $params['terminal_selection'] : array();
		$warnings = is_array( $terminal_selection['warnings'] ?? null ) ? array_values( $terminal_selection['warnings'] ) : array();

		if ( ! $this->settings->credentials_are_complete() ) {
			return new DpdTerminalCodeTariffDiagnosticResult( false, array( 'DPD credentials are incomplete for current environment.' ), $warnings, terminal_selection: $terminal_selection );
		}

		$pickup_city_id = $this->pickup_city_id( $params );
		$delivery_city_id = $this->delivery_city_id( $params );
		$delivery_terminal_code = trim( (string) ( $params['delivery_terminal_code'] ?? '' ) );
		$errors = array();
		if ( '' === $pickup_city_id ) {
			$errors[] = 'DPD pickup cityId is required for terminalCode diagnostic.';
		}
		if ( '' === $delivery_city_id ) {
			$errors[] = 'DPD delivery cityId is required for terminalCode diagnostic.';
		}
		if ( '' === $delivery_terminal_code ) {
			$errors[] = 'DPD delivery terminalCode is required for terminalCode diagnostic.';
		}
		if ( array() !== $errors ) {
			return new DpdTerminalCodeTariffDiagnosticResult( false, $errors, $warnings, terminal_selection: $terminal_selection );
		}

		$request = new DpdTerminalCodeTariffDiagnosticRequest(
			$pickup_city_id,
			$delivery_city_id,
			$delivery_terminal_code,
			$this->parcels( $params ),
			$this->non_negative_float( $params['declared_value_rub'] ?? $this->settings->tariff_default_declared_value_rub(), $this->settings->tariff_default_declared_value_rub() ),
			! empty( $params['self_pickup'] ),
			! empty( $params['self_delivery'] ),
			(string) ( $params['pickup_terminal_code'] ?? '' ),
			(string) ( $params['service_code'] ?? '' ),
			(string) ( $params['pickup_date'] ?? '' )
		);
		$parcels3_payload = $this->builder->build( $request );
		$parcels2_payload = $parcels3_payload;
		unset( $parcels2_payload['pickup']['terminalCode'], $parcels2_payload['delivery']['terminalCode'] );

		try {
			$parcels3_response = $this->api->getServiceCostByParcels3( $parcels3_payload );
			$parcels2_response = $this->api->getServiceCostByParcels2( $parcels2_payload );
			$parcels3_options = $this->normalizer->normalize( $parcels3_response );
			$parcels2_options = $this->normalizer->normalize( $parcels2_response );

			return new DpdTerminalCodeTariffDiagnosticResult(
				true,
				array(),
				$warnings,
				$parcels3_options,
				$parcels2_options,
				$this->comparison( $parcels2_options, $parcels3_options ),
				$parcels3_payload,
				$parcels2_payload,
				$this->normalizer->raw_body( $parcels3_response ),
				$this->normalizer->raw_body( $parcels2_response ),
				$terminal_selection,
				array(
					'method' => 'getServiceCostByParcels3',
					'comparison_method' => 'getServiceCostByParcels2',
					'parcels3_wrapper' => (string) ( $parcels3_response->meta['wrapper'] ?? '' ),
					'parcels2_wrapper' => (string) ( $parcels2_response->meta['wrapper'] ?? '' ),
					'parcels3_debug_payload_shape' => is_array( $parcels3_response->meta['debug_payload_shape'] ?? null ) ? $parcels3_response->meta['debug_payload_shape'] : array(),
					'parcels2_debug_payload_shape' => is_array( $parcels2_response->meta['debug_payload_shape'] ?? null ) ? $parcels2_response->meta['debug_payload_shape'] : array(),
				)
			);
		} catch ( DpdException $exception ) {
			return new DpdTerminalCodeTariffDiagnosticResult( false, array( $exception->getMessage() ), $warnings, parcels3_payload: $parcels3_payload, parcels2_payload: $parcels2_payload, terminal_selection: $terminal_selection );
		} catch ( \Throwable $exception ) {
			return new DpdTerminalCodeTariffDiagnosticResult( false, array( 'DPD terminalCode diagnostic failed: ' . $exception->getMessage() ), $warnings, parcels3_payload: $parcels3_payload, parcels2_payload: $parcels2_payload, terminal_selection: $terminal_selection );
		}
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function pickup_city_id( array $params ): string {
		$city_id = $this->digits( (string) ( $params['pickup_city_id'] ?? $params['sender_dpd_city_id'] ?? $this->settings->tariff_sender_dpd_city_id() ) );
		if ( '' !== $city_id ) {
			return $city_id;
		}

		return $this->city_id_from_location( $params['pickup_location_id'] ?? $params['sender_location_id'] ?? $this->settings->tariff_sender_location_id() );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function delivery_city_id( array $params ): string {
		$city_id = $this->digits( (string) ( $params['delivery_city_id'] ?? '' ) );
		if ( '' !== $city_id ) {
			return $city_id;
		}

		return $this->city_id_from_location( $params['delivery_location_id'] ?? 0 );
	}

	private function city_id_from_location( mixed $location_id ): string {
		if ( ! $this->city_resolver instanceof DpdCityResolver || ! $this->locations instanceof LocationRepository ) {
			return '';
		}
		$location_id = is_numeric( $location_id ) ? (int) $location_id : 0;
		if ( $location_id <= 0 ) {
			return '';
		}
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location ) {
			return '';
		}
		$resolution = $this->city_resolver->resolve( $location );

		return is_array( $resolution ) ? $this->digits( (string) ( $resolution['city_id'] ?? '' ) ) : '';
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<int,DpdTariffParcel>
	 */
	private function parcels( array $params ): array {
		$parcels = array();
		if ( is_array( $params['parcels'] ?? null ) ) {
			foreach ( $params['parcels'] as $parcel ) {
				$normalized = $this->parcel_from_param( $parcel );
				if ( $normalized instanceof DpdTariffParcel ) {
					$parcels[] = $normalized;
				}
			}
		}
		if ( array() !== $parcels ) {
			return $parcels;
		}

		return array(
			new DpdTariffParcel(
				$this->positive_int( $params['weight_g'] ?? $this->settings->tariff_default_weight_g(), $this->settings->tariff_default_weight_g() ),
				$this->positive_float( $params['length_cm'] ?? $this->settings->tariff_default_length_cm(), $this->settings->tariff_default_length_cm() ),
				$this->positive_float( $params['width_cm'] ?? $this->settings->tariff_default_width_cm(), $this->settings->tariff_default_width_cm() ),
				$this->positive_float( $params['height_cm'] ?? $this->settings->tariff_default_height_cm(), $this->settings->tariff_default_height_cm() )
			),
		);
	}

	private function parcel_from_param( mixed $parcel ): ?DpdTariffParcel {
		if ( $parcel instanceof DpdTariffParcel ) {
			return $parcel;
		}
		if ( ! is_array( $parcel ) ) {
			return null;
		}
		$raw_weight = $parcel['weight_g'] ?? $parcel['weight'] ?? 0;
		$raw_length = $parcel['length_cm'] ?? $parcel['length'] ?? 0;
		$raw_width = $parcel['width_cm'] ?? $parcel['width'] ?? 0;
		$raw_height = $parcel['height_cm'] ?? $parcel['height'] ?? 0;
		if ( ! is_numeric( $raw_weight ) || ! is_numeric( $raw_length ) || ! is_numeric( $raw_width ) || ! is_numeric( $raw_height ) ) {
			return null;
		}
		$weight = (int) $raw_weight;
		$length = (float) $raw_length;
		$width = (float) $raw_width;
		$height = (float) $raw_height;
		if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}

		return new DpdTariffParcel( $weight, $length, $width, $height, $this->positive_int( $parcel['quantity'] ?? 1, 1 ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $parcels2_options
	 * @param array<int,array<string,mixed>> $parcels3_options
	 * @return array<int,array<string,mixed>>
	 */
	private function comparison( array $parcels2_options, array $parcels3_options ): array {
		$parcels2_by_code = $this->options_by_code( $parcels2_options );
		$parcels3_by_code = $this->options_by_code( $parcels3_options );
		$codes = array_values( array_unique( array_merge( array_keys( $parcels2_by_code ), array_keys( $parcels3_by_code ) ) ) );
		sort( $codes );
		$rows = array();
		foreach ( $codes as $code ) {
			$option2 = $parcels2_by_code[ $code ] ?? array();
			$option3 = $parcels3_by_code[ $code ] ?? array();
			$cost2 = $option2['cost'] ?? null;
			$cost3 = $option3['cost'] ?? null;
			$rows[] = array(
				'service_code' => $code,
				'service_name' => (string) ( $option3['service_name'] ?? $option2['service_name'] ?? '' ),
				'parcels2_cost' => $cost2,
				'parcels3_cost' => $cost3,
				'delta' => is_numeric( $cost2 ) && is_numeric( $cost3 ) ? (float) $cost3 - (float) $cost2 : null,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $options
	 * @return array<string,array<string,mixed>>
	 */
	private function options_by_code( array $options ): array {
		$by_code = array();
		foreach ( $options as $index => $option ) {
			$code = (string) ( $option['service_code'] ?? '' );
			$by_code[ '' !== $code ? $code : '#' . (string) $index ] = $option;
		}

		return $by_code;
	}

	private function digits( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}

	private function positive_int( mixed $value, int $default ): int {
		$value = is_numeric( $value ) ? (int) $value : $default;

		return max( 1, $value );
	}

	private function positive_float( mixed $value, float $default ): float {
		$value = is_numeric( $value ) ? (float) $value : $default;

		return max( 0.1, $value );
	}

	private function non_negative_float( mixed $value, float $default ): float {
		$value = is_numeric( $value ) ? (float) $value : $default;

		return max( 0.0, $value );
	}
}
