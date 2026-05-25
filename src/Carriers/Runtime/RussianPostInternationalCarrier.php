<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostInternationalCarrier implements CarrierAdapterInterface {
	public const KEY = RussianPostSettings::CARRIER_KEY;
	public const SERVICE_KEY = RussianPostSettings::SERVICE_KEY;

	public function __construct(
		private RussianPostSettings $settings,
		private RussianPostApiClient $client,
		private RussianPostCountryDirectory $countries,
		private Logger $logger
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, 'Почта России', 'api', $this->settings->enabled() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_courier_delivery: true,
			supports_international: true
		);
	}

	public function supports_country( string $countryCode ): bool {
		$countryCode = strtoupper( trim( $countryCode ) );

		return '' !== $countryCode && 'RU' !== $countryCode && $this->settings->enabled();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$country_code = strtoupper( trim( $request->country_code ?: $request->destination->country_code ) );
		$settings = $this->settings->all();

		if ( ! $this->supports_country( $country_code ) ) {
			return $this->empty_quote( $request, 'unsupported_country_' . $country_code );
		}

		$package = $request->package;
		if ( $package->get_total_weight_g() > (int) $settings['max_package_weight_g'] ) {
			return $this->fallback_quote( $request, $package, 'overweight', array( 'max_package_weight_g' => (int) $settings['max_package_weight_g'] ) );
		}

		$country = $this->countries->get_country( $country_code );
		if ( array() === $country ) {
			return $this->fallback_quote( $request, $package, 'unsupported_country_' . $country_code );
		}

		$params = $this->request_params( $settings, (int) $country['carrier_country_id'], $package->get_total_weight_g() );
		$cache_key = $this->api_cache_key( $country_code, $params );
		$api_result = $this->cached_api_result( $cache_key, $params );
		if ( empty( $api_result['success'] ) || ! is_array( $api_result['raw'] ?? null ) ) {
			return $this->fallback_quote( $request, $package, (string) ( $api_result['error_code'] ?? 'api_error' ), array( 'api_result' => $api_result, 'cache_key' => $cache_key ) );
		}

		$price = $this->extract_price_with_vat_rub( $api_result['raw'], (float) $settings['vat_rate'] );
		if ( null === $price ) {
			return $this->fallback_quote( $request, $package, 'missing_price', array( 'api_result' => $api_result, 'cache_key' => $cache_key ) );
		}
		if ( $price['price_with_vat_rub'] <= 0 ) {
			return $this->fallback_quote( $request, $package, 'zero_price', array( 'api_result' => $api_result, 'cache_key' => $cache_key ) );
		}

		$meta = array(
			'api_price_rub' => $price['api_price_rub'],
			'api_price_has_vat' => $price['has_vat'],
			'api_price_with_vat_rub' => $price['price_with_vat_rub'],
			'vat_rate' => (float) $settings['vat_rate'],
			'api_base_price_rub' => $price['price_with_vat_rub'],
			'cache_key' => $cache_key,
			'cache_hit' => ! empty( $api_result['cache_hit'] ),
			'request_url' => (string) ( $api_result['url'] ?? '' ),
			'request_params' => $api_result['params'] ?? $params,
			'http_code' => (int) ( $api_result['http_code'] ?? 0 ),
			'raw_response' => $api_result['raw'],
			'country_mapping' => array(
				'country_code' => $country_code,
				'carrier_country_id' => (string) $country['carrier_country_id'],
				'country_name' => (string) ( $country['name'] ?? '' ),
			),
			'package' => $package->to_array(),
		);

		$this->debug( 'Russian Post API base price calculated.', $meta );

		$rate = new DeliveryRate(
			self::SERVICE_KEY,
			self::KEY,
			'Почта России',
			self::SERVICE_KEY,
			RussianPostSettings::TITLE,
			$this->transport_type( $api_result['raw']['transtype'] ?? null ),
			RussianPostSettings::TITLE,
			DeliveryType::PICKUP,
			RussianPostSettings::TITLE,
			Money::from_rubles( $price['price_with_vat_rub'] ),
			null,
			null,
			DateRange::range( null, null ),
			'',
			'',
			array(),
			false,
			'',
			false,
			false,
			$meta
		);

		return new DeliveryQuote( $this->quote_id( $request, $package ), self::KEY, $request->destination, $package, array( $rate ), true, '', '', ! empty( $api_result['cache_hit'] ), ! empty( $api_result['cache_hit'] ) ? 'cache' : 'api', $meta );
	}

	private function empty_quote( QuoteRequest $request, string $reason ): DeliveryQuote {
		return new DeliveryQuote( $this->quote_id( $request, $request->package ), self::KEY, $request->destination, $request->package, array(), true, $reason, $reason, false, 'manual', array( 'fallback_reason' => $reason ) );
	}

	/**
	 * @param array<string,mixed> $extra
	 */
	private function fallback_quote( QuoteRequest $request, Package $package, string $reason, array $extra = array() ): DeliveryQuote {
		$settings = $this->settings->all();
		$this->debug( 'Russian Post fallback used.', array_merge( array( 'fallback_reason' => $reason ), $extra ) );
		if ( empty( $settings['fallback_enabled'] ) ) {
			return new DeliveryQuote( $this->quote_id( $request, $package ), self::KEY, $request->destination, $package, array(), false, $reason, $reason, false, 'fallback', array_merge( array( 'fallback_reason' => $reason ), $extra ) );
		}

		$comment = trim( (string) $settings['fallback_text'] );
		$comment = '' !== $comment ? $comment : 'Стоимость доставки рассчитает менеджер';
		$rate = new DeliveryRate(
			self::SERVICE_KEY . ':fallback',
			self::KEY,
			'Почта России',
			self::SERVICE_KEY,
			RussianPostSettings::TITLE,
			'fallback',
			RussianPostSettings::TITLE,
			DeliveryType::PICKUP,
			$comment,
			Money::from_rubles( 0 ),
			null,
			null,
			DateRange::range( null, null ),
			'',
			'',
			array(),
			false,
			'',
			false,
			false,
			array_merge(
				array(
					'fallback' => true,
					'terminal_fallback' => true,
					'skip_rules' => true,
					'skip_service_post_processing' => true,
					'fallback_reason' => $reason,
					'fallback_text' => $comment,
					'round_up_applied' => false,
					'minimum_price_applied' => false,
					'package' => $package->to_array(),
				),
				$extra
			)
		);

		return new DeliveryQuote( $this->quote_id( $request, $package ), self::KEY, $request->destination, $package, array( $rate ), true, $reason, $reason, false, 'fallback', $rate->meta );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,scalar>
	 */
	private function request_params( array $settings, int $country_id, int $weight_g ): array {
		$date = function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );

		return array(
			'object' => (int) $settings['object_code'],
			'from' => (string) $settings['origin_postcode'],
			'country-to' => $country_id,
			'weight' => $weight_g,
			'date' => $date,
			'date-discount' => $date,
			'isavia' => (int) $settings['isavia'],
		);
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,mixed>
	 */
	private function cached_api_result( string $cache_key, array $params ): array {
		$settings = $this->settings->all();
		$cached = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( is_array( $cached ) ) {
			$this->debug( 'Russian Post tariff cache hit.', array( 'cache_key' => $cache_key ) );
			$cached['cache_hit'] = true;
			return $cached;
		}

		$this->debug( 'Russian Post tariff cache miss.', array( 'cache_key' => $cache_key, 'params' => $params ) );
		$result = $this->client->calculate_tariff( $params );
		$result['cache_hit'] = false;
		if ( ! empty( $result['success'] ) && ! empty( $settings['cache_until_end_of_day'] ) && function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $result, $this->seconds_until_end_of_day() );
		}

		return $result;
	}

	/**
	 * @param array<string,scalar> $params
	 */
	private function api_cache_key( string $country_code, array $params ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $params ) : json_encode( $params );

		return 'wdc_rp_tariff_' . sha1( implode( '|', array( self::KEY, self::SERVICE_KEY, $country_code, is_string( $json ) ? $json : '' ) ) );
	}

	private function seconds_until_end_of_day(): int {
		if ( function_exists( 'wp_timezone' ) ) {
			$now = new \DateTimeImmutable( 'now', wp_timezone() );
			$end = $now->setTime( 23, 59, 59 );

			return max( 1, $end->getTimestamp() - $now->getTimestamp() );
		}

		$end = strtotime( 'today 23:59:59' );

		return is_int( $end ) ? max( 1, $end - time() ) : 86400;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{api_price_rub:float,has_vat:bool,price_with_vat_rub:float}|null
	 */
	private function extract_price_with_vat_rub( array $raw, float $vat_rate ): ?array {
		foreach ( array( 'paynds', 'paymoneynds' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$rub = (float) $raw[ $key ] / 100;
				return array( 'api_price_rub' => $rub, 'has_vat' => true, 'price_with_vat_rub' => $rub );
			}
		}

		foreach ( array( 'paymoney', 'pay' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$rub = (float) $raw[ $key ] / 100;
				return array( 'api_price_rub' => $rub, 'has_vat' => false, 'price_with_vat_rub' => $rub * ( 1 + $vat_rate ) );
			}
		}

		return null;
	}

	private function transport_type( mixed $transtype ): string {
		return 2 === (int) $transtype ? 'air' : 'ground';
	}

	private function quote_id( QuoteRequest $request, Package $package ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( array( $request->to_array(), $package->to_array() ) ) : json_encode( array( $request->to_array(), $package->to_array() ) );

		return self::KEY . '-' . substr( sha1( is_string( $json ) ? $json : '' ), 0, 12 );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function debug( string $message, array $context = array() ): void {
		if ( $this->settings->debug_enabled() ) {
			$this->logger->debug( $message, $context );
		}
	}
}
