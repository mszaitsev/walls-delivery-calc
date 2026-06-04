<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentProductMapper {
	/**
	 * @return array{mail_type:string,mail_category:string,product:string,mmo_allowed:bool}
	 */
	public function by_object_code( string|int $object_code, string $delivery_type = '' ): array {
		$code = (string) $object_code;
		$map = array(
			'4030' => array( 'POSTAL_PARCEL', 'ORDINARY', 'POSTAL_PARCEL', false ),
			'4020' => array( 'POSTAL_PARCEL', 'WITH_DECLARED_VALUE', 'POSTAL_PARCEL', false ),
			'47030' => array( 'PARCEL_CLASS_1', 'ORDINARY', 'PARCEL_CLASS_1', false ),
			'47020' => array( 'PARCEL_CLASS_1', 'WITH_DECLARED_VALUE', 'PARCEL_CLASS_1', false ),
			'54020' => array( 'ECOM_MARKETPLACE', 'WITH_DECLARED_VALUE', 'ECOM_MARKETPLACE', true ),
			'23030' => array( 'ONLINE_PARCEL', 'ORDINARY', 'ONLINE_PARCEL', true ),
			'23020' => array( 'ONLINE_PARCEL', 'WITH_DECLARED_VALUE', 'ONLINE_PARCEL', true ),
			'24030' => array( 'ONLINE_COURIER', 'ORDINARY', 'ONLINE_COURIER', true ),
			'24020' => array( 'ONLINE_COURIER', 'WITH_DECLARED_VALUE', 'ONLINE_COURIER', true ),
			'7030' => array( 'EMS', 'ORDINARY', 'EMS', false ),
			'7020' => array( 'EMS', 'WITH_DECLARED_VALUE', 'EMS', false ),
			'41030' => array( 'EMS_RT', 'ORDINARY', 'EMS_RT', true ),
			'52030' => array( 'EMS_TENDER', 'ORDINARY', 'EMS_TENDER', true ),
		);
		$row = $map[ $code ] ?? ( 'courier' === $delivery_type ? array( 'ONLINE_COURIER', 'ORDINARY', 'ONLINE_COURIER', true ) : array( 'ONLINE_PARCEL', 'ORDINARY', 'ONLINE_PARCEL', true ) );

		return array(
			'mail_type' => $row[0],
			'mail_category' => $row[1],
			'product' => $row[2],
			'mmo_allowed' => (bool) $row[3],
		);
	}
}
