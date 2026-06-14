<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;

defined( 'ABSPATH' ) || exit;

final class CdekRecipientAddressPreparationService {
	public const CITY_CODE_ERROR = "Не удалось определить код города СДЭК для адреса получателя.\nПроверьте адрес и повторите обработку.";

	public function __construct(
		private OrderDeliveryAddressNormalizationService $normalizer,
		private CdekApiClient $client
	) {
	}

	/**
	 * @param array<string,mixed> $location_context
	 * @return array<string,mixed>
	 */
	public function prepare( object $order, string $original_address, array $location_context, string $service_key = 'cdek' ): array {
		$result = $this->normalizer->normalize( $order, $location_context, $original_address );
		$payload = is_array( $result['payload'] ?? null ) ? $result['payload'] : array();
		if ( empty( $result['success'] ) || array() === $payload ) {
			return $this->failure( (string) ( $result['message'] ?? 'Адрес не удалось нормализовать.' ), $service_key, $original_address );
		}

		$city = $this->clean_city_name( (string) ( $payload['city'] ?? $location_context['city_value'] ?? $location_context['city_name'] ?? '' ) );
		$postal_code = preg_replace( '/\D+/', '', (string) ( $payload['postcode'] ?? $payload['postal_code'] ?? '' ) ) ?: '';
		$delivery_address = trim( (string) ( $payload['address_1'] ?? '' ) );
		if ( '' === $delivery_address ) {
			return $this->failure( 'DaData не вернула адрес доставки до двери.', $service_key, $original_address );
		}

		$lat = $this->coordinate( $payload, array( 'lat', 'geo_lat', 'latitude' ) );
		$lng = $this->coordinate( $payload, array( 'lng', 'geo_lon', 'longitude' ) );
		if ( null === $lat || null === $lng ) {
			$lat = $this->coordinate( $location_context, array( 'lat', 'latitude' ) );
			$lng = $this->coordinate( $location_context, array( 'lng', 'lon', 'longitude' ) );
		}

		$city_code = $this->city_code( $lat, $lng, $city, $postal_code );
		if ( $city_code <= 0 ) {
			return $this->failure( self::CITY_CODE_ERROR, $service_key, $original_address, $payload );
		}

		$display = trim( implode( ', ', array_filter( array( $postal_code, $city, $delivery_address ), static fn( string $part ): bool => '' !== trim( $part ) ) ) );

		return array(
			'success' => true,
			'source' => 'dadata+cdek_location',
			'service_key' => $service_key,
			'original_hash' => $this->original_address_hash( $original_address ),
			'display' => $display,
			'message' => 'Данные для СДЭК корректны',
			'fields' => array(
				'cdek_city_code' => $city_code,
				'cdek_city_name' => $city,
				'cdek_postal_code' => $postal_code,
				'cdek_delivery_address' => $delivery_address,
				'cdek_lat' => null !== $lat ? (string) $lat : '',
				'cdek_lon' => null !== $lng ? (string) $lng : '',
				'postal_code' => $postal_code,
				'region' => (string) ( $payload['region'] ?? '' ),
				'area' => (string) ( $payload['area'] ?? '' ),
				'city' => $city,
				'settlement' => (string) ( $payload['settlement'] ?? '' ),
				'street' => (string) ( $payload['street'] ?? '' ),
				'house' => (string) ( $payload['house'] ?? '' ),
				'block' => (string) ( $payload['block'] ?? '' ),
				'flat' => (string) ( $payload['flat'] ?? '' ),
				'fias_id' => (string) ( $payload['fias_id'] ?? '' ),
				'kladr_id' => (string) ( $payload['kladr_id'] ?? '' ),
				'geo_lat' => null !== $lat ? (string) $lat : '',
				'geo_lon' => null !== $lng ? (string) $lng : '',
			),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function failure( string $message, string $service_key, string $original_address, array $payload = array() ): array {
		return array(
			'success' => false,
			'source' => 'dadata+cdek_location',
			'service_key' => $service_key,
			'original_hash' => $this->original_address_hash( $original_address ),
			'display' => (string) ( $payload['full_address'] ?? '' ),
			'message' => $message,
			'fields' => array(),
		);
	}

	private function city_code( ?float $lat, ?float $lng, string $city, string $postal_code ): int {
		$query = array( 'country_codes' => 'RU', 'size' => 1 );
		if ( null !== $lat && null !== $lng ) {
			$query['latitude'] = $lat;
			$query['longitude'] = $lng;
		} elseif ( '' !== $city ) {
			$query['city'] = $city;
		}
		if ( '' !== $postal_code ) {
			$query['postal_code'] = $postal_code;
		}

		try {
			$response = $this->client->cities( $query );
		} catch ( CdekApiException ) {
			return 0;
		}
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$rows = is_array( $body['items'] ?? null ) ? $body['items'] : $body;
		$row = is_array( $rows[0] ?? null ) ? $rows[0] : array();
		$code = (int) ( $row['code'] ?? 0 );

		return $code > 0 ? $code : 0;
	}

	private function clean_city_name( string $city ): string {
		$city = trim( preg_replace( '/\s+/', ' ', $city ) ?? $city );
		$city = preg_replace( '/^(г|город|пос|п|с|д)\.?\s+/iu', '', $city ) ?? $city;

		return trim( $city );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<int,string> $keys
	 */
	private function coordinate( array $payload, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( ! isset( $payload[ $key ] ) || '' === (string) $payload[ $key ] || ! is_numeric( $payload[ $key ] ) ) {
				continue;
			}

			return (float) $payload[ $key ];
		}

		return null;
	}

	private function original_address_hash( string $original_address ): string {
		return hash( 'sha256', trim( $original_address ) );
	}
}
