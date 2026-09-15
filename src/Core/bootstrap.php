<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\Plugin;
use WallsShop\WDC\Core\PluginEnvironment;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wdc_bootstrap_core_platform' ) ) {
	function wdc_bootstrap_core_platform(): Plugin {
		require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';

		$autoloader = new Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' );
		$autoloader->register();

		$plugin = new Plugin(
			new PluginEnvironment(
				WDC_PLUGIN_FILE,
				WDC_PLUGIN_DIR,
				WDC_PLUGIN_URL,
				WDC_VERSION
			)
		);
		$plugin->register();

		return $plugin;
	}
}
