<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoCoverageStatus {
	public const COVERED = 'covered';
	public const NOT_COVERED = 'not_covered';
	public const NO_GEO_ID = 'no_geo_id';
	public const ERROR = 'error';
	public const UNKNOWN = 'unknown';

	/** @return array<int,string> */
	public static function all(): array {
		return array( self::COVERED, self::NOT_COVERED, self::NO_GEO_ID, self::ERROR, self::UNKNOWN );
	}

	public static function normalize( string $status ): string {
		$status = trim( $status );

		return in_array( $status, self::all(), true ) ? $status : self::UNKNOWN;
	}
}
