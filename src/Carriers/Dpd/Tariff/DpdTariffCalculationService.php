<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class DpdTariffCalculationService {
	public function __construct(
		private DpdApiClient $api,
		private DpdCityResolver $city_resolver,
		private LocationRepository $locations,
		private DpdSettings $settings,
		private DpdTariffRequestBuilder $builder,
		private DpdTariffOptionNormalizer $normalizer,
		private ?DpdPickupPointService $pickup_points = null,
		private ?DpdTerminalCodeTariffRequestBuilder $terminal_builder = null
	) {
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function calculate( int $receiver_location_id, array $params = array() ): DpdTariffResult {
		if ( ! $this->settings->credentials_are_complete() ) {
			return DpdTariffResult::failure( array( 'DPD credentials are incomplete for current environment.' ) );
		}

		$sender_city_id = $this->sender_city_id( $params );
		if ( '' === $sender_city_id ) {
			return DpdTariffResult::failure( array( 'DPD sender cityId is not configured.' ) );
		}

		$receiver_city_id = $this->digits( (string) ( $params['receiver_dpd_city_id'] ?? $params['dpd_receiver_city_id'] ?? '' ) );
		$receiver = $this->locations->find_by_id( $receiver_location_id );
		if ( null === $receiver && '' === $receiver_city_id ) {
			return DpdTariffResult::failure( array( 'DPD cityId was not found for receiver location_id. Run DPD geography import or manual mapping.' ), array( 'receiver_location_id' => $receiver_location_id ) );
		}

		if ( '' === $receiver_city_id ) {
			$receiver_resolution = $this->city_resolver->resolve( $receiver );
			$receiver_city_id = is_array( $receiver_resolution ) ? $this->digits( (string) ( $receiver_resolution['city_id'] ?? '' ) ) : '';
		}
		if ( '' === $receiver_city_id ) {
			return DpdTariffResult::failure( array( 'DPD cityId was not found for receiver location_id. Run DPD geography import or manual mapping.' ), array( 'receiver_location_id' => $receiver_location_id ) );
		}

		if ( ! $this->pickup_points instanceof DpdPickupPointService ) {
			return DpdTariffResult::failure(
				array( 'DPD pickup point service is unavailable. Import DPD pickup points before terminalCode pricing.' ),
				array(
					'sender_city_id' => $sender_city_id,
					'receiver_city_id' => $receiver_city_id,
					'receiver_location_id' => $receiver_location_id,
					'method' => 'getServiceCostByParcels3',
				)
			);
		}

		$pickup_terminal = $this->pickup_points->find_runtime_parcel_shop_for_city_id( (int) $sender_city_id );
		$pickup_terminal_code = trim( (string) ( $pickup_terminal['selected_terminal_code'] ?? '' ) );
		if ( '' === $pickup_terminal_code ) {
			return DpdTariffResult::failure(
				array_merge(
					array( 'DPD pickup terminalCode was not found for sender cityId. Import DPD pickup points or configure sender DPD city mapping.' ),
					is_array( $pickup_terminal['warnings'] ?? null ) ? $pickup_terminal['warnings'] : array()
				),
				array(
					'sender_city_id' => $sender_city_id,
					'receiver_city_id' => $receiver_city_id,
					'receiver_location_id' => $receiver_location_id,
					'method' => 'getServiceCostByParcels3',
					'pickup_terminal_selection' => $pickup_terminal,
				)
			);
		}

		$self_delivery = ! empty( $params['self_delivery'] );
		$delivery_terminal = array();
		$delivery_terminal_code = '';
		$delivery_terminal_source = '';
		if ( $self_delivery ) {
			$selected_delivery_terminal_code = trim( (string) ( $params['delivery_terminal_code'] ?? '' ) );
			if ( '' !== $selected_delivery_terminal_code ) {
				$selected_point = $this->pickup_points->find_runtime_parcel_shop_by_terminal_code( $selected_delivery_terminal_code, (int) $receiver_city_id );
				if ( null === $selected_point ) {
					return DpdTariffResult::failure(
						array( 'Selected DPD delivery terminalCode is not an active parcel_shop in receiver cityId.' ),
						array(
							'sender_city_id' => $sender_city_id,
							'receiver_city_id' => $receiver_city_id,
							'receiver_location_id' => $receiver_location_id,
							'method' => 'getServiceCostByParcels3',
							'pickup_terminal_selection' => $pickup_terminal,
							'delivery_terminal_code' => $selected_delivery_terminal_code,
						)
					);
				}
				$delivery_terminal_code = $selected_delivery_terminal_code;
				$delivery_terminal_source = 'selected';
				$delivery_terminal = array(
					'point' => $selected_point,
					'selected_terminal_code' => $delivery_terminal_code,
					'selected_type' => (string) ( $selected_point['type'] ?? '' ),
					'selected_name' => (string) ( $selected_point['name'] ?? '' ),
					'selected_address' => (string) ( $selected_point['address'] ?? '' ),
					'fallback_duplicate_was_used' => false,
					'ambiguous' => false,
					'warnings' => array(),
				);
			} else {
				$delivery_terminal = $this->pickup_points->find_runtime_parcel_shop_for_city_id( (int) $receiver_city_id );
				$delivery_terminal_code = trim( (string) ( $delivery_terminal['selected_terminal_code'] ?? '' ) );
				$delivery_terminal_source = 'auto';
				if ( '' === $delivery_terminal_code ) {
					return DpdTariffResult::failure(
						array_merge(
							array( 'DPD pickup tariff unavailable: no active parcel_shop for receiver cityId ' . $receiver_city_id ),
							is_array( $delivery_terminal['warnings'] ?? null ) ? $delivery_terminal['warnings'] : array()
						),
						array(
							'sender_city_id' => $sender_city_id,
							'receiver_city_id' => $receiver_city_id,
							'receiver_location_id' => $receiver_location_id,
							'method' => 'getServiceCostByParcels3',
							'pickup_terminal_selection' => $pickup_terminal,
							'delivery_terminal_selection' => $delivery_terminal,
							'delivery_terminal_code' => '',
							'delivery_terminal_source' => 'auto',
						)
					);
				}
			}
		}

		$terminal_builder = $this->terminal_builder ?? new DpdTerminalCodeTariffRequestBuilder();
		$request = new DpdTerminalCodeTariffRequest(
			$sender_city_id,
			$receiver_city_id,
			$this->parcels( $params ),
			$this->non_negative_float( $params['declared_value_rub'] ?? $this->settings->tariff_default_declared_value_rub(), $this->settings->tariff_default_declared_value_rub() ),
			! empty( $params['self_pickup'] ),
			$self_delivery,
			$pickup_terminal_code,
			$self_delivery ? $delivery_terminal_code : '',
			(string) ( $params['service_code'] ?? '' ),
			(string) ( $params['pickup_date'] ?? '' )
		);
		$payload = $terminal_builder->build( $request );

		try {
			$response = $this->api->getServiceCostByParcels3( $payload );
			$options = $this->normalizer->normalize( $response );

			return new DpdTariffResult(
				true,
				array(),
				$options,
				$payload,
				$this->normalizer->raw_body( $response ),
				array(
					'method' => 'getServiceCostByParcels3',
					'sender_city_id' => $sender_city_id,
					'receiver_city_id' => $receiver_city_id,
					'receiver_location_id' => $receiver_location_id,
					'pickup_terminal_code' => $pickup_terminal_code,
					'pickup_terminal_selection' => $pickup_terminal,
					'delivery_terminal_code' => $self_delivery ? $delivery_terminal_code : '',
					'delivery_terminal_source' => $delivery_terminal_source,
					'delivery_terminal_selection' => $delivery_terminal,
					'raw_count' => count( $options ),
					'wrapper' => (string) ( $response->meta['wrapper'] ?? '' ),
					'debug_payload_shape' => is_array( $response->meta['debug_payload_shape'] ?? null ) ? $response->meta['debug_payload_shape'] : array(),
				)
			);
		} catch ( DpdException $exception ) {
			return new DpdTariffResult( false, array( $exception->getMessage() ), array(), $payload, null, array( 'receiver_location_id' => $receiver_location_id, 'receiver_city_id' => $receiver_city_id, 'method' => 'getServiceCostByParcels3', 'delivery_terminal_code' => $self_delivery ? $delivery_terminal_code : '', 'delivery_terminal_source' => $delivery_terminal_source, 'delivery_terminal_selection' => $delivery_terminal ) );
		} catch ( \Throwable $exception ) {
			return new DpdTariffResult( false, array( 'DPD tariff calculation failed: ' . $exception->getMessage() ), array(), $payload, null, array( 'receiver_location_id' => $receiver_location_id, 'receiver_city_id' => $receiver_city_id, 'method' => 'getServiceCostByParcels3', 'delivery_terminal_code' => $self_delivery ? $delivery_terminal_code : '', 'delivery_terminal_source' => $delivery_terminal_source, 'delivery_terminal_selection' => $delivery_terminal ) );
		}
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function sender_city_id( array $params ): string {
		$override = $this->digits( (string) ( $params['sender_dpd_city_id'] ?? $this->settings->tariff_sender_dpd_city_id() ) );
		if ( '' !== $override ) {
			return $override;
		}

		$sender_location_id = $this->positive_int( $params['sender_location_id'] ?? $this->settings->tariff_sender_location_id(), $this->settings->tariff_sender_location_id() );
		if ( $sender_location_id <= 0 ) {
			return '';
		}
		$sender = $this->locations->find_by_id( $sender_location_id );
		if ( null === $sender ) {
			return '';
		}
		$resolution = $this->city_resolver->resolve( $sender );

		return is_array( $resolution ) ? $this->digits( (string) ( $resolution['city_id'] ?? '' ) ) : '';
	}

	private function digits( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
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
		$quantity = $this->positive_int( $parcel['quantity'] ?? 1, 1 );
		if ( $weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}

		return new DpdTariffParcel( $weight, $length, $width, $height, max( 1, $quantity ) );
	}

	private function positive_int( mixed $value, int $default ): int {
		$value = is_numeric( $value ) ? (int) $value : $default;

		return max( 0, $value );
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
