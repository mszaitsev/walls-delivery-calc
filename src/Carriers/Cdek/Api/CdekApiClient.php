<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Api;

defined( 'ABSPATH' ) || exit;

final class CdekApiClient {
	public function __construct(
		private CdekOAuthTokenService $tokens
	) {
	}

	public function getToken(): string {
		return $this->tokens->getToken();
	}

	public function clearTokenCache(): void {
		$this->tokens->clearTokenCache();
	}

	public function clearAllTokenCaches(): void {
		$this->tokens->clearAllTokenCaches();
	}

	/**
	 * @return array{success:bool,message:string}
	 */
	public function checkConnection(): array {
		return $this->tokens->checkConnection();
	}
}
