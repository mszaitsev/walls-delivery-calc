<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentDeclaredValueResolver {
	public function resolve( ShipmentCreateRequest $request ): Money {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $request->order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			throw new \RuntimeException( 'Не удалось загрузить заказ для расчёта объявленной стоимости ПЭК.' );
		}
		$total = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_total' ) ) {
				continue;
			}
			$total += $this->rub_to_kopecks( (string) $item->get_total() );
			if ( method_exists( $item, 'get_total_tax' ) ) {
				$total += $this->rub_to_kopecks( (string) $item->get_total_tax() );
			}
		}
		if ( $total <= 0 ) {
			throw new \RuntimeException( 'Объявленная стоимость ПЭК должна быть больше нуля.' );
		}

		return Money::from_kopecks( $total, 'RUB' );
	}

	private function rub_to_kopecks( string $value ): int {
		$value = str_replace( ',', '.', trim( $value ) );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$parts = explode( '.', $value, 2 );
		$rub = (int) preg_replace( '/\D+/', '', $parts[0] );
		$kop = str_pad( preg_replace( '/\D+/', '', $parts[1] ?? '' ) ?? '', 2, '0' );

		return $rub * 100 + (int) substr( $kop, 0, 2 );
	}
}
