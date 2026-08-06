<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;

defined( 'ABSPATH' ) || exit;

final class PekPrivateAccessTokenService {
	/** @var array{token:string,expires_at:int}|null */
	private ?array $memoized = null;

	public function __construct( private PekApiClient $api ) {
	}

	public function token(): string {
		$now = $this->now();
		if ( is_array( $this->memoized ) && $this->memoized['expires_at'] > $now + 30 ) {
			return $this->memoized['token'];
		}

		$response = $this->api->create_private_access_token();
		$token = $this->access_token( $response );
		$token_type = $response['token_type'] ?? null;
		if ( ! is_string( $token_type ) || 'bearer' !== strtolower( trim( $token_type ) ) ) {
			throw new PekApiException( 'ПЭК вернул недействительный private token.', array( 'error_code' => 'pek_private_token_invalid_type', 'failure_stage' => 'private_token_contract' ) );
		}
		$expires_at = $this->expires_at( $response );
		if ( $expires_at <= $now + 30 ) {
			throw new PekApiException( 'ПЭК вернул недействительный private token.', array( 'error_code' => 'pek_private_token_invalid', 'failure_stage' => 'private_token_contract' ) );
		}

		$this->memoized = array( 'token' => $token, 'expires_at' => $expires_at );

		return $token;
	}

	/** @param array<string,mixed> $response */
	private function access_token( array $response ): string {
		$value = $response['access_token'] ?? null;
		if ( ! is_string( $value ) ) {
			throw new PekApiException( 'ПЭК вернул недействительный private token.', array( 'error_code' => 'pek_private_token_invalid_access_token', 'failure_stage' => 'private_token_contract' ) );
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 8192 ) {
			throw new PekApiException( 'ПЭК вернул недействительный private token.', array( 'error_code' => 'pek_private_token_invalid_access_token', 'failure_stage' => 'private_token_contract' ) );
		}

		return $value;
	}

	/** @param array<string,mixed> $response */
	private function expires_at( array $response ): int {
		$unix = $response['expires_in_unix'] ?? null;
		if ( is_int( $unix ) && $unix > 0 ) {
			return (int) $unix;
		}
		if ( is_string( $unix ) && 1 === preg_match( '/^\d+$/', trim( $unix ) ) ) {
			return (int) trim( $unix );
		}
		$text = $response['expires_in'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return 0;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\ZH:i:s', trim( $text ), new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();

		return $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ? $date->getTimestamp() : 0;
	}

	private function now(): int {
		return function_exists( 'current_datetime' ) ? current_datetime()->getTimestamp() : time();
	}
}
