<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

use RuntimeException;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekDestinationPickupDiagnosticService {
	public function __construct(
		private CarrierPickupPointProviderRegistry $providers,
		private LocationRepository $locations,
		private PekTerminalService $terminals,
		private PekSettings $settings,
		private ?PekCredentials $credentials = null,
		private ?Logger $logger = null
	) {
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function run( array $input ): array {
		$location_id = max( 0, (int) ( $input['pek_destination_location_id'] ?? 0 ) );
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active ) {
			return $this->failure_report( 'pek_destination_location_missing', 'Canonical location not found.', 'Canonical location not found.' );
		}
		$country = strtoupper( trim( $location->country_code ) );
		if ( ! in_array( $country, PekSettings::PLANNED_COUNTRIES, true ) ) {
			return $this->failure_report( 'pek_destination_country_unsupported', 'PEK destination diagnostics supports only planned PEK countries.', 'PEK destination diagnostics supports only planned PEK countries.' );
		}
		try {
			$cargo = $this->cargo_from_input( $input );
		} catch ( RuntimeException $exception ) {
			return $this->failure_report( 'pek_invalid_pickup_query', $exception->getMessage(), 'Некорректные параметры диагностического груза.' );
		}
		$has_coordinates = $this->has_usable_location_coordinates( $location->latitude, $location->longitude );
		$query = new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			$location_id,
			$country,
			'',
			$has_coordinates ? $location->latitude : null,
			$has_coordinates ? $location->longitude : null,
			$cargo,
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			$this->settings->pek_destination_terminal_search_radius(),
			$this->settings->pek_destination_terminal_search_limit()
		);
		$provider = $this->providers->get( PekSettings::CARRIER_KEY );
		if ( null === $provider ) {
			throw new RuntimeException( 'PEK pickup provider is not registered.' );
		}
		try {
			$points = $provider->search( $query );
		} catch ( PekApiException $exception ) {
			$terminal_report = $this->terminals->last_report();
			$mapping = is_array( $terminal_report['mapping'] ?? null ) ? $terminal_report['mapping'] : array();
			$context = $exception->context();
			$api_error_message = $this->safe_api_error_message( $exception->getMessage() );
			$report = array(
				'success' => false,
				'error_code' => (string) ( $exception->context()['error_code'] ?? $terminal_report['error_code'] ?? 'pek_destination_api_contract_failed' ),
				'api_error_message' => $api_error_message,
				'failure_stage' => (string) ( $terminal_report['failure_stage'] ?? $context['failure_stage'] ?? 'unknown' ),
				'endpoint' => (string) ( $terminal_report['endpoint'] ?? $context['endpoint'] ?? '' ),
				'method' => (string) ( $terminal_report['method'] ?? $context['method'] ?? '' ),
				'http_status' => $terminal_report['http_status'] ?? $context['http_status'] ?? '',
				'response_shape' => is_array( $terminal_report['response_shape'] ?? null ) ? $terminal_report['response_shape'] : ( is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array() ),
				'rejections' => is_array( $terminal_report['rejection_reasons'] ?? null ) ? $terminal_report['rejection_reasons'] : array(),
				'checked_at' => $this->now(),
				'location' => array(
					'location_id' => $location_id,
					'country' => $country,
					'canonical_address' => $location->resolved_display_name(),
					'coordinates_available' => $has_coordinates,
					'resolution_method' => (string) ( $mapping['resolution_method'] ?? '' ),
					'mapping_state' => (string) ( $mapping['mapping_state'] ?? '' ),
					'precision' => (string) ( $mapping['precision'] ?? '' ),
					'branch' => (string) ( $mapping['branch_title'] ?? '' ),
					'zone' => (string) ( $mapping['zone_name'] ?? '' ),
					'main_warehouse_id' => (string) ( $mapping['main_warehouse_id'] ?? '' ),
					'mapping_cache_hit' => (bool) ( $mapping['cache_hit'] ?? false ),
				),
				'terminals' => array(
					'total_returned' => (int) ( $terminal_report['total_returned'] ?? 0 ),
					'free_count' => (int) ( $terminal_report['free_count'] ?? 0 ),
					'paid_count' => (int) ( $terminal_report['paid_count'] ?? 0 ),
					'rejected_invalid' => (int) ( $terminal_report['rejected_invalid'] ?? 0 ),
					'rejected_limits' => (int) ( $terminal_report['rejected_limits'] ?? 0 ),
					'api_source' => 'api',
					'query_fingerprint' => (string) ( $terminal_report['query_fingerprint'] ?? '' ),
					'points' => array(),
				),
				'message' => 'Не удалось использовать ответ ПЭК для выбранного направления.',
			);
			$this->log_failure( $report );
			return $report;
		}
		$terminal_report = $this->terminals->last_report();
		$mapping = is_array( $terminal_report['mapping'] ?? null ) ? $terminal_report['mapping'] : array();
		$success = true === ( $terminal_report['success'] ?? false );
		$error_code = (string) ( $terminal_report['error_code'] ?? '' );
		$message = $success
			? ( array() === $points ? 'Диагностика направления ПЭК выполнена. Подходящие терминалы не найдены.' : 'Диагностика направления ПЭК выполнена.' )
			: $this->message_for_error_code( $error_code );

		$report = array(
			'success' => $success,
			'error_code' => $error_code,
			'api_error_message' => '',
			'failure_stage' => (string) ( $terminal_report['failure_stage'] ?? '' ),
			'endpoint' => (string) ( $terminal_report['endpoint'] ?? '' ),
			'method' => (string) ( $terminal_report['method'] ?? '' ),
			'http_status' => $terminal_report['http_status'] ?? '',
			'response_shape' => is_array( $terminal_report['response_shape'] ?? null ) ? $terminal_report['response_shape'] : array(),
			'rejections' => is_array( $terminal_report['rejection_reasons'] ?? null ) ? $terminal_report['rejection_reasons'] : array(),
			'checked_at' => $this->now(),
			'location' => array(
				'location_id' => $location_id,
				'country' => $country,
				'canonical_address' => $location->resolved_display_name(),
				'coordinates_available' => $has_coordinates,
				'resolution_method' => (string) ( $mapping['resolution_method'] ?? '' ),
				'mapping_state' => (string) ( $mapping['mapping_state'] ?? '' ),
				'precision' => (string) ( $mapping['precision'] ?? '' ),
				'branch' => (string) ( $mapping['branch_title'] ?? '' ),
				'zone' => (string) ( $mapping['zone_name'] ?? '' ),
				'main_warehouse_id' => (string) ( $mapping['main_warehouse_id'] ?? '' ),
				'mapping_cache_hit' => (bool) ( $mapping['cache_hit'] ?? false ),
			),
			'terminals' => array(
				'total_returned' => count( $points ),
				'free_count' => (int) ( $terminal_report['free_count'] ?? 0 ),
				'paid_count' => (int) ( $terminal_report['paid_count'] ?? 0 ),
				'rejected_invalid' => (int) ( $terminal_report['rejected_invalid'] ?? 0 ),
				'rejected_limits' => (int) ( $terminal_report['rejected_limits'] ?? 0 ),
				'api_source' => (string) ( $terminal_report['api_source'] ?? '' ),
				'query_fingerprint' => (string) ( $terminal_report['query_fingerprint'] ?? '' ),
				'points' => array_slice( array_map( static fn( $point ): array => $point->to_array(), $points ), 0, 20 ),
			),
			'message' => $message,
		);
		if ( ! $success ) {
			$this->log_failure( $report );
		}

		return $report;
	}

	/** @param array<string,mixed> $input */
	private function cargo_from_input( array $input ): PickupCargoConstraints {
		$weight_kg = $this->positive_float( $input, 'pek_destination_weight_kg', 0.001, 100000 );
		$length = $this->positive_float( $input, 'pek_destination_length_cm', 0.1, 2000 );
		$width = $this->positive_float( $input, 'pek_destination_width_cm', 0.1, 2000 );
		$height = $this->positive_float( $input, 'pek_destination_height_cm', 0.1, 2000 );
		$max_place_kg = $this->positive_float( $input, 'pek_destination_max_place_weight_kg', 0.001, 100000 );
		$places = $this->positive_int( $input, 'pek_destination_places_count', 1, 1000 );
		$total_volume = $length * $width * $height * $places;
		if ( $total_volume > 1000000000 ) {
			throw new RuntimeException( 'Diagnostic cargo volume is too large.' );
		}

		return new PickupCargoConstraints(
			(int) ceil( $weight_kg * 1000 ),
			(int) ceil( $total_volume ),
			(int) ceil( max( $length, $width, $height ) ),
			(int) ceil( $max_place_kg * 1000 ),
			$places
		);
	}

	/** @param array<string,mixed> $input */
	private function positive_float( array $input, string $key, float $min, float $max ): float {
		if ( ! array_key_exists( $key, $input ) || ! is_numeric( $input[ $key ] ) ) {
			throw new RuntimeException( 'Diagnostic cargo field is required and must be numeric.' );
		}
		$value = (float) $input[ $key ];
		if ( ! is_finite( $value ) || $value <= 0 || $value < $min || $value > $max ) {
			throw new RuntimeException( 'Diagnostic cargo field is outside the allowed range.' );
		}

		return $value;
	}

	/** @param array<string,mixed> $input */
	private function positive_int( array $input, string $key, int $min, int $max ): int {
		if ( ! array_key_exists( $key, $input ) || ! is_numeric( $input[ $key ] ) ) {
			throw new RuntimeException( 'Diagnostic cargo places count is required and must be numeric.' );
		}
		$value = (float) $input[ $key ];
		if ( ! is_finite( $value ) || floor( $value ) !== $value || $value < $min || $value > $max ) {
			throw new RuntimeException( 'Diagnostic cargo places count is outside the allowed range.' );
		}

		return (int) $value;
	}

	/** @return array<string,mixed> */
	private function failure_report( string $error_code, string $error, string $message ): array {
		return array(
			'success' => false,
			'error_code' => $error_code,
			'api_error_message' => '',
			'failure_stage' => 'destination_terminal_request',
			'endpoint' => '',
			'method' => '',
			'http_status' => '',
			'response_shape' => array(),
			'rejections' => array(),
			'checked_at' => $this->now(),
			'location' => array(),
			'terminals' => array( 'total_returned' => 0, 'free_count' => 0, 'paid_count' => 0, 'rejected_invalid' => 0, 'rejected_limits' => 0, 'api_source' => '', 'query_fingerprint' => '', 'points' => array() ),
			'errors' => array( $error ),
			'message' => $message,
		);
	}

	private function message_for_error_code( string $error_code ): string {
		return match ( $error_code ) {
			'pek_destination_location_unsupported' => 'ПЭК не подтвердил обслуживание выбранного населённого пункта.',
			'pek_invalid_pickup_query', 'pek_canonical_location_required' => 'Некорректные параметры диагностического груза.',
			'pek_destination_country_mismatch' => 'Страна запроса не совпадает с canonical location.',
			'pek_destination_mapping_incomplete' => 'ПЭК не вернул достаточные данные направления для поиска терминалов.',
			default => 'Не удалось выполнить диагностику направления ПЭК.',
		};
	}

	private function has_usable_location_coordinates( mixed $latitude, mixed $longitude ): bool {
		if ( ! is_numeric( $latitude ) || ! is_numeric( $longitude ) ) {
			return false;
		}
		$latitude = (float) $latitude;
		$longitude = (float) $longitude;

		return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/** @param array<string,mixed> $report */
	private function log_failure( array $report ): void {
		if ( null === $this->logger ) {
			return;
		}
		$location = is_array( $report['location'] ?? null ) ? $report['location'] : array();
		$terminals = is_array( $report['terminals'] ?? null ) ? $report['terminals'] : array();
		$this->logger->error(
			'PEK destination pickup diagnostic failed.',
			array(
				'carrier' => 'pek',
				'location_id' => (int) ( $location['location_id'] ?? 0 ),
				'country_code' => (string) ( $location['country'] ?? '' ),
				'failure_stage' => (string) ( $report['failure_stage'] ?? '' ),
				'error_code' => (string) ( $report['error_code'] ?? '' ),
				'api_error_message' => (string) ( $report['api_error_message'] ?? '' ),
				'endpoint' => (string) ( $report['endpoint'] ?? '' ),
				'method' => (string) ( $report['method'] ?? '' ),
				'http_status' => $report['http_status'] ?? '',
				'response_shape' => is_array( $report['response_shape'] ?? null ) ? $report['response_shape'] : array(),
				'rejected_invalid' => (int) ( $terminals['rejected_invalid'] ?? 0 ),
				'rejected_limits' => (int) ( $terminals['rejected_limits'] ?? 0 ),
				'rejection_reasons' => is_array( $report['rejections'] ?? null ) ? $report['rejections'] : array(),
			)
		);
	}

	private function safe_api_error_message( string $message ): string {
		$message = trim( $message );
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;
		$message = preg_replace( '/\s+/u', ' ', $message ) ?? $message;
		$message = trim( $this->redact_api_error_message( $message ) );
		if ( '' === $message || ':' === $message ) {
			return 'ПЭК вернул ошибку без безопасного описания.';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 500 );
		} else {
			$message = substr( $message, 0, 500 );
		}

		return '' !== trim( $message ) ? trim( $message ) : 'ПЭК вернул ошибку без безопасного описания.';
	}

	private function redact_api_error_message( string $message ): string {
		if ( null !== $this->credentials ) {
			$api_key = trim( $this->credentials->api_key() );
			$login = trim( $this->credentials->login() );
			if ( '' !== $login && '' !== $api_key ) {
				$message = str_replace( $login . ':' . $api_key, '[redacted]', $message );
			}
			foreach ( array( $api_key, $login ) as $secret ) {
				if ( '' !== $secret ) {
					$message = str_replace( $secret, '[redacted]', $message );
				}
			}
		}
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		$message = preg_replace( '/([?&](?:api_key|apikey|token|password|authorization|login)=)[^&\s]+/i', '$1[redacted]', $message ) ?? $message;
		$message = preg_replace( '/\b(api_key|apikey|token|password|authorization|login)\s*[:=]\s*["\']?[^"\'\s,;&]+/i', '$1=[redacted]', $message ) ?? $message;

		return $message;
	}
}
