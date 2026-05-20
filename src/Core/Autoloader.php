<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
	private string $prefix;

	private string $root;

	public function __construct( string $prefix, string $root ) {
		$this->prefix = trim( $prefix, '\\' ) . '\\';
		$this->root   = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	public function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, $this->prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $this->prefix ) );
		$file           = $this->root . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
