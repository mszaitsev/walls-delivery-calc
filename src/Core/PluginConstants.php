<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class PluginConstants {
	private PluginEnvironment $environment;

	public function __construct( PluginEnvironment $environment ) {
		$this->environment = $environment;
	}

	public function plugin_file(): string {
		return $this->environment->plugin_file();
	}

	public function plugin_dir(): string {
		return $this->environment->plugin_dir();
	}

	public function plugin_url(): string {
		return $this->environment->plugin_url();
	}

	public function version(): string {
		return $this->environment->version();
	}
}
