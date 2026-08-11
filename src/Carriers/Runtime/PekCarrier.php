<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Contracts\CarrierQuoteCacheContextProviderInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Checkout\PekCheckoutQuoteContextResolver;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuotePlannedDateTimeResolver;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResult;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
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

final class PekCarrier implements CarrierAdapterInterface, CarrierQuoteCacheContextProviderInterface {
	public const KEY = PekSettings::CARRIER_KEY;

	public function __construct(
		private PekSettings $settings,
		private PekCredentials $credentials,
		private PekCheckoutQuoteContextResolver $context_resolver,
		private PekQuoteService $quotes,
		private PekQuotePlannedDateTimeResolver $planned_datetime,
		private Logger $logger,
		private PekCountryPolicy $countries
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( self::KEY, PekSettings::TITLE, 'api', $this->credentials->is_complete() );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities(
			supports_quotes: true,
			supports_pickup_points: true,
			supports_status_sync: false,
			supports_courier_delivery: true,
			supports_pickup_delivery: true,
			supports_international: true
		);
	}

	public function supports_country( string $countryCode ): bool {
		return $this->countries->supports_receiver_country( $countryCode ) && $this->credentials->is_complete();
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		if ( ! $this->supports_country( $request->country_code ?: $request->destination->country_code ) ) {
			return $this->empty_quote( $request, 'pek_checkout_country_or_credentials_unavailable', array() );
		}
		try {
			$context = $this->context_resolver->resolve( $request );
		} catch ( PekApiException $exception ) {
			return $this->empty_quote(
				$request,
				(string) ( $exception->context()['error_code'] ?? 'pek_checkout_context_unavailable' ),
				$this->safe_checkout_exception_context( $exception )
			);
		}

		$rates = array();
		$outcomes = array();
		foreach ( array( PekQuoteOptions::MODE_PICKUP, PekQuoteOptions::MODE_COURIER ) as $mode ) {
			$outcome = $this->calculate_mode( $request, $context, $mode );
			$outcomes[ $mode ] = $outcome['diagnostic'];
			if ( $outcome['rate'] instanceof DeliveryRate ) {
				$rates[] = $outcome['rate'];
			}
		}

		return new DeliveryQuote(
			$this->quote_id( $request, $context, $outcomes ),
			self::KEY,
			$request->destination,
			$request->package,
			$rates,
			array() !== $rates,
			array() === $rates ? 'pek_checkout_quote_unavailable' : '',
			array() === $rates ? 'Расчёт ПЭК временно недоступен.' : '',
			false,
			'api',
			array(
				'modes' => $outcomes,
				'country_code' => strtoupper( trim( $request->country_code ?: $request->destination->country_code ) ),
				'direction_supported' => true,
				'location_id' => (int) ( $context['location_id'] ?? 0 ),
				'destination_fingerprint' => (string) ( $context['destination_fingerprint'] ?? '' ),
			)
		);
	}

