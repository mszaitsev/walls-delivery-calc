<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdEndpoints {
	public const SERVICE_GEOGRAPHY = 'geography2';
	public const SERVICE_CALCULATOR = 'calculator2';
	public const SERVICE_ORDER = 'order2';
	public const SERVICE_TRACING = 'tracing';
	public const SERVICE_TRACING_1_1 = 'tracing1-1';
	public const SERVICE_EVENT_TRACKING = 'event-tracking';
	public const SERVICE_LABEL_PRINT = 'label-print';
	public const SERVICE_DELIVERY_MANAGEMENT = 'delivery-management';

	private const TEST_BASE = 'https://wstest.dpd.ru/services/';
	private const PRODUCTION_BASE = 'https://ws.dpd.ru/services/';

	/**
	 * @return array<string,string>
	 */
	public static function wsdl_map( string $environment ): array {
		$base = DpdSettings::ENV_PRODUCTION === $environment ? self::PRODUCTION_BASE : self::TEST_BASE;

		return array(
			self::SERVICE_GEOGRAPHY => $base . 'geography2?wsdl',
			self::SERVICE_CALCULATOR => $base . 'calculator2?wsdl',
			self::SERVICE_ORDER => $base . 'order2?wsdl',
			self::SERVICE_TRACING => $base . 'tracing?wsdl',
			self::SERVICE_TRACING_1_1 => $base . 'tracing1-1?wsdl',
			self::SERVICE_EVENT_TRACKING => $base . 'event-tracking?wsdl',
			self::SERVICE_LABEL_PRINT => $base . 'label-print?wsdl',
			self::SERVICE_DELIVERY_MANAGEMENT => $base . 'delivery-management?wsdl',
		);
	}

	public static function wsdl( string $service, string $environment ): string {
		$map = self::wsdl_map( self::normalize_environment( $environment ) );

		if ( ! isset( $map[ $service ] ) ) {
			throw new DpdException( sprintf( 'Unknown DPD service "%s".', $service ) );
		}

		return $map[ $service ];
	}

	private static function normalize_environment( string $environment ): string {
		return DpdSettings::ENV_PRODUCTION === $environment ? DpdSettings::ENV_PRODUCTION : DpdSettings::ENV_TEST;
	}
}

