<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingStatus {
	public const MAPPED = 'mapped';
	public const MULTIPLE_MATCHES = 'multiple_matches';
	public const NOT_FOUND = 'not_found';
	public const MANUAL = 'manual';
	public const ERROR = 'error';

	/** @return array<int,string> */
	public static function all(): array {
		return array( self::MAPPED, self::MULTIPLE_MATCHES, self::NOT_FOUND, self::MANUAL, self::ERROR );
	}

	public static function normalize( string $status ): string {
		return in_array( $status, self::all(), true ) ? $status : self::ERROR;
	}
}