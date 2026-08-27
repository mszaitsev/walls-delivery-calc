<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use Throwable;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
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

final class JetLogisticCarrier implements CarrierAdapterInterface {
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
			$this->assert_destination_city( (string) $payload['cityto'], $result->city_to );
			$local_terminal = $this->normalizer->normalize( $result->city_terminal_to ) === $this->normalizer->normalize( $result->city_to );
			$rates = array(
				$this->pickup_rate( $result, $destination, $local_terminal ),
				$this->courier_rate( $result, $destination, $local_terminal ),
			);

			return new DeliveryQuote( $this->quote_id( $request, 'ok' ), JetLogisticSettings::CARRIER_KEY, $request->destination, $request->package, $rates, true, '', '', false, 'api', array( 'jet_request' => $this->safe_payload( $payload ) ) );
		} catch ( Throwable $exception ) {
			$code = $exception instanceof JetLogisticApiException ? $exception->error_code() : 'jet_quote_failed';
			$this->logger?->warning( 'Jet Logistic quote failed.', array( 'error_code' => $code ) );

			return new DeliveryQuote( $this->quote_id( $request, $code ), JetLogisticSettings::CARRIER_KEY, $request->destination, $request->package, array(), false, $code, 'Jet Logistic quote unavailable.', false, 'api' );
		}
	}

	private function pickup_rate( object $result, array $destination, bool $local_terminal ): DeliveryRate {
		$title = $local_terminal ? 'Джет Логистик до склада выдачи' : 'Джет Логистик до склада выдачи в г. ' . $result->city_terminal_to;
		$comments = $local_terminal ? array() : array( 'Получение груза на складе Джет Логистик в г. ' . $result->city_terminal_to . '.' );

		return $this->rate( JetLogisticSettings::PICKUP_RATE_KEY, DeliveryType::PICKUP, $title, $result->price_terminal + $result->price_dop, $result, $destination, $local_terminal, $comments, false );
	}

	private function courier_rate( object $result, array $destination, bool $local_terminal ): DeliveryRate {
		return $this->rate( JetLogisticSettings::COURIER_RATE_KEY, DeliveryType::COURIER, 'Джет Логистик курьером', $result->price_terminal + $result->price_delivery + $result->price_dop, $result, $destination, $local_terminal, array(), true );
	}

	/** @param array<string,mixed> $destination @param array<int,string> $comments */
	private function rate( string $rate_id, string $type, string $title, int $rubles, object $result, array $destination, bool $local_terminal, array $comments, bool $requires_address ): DeliveryRate {
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
			Money::from_rubles( $rubles ),
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
				'api_base_price_rub' => $rubles,
				'requested_city' => (string) ( $destination['source_city'] ?? '' ),
				'jet_city_to' => $result->city_to,
				'jet_city_terminal_to' => $result->city_terminal_to,
				'jet_local_terminal' => $local_terminal ? 'yes' : 'no',
				'delivery_days_are_working' => true,
			),
			Money::from_rubles( $rubles ),
			DateRange::range( $result->day_from, $result->day_to, DateRange::UNIT_WORKING_DAYS )
		);
	}

	/** @return array<string,mixed> */
	private function destination_mapping( QuoteRequest $request ): array {
		$location_id = max( 0, (int) ( $request->customer_context['selected_location_id'] ?? $request->customer_context['location_id'] ?? 0 ) );
		if ( $location_id <= 0 ) {
			throw new JetLogisticApiException( 'Jet Logistic destination location is missing.', array( 'error_code' => 'jet_destination_location_missing' ) );
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

	private function assert_destination_city( string $requested, string $actual ): void {
		if ( $this->normalizer->normalize( $requested ) !== $this->normalizer->normalize( $actual ) ) {
			throw new JetLogisticApiException( 'Jet Logistic response destination city mismatch.', array( 'error_code' => 'jet_destination_city_mismatch' ) );
		}
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
