<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

defined( 'ABSPATH' ) || exit;

final class PekQuoteDiagnosticStore {
	public function ttl(): int {
		return 900;
	}

	/** @param array<string,mixed> $report */
	public function save_for_current_user( array $report ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $this->key(), $this->sanitize_report( $report ), $this->ttl() );
		}
	}

	/** @return array<string,mixed> */
	public function consume_for_current_user(): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$key = $this->key();
		$value = get_transient( $key );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}

		return is_array( $value ) ? $value : array();
	}

	public function clear_for_current_user(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->key() );
		}
	}

	private function key(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return 'wdc_pek_quote_diag_' . max( 0, $user_id );
	}

	/** @param array<string,mixed> $report @return array<string,mixed> */
	private function sanitize_report( array $report ): array {
		$allow = array( 'checked_at', 'success', 'message', 'error_code', 'error_message', 'api_error_message', 'field_errors', 'failure_stage', 'endpoint', 'method', 'http_status', 'mode_location', 'safe_request', 'result', 'response_shape' );
		$safe = array();
		foreach ( $allow as $key ) {
			if ( array_key_exists( $key, $report ) ) {
				$safe[ $key ] = 'field_errors' === $key ? $this->field_errors( $report[ $key ] ) : $this->sanitize_value( $report[ $key ] );
			}
		}

		return $safe;
	}

	/** @return array<int,array{field:string,messages:array<int,string>}> */
	private function field_errors( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $value, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['field'] ?? null ) || ! is_array( $item['messages'] ?? null ) || ! array_is_list( $item['messages'] ) ) {
				continue;
			}
			$messages = array_values( array_filter( array_slice( $item['messages'], 0, 5 ), 'is_string' ) );
			if ( array() !== $messages ) {
				$result[] = array( 'field' => (string) $item['field'], 'messages' => $messages );
			}
		}

		return $result;
	}

	private function sanitize_value( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$safe = array();
		foreach ( $value as $key => $nested ) {
			$normalized = strtolower( (string) $key );
			if ( in_array( $normalized, array( 'raw_request', 'request', 'request_body', 'raw_response', 'response', 'body', 'headers', 'authorization', 'credentials', 'api_key', 'login', 'password', 'token', 'inn', 'kpp', 'counterpartclientcard', 'client_card' ), true ) ) {
				continue;
			}
			$safe[ is_int( $key ) ? $key : (string) $key ] = $this->sanitize_value( $nested );
		}

		return $safe;
	}
}
