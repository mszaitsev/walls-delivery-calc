<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class PlatformRuntimeSettings {
	public const RUNTIME_ENABLED_KEY = 'woocommerce_runtime_enabled';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	public function runtime_enabled(): bool {
		return $this->normalize_bool( $this->settings->all()[ self::RUNTIME_ENABLED_KEY ] ?? true, true );
	}

	private function normalize_bool( mixed $value, bool $default ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
				return false;
			}
		}

		return $default;
	}
}
