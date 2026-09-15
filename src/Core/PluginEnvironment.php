<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class PluginEnvironment {
	private string $plugin_file;

	private string $plugin_dir;

	private string $plugin_url;

	private string $version;

	public function __construct( string $plugin_file, string $plugin_dir, string $plugin_url, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_dir  = trailingslashit( $plugin_dir );
		$this->plugin_url  = trailingslashit( $plugin_url );
		$this->version     = $version;
	}

	public function plugin_file(): string {
		return $this->plugin_file;
	}

	public function plugin_dir(): string {
		return $this->plugin_dir;
	}

	public function plugin_url(): string {
		return $this->plugin_url;
	}

	public function version(): string {
		return $this->version;
	}

	public function wp_version(): string {
		global $wp_version;

		return is_string( $wp_version ) ? $wp_version : '';
	}

	public function wc_version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	public function hpos_enabled(): bool {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}
}
