<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekRequestBudget {
	public const ERROR_CODE = 'pek_local_rate_limit_exceeded';

	public function __construct( private PekSettings $settings ) {
	}

	public function consume(): void {
		$limit = $this->settings->request_soft_limit_per_minute();
		$key = 'wdc_pek_request_budget_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			throw new PekApiException( 'Локальный лимит запросов ПЭК на минуту исчерпан. Повторите действие позже.', array( 'error_code' => self::ERROR_CODE, 'soft_limit' => $limit ) );
		}
		set_transient( $key, $count + 1, 70 );
	}
}
