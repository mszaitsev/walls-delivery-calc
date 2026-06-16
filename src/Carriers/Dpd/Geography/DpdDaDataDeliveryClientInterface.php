<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

interface DpdDaDataDeliveryClientInterface {
	/**
	 * @return array{success:bool,dpd_id:string,message:string,status_code:int,token_id:string}
	 */
	public function find_dpd_id_by_kladr( string $kladr_id ): array;
}
