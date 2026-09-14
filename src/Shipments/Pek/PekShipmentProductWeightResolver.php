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
		$draft_rows = is_array( $request->meta['shipment_item_rows'] ?? null ) ? $request->meta['shipment_item_rows'] : array();
		if ( array() !== $draft_rows ) {
			$weight = 0;
			foreach ( $draft_rows as $row ) {
				if ( is_array( $row ) ) {
					$weight += max( 0, (int) ( $row['weight'] ?? 0 ) ) * max( 0, (int) ( $row['amount'] ?? 0 ) );
				}
			}
			if ( $weight > 0 ) {
				return $weight;
			}
		}
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
