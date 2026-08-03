<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalRequest;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekApiClient {
	/** @var array<string,mixed> */
	private array $last_response_meta = array();

	public function __construct(
		private PekSettings $settings,
		private PekCredentials $credentials,
		private PekHttpClientInterface $http,
		private PekRequestBudget $budget
	) {
	}

	/** @return array<int,array<string,mixed>> */
	public function types_of_delivery_all(): array {
		return $this->expect_list( $this->call( 'GET', '/typesOfDelivery/all/', array() ), 'typesOfDelivery/all' );
	}

	/** @return array<int,array<string,mixed>> */
	public function branches_country(): array {
		return $this->expect_list( $this->call( 'POST', '/branches/country/', array() ), 'branches/country' );
	}

	/** @return array<int,array<string,mixed>> */
	public function legal_form_types(): array {
		return $this->expect_list( $this->call( 'POST', '/counterparts/legalformtypes/', array() ), 'counterparts/legalformtypes' );
	}

	/** @return array<string,mixed> */
	public function nearest_departments( string $address, ?float $weight = null, ?float $volume = null, ?float $max_dimension = null, ?float $max_weight_per_place = null ): array {
		$payload = array(
			'address' => trim( $address ),
			'coordinates' => null,
			'weight' => $weight,
			'volume' => $volume,
			'maxDimension' => $max_dimension,
			'maxWeightPerPlace' => $max_weight_per_place,
			'departmentOperation' => 2,
			'type' => PekSettings::LTL_PRODUCT_TYPE,
			'searchRadius' => $this->settings->warehouse_search_radius(),
			'limit' => $this->settings->warehouse_search_limit(),
		);

		$result = $this->call( 'POST', '/branches/nearestdepartments/', $payload );

		return $this->expect_nearest_departments_response( $result, 'pek_unexpected_nearest_departments' );
	}

	/** @return array<string,mixed> */
	public function branches_all_for_warehouse( string $warehouse_id ): array {
		$warehouse_id = trim( $warehouse_id );
		if ( '' === $warehouse_id ) {
			throw new PekApiException( 'Не указан ID склада ПЭК.', array( 'error_code' => 'pek_empty_warehouse_id' ) );
		}

		$result = $this->call( 'POST', '/branches/all/', array( 'warehouseId' => $warehouse_id ) );
		if ( ! is_array( $result ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру справочника складов.', array( 'error_code' => 'pek_unexpected_branches_all' ) );
		}

		return $result;
	}

	/** @return array<string,mixed> */
	public function find_zone_by_coordinates( float $latitude, float $longitude ): array {
		$result = $this->call( 'POST', '/branches/findzonebycoordinates/', array( array( 'latitude' => $latitude, 'longitude' => $longitude ) ) );

		return $this->expect_find_zone_by_coordinates_response( $result );
	}

	/** @return array<string,mixed>|array<int,mixed> */
	public function find_zone_by_address( string $address ): array {
		$address = trim( $address );
		if ( '' === $address ) {
			throw new PekApiException( 'Не указан адрес для поиска зоны ПЭК.', array( 'error_code' => 'pek_empty_zone_address' ) );
		}
		$result = $this->call( 'POST', '/branches/findzonebyaddress/', array( 'address' => $address ) );

		return $this->expect_find_zone_by_address_response( $result );
	}

	/** @return array<string,mixed> */
	public function destination_nearest_departments( PekDestinationTerminalRequest $request ): array {
		$result = $this->call( 'POST', '/branches/nearestdepartments/', $request->to_payload() );

		return $this->expect_nearest_departments_response( $result, 'pek_unexpected_destination_nearest_departments' );
	}

	/** @param array<string,mixed> $payload */
	private function call( string $method, string $path, array $payload ): mixed {
		$this->last_response_meta = array();
		$method = strtoupper( trim( $method ) );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			throw new PekApiException( 'Неподдерживаемый HTTP метод ПЭК.', array( 'endpoint' => $path, 'method' => $method, 'error_code' => 'pek_invalid_http_method', 'failure_stage' => $this->failure_stage_for_path( $path, 'request' ) ) );
		}
		if ( ! $this->credentials->is_complete() ) {
			throw new PekApiException( 'Не заданы логин или API key ПЭК.', array( 'endpoint' => $path, 'method' => $method, 'error_code' => 'pek_credentials_missing', 'failure_stage' => $this->failure_stage_for_path( $path, 'request' ) ) );
		}
		$this->budget->consume();
		$url = PekSettings::BASE_URL . $path;
		$response = $this->http->request( $method, $url, $this->request_args( $method, $payload ) );
		if ( ! empty( $response['error'] ) ) {
			throw new PekApiException( 'Не удалось выполнить запрос к ПЭК.', array( 'endpoint' => $path, 'method' => $method, 'error_code' => 'pek_transport_error', 'failure_stage' => $this->failure_stage_for_path( $path, 'transport' ), 'message' => $this->safe_message( (string) ( $response['message'] ?? '' ) ) ) );
		}

		$status = (int) ( $response['status'] ?? 0 );
		$this->last_response_meta = array(
			'endpoint' => $path,
			'method' => $method,
			'http_status' => $status,
		);
		$body = (string) ( $response['body'] ?? '' );
		if ( 403 === $status ) {
			throw new PekApiException( 'ПЭК отклонил доступ к методу.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_http_403', 'failure_stage' => $this->failure_stage_for_path( $path, 'http' ) ) ) );
		}
		if ( 404 === $status ) {
			throw new PekApiException( 'Метод ПЭК не найден.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_http_404', 'failure_stage' => $this->failure_stage_for_path( $path, 'http' ) ) ) );
		}
		if ( 500 === $status ) {
			throw new PekApiException( 'ПЭК вернул внутреннюю ошибку.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_http_500', 'failure_stage' => $this->failure_stage_for_path( $path, 'http' ) ) ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new PekApiException( 'ПЭК вернул ошибочный HTTP статус.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_http_non_2xx', 'failure_stage' => $this->failure_stage_for_path( $path, 'http' ) ) ) );
		}
		if ( '' === trim( $body ) ) {
			throw new PekApiException( 'ПЭК вернул пустой ответ.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_empty_response', 'failure_stage' => $this->failure_stage_for_path( $path, 'contract' ) ) ) );
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new PekApiException( 'ПЭК вернул некорректный JSON.', array_merge( $this->last_response_meta, array( 'error_code' => 'pek_invalid_json', 'failure_stage' => $this->failure_stage_for_path( $path, 'contract' ) ) ) );
		}
		if ( is_array( $decoded['error'] ?? null ) ) {
			$error = $decoded['error'];
			throw new PekApiException( $this->safe_message( (string) ( $error['title'] ?? 'Ошибка ПЭК' ) . ': ' . (string) ( $error['message'] ?? '' ) ), array_merge( $this->last_response_meta, array( 'error_code' => 'pek_logical_error', 'failure_stage' => $this->failure_stage_for_path( $path, 'logical' ), 'response_shape' => $this->response_shape( $decoded ) ) ) );
		}
		if ( is_array( $decoded ) && true === ( $decoded['hasError'] ?? false ) ) {
			throw new PekApiException( $this->safe_message( (string) ( $decoded['errorMessage'] ?? 'ПЭК вернул логическую ошибку.' ) ), array_merge( $this->last_response_meta, array( 'error_code' => 'pek_has_error', 'failure_stage' => $this->failure_stage_for_path( $path, 'logical' ), 'response_shape' => $this->response_shape( $decoded ) ) ) );
		}

		return $decoded;
	}

	/** @param array<string,mixed> $payload */
	private function request_args( string $method, array $payload ): array {
		$args = array(
			'timeout' => $this->settings->request_timeout(),
			'sslverify' => true,
			'headers' => array(
				'Accept' => 'application/json',
				'Accept-Encoding' => 'gzip',
				'Content-Type' => 'application/json;charset=utf-8',
				'Authorization' => 'Basic ' . base64_encode( $this->credentials->login() . ':' . $this->credentials->api_key() ),
			),
		);
		if ( 'POST' === $method ) {
			$json = ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) : json_encode( $payload, JSON_UNESCAPED_UNICODE ) ) ?: '{}';
			$args['body'] = $json;
		}

		return $args;
	}

	/** @return array<int,array<string,mixed>> */
	private function expect_list( mixed $value, string $name ): array {
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру справочника.', array( 'method' => $name, 'error_code' => 'pek_unexpected_structure' ) );
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}

	/** @return array<string,mixed> */
	private function expect_find_zone_by_coordinates_response( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру зоны координат.',
				array(
					'endpoint' => '/branches/findzonebycoordinates/',
					'error_code' => 'pek_unexpected_findzone_coordinates',
					'method' => 'POST',
					'http_status' => (int) ( $this->last_response_meta['http_status'] ?? 200 ),
					'failure_stage' => 'location_resolution',
					'response_shape' => $this->response_shape( $value ),
				)
			);
		}
		if ( array() === $value ) {
			return array();
		}
		if ( 1 !== count( $value ) || ! is_array( $value[0] ) || array_is_list( $value[0] ) ) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру зоны координат.',
				array(
					'endpoint' => '/branches/findzonebycoordinates/',
					'error_code' => 'pek_unexpected_findzone_coordinates',
					'method' => 'POST',
					'http_status' => (int) ( $this->last_response_meta['http_status'] ?? 200 ),
					'failure_stage' => 'location_resolution',
					'response_shape' => $this->response_shape( $value ),
				)
			);
		}

		return $value[0];
	}

	/** @return array<string,mixed> */
	private function expect_find_zone_by_address_response( mixed $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру зоны адреса.',
				array(
					'endpoint' => '/branches/findzonebyaddress/',
					'error_code' => 'pek_unexpected_findzone_address',
					'method' => 'POST',
					'http_status' => (int) ( $this->last_response_meta['http_status'] ?? 200 ),
					'failure_stage' => 'location_resolution',
					'response_shape' => $this->response_shape( $value ),
				)
			);
		}

		return $value;
	}

	/** @return array{freeDepartments:array<int,mixed>,paidDepartments:array<int,mixed>} */
	private function expect_nearest_departments_response( mixed $value, string $error_code ): array {
		if (
			! is_array( $value )
			|| array() === $value
			|| array_values( $value ) === $value
			|| ! array_key_exists( 'freeDepartments', $value )
			|| ! array_key_exists( 'paidDepartments', $value )
			|| ! is_array( $value['freeDepartments'] )
			|| ! is_array( $value['paidDepartments'] )
			|| ! array_is_list( $value['freeDepartments'] )
			|| ! array_is_list( $value['paidDepartments'] )
		) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру ближайших отделений.',
				array(
					'endpoint' => '/branches/nearestdepartments/',
					'error_code' => $error_code,
					'method' => 'POST',
					'http_status' => (int) ( $this->last_response_meta['http_status'] ?? 200 ),
					'failure_stage' => str_contains( $error_code, 'destination' ) ? 'destination_terminal_contract' : 'unknown',
					'response_shape' => $this->response_shape( $value ),
				)
			);
		}

		return array(
			'freeDepartments' => array_values( $value['freeDepartments'] ),
			'paidDepartments' => array_values( $value['paidDepartments'] ),
		);
	}

	private function safe_message( string $message ): string {
		$message = str_replace( array( $this->credentials->api_key(), $this->credentials->login() . ':' . $this->credentials->api_key() ), '[redacted]', $message );
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;

		return trim( $message );
	}

	/** @return array<string,mixed> */
	private function response_shape( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'root_type' => get_debug_type( $value ) );
		}
		if ( array_is_list( $value ) ) {
			return array( 'root_type' => 'list', 'root_count' => count( $value ) );
		}
		$keys = array_slice( array_map( static fn( mixed $key ): string => substr( preg_replace( '/[\x00-\x1F\x7F]+/u', '', (string) $key ) ?? '', 0, 64 ), array_keys( $value ) ), 0, 30 );
		$shape = array(
			'root_type' => 'object',
			'root_keys' => $keys,
		);
		foreach ( array( 'freeDepartments' => 'free_departments', 'paidDepartments' => 'paid_departments' ) as $source_key => $prefix ) {
			$present = array_key_exists( $source_key, $value );
			$shape[ $prefix . '_present' ] = $present;
			$shape[ $prefix . '_type' ] = $present ? $this->shape_type( $value[ $source_key ] ) : 'missing';
			if ( $present && is_array( $value[ $source_key ] ) && array_is_list( $value[ $source_key ] ) ) {
				$shape[ $prefix . '_count' ] = count( $value[ $source_key ] );
			}
		}

		return $shape;
	}

	private function shape_type( mixed $value ): string {
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? 'list' : 'object';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}

		return 'unknown';
	}

	private function failure_stage_for_path( string $path, string $kind ): string {
		if ( str_contains( $path, '/branches/findzoneby' ) ) {
			return 'location_resolution';
		}
		if ( str_contains( $path, '/branches/nearestdepartments/' ) ) {
			return match ( $kind ) {
				'request' => 'destination_terminal_request',
				'transport' => 'destination_terminal_transport',
				'http' => 'destination_terminal_http',
				'logical' => 'destination_terminal_logical',
				default => 'destination_terminal_contract',
			};
		}

		return 'unknown';
	}
}
