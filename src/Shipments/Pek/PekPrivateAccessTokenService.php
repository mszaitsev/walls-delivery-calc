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
		$now = time();
		if ( is_array( $this->memoized ) && $this->memoized['expires_at'] > $now + 30 ) {
			return $this->memoized['token'];
		}

		$response = $this->api->create_private_access_token();
		$token = trim( (string) ( $response['access_token'] ?? '' ) );
		$expires_at = $this->expires_at( $response );
		if ( '' === $token || $expires_at <= $now + 30 ) {
			throw new PekApiException( 'ПЭК вернул недействительный private token.', array( 'error_code' => 'pek_private_token_invalid', 'failure_stage' => 'private_token_contract' ) );
		}

		$this->memoized = array( 'token' => $token, 'expires_at' => $expires_at );

		return $token;
	}

	/** @param array<string,mixed> $response */
	private function expires_at( array $response ): int {
		$unix = $response['expires_in_unix'] ?? null;
		if ( is_numeric( $unix ) ) {
			return (int) $unix;
		}
		$text = trim( (string) ( $response['expires_in'] ?? '' ) );
		if ( '' === $text ) {
			return 0;
		}
		$timestamp = strtotime( $text );

		return false !== $timestamp ? (int) $timestamp : 0;
	}
}
