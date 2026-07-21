<?php
declare(strict_types=1);

use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsTable;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	if ( function_exists( 'dbDelta' ) ) {
		dbDelta( ( new ShipmentCostAnalyticsTable() )->schema() );
	}
};
