<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

final class MigrationManager {
	private const OPTION_NAME = 'wdc_db_version';
	private const MIGRATIONS_OPTION_NAME = 'wdc_applied_migrations';

	private string $code_version;
	private string $migrations_path;

	public function __construct( string $code_version, string $migrations_path = '' ) {
		$this->code_version    = $code_version;
		$this->migrations_path = '' !== $migrations_path ? rtrim( $migrations_path, '/\\' ) : WDC_PLUGIN_DIR . 'database/migrations';
	}

	public function code_version(): string {
		return $this->code_version;
	}

	public function installed_version(): string {
		$version = get_option( self::OPTION_NAME, '' );

		return is_scalar( $version ) ? (string) $version : '';
	}

	public function is_current(): bool {
		return $this->installed_version() === $this->code_version;
	}

	public function run(): void {
		$applied = $this->applied_migrations();
		$files   = glob( $this->migrations_path . DIRECTORY_SEPARATOR . '*.php' );

		if ( ! is_array( $files ) ) {
			$files = array();
		}

		sort( $files );

		foreach ( $files as $file ) {
			$migration = basename( $file );
			if ( in_array( $migration, $applied, true ) ) {
				continue;
			}

			$callback = require $file;
			if ( is_callable( $callback ) ) {
				$callback();
			}

			$applied[] = $migration;
			update_option( self::MIGRATIONS_OPTION_NAME, array_values( array_unique( $applied ) ), false );
		}

		update_option( self::OPTION_NAME, $this->code_version, false );
	}

	/**
	 * @return array<int, string>
	 */
	public function applied_migrations(): array {
		$value = get_option( self::MIGRATIONS_OPTION_NAME, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', $value ),
				static fn( string $migration ): bool => '' !== $migration
			)
		);
	}
}
