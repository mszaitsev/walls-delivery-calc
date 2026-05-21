<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasCredentials;
use WallsShop\WDC\Locations\Fias\FiasEndpoints;
use WallsShop\WDC\Locations\Fias\FiasHttpClient;
use WallsShop\WDC\Locations\Fias\FiasLogger;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;

defined( 'ABSPATH' ) || exit;

final class FiasAddressNormalizer implements AddressNormalizerInterface {
	public function __construct(
		private CheckoutCityResolver $city_resolver,
		private ?SettingsRepository $settings = null,
		private ?FiasEndpoints $endpoints = null,
		private ?FiasHttpClient $http_client = null,
		private ?FiasRateLimiter $rate_limiter = null,
		private ?FiasLogger $logger = null,
		private ?FiasCredentials $credentials = null
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		// FIAS runtime normalization is intentionally disabled until the API methods are verified with a real token.
		$has_token = $this->credentials instanceof FiasCredentials && $this->credentials->has_token();
		$error_code = $has_token ? 'fias_runtime_disabled' : 'fias_token_missing';
		$message = $has_token
			? 'FIAS API normalization is prepared but disabled until API methods are verified.'
			: 'FIAS token is not configured.';

		return new AddressNormalizationResult(
			$input,
			$this->address_from_context( $input, $context ),
			false,
			0.0,
			'fias',
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
