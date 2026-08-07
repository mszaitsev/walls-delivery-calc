<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalRequest;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekApiClient {
	private const MAX_FIELD_ERRORS = 20;
	private const MAX_FIELD_MESSAGES = 5;
	private const MAX_TOTAL_FIELD_MESSAGES = 50;

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
	public function nearest_departments( string $address, ?float $weight = null, ?float $volume = null, ?float $max_dimension = null, ?float $max_weight_per_place = null, ?int $search_radius = null, ?int $limit = null ): array {
		$payload = array(
			'address' => trim( $address ),
			'coordinates' => null,
			'weight' => $weight,
			'volume' => $volume,
			'maxDimension' => $max_dimension,
			'maxWeightPerPlace' => $max_weight_per_place,
			'departmentOperation' => 2,
			'type' => PekSettings::LTL_PRODUCT_TYPE,
			'searchRadius' => null !== $search_radius ? max( 1, min( 500, $search_radius ) ) : $this->settings->warehouse_search_radius(),
			'limit' => null !== $limit ? max( 1, min( 50, $limit ) ) : $this->settings->warehouse_search_limit(),
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
	public function branches_all(): array {
		$result = $this->call( 'POST', '/branches/all/', array() );
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

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function calculate_price( array $payload ): array {
		$result = $this->call( 'POST', '/calculator/calculateprice/', $payload );
		if ( ! is_array( $result ) || array() === $result || array_is_list( $result ) ) {
			throw new PekApiException(
				'ПЭК вернул неожиданную структуру расчёта стоимости.',
				array(
					'endpoint' => '/calculator/calculateprice/',
					'error_code' => 'pek_unexpected_calculate_price_response',
					'method' => 'POST',
					'http_status' => (int) ( $this->last_response_meta['http_status'] ?? 200 ),
					'failure_stage' => 'quote_calculator_contract',
					'response_shape' => $this->response_shape( $result ),
				)
			);
		}

		return $result;
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function preregistration_submit( array $payload ): array {
		$result = $this->call( 'POST', '/preregistration/submit/', $payload );
		if ( ! is_array( $result ) || array() === $result || array_is_list( $result ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру создания заявки.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_preregistration_submit', 'failure_stage' => 'shipment_create_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @param array<int,string> $cargo_codes @return array<string,mixed> */
	public function cargo_status( array $cargo_codes ): array {
		$result = $this->call( 'POST', '/cargos/status/', array( 'cargoCodes' => $this->cargo_codes( $cargo_codes, 15 ) ) );
		if ( ! is_array( $result ) || ! isset( $result['cargos'] ) || ! is_array( $result['cargos'] ) || ! array_is_list( $result['cargos'] ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру статуса груза.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_cargos_status', 'failure_stage' => 'shipment_status_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @param array<int,string> $cargo_codes @return array<string,mixed> */
	public function cargo_basic_status( array $cargo_codes ): array {
		$result = $this->call( 'POST', '/cargos/basicstatus/', array( 'cargoCodes' => $this->cargo_codes( $cargo_codes, 50 ) ) );
		if ( ! is_array( $result ) || ! isset( $result['cargos'] ) || ! is_array( $result['cargos'] ) || ! array_is_list( $result['cargos'] ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру базового статуса груза.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_cargos_basicstatus', 'failure_stage' => 'shipment_status_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @param array<int,string> $cargo_codes @return array<string,mixed> */
	public function cargo_current_status( array $cargo_codes ): array {
		$result = $this->call( 'POST', '/cargos/currentstatus/', array( 'cargoCodes' => $this->cargo_codes( $cargo_codes, 50 ) ) );
		if ( ! is_array( $result ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру текущего статуса груза.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_cargos_currentstatus', 'failure_stage' => 'shipment_status_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @param array<int,string> $cargo_codes @return array<int,array<string,mixed>> */
	public function cargo_status_full_history( array $cargo_codes ): array {
		$result = $this->call( 'POST', '/cargos/statusfullhistory/', array( 'cargoCodes' => $this->cargo_codes( $cargo_codes, 15 ) ) );

		return $this->expect_list( $result, 'cargos/statusfullhistory' );
	}

	/** @return array<int,array<string,mixed>> */
	public function cargo_status_tables(): array {
		return $this->expect_list( $this->call( 'POST', '/cargos/statustables/', array() ), 'cargos/statustables' );
	}

	public function order_print( string $cargo_code, string $type ): string {
		$result = $this->call( 'POST', '/order/print/', array( 'cargoIndex' => $this->cargo_code( $cargo_code ), 'type' => $this->document_type( $type ) ) );
		if ( is_string( $result ) && '' !== trim( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && 1 === count( $result ) ) {
			$value = reset( $result );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		throw new PekApiException( 'ПЭК вернул неожиданную структуру документа.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_order_print', 'failure_stage' => 'shipment_document_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
	}

	/** @param array<int,string> $cargo_codes @return array<int,array<string,mixed>> */
	public function order_cancellation( array $cargo_codes ): array {
		return $this->expect_object_list( $this->call( 'POST', '/order/cancellation/', $this->cargo_codes( $cargo_codes, 50 ) ), 'order/cancellation', 'shipment_cancellation_contract' );
	}

	/** @return array<int,array<string,mixed>> */
	public function check_no_calc_services( string $sender_branch_id, string $receiver_branch_id ): array {
		return $this->expect_object_list( $this->call( 'POST', '/branches/checknocalcservices/', array( 'branchSenderId' => $this->required_string( $sender_branch_id, 'branchSenderId' ), 'branchReceiverId' => $this->required_string( $receiver_branch_id, 'branchReceiverId' ) ) ), 'branches/checknocalcservices', 'sms_geography_contract' );
	}

	/** @return array<string,mixed> */
	public function create_private_access_token(): array {
		$result = $this->call( 'POST', '/auth/createtokentoaccessprivatedata/', array() );
		if (
			! is_array( $result )
			|| array_is_list( $result )
			|| ! $this->valid_private_access_token_response( $result )
		) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру private token.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_private_token', 'failure_stage' => 'private_token_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	public function confirmed_counterparties( string $access_token ): array {
		return $this->expect_object_list( $this->call( 'POST', '/counterparts/confirmedaccesstocounterparties/', array( 'access_token' => $this->required_string( $access_token, 'access_token' ) ) ), 'counterparts/confirmedaccesstocounterparties', 'counterpart_contract' );
	}

	/** @return array<string,mixed> */
	public function connected_services( string $access_token, string $counterpart_guid ): array {
		$result = $this->call( 'POST', '/counterparts/connecteddiscountsservicesagreements/', array( 'access_token' => $this->required_string( $access_token, 'access_token' ), 'counterpartGUID' => $this->required_string( $counterpart_guid, 'counterpartGUID' ) ) );
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру подключённых сервисов.', array_merge( $this->last_response_meta(), array( 'error_code' => 'pek_unexpected_connected_services', 'failure_stage' => 'counterpart_services_contract', 'response_shape' => $this->response_shape( $result ) ) ) );
		}

		return $result;
	}

	/** @return array{endpoint:string,method:string,http_status:int|string} */
	public function last_response_meta(): array {
		$endpoint = is_string( $this->last_response_meta['endpoint'] ?? null ) ? $this->last_response_meta['endpoint'] : '';
		$method = is_string( $this->last_response_meta['method'] ?? null ) ? $this->last_response_meta['method'] : '';
		$status = $this->last_response_meta['http_status'] ?? '';
		if ( '' !== $status && ( ! is_int( $status ) || $status < 100 || $status > 599 ) ) {
			$status = '';
		}

		return array(
			'endpoint' => $endpoint,
			'method' => $method,
			'http_status' => $status,
		);
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
			throw new PekApiException( $this->safe_message( $this->logical_error_message( $error ) ), array_merge( $this->last_response_meta, array( 'error_code' => 'pek_logical_error', 'failure_stage' => $this->failure_stage_for_path( $path, 'logical' ), 'response_shape' => $this->response_shape( $decoded ), 'field_errors' => $this->extract_safe_field_errors( $error ) ) ) );
		}
		if ( '/calculator/calculateprice/' !== $path && is_array( $decoded ) && true === ( $decoded['hasError'] ?? false ) ) {
			$error_message = $this->api_error_part( $decoded['errorMessage'] ?? null );
			throw new PekApiException( $this->safe_message( '' !== $error_message ? $error_message : 'ПЭК вернул логическую ошибку.' ), array_merge( $this->last_response_meta, array( 'error_code' => 'pek_has_error', 'failure_stage' => $this->failure_stage_for_path( $path, 'logical' ), 'response_shape' => $this->response_shape( $decoded ) ) ) );
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

	/** @return array<int,array<string,mixed>> */
	private function expect_object_list( mixed $value, string $name, string $failure_stage ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new PekApiException( 'ПЭК вернул неожиданную структуру списка.', array_merge( $this->last_response_meta(), array( 'method' => $name, 'error_code' => 'pek_unexpected_list_structure', 'failure_stage' => $failure_stage, 'response_shape' => $this->response_shape( $value ) ) ) );
		}
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw new PekApiException( 'ПЭК вернул неожиданную структуру списка.', array_merge( $this->last_response_meta(), array( 'method' => $name, 'error_code' => 'pek_unexpected_list_row', 'failure_stage' => $failure_stage, 'response_shape' => $this->response_shape( $value ) ) ) );
			}
		}

		return $value;
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
		$message = preg_replace( '/"access_token"\s*:\s*"[^"]+"/i', '"access_token":"[redacted]"', $message ) ?? $message;
		$message = preg_replace( '/"token_type"\s*:\s*"[^"]+"/i', '"token_type":"[redacted]"', $message ) ?? $message;

		return trim( $message );
	}

	/** @param array<string,mixed> $error */
	private function logical_error_message( array $error ): string {
		$title = $this->api_error_part( $error['title'] ?? null );
		$message = $this->api_error_part( $error['message'] ?? null );
		if ( '' !== $title && '' !== $message ) {
			return $title . ': ' . $message;
		}
		if ( '' !== $title ) {
			return $title;
		}
		if ( '' !== $message ) {
			return $message;
		}

		return 'ПЭК вернул логическую ошибку без описания.';
	}

	private function api_error_part( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? (string) $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	/** @param array<string,mixed> $error @return array<int,array{field:string,messages:array<int,string>}> */
	private function extract_safe_field_errors( array $error ): array {
		if ( ! array_key_exists( 'fields', $error ) || ! is_array( $error['fields'] ) ) {
			return array();
		}
		$fields = $error['fields'];
		$result = array();
		$index_by_field = array();
		$total_messages = 0;
		if ( array_is_list( $fields ) ) {
			foreach ( $fields as $item ) {
				if ( ! is_array( $item ) || array_is_list( $item ) ) {
					continue;
				}
				$this->append_field_error( $result, $index_by_field, $total_messages, $item['Key'] ?? null, $item['Value'] ?? null );
				if ( count( $result ) >= self::MAX_FIELD_ERRORS || $total_messages >= self::MAX_TOTAL_FIELD_MESSAGES ) {
					break;
				}
			}
		} else {
			foreach ( $fields as $field => $messages ) {
				$this->append_field_error( $result, $index_by_field, $total_messages, is_string( $field ) ? $field : null, $messages );
				if ( count( $result ) >= self::MAX_FIELD_ERRORS || $total_messages >= self::MAX_TOTAL_FIELD_MESSAGES ) {
					break;
				}
			}
		}

		return $result;
	}

	/** @param array<int,array{field:string,messages:array<int,string>}> $result @param array<string,int> $index_by_field */
	private function append_field_error( array &$result, array &$index_by_field, int &$total_messages, mixed $field, mixed $messages ): void {
		if ( ! is_string( $field ) ) {
			return;
		}
		$field = $this->safe_field_name( $field );
		$messages = $this->safe_field_messages( $messages );
		if ( array() === $messages ) {
			return;
		}
		if ( ! array_key_exists( $field, $index_by_field ) ) {
			if ( count( $result ) >= self::MAX_FIELD_ERRORS ) {
				return;
			}
			$index_by_field[ $field ] = count( $result );
			$result[] = array( 'field' => $field, 'messages' => array() );
		}
		$index = $index_by_field[ $field ];
		foreach ( $messages as $message ) {
			if ( $total_messages >= self::MAX_TOTAL_FIELD_MESSAGES || count( $result[ $index ]['messages'] ) >= self::MAX_FIELD_MESSAGES ) {
				break;
			}
			if ( in_array( $message, $result[ $index ]['messages'], true ) ) {
				continue;
			}
			$result[ $index ]['messages'][] = $message;
			++$total_messages;
		}
	}

	private function safe_field_name( string $value ): string {
		$value = $this->safe_error_text( $value, 100, '' );

		return '' !== $value ? $value : 'unknown_field';
	}

	/** @return array<int,string> */
	private function safe_field_messages( mixed $value ): array {
		$values = is_array( $value ) && array_is_list( $value ) ? $value : array( $value );
		$messages = array();
		foreach ( $values as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$message = $this->safe_error_text( $item, 500, 'ПЭК вернул ошибку поля без безопасного описания.' );
			if ( '' === $message ) {
				continue;
			}
			$messages[] = $message;
			if ( count( $messages ) >= self::MAX_FIELD_MESSAGES ) {
				break;
			}
		}

		return array_values( array_unique( $messages ) );
	}

	private function safe_error_text( string $value, int $max_length, string $fallback ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $this->redact_error_text( $value ) );
		if ( '' === $value ) {
			return $fallback;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max_length );
		} else {
			$value = substr( $value, 0, $max_length );
		}

		return trim( $value );
	}

	private function redact_error_text( string $message ): string {
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
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		$message = preg_replace( '/([?&])(?:api_key|apikey|token|password|authorization|login)=[^&\s]+/i', '$1[redacted]', $message ) ?? $message;
		$message = preg_replace( '/\b(?:api_key|apikey|token|password|authorization|login)\s*[:=]\s*["\']?[^"\'\s,;&]+/i', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/"access_token"\s*:\s*"[^"]+"/i', '"access_token":"[redacted]"', $message ) ?? $message;
		$message = preg_replace( '/"token_type"\s*:\s*"[^"]+"/i', '"token_type":"[redacted]"', $message ) ?? $message;

		return $message;
	}

	/** @param array<int,string> $codes @return array<int,string> */
	private function cargo_codes( array $codes, int $limit ): array {
		$result = array();
		foreach ( $codes as $code ) {
			$result[] = $this->cargo_code( $code );
			if ( count( $result ) >= $limit ) {
				break;
			}
		}
		if ( array() === $result ) {
			throw new PekApiException( 'Не указан код груза ПЭК.', array( 'error_code' => 'pek_empty_cargo_codes' ) );
		}

		return $result;
	}

	private function cargo_code( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 64 || 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $value ) ) {
			throw new PekApiException( 'Некорректный код груза ПЭК.', array( 'error_code' => 'pek_invalid_cargo_code' ) );
		}

		return $value;
	}

	private function document_type( string $value ): string {
		$value = trim( $value );
		if ( ! in_array( $value, array( 'big', 'simple', 'multiple' ), true ) ) {
			throw new PekApiException( 'Некорректный тип документа ПЭК.', array( 'error_code' => 'pek_invalid_document_type' ) );
		}

		return $value;
	}

	private function required_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new PekApiException( 'Не заполнен обязательный параметр ПЭК.', array( 'error_code' => 'pek_required_field_missing', 'field' => $field ) );
		}

		return $value;
	}

	/** @param array<string,mixed> $result */
	private function valid_private_access_token_response( array $result ): bool {
		$token = $result['access_token'] ?? null;
		if ( ! is_string( $token ) || '' === trim( $token ) || strlen( trim( $token ) ) > 8192 || 1 === preg_match( '/[\x00-\x1F\x7F]/', trim( $token ) ) ) {
			return false;
		}
		$type = $result['token_type'] ?? null;
		if ( ! is_string( $type ) || 'bearer' !== strtolower( trim( $type ) ) ) {
			return false;
		}
		$unix = $result['expires_in_unix'] ?? null;
		if ( is_int( $unix ) ) {
			return $unix > 0;
		}
		if ( is_string( $unix ) ) {
			$value = trim( $unix );
			return 1 === preg_match( '/^\d+$/', $value )
				&& strlen( $value ) <= strlen( (string) PHP_INT_MAX )
				&& strcmp( str_pad( $value, strlen( (string) PHP_INT_MAX ), '0', STR_PAD_LEFT ), (string) PHP_INT_MAX ) <= 0
				&& (int) $value > 0;
		}
		if ( null !== $unix ) {
			return false;
		}
		$text = $result['expires_in'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return false;
		}
		$text = trim( $text );
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\ZH:i:s', $text, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();

		return $date instanceof \DateTimeImmutable
			&& $date->format( 'Y-m-d\ZH:i:s' ) === $text
			&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
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
		if ( str_contains( $path, '/calculator/calculateprice/' ) ) {
			return match ( $kind ) {
				'transport' => 'quote_calculator_transport',
				'http' => 'quote_calculator_http',
				'logical' => 'quote_calculator_logical',
				default => 'quote_calculator_contract',
			};
		}
		if ( str_contains( $path, '/preregistration/submit/' ) ) {
			return match ( $kind ) {
				'request' => 'shipment_create_request',
				'transport' => 'shipment_create_transport',
				'http' => 'shipment_create_http',
				'logical' => 'shipment_create_logical',
				default => 'shipment_create_contract',
			};
		}
		foreach ( array(
			'/cargos/status/' => 'shipment_status',
			'/cargos/basicstatus/' => 'shipment_status_basic',
			'/cargos/currentstatus/' => 'shipment_status_current',
			'/order/print/' => 'shipment_document',
			'/order/cancellation/' => 'shipment_cancellation',
			'/auth/createtokentoaccessprivatedata/' => 'private_token',
			'/counterparts/connecteddiscountsservicesagreements/' => 'counterpart_services',
			'/counterparts/confirmedaccesstocounterparties/' => 'counterpart_confirmed',
			'/branches/checknocalcservices/' => 'sms_geography',
		) as $needle => $prefix ) {
			if ( str_contains( $path, $needle ) ) {
				return match ( $kind ) {
					'request' => $prefix . '_request',
					'transport' => $prefix . '_transport',
					'http' => $prefix . '_http',
					'logical' => $prefix . '_logical',
					default => $prefix . '_contract',
				};
			}
		}

		return 'unknown';
	}
}
