<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionSettings {
	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption,
		private ?DaDataTokenPool $token_pool = null
	) {
	}

	public function enabled(): bool {
		return $this->settings->get_bool( 'dadata_suggestions_enabled', false );
	}

	public function has_any_configured_token(): bool {
		return $this->token_pool instanceof DaDataTokenPool && $this->token_pool->has_any_configured_token();
	}

	public function has_available_token(): bool {
		return $this->token_pool instanceof DaDataTokenPool && $this->token_pool->has_available_token();
	}

	public function timeout(): int {
		return max( 1, min( 10, $this->settings->get_int( 'dadata_suggestions_timeout', 3 ) ) );
	}

	public function count(): int {
		return max( 3, min( 20, $this->settings->get_int( 'dadata_suggestions_count', 10 ) ) );
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key();
	}
}
