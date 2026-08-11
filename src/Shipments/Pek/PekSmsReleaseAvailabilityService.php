<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteMessageSanitizer;
use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class PekSmsReleaseAvailabilityService {
	public const SMS_SERVICE_UID = 'ffb40421-4761-11e8-80c9-00155d668927';
	private const PUBLIC_FAILURE = 'Не удалось подтвердить возможность выдачи груза по СМС.';

	/** @var array<string,PekSmsReleaseResult> */
	private array $cache = array();
	private string $active_private_token = '';

	public function __construct(
		private PekApiClient $api,
		private PekPrivateAccessTokenService $tokens,
		private PekSettings $settings,
		private PekQuoteMessageSanitizer $sanitizer
	) {
	}

	public function check( string $counterpart_guid, string $sender_branch_id, string $receiver_branch_id, int $declared_value_kopecks ): PekSmsReleaseResult {
		$key = hash( 'sha256', implode( '|', array( $counterpart_guid, $sender_branch_id, $receiver_branch_id, (string) $declared_value_kopecks ) ) );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		try {
			$geography = $this->api->check_no_calc_services( $sender_branch_id, $receiver_branch_id );
		} catch ( PekApiException $exception ) {
			return $this->cache[ $key ] = $this->fail( $this->api_diagnostic( 'sms_geography', $exception ) );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'error', 'sms_geography', 'sms_geography_unexpected' ) );
		}

		try {
			if ( ! $this->has_sms_geography( $geography ) ) {
				return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'unavailable', 'sms_business_unavailable', 'sms_geography_not_available', array( 'geography_check_completed' => true ) ), 'Выдача груза по СМС недоступна для выбранного направления или параметров груза.' );
			}
		} catch ( PekApiException $exception ) {
			return $this->cache[ $key ] = $this->fail( $this->contract_diagnostic( 'sms_geography', $exception, array( 'geography_check_completed' => true ) ) );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'error', 'sms_geography', 'sms_geography_unexpected', array( 'geography_check_completed' => true ) ) );
		}

		try {
			$token = $this->tokens->token();
			$this->active_private_token = $token;
		} catch ( PekApiException $exception ) {
			return $this->cache[ $key ] = $this->fail( $this->api_diagnostic( 'sms_private_token', $exception, array( 'geography_check_completed' => true ) ) );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'error', 'sms_private_token', 'sms_private_token_unexpected', array( 'geography_check_completed' => true ) ) );
		}

		try {
			$services = $this->api->connected_services( $token, $counterpart_guid );
		} catch ( PekApiException $exception ) {
			return $this->cache[ $key ] = $this->fail( $this->api_diagnostic( 'sms_connected_services', $exception, array( 'geography_check_completed' => true, 'private_token_acquired' => true ) ) );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'error', 'sms_connected_services', 'sms_connected_services_unexpected', array( 'geography_check_completed' => true, 'private_token_acquired' => true ) ) );
		}

		try {
			if ( ! in_array( PekSettings::LTL_PRODUCT_TYPE, $this->available_types( $services ), true ) ) {
				return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'unavailable', 'sms_business_unavailable', 'sms_ltl_type_not_available', array( 'geography_check_completed' => true, 'private_token_acquired' => true, 'connected_services_checked' => true ) ), 'Выдача груза по СМС недоступна для выбранного направления или параметров груза.' );
			}
			$api_limit = $this->api_limit_kopecks( $services, $sender_branch_id, $receiver_branch_id );
		} catch ( PekApiException $exception ) {
			$code = (string) ( $exception->context()['error_code'] ?? '' );
			if ( 'pek_sms_service_absent' === $code ) {
				return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'unavailable', 'sms_business_unavailable', $code, array( 'geography_check_completed' => true, 'private_token_acquired' => true, 'connected_services_checked' => true, 'sms_service_row_found' => false, 'sms_service_row_count' => 0 ) ), 'Выдача груза по СМС недоступна для выбранного направления или параметров груза.' );
			}
			return $this->cache[ $key ] = $this->fail( $this->contract_diagnostic( $this->stage_for_contract_code( $code ), $exception, array( 'geography_check_completed' => true, 'private_token_acquired' => true, 'connected_services_checked' => true ) ) );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail( $this->diagnostic( 'error', 'sms_service_contract', 'sms_service_unexpected', array( 'geography_check_completed' => true, 'private_token_acquired' => true, 'connected_services_checked' => true ) ) );
		}

		$configured = $this->settings->sms_release_limit_rub() * 100;
		$effective = min( $configured, $api_limit );
		if ( $effective <= 0 || $declared_value_kopecks <= 0 || $declared_value_kopecks > $effective ) {
			return $this->cache[ $key ] = $this->fail(
				$this->diagnostic(
					'unavailable',
					'sms_business_unavailable',
					'declared_value_exceeds_sms_limit',
					array(
						'geography_check_completed' => true,
						'private_token_acquired' => true,
						'connected_services_checked' => true,
						'sms_service_row_found' => true,
						'cod_max_sum_present' => true,
						'declared_value_within_limit' => false,
						'declared_value_kopecks' => max( 0, $declared_value_kopecks ),
						'effective_limit_kopecks' => max( 0, $effective ),
					)
				),
				'Выдача груза по СМС недоступна для выбранного направления или параметров груза.'
			);
		}

		return $this->cache[ $key ] = new PekSmsReleaseResult(
			true,
			$effective,
			true,
			true,
			'',
			$this->diagnostic(
				'success',
				'completed',
				'',
				array(
					'geography_check_completed' => true,
					'private_token_acquired' => true,
					'connected_services_checked' => true,
					'sms_service_row_found' => true,
					'sms_service_row_count' => 1,
					'cod_max_sum_present' => true,
					'declared_value_within_limit' => true,
				)
			)
		);
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function has_sms_geography( array $rows ): bool {
		$matches = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || ! is_array( $row['specialCondition'] ?? null ) || array_is_list( $row['specialCondition'] ) || ! is_string( $row['specialCondition']['UID'] ?? null ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_geography_malformed', 'failure_stage' => 'sms_geography' ) );
			}
			if ( strtolower( self::SMS_SERVICE_UID ) === strtolower( trim( $row['specialCondition']['UID'] ) ) ) {
				++$matches;
			}
		}

		if ( $matches > 1 ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_geography_duplicate', 'failure_stage' => 'sms_geography' ) );
		}

		return 1 === $matches;
	}

	/** @param array<string,mixed> $services */
	/** @return array<int,int> */
	private function available_types( array $services ): array {
		if ( ! array_key_exists( 'availableTypesOfDelivery', $services ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_missing' ) );
		}
		$types = $services['availableTypesOfDelivery'];
		if ( ! is_array( $types ) || ! array_is_list( $types ) || array() === $types ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_malformed' ) );
		}
		$result = array();
		foreach ( $types as $type ) {
			if ( ! is_int( $type ) || $type <= 0 ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_malformed' ) );
			}
			$result[] = $type;
		}

		return array_values( array_unique( $result ) );
	}

	/** @param array<string,mixed> $services */
	private function api_limit_kopecks( array $services, string $sender_branch_id, string $receiver_branch_id ): int {
		unset( $sender_branch_id, $receiver_branch_id );
		if ( ! array_key_exists( 'specialConditionsWithParams', $services ) || ! is_array( $services['specialConditionsWithParams'] ) || ! array_is_list( $services['specialConditionsWithParams'] ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_special_conditions_malformed' ) );
		}
		$rows = $services['specialConditionsWithParams'];
		$matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || ! is_array( $row['specialCondition'] ?? null ) || array_is_list( $row['specialCondition'] ) || ! is_string( $row['specialCondition']['UID'] ?? null ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_special_conditions_malformed' ) );
			}
			if ( strtolower( self::SMS_SERVICE_UID ) !== strtolower( $row['specialCondition']['UID'] ) ) {
				continue;
			}
			$matches[] = $row;
		}
		if ( 1 !== count( $matches ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 0 === count( $matches ) ? 'pek_sms_service_absent' : 'pek_sms_service_duplicate' ) );
		}

		return $this->cod_limit_from_sms_row( $matches[0] );
	}

	/** @param array<string,mixed> $row */
	private function cod_limit_from_sms_row( array $row ): int {
		if ( ! is_array( $row['params'] ?? null ) || ! array_is_list( $row['params'] ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_params_malformed' ) );
		}
		$matches = array();
		foreach ( $row['params'] as $param ) {
			if ( ! is_array( $param ) || array_is_list( $param ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_params_malformed' ) );
			}
			if ( 'CODMaxSum' !== (string) ( $param['key'] ?? '' ) || 'Money' !== (string) ( $param['type'] ?? '' ) ) {
				continue;
			}
			$matches[] = $param;
		}
		if ( 1 !== count( $matches ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 0 === count( $matches ) ? 'pek_sms_cod_limit_missing' : 'pek_sms_cod_limit_duplicate' ) );
		}
		$value = $this->money_scalar_value( $matches[0] );
		$kopecks = MoneyParser::numeric_to_kopecks( $value );
		if ( null !== $kopecks && $kopecks > 0 ) {
			return $kopecks;
		}

		throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_malformed' ) );
	}

	/** @param array<string,mixed> $param */
	private function money_scalar_value( array $param ): int|float|string {
		if ( ! array_key_exists( 'values', $param ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_missing' ) );
		}
		$value = $param['values'];
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) || is_string( $value ) ) {
			return $value;
		}

		throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_malformed' ) );
	}

	private function fail( array $diagnostic = array(), string $message = self::PUBLIC_FAILURE ): PekSmsReleaseResult {
		return new PekSmsReleaseResult( false, 0, false, false, $message, $this->diagnostic_from_safe_array( $diagnostic ) );
	}

	/** @param array<string,mixed> $extra */
	private function api_diagnostic( string $stage, PekApiException $exception, array $extra = array() ): array {
		$context = $exception->context();

		return $this->diagnostic(
			'error',
			$stage,
			$this->safe_token( (string) ( $context['error_code'] ?? 'pek_api_error' ) ),
			array_merge(
				array(
					'failure_stage' => $this->safe_token( (string) ( $context['failure_stage'] ?? '' ) ),
					'endpoint' => $this->safe_endpoint( (string) ( $context['endpoint'] ?? '' ) ),
					'method' => $this->safe_method( (string) ( $context['method'] ?? '' ) ) ?: 'POST',
					'http_status' => $this->safe_http_status( $context['http_status'] ?? null ),
					'api_error_message' => $this->safe_message( $exception->getMessage() ),
					'field_errors' => $this->safe_field_errors( $context['field_errors'] ?? array() ),
					'response_shape' => $this->safe_response_shape( $context['response_shape'] ?? array() ),
				),
				$extra
			)
		);
	}

	/** @param array<string,mixed> $extra */
	private function contract_diagnostic( string $stage, PekApiException $exception, array $extra = array() ): array {
		$context = $exception->context();

		return $this->diagnostic(
			'error',
			$stage,
			$this->safe_token( (string) ( $context['error_code'] ?? 'pek_sms_contract_error' ) ),
			array_merge(
				array(
					'failure_stage' => $this->safe_token( (string) ( $context['failure_stage'] ?? $stage ) ),
					'api_error_message' => $this->safe_message( $exception->getMessage() ),
				),
				$extra,
				$this->contract_flags_for_code( (string) ( $context['error_code'] ?? '' ) )
			)
		);
	}

	private function stage_for_contract_code( string $code ): string {
		return str_contains( $code, 'cod' ) || str_contains( $code, 'param' ) ? 'sms_limit_contract' : 'sms_service_contract';
	}

	/** @return array<string,mixed> */
	private function contract_flags_for_code( string $code ): array {
		if ( str_contains( $code, 'cod' ) || str_contains( $code, 'param' ) ) {
			return array( 'sms_service_row_found' => true, 'cod_max_sum_present' => ! in_array( $code, array( 'pek_sms_cod_limit_missing', 'pek_sms_param_values_missing' ), true ) );
		}
		if ( 'pek_sms_service_absent' === $code ) {
			return array( 'sms_service_row_found' => false, 'sms_service_row_count' => 0 );
		}
		if ( 'pek_sms_service_duplicate' === $code ) {
			return array( 'sms_service_row_found' => true, 'sms_service_row_count' => 2 );
		}

		return array();
	}

	/** @param array<string,mixed> $extra */
	private function diagnostic( string $status, string $stage, string $error_code = '', array $extra = array() ): array {
		$base = array(
			'status' => in_array( $status, array( 'success', 'error', 'unavailable' ), true ) ? $status : 'error',
			'stage' => $this->safe_stage( $stage ),
			'error_code' => $this->safe_token( $error_code ),
			'failure_stage' => '',
			'endpoint' => '',
			'method' => '',
			'http_status' => null,
			'api_error_message' => '',
			'field_errors' => array(),
			'response_shape' => array(),
			'geography_check_completed' => false,
			'private_token_acquired' => false,
			'connected_services_checked' => false,
			'sms_service_row_found' => false,
			'sms_service_row_count' => 0,
			'cod_max_sum_present' => false,
			'declared_value_within_limit' => false,
		);
		foreach ( $extra as $key => $value ) {
			if ( ! array_key_exists( $key, $base ) && ! in_array( $key, array( 'declared_value_kopecks', 'effective_limit_kopecks' ), true ) ) {
				continue;
			}
			$base[ $key ] = match ( $key ) {
				'failure_stage', 'error_code' => $this->safe_token( (string) $value ),
				'stage' => $this->safe_stage( (string) $value ),
				'endpoint' => $this->safe_endpoint( (string) $value ),
				'method' => $this->safe_method( (string) $value ),
				'http_status' => $this->safe_http_status( $value ),
				'api_error_message' => $this->safe_message( (string) $value ),
				'field_errors' => $this->safe_field_errors( $value ),
				'response_shape' => $this->safe_response_shape( $value ),
				'declared_value_kopecks', 'effective_limit_kopecks', 'sms_service_row_count' => max( 0, (int) $value ),
				default => (bool) $value,
			};
		}
		if ( '' !== $this->safe_token( $error_code ) ) {
			$base['error_code'] = $this->safe_token( $error_code );
		}

		return $base;
	}

	/** @param array<string,mixed> $value */
	private function diagnostic_from_safe_array( array $value ): array {
		if ( array() === $value ) {
			return $this->diagnostic( 'error', 'sms_service_contract', 'pek_sms_unknown_failure' );
		}

		return $value;
	}

	/** @return array<int,array{field:string,messages:array<int,string>}> */
	private function safe_field_errors( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$result = array();
		$total = 0;
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['field'] ?? null ) ) {
				continue;
			}
			$field = $this->safe_field_name( $item['field'] );
			$messages = array();
			$raw_messages = is_array( $item['messages'] ?? null ) ? $item['messages'] : array();
			foreach ( $raw_messages as $message ) {
				if ( ! is_string( $message ) ) {
					continue;
				}
				$messages[] = $this->safe_field_message( $message );
				++$total;
				if ( count( $messages ) >= 5 || $total >= 50 ) {
					break;
				}
			}
			if ( array() !== $messages ) {
				$result[] = array( 'field' => $field, 'messages' => array_values( array_unique( $messages ) ) );
			}
			if ( count( $result ) >= 20 || $total >= 50 ) {
				break;
			}
		}

		return $result;
	}

	private function safe_message( string $message ): string {
		$message = $this->redact_active_private_token( $message );
		return $this->sanitizer->sanitize( $message );
	}

	private function safe_field_name( string $field ): string {
		$field = $this->redact_active_private_token( $field );
		return $this->sanitizer->sanitize_field_name( $field );
	}

	private function safe_field_message( string $message ): string {
		$message = $this->redact_active_private_token( $message );
		return $this->sanitizer->sanitize_field_message( $message );
	}

	private function redact_active_private_token( string $message ): string {
		$token = trim( $this->active_private_token );
		if ( '' !== $token ) {
			$message = str_replace( $token, '[redacted]', $message );
		}

		return $message;
	}

	/** @return array<string,mixed> */
	private function safe_response_shape( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$shape = array();
		foreach ( array( 'root_type', 'root_keys', 'root_count', 'error_present', 'error_type', 'fields_present', 'fields_type', 'fields_count' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$shape[ $key ] = is_array( $value[ $key ] ) ? array_values( array_slice( array_map( 'strval', $value[ $key ] ), 0, 30 ) ) : ( is_bool( $value[ $key ] ) ? (bool) $value[ $key ] : ( is_int( $value[ $key ] ) ? max( 0, $value[ $key ] ) : $this->sanitizer->sanitize_field_name( (string) $value[ $key ] ) ) );
			}
		}

		return $shape;
	}

	private function safe_stage( string $value ): string {
		$value = $this->safe_token( $value );
		return in_array( $value, array( 'completed', 'sms_geography', 'sms_private_token', 'sms_connected_services', 'sms_service_contract', 'sms_limit_contract', 'sms_business_unavailable' ), true ) ? $value : 'sms_service_contract';
	}

	private function safe_token( string $value ): string {
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[a-z0-9_:-]{0,100}$/', $value ) ? $value : '';
	}

	private function safe_endpoint( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '#^/[a-z0-9/_-]+/$#i', $value ) ? $value : '';
	}

	private function safe_method( string $value ): string {
		$value = strtoupper( trim( $value ) );
		return in_array( $value, array( 'GET', 'POST' ), true ) ? $value : '';
	}

	private function safe_http_status( mixed $value ): ?int {
		$status = is_int( $value ) ? $value : ( is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0 );
		return $status >= 100 && $status <= 599 ? $status : null;
	}
}
