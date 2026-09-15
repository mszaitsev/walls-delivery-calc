<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Checkout;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCustomerComments {
	public const WAREHOUSE_CONTACTS_TEXT_BEFORE = 'Адрес склада выдачи - ';
	public const WAREHOUSE_CONTACTS_LABEL = 'на сайте Jet Logistic';
	public const REMOTE_TERMINAL_COMMENT_PREFIX = 'Получение груза на складе Джет Логистик в г. ';

	public static function remote_terminal_comment( string $terminal_city ): string {
		$terminal_city = trim( $terminal_city );
		return '' === $terminal_city ? '' : self::REMOTE_TERMINAL_COMMENT_PREFIX . $terminal_city . '.';
	}
}
