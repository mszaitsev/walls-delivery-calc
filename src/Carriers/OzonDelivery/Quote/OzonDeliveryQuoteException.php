<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteException extends \RuntimeException {
	/** @param array<string,scalar|array<int,string>> $details */
	public function __construct(
		public readonly string $safe_code,
		public readonly string $operation,
		public readonly int $http_status = 0,
		string $message = 'Расчет Ozon Delivery недоступен.',
		public readonly array $details = array()
	) {
		parent::__construct( $message );
	}
}
