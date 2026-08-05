<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Common\MoneyParser;
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
			$total += MoneyParser::rubles_to_kopecks( (string) $item->get_total() );
			$prices_include_tax = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
			if ( $prices_include_tax && method_exists( $item, 'get_total_tax' ) ) {
				$total += MoneyParser::rubles_to_kopecks( (string) $item->get_total_tax() );
			}
		}
		if ( $total <= 0 ) {
			throw new \RuntimeException( 'Объявленная стоимость ПЭК должна быть больше нуля.' );
		}

		return Money::from_kopecks( $total, 'RUB' );
	}
}
