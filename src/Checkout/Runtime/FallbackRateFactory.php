<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class FallbackRateFactory {
	public function create(): DeliveryRate {
		$title = 'Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина';

		return new DeliveryRate(
			'fallback:manager',
			'fallback',
			'Fallback',
			DeliveryType::UNKNOWN,
			$title,
			DeliveryType::UNKNOWN,
			$title,
			DeliveryType::UNKNOWN,
			$title,
			Money::from_rubles( 0 ),
			null,
			null,
			DateRange::range( null, null ),
			'',
			'',
			array(),
			false,
			'',
			false,
			false,
			array( 'fallback' => true )
		);
	}
}