	/** @return array<string,mixed> */
	public function quote_cache_context( QuoteRequest $request ): array {
		$selection = is_array( $request->customer_context['pickup_selections'][ PekSettings::PICKUP_FAMILY ] ?? null )
			? $request->customer_context['pickup_selections'][ PekSettings::PICKUP_FAMILY ]
			: array();
		$selection_snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$sender = $this->settings->sender_warehouse();
		$address = $request->destination;
		$full_address = trim( implode( '|', array( $address->street, $address->house, $address->apartment, $address->raw_address ) ) );
		$pricing_identity = hash( 'sha256', implode( '|', array(
			$this->settings->sender_inn() ? 'inn-present' : 'inn-missing',
			$this->settings->sender_kpp() ? 'kpp-present' : 'kpp-missing',
			$this->settings->client_card() ? 'card-present' : 'card-missing',
		) ) );

		return array(
			'pek_selected_terminal_code' => (string) ( $selection['point_code'] ?? '' ),
			'pek_destination_country' => strtoupper( trim( $request->country_code ?: $request->destination->country_code ) ),
			'pek_selection_provider_destination_fingerprint' => (string) ( $selection['provider_destination_fingerprint'] ?? $selection_snapshot['provider_destination_fingerprint'] ?? '' ),
			'pek_selection_destination_fingerprint' => (string) ( $selection['destination_fingerprint'] ?? $selection_snapshot['destination_fingerprint'] ?? '' ),
			'pek_courier_address_scope' => '' !== $full_address ? 'full_address' : 'location',
			'pek_full_courier_address_fingerprint' => '' !== $full_address ? hash( 'sha256', $full_address ) : '',
			'pek_planned_datetime_bucket' => $this->planned_datetime->resolve(),
			'pek_sender_warehouse_id' => (string) ( $sender['warehouseId'] ?? '' ),
			'pek_contract_pricing_identity' => $pricing_identity,
			'pek_bag_surcharge_kopecks' => $this->settings->light_cargo_bag_price_kopecks(),
			'pek_sealing_surcharge_kopecks' => $this->settings->light_cargo_sealing_price_kopecks(),
			'pek_light_cargo_weight_limit_g' => $this->settings->light_cargo_weight_limit_g(),
			'package_product_weight_g' => $request->package->weight_g,
			'package_packaging_weight_g' => $request->package->packaging_weight_g,
			'package_total_weight_g' => $request->package->get_total_weight_g(),
			'package_volume_cm3' => $request->package->get_total_volume_cm3(),
		);
	}

	/** @param array<string,mixed> $context @return array{rate:?DeliveryRate,diagnostic:array<string,mixed>} */
	private function calculate_mode( QuoteRequest $request, array $context, string $mode ): array {
		if ( PekQuoteOptions::MODE_PICKUP === $mode ) {
			return $this->calculate_pickup_mode( $request, $context );
		}
		$options = $mode === PekQuoteOptions::MODE_PICKUP
			? ( $context['pickup_options']['options'] ?? null )
			: ( $context['courier_options']['options'] ?? null );
		if ( ! $options instanceof PekQuoteOptions ) {
			$error = $mode === PekQuoteOptions::MODE_PICKUP && is_array( $context['pickup_options_error'] ?? null )
				? $context['pickup_options_error']
				: array( 'success' => false, 'error_code' => 'pek_checkout_' . $mode . '_options_missing' );
			return array( 'rate' => null, 'diagnostic' => $error );
		}
		$result = $this->quotes->calculate( $request, $options );
		if ( ! $result->success ) {
			$code = $mode === PekQuoteOptions::MODE_PICKUP && ! empty( $context['pickup_options']['selected'] )
				? 'pek_selected_terminal_quote_failed'
				: $result->error_code;
			$this->logger->warning(
				'PEK checkout quote mode unavailable.',
				array(
					'carrier' => self::KEY,
					'mode' => $mode,
					'error_code' => $code,
					'failure_stage' => $result->failure_stage,
					'location_id' => (int) ( $context['location_id'] ?? 0 ),
					'http_status' => $result->http_status,
					'receiver_warehouse_source' => (string) ( $context['pickup_options']['warehouse_source'] ?? '' ),
				)
			);

			return array(
				'rate' => null,
				'diagnostic' => array(
					'success' => false,
					'error_code' => $code,
					'failure_stage' => $result->failure_stage,
					'http_status' => $result->http_status,
				),
			);
		}

		return array(
			'rate' => $this->rate_from_result( $request, $result, $context ),
			'diagnostic' => array( 'success' => true, 'price_kopecks' => $result->price_kopecks ),
		);
	}

