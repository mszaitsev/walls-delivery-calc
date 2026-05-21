<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasEndpoints;
use WallsShop\WDC\Locations\Fias\FiasHttpClient;
use WallsShop\WDC\Locations\Fias\FiasLogger;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class FiasAddressNormalizer implements AddressNormalizerInterface {
	public function __construct(
		private CheckoutCityResolver $city_resolver,
		private ?SettingsRepository $settings = null,
		private ?FiasEndpoints $endpoints = null,
		private ?FiasHttpClient $http_client = null,
		private ?FiasRateLimiter $rate_limiter = null,
		private ?FiasLogger $logger = null
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		$city_input = trim( (string) ( $context['city'] ?? '' ) );
		if ( '' === $city_input ) {
			$city_input = $input;
		}

		$location = $this->city_resolver->resolve_city( $city_input );
		$api_ready = $this->settings instanceof SettingsRepository
			&& $this->endpoints instanceof FiasEndpoints
			&& $this->http_client instanceof FiasHttpClient
			&& $this->rate_limiter instanceof FiasRateLimiter;

		if ( $location instanceof Location && ( ! $api_ready || ! $this->settings->get_bool( 'fias_api_enabled', true ) || $this->is_confident_local_match( $city_input, $location ) ) ) {
			return new AddressNormalizationResult( $input, $this->address_from_location( $input, $context, $location ), true, 0.9, 'fias' );
		}

		if ( ! $api_ready || ! $this->settings->get_bool( 'fias_api_enabled', true ) ) {
			$this->logger?->fallback_used( 'normalize', array( 'reason' => 'api_disabled' ) );
			return new AddressNormalizationResult(
				$input,
				$this->address_from_context( $input, $context ),
				false,
				0.0,
				'fias',
				'location_not_found',
				'Local FIAS stub could not match the checkout city.'
			);
		}

		if ( ! $this->rate_limiter->can_request() ) {
			return new AddressNormalizationResult(
				$input,
				$this->address_from_context( $input, $context ),
				false,
				0.0,
				'fias',
				'rate_limited',
				'FIAS request limit exceeded.'
			);
		}

		$this->rate_limiter->increment();
		$response = $this->http_client->post(
			$this->endpoints->normalize(),
			array(
				'query' => $input,
				'city'  => $city_input,
			)
		);

		if ( empty( $response['success'] ) ) {
			$this->logger?->fallback_used( 'normalize', array( 'reason' => ! empty( $response['timeout'] ) ? 'timeout' : 'api_error' ) );
			return new AddressNormalizationResult(
				$input,
				$this->address_from_context( $input, $context ),
				false,
				0.0,
				'fias',
				! empty( $response['timeout'] ) ? 'api_timeout' : 'api_failed',
				(string) ( $response['error_message'] ?? 'FIAS API request failed.' )
			);
		}

		$api_address = $this->address_from_api_body( $input, $context, $response['body'] ?? null, $location );
		if ( ! $api_address instanceof Address ) {
			$this->logger?->parse_error( 'normalize', array( 'status_code' => (int) ( $response['status_code'] ?? 0 ) ) );
			return new AddressNormalizationResult( $input, $this->address_from_context( $input, $context ), false, 0.0, 'fias', 'api_parse_failed', 'FIAS API response did not contain a usable address.' );
		}

		return new AddressNormalizationResult( $input, $api_address, true, 0.95, 'fias' );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function address_from_location( string $input, array $context, Location $location ): Address {
		$postcode = trim( (string) ( $context['postcode'] ?? '' ) );
		if ( '' === $postcode || '' !== trim( $location->postcode ) ) {
			$postcode = trim( $location->postcode );
		}

		$address = new Address(
			country_code: '' !== $location->country_code ? $location->country_code : (string) ( $context['country_code'] ?? '' ),
			region_name: $location->region_name,
			region_code: $location->region_code,
			city: $location->city_name,
			settlement: $location->settlement_name,
			postcode: $postcode,
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			fias_id: $location->fias_id,
			gar_id: $location->gar_id,
			normalized: true,
			fallback: false
		);

		return $address;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function address_from_api_body( string $input, array $context, mixed $body, ?Location $local_location ): ?Address {
		$item = $this->first_address_item( $body );
		if ( ! is_array( $item ) ) {
			return null;
		}

		$data = is_array( $item['data'] ?? null ) ? $item['data'] : $item;
		$city = $this->first_scalar( $data, array( 'city', 'city_name', 'settlement', 'settlement_name', 'mun_name', 'name' ) );
		if ( '' === $city && $local_location instanceof Location ) {
			$city = '' !== $local_location->settlement_name ? $local_location->settlement_name : $local_location->city_name;
		}

		if ( '' === trim( $city ) ) {
			return null;
		}

		$postcode = $this->first_scalar( $data, array( 'postal_code', 'postcode', 'index' ) );
		if ( '' === $postcode && $local_location instanceof Location ) {
			$postcode = $local_location->postcode;
		}
		if ( '' === $postcode ) {
			$postcode = (string) ( $context['postcode'] ?? '' );
		}

		return new Address(
			country_code: (string) ( $context['country_code'] ?? 'RU' ),
			region_name: $this->first_scalar( $data, array( 'region', 'region_name', 'subject_name' ) ) ?: ( $local_location instanceof Location ? $local_location->region_name : '' ),
			region_code: $this->first_scalar( $data, array( 'region_code', 'subject_code' ) ) ?: ( $local_location instanceof Location ? $local_location->region_code : '' ),
			city: $city,
			settlement: $this->first_scalar( $data, array( 'settlement_with_type', 'settlement_name' ) ),
			postcode: $postcode,
			street: $this->first_scalar( $data, array( 'street', 'street_with_type' ) ) ?: (string) ( $context['address_1'] ?? '' ),
			house: $this->first_scalar( $data, array( 'house', 'house_num' ) ) ?: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			fias_id: $this->first_scalar( $data, array( 'fias_id', 'object_guid', 'aoguid' ) ) ?: ( $local_location instanceof Location ? $local_location->fias_id : '' ),
			gar_id: $this->first_scalar( $data, array( 'gar_id', 'objectid', 'object_id' ) ) ?: ( $local_location instanceof Location ? $local_location->gar_id : '' ),
			normalized: true,
			fallback: false
		);
	}

	private function is_confident_local_match( string $input, Location $location ): bool {
		$query = Location::normalize_search_text( $input );
		return '' !== $query && in_array(
			$query,
			array(
				Location::normalize_search_text( $location->city_name ),
				Location::normalize_search_text( $location->settlement_name ),
				Location::normalize_search_text( $location->display_name ),
			),
			true
		);
	}

	private function first_address_item( mixed $body ): mixed {
		if ( ! is_array( $body ) ) {
			return null;
		}

		foreach ( array( 'suggestions', 'items', 'addresses', 'data', 'result' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return array_is_list( $body[ $key ] ) ? ( $body[ $key ][0] ?? null ) : $body[ $key ];
			}
		}

		return array_is_list( $body ) ? ( $body[0] ?? null ) : $body;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,string>  $keys
	 */
	private function first_scalar( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function address_from_context( string $input, array $context ): Address {
		return new Address(
			country_code: (string) ( $context['country_code'] ?? '' ),
			city: (string) ( $context['city'] ?? '' ),
			postcode: (string) ( $context['postcode'] ?? '' ),
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			normalized: false,
			fallback: false
		);
	}
}
