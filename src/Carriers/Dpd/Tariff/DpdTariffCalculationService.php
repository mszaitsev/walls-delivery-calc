<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class DpdTariffCalculationService {
	public function __construct(
		private DpdApiClient $api,
		private DpdCityResolver $city_resolver,
		private LocationRepository $locations,
		private DpdSettings $settings,
		private DpdTariffRequestBuilder $builder,
		private DpdTariffOptionNormalizer $normalizer
	) {
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function calculate( int $receiver_location_id, array $params = array() ): DpdTariffResult {
		$sender_city_id = $this->sender_city_id( $params );
		if ( '' === $sender_city_id ) {
			return DpdTariffResult::failure( array( 'DPD sender cityId is not configured.' ) );
		}

		$receiver = $this->locations->find_by_id( $receiver_location_id );
		if ( null === $receiver ) {
			return DpdTariffResult::failure( array( 'DPD cityId was not found for receiver location_id. Run DPD geography import or manual mapping.' ), array( 'receiver_location_id' => $receiver_location_id ) );
		}

		$receiver_resolution = $this->city_resolver->resolve( $receiver );
		$receiver_city_id = is_array( $receiver_resolution ) ? (string) ( $receiver_resolution['city_id'] ?? '' ) : '';
		if ( '' === $receiver_city_id ) {
			return DpdTariffResult::failure( array( 'DPD cityId was not found for receiver location_id. Run DPD geography import or manual mapping.' ), array( 'receiver_location_id' => $receiver_location_id ) );
		}

		$request = new DpdTariffRequest(
			$sender_city_id,
			$receiver_city_id,
			array(
				new DpdTariffParcel(
					$this->positive_int( $params['weight_g'] ?? $this->settings->tariff_default_weight_g(), $this->settings->tariff_default_weight_g() ),
					$this->positive_float( $params['length_cm'] ?? $this->settings->tariff_default_length_cm(), $this->settings->tariff_default_length_cm() ),
					$this->positive_float( $params['width_cm'] ?? $this->settings->tariff_default_width_cm(), $this->settings->tariff_default_width_cm() ),
					$this->positive_float( $params['height_cm'] ?? $this->settings->tariff_default_height_cm(), $this->settings->tariff_default_height_cm() )
				),
			),
			$this->non_negative_float( $params['declared_value_rub'] ?? $this->settings->tariff_default_declared_value_rub(), $this->settings->tariff_default_declared_value_rub() ),
			! empty( $params['self_pickup'] ),
			! empty( $params['self_delivery'] ),
			(string) ( $params['service_code'] ?? '' ),
			(string) ( $params['pickup_date'] ?? '' )
		);
		$payload = $this->builder->build( $request );

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
					'sender_city_id' => $sender_city_id,
					'receiver_city_id' => $receiver_city_id,
					'receiver_location_id' => $receiver_location_id,
					'raw_count' => count( $options ),
				)
			);
		} catch ( DpdException $exception ) {
			return new DpdTariffResult( false, array( $exception->getMessage() ), array(), $payload, null, array( 'receiver_location_id' => $receiver_location_id ) );
		} catch ( \Throwable $exception ) {
			return new DpdTariffResult( false, array( 'DPD tariff calculation failed: ' . $exception->getMessage() ), array(), $payload, null, array( 'receiver_location_id' => $receiver_location_id ) );
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
