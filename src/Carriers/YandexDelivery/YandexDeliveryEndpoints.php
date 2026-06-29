<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryEndpoints {
	public const LOCATION_DETECT_PATH = '/api/b2b/platform/location/detect';
	public const PICKUP_POINTS_LIST_PATH = '/api/b2b/platform/pickup-points/list';
	public const PRICING_CALCULATOR_PATH = '/api/b2b/platform/pricing-calculator';

	private const TEST_HOST = 'https://b2b.taxi.tst.yandex.net';
	private const PRODUCTION_HOST = 'https://b2b-authproxy.taxi.yandex.net';

	public static function host( string $environment ): string {
		return YandexDeliverySettings::ENV_PRODUCTION === $environment ? self::PRODUCTION_HOST : self::TEST_HOST;
	}

	public static function url( string $environment, string $path ): string {
		return rtrim( self::host( $environment ), '/' ) . '/' . ltrim( $path, '/' );
	}
}

