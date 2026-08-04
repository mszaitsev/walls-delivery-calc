<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekQuoteDiagnosticService {
	private string $receiver_warehouse_source = '';

	public function __construct(
		private LocationRepository $locations,
		private PekLocationResolver $resolver,
		private PekAddressBuilder $addresses,
		private PekSettings $settings,
		private CarrierPickupPointProviderRegistry $providers,
		private PekQuoteService $quotes
	) {
	}

	/** @param array<string,mixed> $post @return array<string,mixed> */
	public function run( array $post ): array {
		$this->receiver_warehouse_source = '';
		$location_id = max( 0, (int) ( $post['pek_quote_location_id'] ?? 0 ) );
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active ) {
			return $this->failure_report( 'pek_quote_location_missing', 'Некорректная canonical location.' );
		}
		if ( 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			return $this->failure_report( 'pek_quote_country_not_supported', 'Расчёт ПЭК на этом этапе поддерживает только RU.' );
		}
		try {
			$mapping = $this->resolver->resolve( $location_id );
			$cargo = $this->cargo_from_input( $post );
			$mode = $this->mode_from_input( $post );
			$planned = $this->planned_from_input( $post );
			$declared = $this->money_from_input( $post, 'pek_quote_declared_value_rub', 0.01, 999999999 );
			$address = $this->diagnostic_address( $location, $mapping, $post );
			$options = $this->options_for_mode( $mode, $planned, $location_id, $location, $mapping, $address, $cargo, $post );
			$request = new QuoteRequest(
				strtoupper( trim( $location->country_code ) ),
				new Address( country_code: strtoupper( trim( $location->country_code ) ), city: $location->resolved_place_name(), raw_address: $address, normalized: true ),
				new Package( array(), $declared, $declared, $cargo->weight_g, 0, $cargo->weight_g, (int) ceil( (float) $post['pek_quote_length_cm'] ), (int) ceil( (float) $post['pek_quote_width_cm'] ), (int) ceil( (float) $post['pek_quote_height_cm'] ), $cargo->volume_cm3, 'manual' ),
				'',
				$declared,
				gmdate( 'Y-m-d' ),
				array( 'selected_location_id' => $location_id, 'source' => 'pek_admin_quote_diagnostic' )
			);
			$result = $this->quotes->calculate( $request, $options );
			$report = $this->report_from_result( $result, $location_id, $location, $mapping, $mode );

			return $report;
		} catch ( \Throwable $exception ) {
			return $this->failure_report( 'pek_quote_diagnostic_failed', $this->safe_message( $exception->getMessage() ) );
		}
	}

	/** @return array{planned:string,timezone_source:string} */
	public function default_planned_datetime(): array {
		$timezone = $this->sender_timezone();
		$now = function_exists( 'current_datetime' ) ? current_datetime()->setTimezone( $timezone ) : new DateTimeImmutable( 'now', $timezone );
		$planned = $now->add( new DateInterval( 'PT1H' ) );
		$minute = (int) $planned->format( 'i' );
		$add = ( 15 - ( $minute % 15 ) ) % 15;
		if ( $add > 0 ) {
			$planned = $planned->modify( '+' . $add . ' minutes' );
		}
		$planned = $planned->setTime( (int) $planned->format( 'H' ), (int) $planned->format( 'i' ), 0 );

		return array( 'planned' => $planned->format( 'Y-m-d\TH:i:s' ), 'timezone_source' => $timezone->getName() );
	}

	/** @param array<string,mixed> $mapping @param array<string,mixed> $post */
	private function options_for_mode( string $mode, string $planned, int $location_id, mixed $location, array $mapping, string $address, PickupCargoConstraints $cargo, array $post ): PekQuoteOptions {
		if ( PekQuoteOptions::MODE_PICKUP === $mode ) {
			$warehouse = trim( (string) ( $post['pek_quote_receiver_warehouse_id'] ?? '' ) );
			$source = 'mapping_main_warehouse';
			if ( '' === $warehouse ) {
				$warehouse = trim( (string) ( $mapping['main_warehouse_id'] ?? '' ) );
			} else {
				$source = 'explicit_terminal';
				$provider = $this->providers->get( PekSettings::CARRIER_KEY );
				if ( null === $provider ) {
					throw new RuntimeException( 'PEK pickup provider is not registered.' );
				}
				$query = new CarrierPickupPointQuery( PekSettings::CARRIER_KEY, $location_id, strtoupper( trim( $location->country_code ) ), '', $location->latitude, $location->longitude, $cargo, CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, $this->settings->pek_destination_terminal_search_radius(), $this->settings->pek_destination_terminal_search_limit() );
				$point = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $warehouse ) );
				if ( null === $point || $point->code !== $warehouse ) {
					throw new RuntimeException( 'PEK receiver warehouse is not available for diagnostic cargo.' );
				}
			}
			$this->receiver_warehouse_source = $source;

			return new PekQuoteOptions( $mode, $planned, $warehouse );
		}
		$latitude = is_numeric( $location->latitude ) && is_numeric( $location->longitude ) ? (float) $location->latitude : null;
		$longitude = null !== $latitude ? (float) $location->longitude : null;
		$this->receiver_warehouse_source = 'courier';

		return new PekQuoteOptions( $mode, $planned, '', $address, $latitude, $longitude );
	}

	/** @param array<string,mixed> $post */
	private function cargo_from_input( array $post ): PickupCargoConstraints {
		$weight_kg = $this->positive_float( $post, 'pek_quote_weight_kg', 0.001, 100000 );
		$length = $this->positive_float( $post, 'pek_quote_length_cm', 0.1, 2000 );
		$width = $this->positive_float( $post, 'pek_quote_width_cm', 0.1, 2000 );
		$height = $this->positive_float( $post, 'pek_quote_height_cm', 0.1, 2000 );
		$volume = $length * $width * $height;

		return new PickupCargoConstraints( (int) ceil( $weight_kg * 1000 ), (int) ceil( $volume ), (int) ceil( max( $length, $width, $height ) ), (int) ceil( $weight_kg * 1000 ), 1 );
	}

	/** @param array<string,mixed> $post */
	private function diagnostic_address( mixed $location, array $mapping, array $post ): string {
		$override = trim( (string) ( $post['pek_quote_delivery_address'] ?? '' ) );
		if ( '' !== $override ) {
			return $this->safe_message( $override );
		}
		$normalized = trim( (string) ( $mapping['normalized_address'] ?? '' ) );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		return $this->addresses->build( $location );
	}

	/** @param array<string,mixed> $post */
	private function mode_from_input( array $post ): string {
		$mode = strtolower( trim( (string) ( $post['pek_quote_mode'] ?? PekQuoteOptions::MODE_PICKUP ) ) );
		if ( ! in_array( $mode, array( PekQuoteOptions::MODE_PICKUP, PekQuoteOptions::MODE_COURIER ), true ) ) {
			throw new RuntimeException( 'PEK quote mode is invalid.' );
		}

		return $mode;
	}

	/** @param array<string,mixed> $post */
	private function planned_from_input( array $post ): string {
		$planned = trim( (string) ( $post['pek_quote_planned_datetime'] ?? '' ) );
		if ( '' === $planned ) {
			throw new RuntimeException( 'PEK planned datetime is required.' );
		}

		return $planned;
	}

	/** @param array<string,mixed> $post */
	private function positive_float( array $post, string $key, float $min, float $max ): float {
		if ( ! isset( $post[ $key ] ) || ! is_numeric( $post[ $key ] ) ) {
			throw new RuntimeException( 'PEK quote diagnostic numeric field is required.' );
		}
		$value = (float) $post[ $key ];
		if ( ! is_finite( $value ) || $value < $min || $value > $max ) {
			throw new RuntimeException( 'PEK quote diagnostic numeric field is outside the allowed range.' );
		}

		return $value;
	}

	/** @param array<string,mixed> $post */
	private function money_from_input( array $post, string $key, float $min, float $max ): Money {
		$value = $this->positive_float( $post, $key, $min, $max );

		return Money::from_rubles( (string) $value );
	}

	/** @return array<string,mixed> */
	private function report_from_result( mixed $result, int $location_id, mixed $location, array $mapping, string $mode ): array {
		$data = $result->to_array();
		return array(
			'checked_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'success' => $result->success,
			'message' => $result->success ? 'Расчёт ПЭК успешно выполнен.' : 'Расчёт ПЭК завершился ошибкой. Подробности приведены в диагностическом отчёте.',
			'error_code' => $result->error_code,
			'error_message' => $result->error_message,
			'api_error_message' => $result->api_error_message,
			'field_errors' => $result->field_errors,
			'failure_stage' => $result->failure_stage,
			'endpoint' => $result->endpoint,
			'method' => $result->method,
			'http_status' => $result->http_status,
			'mode_location' => array(
				'mode' => $mode,
				'location_id' => $location_id,
				'country' => strtoupper( trim( $location->country_code ) ),
				'resolution_method' => (string) ( $mapping['resolution_method'] ?? '' ),
				'mapping_state' => (string) ( $mapping['mapping_state'] ?? '' ),
				'branch' => (string) ( $mapping['branch_title'] ?? '' ),
				'zone' => (string) ( $mapping['zone_name'] ?? '' ),
				'receiver_warehouse_source' => $this->receiver_warehouse_source,
			),
			'safe_request' => $data['safe_request'] ?? array(),
			'result' => array(
				'cost_total_rub' => round( $result->price_kopecks / 100, 2 ),
				'cost_total_kopecks' => $result->price_kopecks,
				'delivery_days' => $result->delivery_days,
				'sender_branch' => $result->sender_branch_title,
				'receiver_branch' => $result->receiver_branch_title,
				'services' => $result->services,
			),
			'response_shape' => $data['safe_response_meta']['response_shape'] ?? array(),
		);
	}

	/** @return array<string,mixed> */
	private function failure_report( string $code, string $message ): array {
		return array(
			'checked_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'success' => false,
			'message' => 'Расчёт ПЭК завершился ошибкой. Подробности приведены в диагностическом отчёте.',
			'error_code' => $code,
			'error_message' => $message,
			'failure_stage' => 'quote_calculator_contract',
			'endpoint' => '/calculator/calculateprice/',
			'method' => 'POST',
			'http_status' => '',
			'mode_location' => array(),
			'safe_request' => array(),
			'result' => array(),
			'response_shape' => array(),
			'field_errors' => array(),
			'api_error_message' => '',
		);
	}

	private function sender_timezone(): DateTimeZone {
		$sender = $this->settings->sender_warehouse();
		$value = trim( (string) ( $sender['branchTimezone'] ?? '' ) );
		if ( '' !== $value ) {
			try {
				return new DateTimeZone( $value );
			} catch ( \Throwable ) {
			}
		}

		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private function safe_message( string $message ): string {
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;
		$message = preg_replace( '/\s+/u', ' ', $message ) ?? $message;

		return trim( $message );
	}
}
