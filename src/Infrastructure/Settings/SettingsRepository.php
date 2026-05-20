<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {
	private const OPTION_NAME = 'wdc_core_settings';

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function replace( array $settings ): bool {
		return update_option( self::OPTION_NAME, $settings, false );
	}

	public function set( string $key, mixed $value ): bool {
		$settings = $this->all();
		$settings[ $key ] = $value;

		return $this->replace( $settings );
	}

	public function get_string( string $key, string $default = '' ): string {
		$value = $this->all()[ $key ] ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function get_bool( string $key, bool $default = false ): bool {
		$value = $this->all()[ $key ] ?? $default;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return $default;
	}

	public function get_int( string $key, int $default = 0 ): int {
		$value = $this->all()[ $key ] ?? $default;

		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * @return array<string|int, mixed>
	 */
	public function get_array( string $key, array $default = array() ): array {
		$value = $this->all()[ $key ] ?? $default;

		return is_array( $value ) ? $value : $default;
	}
}
