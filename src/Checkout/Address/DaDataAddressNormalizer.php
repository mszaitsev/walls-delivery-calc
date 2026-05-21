<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\DaData\DaDataCredentials;
use WallsShop\WDC\Locations\DaData\DaDataHttpClient;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;

defined( 'ABSPATH' ) || exit;

final class DaDataAddressNormalizer implements AddressNormalizerInterface {
	public function __construct(
		private ?SettingsRepository $settings = null,
		private ?DaDataCredentials $credentials = null,
		private ?DaDataHttpClient $http_client = null,
		private ?AddressQueryBuilder $query_builder = null
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		if ( ! $this->settings instanceof SettingsRepository || ! $this->settings->get_bool( 'dadata_enabled', false ) ) {
			return $this->failure( $input, $context, 'dadata_disabled', 'DaData normalization is disabled.' );
		}

		if ( ! $this->credentials instanceof DaDataCredentials || ! $this->credentials->has_token() || ! $this->credentials->has_secret() ) {
			return $this->failure( $input, $context, 'dadata_credentials_missing', 'DaData token or secret key is not configured.' );
		}

		$query = $this->query_builder instanceof AddressQueryBuilder ? $this->query_builder->build( $context ) : trim( $input );
		if ( '' === $query || '' === trim( (string) ( $context['address_1'] ?? '' ) ) || '' === trim( (string) ( $context['address_2'] ?? '' ) ) ) {
			return $this->failure( $input, $context, 'dadata_empty_address', 'Address street or house is empty.' );
		}

		if ( ! $this->http_client instanceof DaDataHttpClient ) {
			return $this->failure( $input, $context, 'dadata_api_failed', 'DaData HTTP client is unavailable.' );
		}

		$response = $this->http_client->clean_address( $query, $this->credentials->token(), $this->credentials->secret() );
		if ( empty( $response['success'] ) || ! is_array( $response['body'] ?? null ) ) {
			return $this->failure(
				$input,
				$context,
				! empty( $response['timeout'] ) ? 'dadata_timeout' : 'dadata_api_failed',
				(string) ( $response['error_message'] ?? 'DaData request failed.' )
			);
		}

		$body = $response['body'];
		$address = new Address(
			country_code: (string) ( $context['country_code'] ?? 'RU' ),
			country_name: 'Россия',
			region_name: $this->first_string( $body, array( 'region_with_type', 'region' ) ),
			region_code: $this->first_string( $body, array( 'region_iso_code', 'region_kladr_id' ) ),
			city: $this->first_string( $body, array( 'city', 'settlement' ) ) ?: (string) ( $context['city'] ?? '' ),
			settlement: (string) ( $body['settlement'] ?? '' ),
			postcode: (string) ( $body['postal_code'] ?? ( $context['postcode'] ?? '' ) ),
			street: (string) ( $body['street_with_type'] ?? ( $body['street'] ?? ( $context['address_1'] ?? '' ) ) ),
			house: trim( (string) ( $body['house'] ?? ( $context['address_2'] ?? '' ) ) . ( '' !== (string) ( $body['block'] ?? '' ) ? ' ' . (string) $body['block'] : '' ) ),
			raw_address: (string) ( $body['result'] ?? $query ),
			fias_id: (string) ( $body['fias_id'] ?? '' ),
			normalized: true,
			fallback: false
		);

		return new AddressNormalizationResult(
			$input,
			$address,
			true,
			$this->confidence( $body ),
			'dadata'
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function failure( string $input, array $context, string $error_code, string $message ): AddressNormalizationResult {
		return new AddressNormalizationResult(
			$input,
			$this->address_from_context( $input, $context ),
			false,
			0.0,
			'dadata',
			$error_code,
			$message
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function address_from_context( string $input, array $context ): Address {
		return new Address(
			country_code: (string) ( $context['country_code'] ?? '' ),
			region_name: (string) ( $context['region_name'] ?? $context['selected_region_name'] ?? '' ),
			city: (string) ( $context['city'] ?? '' ),
			postcode: (string) ( $context['postcode'] ?? '' ),
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			normalized: false,
			fallback: false
		);
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<int,string> $keys
	 */
	private function first_string( array $body, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = trim( (string) ( $body[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function confidence( array $body ): float {
		$qc          = (string) ( $body['qc'] ?? '' );
		$qc_complete = (string) ( $body['qc_complete'] ?? '' );
		if ( '0' === $qc && '0' === $qc_complete ) {
			return 0.95;
		}

		return '0' === $qc ? 0.7 : 0.6;
	}
}
