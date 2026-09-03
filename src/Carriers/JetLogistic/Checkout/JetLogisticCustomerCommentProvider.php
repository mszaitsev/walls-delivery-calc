<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Checkout;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCustomerCommentProvider {
	public const WAREHOUSE_CONTACTS_TEXT_BEFORE = 'Адрес склада выдачи - ';
	public const WAREHOUSE_CONTACTS_LABEL = 'на сайте Jet Logistic';
	public const WAREHOUSE_CONTACTS_COMMENT = self::WAREHOUSE_CONTACTS_TEXT_BEFORE . self::WAREHOUSE_CONTACTS_LABEL;
	public const REMOTE_TERMINAL_COMMENT_PREFIX = 'Получение груза на складе Джет Логистик в г. ';

	/**
	 * @return array<int,string>
	 */
	public function customer_comments( DeliveryRate $rate ): array {
		if (
			JetLogisticSettings::CARRIER_KEY !== $rate->carrier_key
			|| DeliveryType::PICKUP !== $rate->delivery_type
			|| $rate->requires_pickup_point
			|| $rate->disabled
			|| ! empty( $rate->meta['fallback'] )
			|| ! empty( $rate->meta['fallback_used'] )
		) {
			return array();
		}

		$comments = array( self::WAREHOUSE_CONTACTS_COMMENT );
		$terminal_comment = trim( (string) ( $rate->meta['jet_pickup_terminal_customer_comment'] ?? '' ) );
		if ( '' !== $terminal_comment ) {
			$comments[] = $terminal_comment;
		}

		return $comments;
	}

	public static function remote_terminal_comment( string $terminal_city ): string {
		$terminal_city = trim( $terminal_city );
		return '' === $terminal_city ? '' : self::REMOTE_TERMINAL_COMMENT_PREFIX . $terminal_city . '.';
	}
}
