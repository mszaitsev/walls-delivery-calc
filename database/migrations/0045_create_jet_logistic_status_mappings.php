<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		throw new \RuntimeException( 'WordPress dbDelta function is unavailable.' );
	}

	( new JetLogisticStatusMappingRepository() )->create_schema();
};
