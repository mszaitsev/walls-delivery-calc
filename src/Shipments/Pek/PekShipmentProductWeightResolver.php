<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentProductWeightResolver {
	public function __construct( private PekSettings $settings ) {
	}

	public function product_weight_g( ShipmentCreateRequest $request ): int {
		$calculation = is_array( $request->meta['calculation_data'] ?? null ) ? $request->meta['calculation_data'] : array();
		$weight = (int) ( $calculation['package']['products_weight_g'] ?? 0 );
		if ( $weight > 0 ) {
			return $weight;
		}
		foreach ( $request->places as $place ) {
			foreach ( is_array( $place->items ?? null ) ? $place->items : array() as $item ) {
				if ( $item instanceof PackageItem ) {
					$weight += $item->get_total_weight_g();
				}
			}
		}
		if ( $weight <= 0 ) {
			throw new \RuntimeException( 'Не удалось определить товарный вес для заявки ПЭК.' );
		}

		return $weight;
	}

	public function sealing_required( ShipmentCreateRequest $request ): bool {
		$weight = $this->product_weight_g( $request );

		return $weight > 0 && $weight < $this->settings->light_cargo_weight_limit_g();
	}
}
