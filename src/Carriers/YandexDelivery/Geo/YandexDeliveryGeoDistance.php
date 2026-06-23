<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoDistance {
	public function haversine_km( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$earth_radius_km = 6371.0088;
		$lat_delta = deg2rad( $lat2 - $lat1 );
		$lon_delta = deg2rad( $lon2 - $lon1 );
		$lat1 = deg2rad( $lat1 );
		$lat2 = deg2rad( $lat2 );
		$a = sin( $lat_delta / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $lon_delta / 2 ) ** 2;
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius_km * $c;
	}
}