	/** @param array<string,mixed> $context @return array{rate:?DeliveryRate,diagnostic:array<string,mixed>} */
	private function calculate_pickup_mode( QuoteRequest $request, array $context ): array {
		$options = $context['pickup_options']['options'] ?? null;
		if ( ! $options instanceof PekQuoteOptions ) {
			$error = is_array( $context['pickup_options_error'] ?? null )
				? $context['pickup_options_error']
				: array( 'success' => false, 'error_code' => 'pek_checkout_pickup_options_missing' );
			$this->log_pickup_options_error( $context, $error );
			return array( 'rate' => null, 'diagnostic' => $error );
		}

		$result = $this->quotes->calculate( $request, $options );
		if ( $result->success ) {
			return array(
				'rate' => $this->rate_from_result( $request, $result, $context ),
				'diagnostic' => array( 'success' => true, 'price_kopecks' => $result->price_kopecks ),
			);
		}

		$selected = ! empty( $context['pickup_options']['selected'] );
		$code = $selected ? 'pek_selected_terminal_quote_failed' : $result->error_code;
		$selected_attempt = array(
			'success' => false,
			'error_code' => $code,
			'failure_stage' => $result->failure_stage,
			'http_status' => $result->http_status,
		);
		$this->log_pickup_failure( $context, $result, $code, $selected, false, false );

		if ( ! $selected || 'pek_selected_terminal_quote_failed' !== $code ) {
			return array( 'rate' => null, 'diagnostic' => $selected_attempt );
		}

		$preliminary_options = $context['pickup_preliminary_options']['options'] ?? null;
		if ( ! $preliminary_options instanceof PekQuoteOptions ) {
			return array(
				'rate' => null,
				'diagnostic' => array(
					'success' => false,
					'selected_attempt' => $selected_attempt,
					'recovery_attempt' => is_array( $context['pickup_preliminary_options_error'] ?? null )
						? $context['pickup_preliminary_options_error']
						: array( 'success' => false, 'error_code' => 'pek_checkout_pickup_recovery_options_missing' ),
				),
			);
		}

		$recovery_context = $context;
		$recovery_pickup = is_array( $context['pickup_preliminary_options'] ?? null ) ? $context['pickup_preliminary_options'] : array();
		$source = (string) ( $recovery_pickup['warehouse_source'] ?? 'provider_first' );
		$recovery_pickup['warehouse_source'] = str_starts_with( $source, 'recovery_' ) ? $source : 'recovery_' . $source;
		$recovery_pickup['selected'] = false;
		$recovery_context['pickup_options'] = $recovery_pickup;
		$recovery_result = $this->quotes->calculate( $request, $preliminary_options );
		if ( $recovery_result->success ) {
			$this->log_pickup_failure( $context, $result, $code, true, true, true );
			return array(
				'rate' => $this->rate_from_result( $request, $recovery_result, $recovery_context, $this->pickup_rejection_meta() ),
				'diagnostic' => array(
					'success' => true,
					'selected_attempt' => $selected_attempt,
					'recovery_attempt' => array(
						'success' => true,
						'warehouse_source' => (string) ( $recovery_pickup['warehouse_source'] ?? '' ),
						'price_kopecks' => $recovery_result->price_kopecks,
					),
				),
			);
		}

		$this->log_pickup_failure( $recovery_context, $recovery_result, $recovery_result->error_code, false, true, false );
		return array(
			'rate' => null,
			'diagnostic' => array(
				'success' => false,
				'selected_attempt' => $selected_attempt,
				'recovery_attempt' => array(
					'success' => false,
					'error_code' => $recovery_result->error_code,
					'failure_stage' => $recovery_result->failure_stage,
					'http_status' => $recovery_result->http_status,
				),
			),
		);
	}

	/** @param array<string,mixed> $context */
	private function log_pickup_options_error( array $context, array $error ): void {
		$this->logger->warning(
			'PEK checkout pickup preliminary options unavailable.',
			array(
				'carrier' => self::KEY,
				'mode' => PekQuoteOptions::MODE_PICKUP,
				'error_code' => (string) ( $error['error_code'] ?? 'pek_checkout_pickup_options_missing' ),
				'failure_stage' => (string) ( $error['failure_stage'] ?? 'checkout_context' ),
				'endpoint' => (string) ( $error['endpoint'] ?? '' ),
				'method' => (string) ( $error['method'] ?? '' ),
				'http_status' => $error['http_status'] ?? '',
				'location_id' => (int) ( $context['location_id'] ?? 0 ),
				'cache_hit' => ! empty( $error['cache_hit'] ),
				'api_source' => (string) ( $error['api_source'] ?? '' ),
			)
		);
	}

