<?php
declare(strict_types=1);

namespace WallsShop\WDC\WooCommerce;

use WallsShop\WDC\Core\PluginEnvironment;

defined( 'ABSPATH' ) || exit;

final class HPOSCompatibility {
	private PluginEnvironment $environment;

	public function __construct( PluginEnvironment $environment ) {
		$this->environment = $environment;
	}

	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
	}

	public function declare_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			$this->environment->plugin_file(),
			true
		);
	}
}
