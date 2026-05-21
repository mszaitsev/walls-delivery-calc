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
		$query_debug = $this->query_debug( $context );
		if ( ! $this->settings instanceof SettingsRepository || ! $this->settings->get_bool( 'dadata_enabled', false ) ) {
			return $this->failure( $input, $context, 'dadata_disabled', 'DaData normalization is disabled.', 'dadata skipped: disabled', $query_debug );
		}

		if ( ! $this->credentials instanceof DaDataCredentials || ! $this->credentials->has_token() ) {
			return $this->failure( $input, $context, 'dadata_credentials_missing', 'DaData token is not configured.', 'dadata skipped: missing token', $query_debug );
		}

		$query = (string) $query_debug['query'];
		if ( '' === $query || '' === trim( (string) ( $context['city'] ?? '' ) ) || '' === trim( (string) ( $context['address_1'] ?? '' ) ) ) {
			return $this->failure( $input, $context, 'dadata_empty_address', 'Address city or address line is empty.', 'dadata skipped: empty address', $query_debug );
		}

		if ( ! $this->http_client instanceof DaDataHttpClient ) {
			return $this->failure( $input, $context, 'dadata_api_failed', 'DaData HTTP client is unavailable.', 'dadata failed', $query_debug );
		}

		$response = $this->http_client->clean_address( $query, $this->credentials->token() );
		if ( empty( $response['success'] ) || ! is_array( $response['body'] ?? null ) ) {
			return $this->failure(
				$input,
				$context,
				! empty( $response['timeout'] ) ? 'dadata_timeout' : 'dadata_api_failed',
				(string) ( $response['error_message'] ?? 'DaData request failed.' ),
				! empty( $response['timeout'] ) ? 'dadata timeout' : 'dadata failed',
				$query_debug
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
			'dadata',
			'',
			'',
			$this->debug_payload( 'dadata success', $query_debug )
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function failure( string $input, array $context, string $error_code, string $message, string $status, array $query_debug ): AddressNormalizationResult {
		return new AddressNormalizationResult(
			$input,
			$this->address_from_context( $input, $context ),
			false,
			0.0,
			'dadata',
			$error_code,
			$message,
			$this->debug_payload( $status, $query_debug )
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	private function query_debug( array $context ): array {
		if ( $this->query_builder instanceof AddressQueryBuilder ) {
			return $this->query_builder->debug( $context );
		}

		return array(
			'country'   => (string) ( $context['country_code'] ?? '' ),
			'region'    => (string) ( $context['region_name'] ?? $context['selected_region_name'] ?? '' ),
			'city'      => (string) ( $context['city'] ?? '' ),
			'address_1' => (string) ( $context['address_1'] ?? '' ),
			'address_2' => (string) ( $context['address_2'] ?? '' ),
			'query'     => '',
		);
	}

	/**
	 * @param array<string,string> $query_debug
	 * @return array<string,mixed>
	 */
	private function debug_payload( string $status, array $query_debug ): array {
		return array(
			'normalization_chain' => array( 'local city DB', 'fias placeholder', 'dadata', 'manual fallback' ),
			'dadata_status'       => $status,
			'dadata_query'        => $query_debug,
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