	/** @param array<string,mixed> $context */
	private function log_pickup_failure( array $context, PekQuoteResult $result, string $code, bool $selected, bool $recovery_attempted, bool $recovery_success ): void {
		$this->logger->warning(
			'PEK checkout quote mode unavailable.',
			array(
				'carrier' => self::KEY,
				'mode' => PekQuoteOptions::MODE_PICKUP,
				'selected_terminal' => $selected,
				'error_code' => $code,
				'failure_stage' => $result->failure_stage,
				'location_id' => (int) ( $context['location_id'] ?? 0 ),
				'http_status' => $result->http_status,
				'receiver_warehouse_source' => (string) ( $context['pickup_options']['warehouse_source'] ?? '' ),
				'recovery_attempted' => $recovery_attempted,
				'recovery_success' => $recovery_success,
			)
		);
	}

	/** @return array<string,mixed> */
	private function pickup_rejection_meta(): array {
		return array(
			'pickup_selection_rejected' => true,
			'pickup_selection_rejected_family' => PekSettings::PICKUP_FAMILY,
			'pickup_selection_rejected_code' => 'pek_selected_terminal_quote_failed',
			'pickup_selection_rejected_message' => 'Не удалось рассчитать доставку в выбранный пункт ПЭК. Выберите другой пункт.',
		);
	}

	/** @param array<string,mixed> $context */
	private function rate_from_result( QuoteRequest $request, PekQuoteResult $result, array $context, array $extra_meta = array() ): DeliveryRate {
		$is_pickup = PekQuoteOptions::MODE_PICKUP === $result->mode;
		$price = Money::from_kopecks( $result->price_kopecks );
		$days = DateRange::single( $result->delivery_days, DateRange::UNIT_CALENDAR_DAYS );
		$meta = array(
			'preserve_rate_title' => true,
			'delivery_days_are_working' => false,
			'service_key' => PekSettings::SERVICE_KEY,
			'api_base_price_rub' => $result->price_kopecks / 100,
			'pek_carrier_base_price_rub' => $result->carrier_price_kopecks / 100,
			'pek_carrier_price_kopecks' => $result->carrier_price_kopecks,
			'pek_bag_surcharge_kopecks' => $result->bag_surcharge_kopecks,
			'pek_sealing_surcharge_kopecks' => $result->sealing_surcharge_kopecks,
			'pek_light_cargo_surcharge_kopecks' => $result->light_cargo_surcharge_kopecks,
			'pek_surcharges' => $result->surcharges,
			'pek_sender_branch_id' => $result->sender_branch_id,
			'pek_sender_branch_title' => $result->sender_branch_title,
			'pek_receiver_branch_id' => $result->receiver_branch_id,
			'pek_receiver_branch_title' => $result->receiver_branch_title,
			'pek_quote_mode' => $result->mode,
			'pek_quote_scope' => $is_pickup ? 'receiver_warehouse' : (string) ( $context['courier_options']['scope'] ?? 'location' ),
			'pek_receiver_warehouse_id' => $is_pickup ? (string) ( $context['pickup_options']['warehouse_id'] ?? '' ) : '',
			'pek_receiver_warehouse_source' => $is_pickup ? (string) ( $context['pickup_options']['warehouse_source'] ?? '' ) : '',
			'pek_original_delivery_days' => $result->delivery_days,
			'pek_calculator_endpoint' => $result->endpoint,
			'pek_calculator_http_status' => $result->http_status,
			'requires_rate_refresh_on_pickup_selection' => $is_pickup,
		);
		$location_id = (int) ( $context['location_id'] ?? 0 );
		if ( $location_id > 0 ) {
			$meta['location_id'] = $location_id;
		}
		$destination_fingerprint = trim( (string) ( $context['destination_fingerprint'] ?? '' ) );
		if ( '' !== $destination_fingerprint ) {
			$meta['destination_fingerprint'] = $destination_fingerprint;
		}
		if ( $is_pickup ) {
			$meta['pickup_family'] = PekSettings::PICKUP_FAMILY;
			$meta['pickup_provider_query'] = is_array( $context['pickup_provider_query'] ?? null ) ? $context['pickup_provider_query'] : array();
		} else {
			$meta['pek_courier_quote_scope'] = (string) ( $context['courier_options']['scope'] ?? 'location' );
			$meta['requires_courier_address'] = 'location' === (string) ( $context['courier_options']['scope'] ?? 'location' );
		}
		$meta = array_merge( $meta, $extra_meta );

		return new DeliveryRate(
			$is_pickup ? PekSettings::PICKUP_RATE_ID : PekSettings::COURIER_RATE_ID,
			self::KEY,
			PekSettings::TITLE,
			PekSettings::SERVICE_KEY,
			PekSettings::TITLE,
			$is_pickup ? PekSettings::PICKUP_TARIFF_KEY : PekSettings::COURIER_TARIFF_KEY,
			$is_pickup ? PekSettings::PICKUP_TARIFF_NAME : PekSettings::COURIER_TARIFF_NAME,
			$is_pickup ? DeliveryType::PICKUP : DeliveryType::COURIER,
			$is_pickup ? 'ПЭК до терминала' : 'ПЭК курьером',
			$price,
			null,
			null,
			$days,
			'',
			'',
			array(),
			false,
			'',
			$is_pickup,
			! $is_pickup,
			$meta,
			$price,
			$days
		);
	}

