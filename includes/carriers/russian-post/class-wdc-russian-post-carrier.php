<?php
/**
 * Russian Post carrier placeholder.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Russian_Post_Carrier implements WDC_Carrier {
	public const INTERNATIONAL_OUTGOING_PARCEL_OBJECT_CODE = 4031;

	public function get_id(): string {
		return WDC_Carrier_Registry::CARRIER_RUSSIAN_POST;
	}

	public function get_title(): string {
		return 'Почта России';
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_services(): array {
		$definitions = ( new WDC_Carrier_Registry() )->get_definitions();

		return $definitions[ WDC_Carrier_Registry::CARRIER_RUSSIAN_POST ]['services'];
	}
}
