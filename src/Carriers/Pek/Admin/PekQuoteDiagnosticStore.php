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
				if ( 'field_errors' === $key ) {
					$safe[ $key ] = $this->field_errors( $report[ $key ] );
				} elseif ( 'result' === $key ) {
					$safe[ $key ] = $this->result( $report[ $key ] );
				} elseif ( 'api_error_message' === $key ) {
					$safe[ $key ] = $this->safe_text( $report[ $key ], 500 );
				} else {
					$safe[ $key ] = $this->sanitize_value( $report[ $key ] );
				}
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
			$messages = array();
			foreach ( array_slice( $item['messages'], 0, 5 ) as $message ) {
				if ( is_string( $message ) ) {
					$messages[] = $this->safe_text( $message, 500 );
				}
			}
			$messages = array_values( array_unique( array_filter( $messages, static fn( string $message ): bool => '' !== $message ) ) );
			if ( array() !== $messages ) {
				$result[] = array( 'field' => $this->safe_text( (string) $item['field'], 100 ), 'messages' => $messages );
			}
		}

		return $result;
	}

	private function result( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( array( 'cost_total_rub', 'cost_total_kopecks', 'delivery_days', 'sender_branch', 'receiver_branch' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$result[ $key ] = $this->sanitize_value( $value[ $key ] );
			}
		}
		if ( array_key_exists( 'services', $value ) ) {
			$result['services'] = $this->services( $value['services'] );
		}

		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	private function services( mixed $value, int $depth = 0 ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || $depth > 3 ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $value, 0, 100 ) as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				continue;
			}
			$row = array();
			foreach ( array( 'serviceType', 'senderCity', 'info' ) as $key ) {
				if ( array_key_exists( $key, $item ) && is_string( $item[ $key ] ) ) {
					$text = $this->safe_text( $item[ $key ], 500 );
					if ( '' !== $text ) {
						$row[ $key ] = $text;
					}
				}
			}
			if ( array_key_exists( 'cost', $item ) && is_numeric( $item['cost'] ) ) {
				$row['cost'] = round( (float) $item['cost'], 2 );
			}
			if ( array_key_exists( 'insuranceTerm', $item ) && is_bool( $item['insuranceTerm'] ) ) {
				$row['insuranceTerm'] = $item['insuranceTerm'];
			}
			if ( array_key_exists( 'services', $item ) ) {
				$row['services'] = $this->services( $item['services'], $depth + 1 );
			}
			$result[] = $row;
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

	private function safe_text( mixed $value, int $max_length ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length );
		}

		return substr( $value, 0, $max_length );
	}
}
