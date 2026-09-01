<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Checkout;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCustomerCommentProvider {
	public const TRACKING_COMMENT = 'Отслеживание посылки - в приложении Ozon, раздел Доставка';
	public const REFUSAL_PREFIX = 'При отказе от посылки после её отправки покупатель оплачивает полную стоимость обратной доставки ';
	public const REFUSAL_SUFFIX = ' руб.';

	/**
	 * @return array<int,string>
	 */
	public function customer_comments( DeliveryRate $rate ): array {
		if (
			OzonDeliverySettings::CARRIER_KEY !== $rate->carrier_key
			|| DeliveryType::PICKUP !== $rate->delivery_type
			|| $rate->disabled
			|| ! empty( $rate->meta['fallback'] )
		) {
			return array();
		}

		$basis = $rate->crossed_price ?? $rate->price;

		return array(
			self::TRACKING_COMMENT,
			self::REFUSAL_PREFIX . $this->format_rubles( $basis ) . self::REFUSAL_SUFFIX,
		);
	}

	private function format_rubles( Money $money ): string {
		$kopecks = $money->get_kopecks();
		$rubles  = intdiv( $kopecks, 100 );
		$rest    = abs( $kopecks % 100 );

		if ( 0 === $rest ) {
			return (string) $rubles;
		}

		return $rubles . ',' . str_pad( (string) $rest, 2, '0', STR_PAD_LEFT );
	}
}
