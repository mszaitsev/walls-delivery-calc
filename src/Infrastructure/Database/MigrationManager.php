<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

final class MigrationManager {
	private const OPTION_NAME = 'wdc_db_version';

	private string $code_version;

	public function __construct( string $code_version ) {
		$this->code_version = $code_version;
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
}
