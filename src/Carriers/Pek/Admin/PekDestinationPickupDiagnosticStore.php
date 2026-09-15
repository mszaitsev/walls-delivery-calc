<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

defined( 'ABSPATH' ) || exit;

final class PekDestinationPickupDiagnosticStore {
	public function ttl(): int {
		return 900;
	}

	/** @param array<string,mixed> $report */
	public function save_for_current_user( array $report ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $this->key(), $this->sanitize( $report ), $this->ttl() );
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

		return 'wdc_pek_dest_pickup_diag_' . max( 0, $user_id );
	}

	/** @param array<string,mixed> $report @return array<string,mixed> */
	private function sanitize( array $report ): array {
		$top_level = array( 'success', 'error_code', 'api_error_message', 'field_errors', 'failure_stage', 'endpoint', 'method', 'http_status', 'response_shape', 'rejections', 'checked_at', 'location', 'terminals', 'message', 'errors' );
		$safe = array();
		foreach ( $top_level as $key ) {
			if ( array_key_exists( $key, $report ) ) {
				$safe[ $key ] = 'field_errors' === $key ? $this->sanitize_field_errors( $report[ $key ] ) : $this->sanitize_value( $report[ $key ] );
			}
		}

		return $safe;
	}

	/** @return array<int,array{field:string,messages:array<int,string>}> */
	private function sanitize_field_errors( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$safe = array();
		foreach ( array_slice( $value, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['field'] ?? null ) || ! is_array( $item['messages'] ?? null ) || ! array_is_list( $item['messages'] ) ) {
				continue;
			}
			$messages = array();
			foreach ( array_slice( $item['messages'], 0, 5 ) as $message ) {
				if ( is_string( $message ) ) {
					$messages[] = $message;
				}
			}
			if ( array() === $messages ) {
				continue;
			}
			$safe[] = array(
				'field' => $item['field'],
				'messages' => $messages,
			);
		}

		return $safe;
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
			if ( in_array( $normalized, array( 'raw_error', 'error', 'raw_response', 'response', 'credentials', 'authorization', 'headers', 'request', 'request_body', 'request_headers', 'request_args', 'body', 'api_key', 'login', 'password', 'token' ), true ) ) {
				continue;
			}
			$safe[ is_int( $key ) ? $key : (string) $key ] = $this->sanitize_value( $nested );
		}

		return $safe;
	}
}
