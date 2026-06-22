<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$repository = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointRepository( $wpdb );
	$repository->create_schema_if_needed();
};