	/** @param array<string,mixed> $context @param array<string,mixed> $outcomes */
	private function quote_id( QuoteRequest $request, array $context, array $outcomes ): string {
		return self::KEY . ':' . sha1( json_encode( array(
			'location' => (int) ( $context['location_id'] ?? 0 ),
			'destination' => (string) ( $context['destination_fingerprint'] ?? '' ),
			'pickup_warehouse' => (string) ( $context['pickup_options']['warehouse_id'] ?? '' ),
			'courier_scope' => (string) ( $context['courier_options']['scope'] ?? '' ),
			'courier_address' => (string) ( $context['courier_options']['address_fingerprint'] ?? '' ),
			'planned' => (string) ( $context['plannedDateTime'] ?? '' ),
			'package' => $request->package->to_array(),
			'settings' => $this->quote_cache_context( $request ),
			'outcomes' => array_keys( array_filter( $outcomes, static fn( array $outcome ): bool => ! empty( $outcome['success'] ) ) ),
		), JSON_UNESCAPED_UNICODE ) ?: '' );
	}

	/** @param array<string,mixed> $diagnostics */
	private function empty_quote( QuoteRequest $request, string $reason, array $diagnostics ): DeliveryQuote {
		$this->logger->warning( 'PEK checkout quote returned empty.', array_merge( array( 'carrier' => self::KEY, 'reason' => $reason ), $diagnostics ) );

		return new DeliveryQuote( self::KEY . ':' . sha1( $reason . '|' . $request->calculation_date ), self::KEY, $request->destination, $request->package, array(), false, $reason, 'Расчёт ПЭК временно недоступен.', false, 'api', array_merge( array( 'fallback_reason' => $reason ), $diagnostics ) );
	}

	/** @return array<string,mixed> */
	private function safe_checkout_exception_context( PekApiException $exception ): array {
		$context = $exception->context();
		$result = array( 'failure_stage' => (string) ( $context['failure_stage'] ?? 'checkout_context' ) );
		foreach ( array( 'location_id', 'country_code', 'mapping_state', 'resolution_method', 'precision' ) as $key ) {
			if ( is_scalar( $context[ $key ] ?? null ) ) {
				$result[ $key ] = $context[ $key ];
			}
		}
		foreach ( array( 'cache_hit', 'stale_fallback' ) as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$result[ $key ] = ! empty( $context[ $key ] );
			}
		}
		if ( is_array( $context['mapping_diagnostic'] ?? null ) ) {
			$diagnostic = array();
			foreach ( array( 'code', 'message', 'expected_country', 'actual_country', 'precision', 'state' ) as $key ) {
				if ( is_scalar( $context['mapping_diagnostic'][ $key ] ?? null ) ) {
					$diagnostic[ $key ] = (string) $context['mapping_diagnostic'][ $key ];
				}
			}
			if ( array() !== $diagnostic ) {
				$result['mapping_diagnostic'] = $diagnostic;
			}
		}

		return $result;
	}
}
