<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalRequest;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekApiClient {
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

	/** @return array<int,array<string,mixed>> */
	public function find_zone_by_coordinates( float $latitude, float $longitude ): array {
		$result = $this->call( 'POST', '/branches/findzonebycoordinates/', array( array( 'latitude' => $latitude, 'longitude' => $longitude ) ) );

		return $this->expect_list( $result, 'branches/findzonebycoordinates' );
	}

	/** @return array<string,mixed>|array<int,mixed> */
	public function find_zone_by_address( string $address ): array {
		$address = trim( $address );
		if ( '' === $address ) {
			throw new PekApiException( 'Не указан адрес для поиска зоны ПЭК.', array( 'error_code' => 'pek_empty_zone_address' ) );
		}
		$result = $this->call( 'POST', '/branches/findzonebyaddress/', array( 'address' => $address ) );
		if ( ! is_array( $result ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру зоны адреса.', array( 'error_code' => 'pek_unexpected_findzone_address' ) );
		}

		return $result;
	}

	/** @return array<string,mixed> */
	public function destination_nearest_departments( PekDestinationTerminalRequest $request ): array {
		$result = $this->call( 'POST', '/branches/nearestdepartments/', $request->to_payload() );

		return $this->expect_nearest_departments_response( $result, 'pek_unexpected_destination_nearest_departments' );
	}

	/** @param array<string,mixed> $payload */
	private function call( string $method, string $path, array $payload ): mixed {
		$method = strtoupper( trim( $method ) );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			throw new PekApiException( 'Неподдерживаемый HTTP метод ПЭК.', array( 'endpoint' => $path, 'error_code' => 'pek_invalid_http_method' ) );
		}
		if ( ! $this->credentials->is_complete() ) {
			throw new PekApiException( 'Не заданы логин или API key ПЭК.', array( 'error_code' => 'pek_credentials_missing' ) );
		}
		$this->budget->consume();
		$url = PekSettings::BASE_URL . $path;
		$response = $this->http->request( $method, $url, $this->request_args( $method, $payload ) );
		if ( ! empty( $response['error'] ) ) {
			throw new PekApiException( 'Не удалось выполнить запрос к ПЭК.', array( 'endpoint' => $path, 'error_code' => 'pek_transport_error', 'message' => $this->safe_message( (string) ( $response['message'] ?? '' ) ) ) );
		}

		$status = (int) ( $response['status'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );
		if ( 403 === $status ) {
			throw new PekApiException( 'ПЭК отклонил доступ к методу.', array( 'endpoint' => $path, 'error_code' => 'pek_http_403', 'http_status' => 403 ) );
		}
		if ( 404 === $status ) {
			throw new PekApiException( 'Метод ПЭК не найден.', array( 'endpoint' => $path, 'error_code' => 'pek_http_404', 'http_status' => 404 ) );
		}
		if ( 500 === $status ) {
			throw new PekApiException( 'ПЭК вернул внутреннюю ошибку.', array( 'endpoint' => $path, 'error_code' => 'pek_http_500', 'http_status' => 500 ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new PekApiException( 'ПЭК вернул ошибочный HTTP статус.', array( 'endpoint' => $path, 'error_code' => 'pek_http_non_2xx', 'http_status' => $status ) );
		}
		if ( '' === trim( $body ) ) {
			throw new PekApiException( 'ПЭК вернул пустой ответ.', array( 'endpoint' => $path, 'error_code' => 'pek_empty_response' ) );
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new PekApiException( 'ПЭК вернул некорректный JSON.', array( 'endpoint' => $path, 'error_code' => 'pek_invalid_json' ) );
		}
		if ( is_array( $decoded['error'] ?? null ) ) {
			$error = $decoded['error'];
			throw new PekApiException( $this->safe_message( (string) ( $error['title'] ?? 'Ошибка ПЭК' ) . ': ' . (string) ( $error['message'] ?? '' ) ), array( 'endpoint' => $path, 'error_code' => 'pek_logical_error' ) );
		}
		if ( is_array( $decoded ) && true === ( $decoded['hasError'] ?? false ) ) {
			throw new PekApiException( $this->safe_message( (string) ( $decoded['errorMessage'] ?? 'ПЭК вернул логическую ошибку.' ) ), array( 'endpoint' => $path, 'error_code' => 'pek_has_error' ) );
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
		) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру ближайших отделений.',
				array(
					'endpoint' => '/branches/nearestdepartments/',
					'error_code' => $error_code,
				)
			);
		}

		return array(
			'freeDepartments' => $value['freeDepartments'],
			'paidDepartments' => $value['paidDepartments'],
		);
	}

	private function safe_message( string $message ): string {
		$message = str_replace( array( $this->credentials->api_key(), $this->credentials->login() . ':' . $this->credentials->api_key() ), '[redacted]', $message );
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;

		return trim( $message );
	}
}
