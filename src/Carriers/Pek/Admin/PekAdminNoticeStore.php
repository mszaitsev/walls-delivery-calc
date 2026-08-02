<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

defined( 'ABSPATH' ) || exit;

final class PekAdminNoticeStore {
	private const TTL_SECONDS = 120;
	private const MAX_MESSAGE_LENGTH = 1000;

	public function save_for_current_user( string $type, string $message ): void {
		set_transient(
			$this->key_for_current_user(),
			array(
				'type' => $this->normalize_type( $type ),
				'message' => $this->sanitize_message( $message ),
			),
			self::TTL_SECONDS
		);
	}

	/** @return array{type:string,message:string}|array{} */
	public function consume_for_current_user(): array {
		$key = $this->key_for_current_user();
		$value = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array(
			'type' => $this->normalize_type( (string) ( $value['type'] ?? 'info' ) ),
			'message' => $this->sanitize_message( (string) ( $value['message'] ?? '' ) ),
		);
	}

	public function clear_for_current_user(): void {
		delete_transient( $this->key_for_current_user() );
	}

	public function ttl_seconds(): int {
		return self::TTL_SECONDS;
	}

	public function key_for_current_user(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? max( 0, (int) get_current_user_id() ) : 0;

		return 'wdc_pek_admin_notice_' . $user_id;
	}

	private function normalize_type( string $type ): string {
		$type = strtolower( trim( $type ) );

		return in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';
	}

	private function sanitize_message( string $message ): string {
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? '';
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		$message = preg_replace( '/[A-Za-z0-9._%+\-]+:[A-Za-z0-9._%+\-\/+=]{6,}/', '[redacted-credentials]', $message ) ?? $message;
		$message = trim( $message );

		return strlen( $message ) > self::MAX_MESSAGE_LENGTH ? substr( $message, 0, self::MAX_MESSAGE_LENGTH ) : $message;
	}
}
