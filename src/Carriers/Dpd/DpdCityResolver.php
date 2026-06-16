<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

interface DpdCityResolver {
	/**
	 * Future boundary only. Stage 1 intentionally performs no DPD geography lookups
	 * and writes no DPD city mappings.
	 *
	 * @return array{city_id:string,source:string,confidence:string}|null
	 */
	public function resolve( Location $location ): ?array;
}

