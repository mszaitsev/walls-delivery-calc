<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use Throwable;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Contracts\CarrierQuoteCacheContextProviderInterface;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiException;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCityNameNormalizer;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteRequestBuilder;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteResponseParser;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCarrier implements CarrierAdapterInterface, CarrierQuoteCacheContextProviderInterface {
	private const WAREHOUSE_CONTACTS_URL = 'https://jet.com.kz/%D0%BA%D0%BE%D0%BD%D1%82%D0%B0%D0%BA%D1%82%D1%8B.html';

	public function __construct(
		private JetLogisticSettings $settings,
		private JetLogisticApiClient $api,
		private JetLogisticQuoteRequestBuilder $builder,
		private JetLogisticQuoteResponseParser $parser,
		private JetLogisticGeographyRepository $geography,
		private JetLogisticCityNameNormalizer $normalizer,
		private ?Logger $logger = null
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( JetLogisticSettings::CARRIER_KEY, JetLogisticSettings::PUBLIC_TITLE, 'api', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true, supports_courier_delivery: true, supports_pickup_delivery: true, supports_status_sync: true, supports_international: true );
	}

	public function supports_country( string $countryCode ): bool {
		return 'RU' !== strtoupper( trim( $countryCode ) );
	}

	/** @return array<string,mixed> */
	public function quote_cache_context( QuoteRequest $request ): array {
		return array(
			'jet_origin_source_identity' => $this->settings->origin_source_identity(),
			'jet_insurance_percent' => $this->settings->insurance_percent(),
			'jet_insurance_min_rub' => $this->settings->insurance_min_rub(),
			'jet_almaty_free_courier' => $this->settings->almaty_free_courier(),
		);
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		try {
			if ( ! $this->supports_country( $request->country_code ) ) {
				throw new JetLogisticApiException( 'Jet Logistic does not calculate RU destinations.', array( 'error_code' => 'jet_country_ru_disabled' ) );
			}
			$destination = $this->destination_mapping( $request );
			$origin = $this->origin_mapping();
			$packaging_errors = $this->builder->validate_packaging( $request );
			if ( array() !== $packaging_errors ) {
				throw new JetLogisticApiException( 'Jet Logistic package dimensions are incomplete.', array( 'error_code' => implode( ',', $packaging_errors ) ) );
			}
			$payload = $this->builder->build( $request, $origin, $destination );
			if ( '' === trim( (string) $payload['access_token'] ) ) {
				throw new JetLogisticApiException( 'Jet Logistic API token is missing.', array( 'error_code' => 'jet_token_missing' ) );
			}
			$result = $this->parser->parse( $this->api->calc_transport( $payload ) );
			$this->assert_destination_city( (string) $payload['cityto'], $result->city_to, $result->city_terminal_to );
			$local_terminal = $this->normalizer->api_city_matches( (string) $payload['cityto'], $result->city_terminal_to );
			$raw_pickup = Money::from_rubles( $result->price_terminal )->add( Money::from_rubles( $result->price_dop ) );
			$raw_delivery_component = Money::from_rubles( $result->price_delivery );
			$effective_delivery_component = $this->effective_delivery_component( $result, $destination );
			$insurance = $this->insurance_cost( $request );
			$pickup_base = $raw_pickup->add( $insurance );
			$courier_base = $raw_pickup->add( $effective_delivery_component )->add( $insurance );
			$almaty_free_courier_applied = $this->settings->almaty_free_courier() && $this->is_almaty_destination( $destination );
			$this->log_quote_success( $payload, $result, $pickup_base, $courier_base, $insurance, $effective_delivery_component, $almaty_free_courier_applied, $local_terminal, $request );
			$rates = array(
				$this->pickup_rate( $result, $destination, $local_terminal, $pickup_base, $insurance, $effective_delivery_component, $almaty_free_courier_applied, $request ),
				$this->courier_rate( $result, $destination, $local_terminal, $courier_base, $insurance, $effective_delivery_component, $almaty_free_courier_applied, $request ),
			);

			return new DeliveryQuote( $this->quote_id( $request, 'ok' ), JetLogisticSettings::CARRIER_KEY, $request->destination, $request->package, $rates, true, '', '', false, 'api', array( 'jet_request' => $this->safe_payload( $payload ) ) );
		} catch ( Throwable $exception ) {
			$code = $exception instanceof JetLogisticApiException ? $exception->error_code() : 'jet_quote_failed';
			$this->log_quote_failure( $code, $exception );

			return new DeliveryQuote( $this->quote_id( $request, $code ), JetLogisticSettings::CARRIER_KEY, $request->destination, $request->package, array(), false, $code, 'Jet Logistic quote unavailable.', false, 'api' );
		}
	}

	private function pickup_rate( object $result, array $destination, bool $local_terminal, Money $price, Money $insurance, Money $effective_delivery_component, bool $almaty_free_courier_applied, QuoteRequest $request ): DeliveryRate {
		$title = $local_terminal ? 'Джет Логистик до склада выдачи' : 'Джет Логистик до склада выдачи в г. ' . $result->city_terminal_to;
		$comments = $local_terminal ? array() : array( 'Получение груза на складе Джет Логистик в г. ' . $result->city_terminal_to . '.' );

		return $this->rate( JetLogisticSettings::PICKUP_RATE_KEY, DeliveryType::PICKUP, $title, $price, $result, $destination, $local_terminal, $comments, false, $insurance, $effective_delivery_component, $almaty_free_courier_applied, $request );
	}

	private function courier_rate( object $result, array $destination, bool $local_terminal, Money $price, Money $insurance, Money $effective_delivery_component, bool $almaty_free_courier_applied, QuoteRequest $request ): DeliveryRate {
		$destination_city = trim( (string) ( $destination['source_city'] ?? '' ) );
		$title = $local_terminal || '' === $destination_city ? 'Джет Логистик курьером' : 'Джет Логистик курьером в ' . $destination_city;

		return $this->rate( JetLogisticSettings::COURIER_RATE_KEY, DeliveryType::COURIER, $title, $price, $result, $destination, $local_terminal, array(), true, $insurance, $effective_delivery_component, $almaty_free_courier_applied, $request );
	}

	/** @param array<string,mixed> $destination @param array<int,string> $comments */
	private function rate( string $rate_id, string $type, string $title, Money $price, object $result, array $destination, bool $local_terminal, array $comments, bool $requires_address, Money $insurance, Money $effective_delivery_component, bool $almaty_free_courier_applied, QuoteRequest $request ): DeliveryRate {
		return new DeliveryRate(
			$rate_id,
			JetLogisticSettings::CARRIER_KEY,
			JetLogisticSettings::PUBLIC_TITLE,
			JetLogisticSettings::SERVICE_KEY,
			JetLogisticSettings::PUBLIC_TITLE,
			$rate_id,
			$title,
			$type,
			$title,
			$price,
			null,
			null,
			DateRange::range( $result->day_from, $result->day_to, DateRange::UNIT_WORKING_DAYS ),
			'',
			'',
			$comments,
			false,
			'',
			false,
			$requires_address,
			array(
				'preserve_rate_title' => true,
				'api_base_price_rub' => $price->get_rubles(),
				'jet_price_zabor_rub' => $result->price_zabor,
				'jet_price_terminal_rub' => $result->price_terminal,
				'jet_price_delivery_rub' => $result->price_delivery,
				'jet_price_dop_rub' => $result->price_dop,
				'jet_effective_price_delivery_rub' => $effective_delivery_component->get_rubles(),
				'jet_almaty_free_courier_applied' => $almaty_free_courier_applied ? 'yes' : 'no',
				'jet_insurance_percent' => $this->settings->insurance_percent(),
				'jet_insurance_min_rub' => $this->settings->insurance_min_rub(),
				'jet_insurance_rub' => $insurance->get_rubles(),
				'jet_goods_cost_rub' => $request->package->cart_total->get_rubles(),
				'requested_city' => (string) ( $destination['source_city'] ?? '' ),
				'jet_city_to' => $result->city_to,
				'jet_city_terminal_to' => $result->city_terminal_to,
				'jet_local_terminal' => $local_terminal ? 'yes' : 'no',
				'customer_link_comments' => DeliveryType::PICKUP === $type ? array( $this->warehouse_link_comment() ) : array(),
				'delivery_days_are_working' => true,
			),
			$price,
			DateRange::range( $result->day_from, $result->day_to, DateRange::UNIT_WORKING_DAYS )
		);
	}

	/** @return array{text_before:string,label:string,url:string} */
	private function warehouse_link_comment(): array {
		return array(
			'text_before' => 'Адрес склада выдачи - ',
			'label' => 'на сайте Jet Logistic',
			'url' => self::WAREHOUSE_CONTACTS_URL,
		);
	}

	private function insurance_cost( QuoteRequest $request ): Money {
		$percentage = $request->package->cart_total->multiply( $this->settings->insurance_percent() / 100 );
		$minimum = Money::from_rubles( $this->settings->insurance_min_rub() );

		return $percentage->max( $minimum );
	}

	private function effective_delivery_component( object $result, array $destination ): Money {
		if ( $this->settings->almaty_free_courier() && $this->is_almaty_destination( $destination ) ) {
			return Money::from_rubles( 0 );
		}

		return Money::from_rubles( $result->price_delivery );
	}

	/** @param array<string,mixed> $destination */
	private function is_almaty_destination( array $destination ): bool {
		return $this->normalizer->normalize( (string) ( $destination['source_city'] ?? '' ) ) === $this->normalizer->normalize( 'Алматы' );
	}

	/** @return array<string,mixed> */
	private function destination_mapping( QuoteRequest $request ): array {
		$location_id = max( 0, (int) ( $request->customer_context['selected_location_id'] ?? $request->customer_context['location_id'] ?? 0 ) );
		if ( $location_id <= 0 ) {
			throw new JetLogisticApiException(
				'Jet Logistic destination location is missing.',
				array(
					'error_code' => 'jet_destination_location_missing',
					'country_code' => $request->country_code,
					'destination_text' => $request->destination->city,
					'selected_location_id' => (string) ( $request->customer_context['selected_location_id'] ?? '' ),
					'location_id' => (string) ( $request->customer_context['location_id'] ?? '' ),
					'location_context_source' => (string) ( $request->customer_context['location_context_source'] ?? '' ),
				)
			);
		}
		$row = $this->geography->active_for_location( $location_id );
		if ( array() === $row ) {
			throw new JetLogisticApiException( 'Jet Logistic destination city is unmapped.', array( 'error_code' => 'jet_destination_unmapped' ) );
		}

		return $row;
	}

	/** @return array<string,mixed> */
	private function origin_mapping(): array {
		$identity = $this->settings->origin_source_identity();
		$row = '' !== $identity ? $this->geography->origin_by_source_identity( $identity ) : array();
		if ( array() === $row ) {
			throw new JetLogisticApiException( 'Jet Logistic origin city is not configured.', array( 'error_code' => 'jet_origin_missing' ) );
		}

		return $row;
	}

	private function assert_destination_city( string $requested, string $actual, string $terminal ): void {
		$normalized_requested = $this->normalizer->normalize_api_city( $requested );
		$normalized_actual = $this->normalizer->normalize_api_city( $actual );
		if ( ! $this->normalizer->api_city_matches( $requested, $actual ) ) {
			throw new JetLogisticApiException(
				'Jet Logistic response destination city mismatch.',
				array(
					'error_code' => 'jet_destination_city_mismatch',
					'requested_city' => $requested,
					'response_city_to' => $actual,
					'response_city_terminal_to' => $terminal,
					'normalized_requested_city' => $normalized_requested,
					'normalized_response_city' => $normalized_actual,
				)
			);
		}
	}

	private function log_quote_failure( string $code, Throwable $exception ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$context = array( 'error_code' => $code );
		if ( $exception instanceof JetLogisticApiException ) {
			foreach ( array( 'requested_city', 'response_city_to', 'response_city_terminal_to', 'normalized_requested_city', 'normalized_response_city' ) as $key ) {
				if ( array_key_exists( $key, $exception->context() ) && is_scalar( $exception->context()[ $key ] ) ) {
					$context[ $key ] = (string) $exception->context()[ $key ];
				}
			}
			if ( 'jet_destination_location_missing' === $code ) {
				foreach ( array( 'country_code', 'destination_text', 'selected_location_id', 'location_id', 'location_context_source' ) as $key ) {
					if ( array_key_exists( $key, $exception->context() ) && is_scalar( $exception->context()[ $key ] ) ) {
						$context[ $key ] = (string) $exception->context()[ $key ];
					}
				}
			}
		}
		if ( 'jet_destination_location_missing' === $code ) {
			$this->logger->debug( 'Jet Logistic quote precondition is incomplete.', $context );
			return;
		}
		$this->logger->warning( 'Jet Logistic quote failed.', $context );
	}

	/** @param array<string,mixed> $payload */
	private function log_quote_success( array $payload, object $result, Money $pickup_base, Money $courier_base, Money $insurance, Money $effective_delivery_component, bool $almaty_free_courier_applied, bool $local_terminal, QuoteRequest $request ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->debug(
			'Jet Logistic quote calculated.',
			array(
				'request_city_from' => (string) ( $payload['cityfrom'] ?? '' ),
				'request_city_to' => (string) ( $payload['cityto'] ?? '' ),
				'request_weight_kg' => (string) ( $payload['ves'] ?? '' ),
				'request_volume_m3' => (string) ( $payload['obm3'] ?? '' ),
				'request_max_side_m' => (string) ( $payload['dlina'] ?? '' ),
				'request_places' => (string) ( $payload['mest'] ?? '' ),
				'request_goods_cost_rub' => (string) ( $payload['cost'] ?? '' ),
				'request_sdoc' => (string) ( is_array( $payload['dops'] ?? null ) ? ( $payload['dops']['D_SDOC'] ?? '' ) : '' ),
				'response_price_zabor' => (string) $result->price_zabor,
				'response_price_terminal' => (string) $result->price_terminal,
				'response_price_delivery' => (string) $result->price_delivery,
				'response_price_dop' => (string) $result->price_dop,
				'response_city_from' => $result->city_from,
				'response_city_terminal_from' => $result->city_terminal_from,
				'response_city_terminal_to' => $result->city_terminal_to,
				'response_city_to' => $result->city_to,
				'response_day_from' => null === $result->day_from ? '' : (string) $result->day_from,
				'response_day_to' => null === $result->day_to ? '' : (string) $result->day_to,
				'response_valuta' => $result->valuta,
				'response_valuta_name' => $result->valuta_name,
				'insurance_percent' => (string) $this->settings->insurance_percent(),
				'insurance_min_rub' => (string) $this->settings->insurance_min_rub(),
				'insurance_rub' => (string) $insurance->get_rubles(),
				'goods_cost_rub' => (string) $request->package->cart_total->get_rubles(),
				'effective_price_delivery_rub' => (string) $effective_delivery_component->get_rubles(),
				'almaty_free_courier_applied' => $almaty_free_courier_applied ? 'yes' : 'no',
				'calculated_pickup_base_rub' => (string) $pickup_base->get_rubles(),
				'calculated_courier_base_rub' => (string) $courier_base->get_rubles(),
				'calculated_pickup_rub' => (string) $pickup_base->get_rubles(),
				'calculated_courier_rub' => (string) $courier_base->get_rubles(),
				'local_terminal' => $local_terminal ? 'yes' : 'no',
			)
		);
	}

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return 'jet_' . md5( $request->country_code . '|' . (string) ( $request->customer_context['location_id'] ?? '' ) . '|' . $suffix );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function safe_payload( array $payload ): array {
		$payload['access_token'] = '[redacted]';

		return $payload;
	}
}
