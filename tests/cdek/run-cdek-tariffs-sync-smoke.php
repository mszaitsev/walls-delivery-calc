<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffSyncService;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function cdek_tariffs_sync_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-12 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'cdek-tariffs-sync-smoke-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_tariffs_sync_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_tariffs_sync_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_tariffs_sync_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_tariffs_sync_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_cdek_tariffs_sync_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_tariffs_sync_transients'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wc_get_logger(): object {
	return new class {
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['wdc_cdek_tariffs_sync_logs'][] = compact( 'level', 'message', 'context' );
		}
	};
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<string,mixed> */
		public array $options = array();
		/** @var array<int,array<string,mixed>> */
		public array $cdek_tariffs = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function query( string $query ): bool { return true; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function get_row( string $query, mixed $output = null ): ?array { return null; }
		public function get_results( string $query, mixed $output = null ): array { return array(); }
		public function insert( string $table, array $data, array $format = array() ): bool { return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { return true; }
	}
}

final class CdekTariffsSyncFakeHttpClient implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<int,array<string,mixed>> */
	public array $all_tariffs_groups = array();

	public function __construct() {
		$this->all_tariffs_groups = array(
			array(
				'tariff_name' => 'Посылка',
				'delivery_modes' => array(
					array( 'delivery_mode' => 4, 'delivery_mode_name' => 'склад-склад', 'tariff_code' => 136 ),
					array( 'delivery_mode' => 3, 'delivery_mode_name' => 'склад-дверь', 'tariff_code' => 137 ),
				),
			),
		);
	}

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( 'access_token' => 'sync-token', 'expires_in' => 3600 ) ) );
		}
		if ( str_contains( $url, '/v2/calculator/alltariffs' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( 'tariff_codes' => $this->all_tariffs_groups ) ) );
		}
		if ( str_contains( $url, '/v2/location/cities' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( array( 'code' => 270, 'city' => 'Москва', 'region' => 'Москва', 'fias_guid' => 'dest-fias' ) ) ) );
		}
		if ( str_contains( $url, '/v2/calculator/tarifflist' ) ) {
			return new CdekApiResponse(
				200,
				(string) json_encode(
					array(
						'tariff_codes' => array(
							array( 'tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 350.5, 'period_min' => 2, 'period_max' => 4 ),
							array( 'tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3, 'delivery_sum' => 520, 'period_min' => 1, 'period_max' => 1 ),
						),
					)
				)
			);
		}

		return new CdekApiResponse( 404, '{}' );
	}
}

function cdek_tariffs_sync_settings( CdekTariffsSyncFakeHttpClient $http ): array {
	$GLOBALS['wdc_cdek_tariffs_sync_options'] = array();
	$GLOBALS['wdc_cdek_tariffs_sync_transients'] = array();
	$settings = new CdekSettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin(
		array(
			CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
			CdekSettings::TEST_ACCOUNT_KEY => 'account-id',
			'cdek_test_secure_password' => 'secure-password',
			CdekSettings::SENDER_CITY_CODE_KEY => '270',
			CdekSettings::SENDER_POSTAL_CODE_KEY => '630005',
			CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		)
	);
	$client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http ), $settings, $http );

	return array( $settings, $client );
}

function cdek_tariffs_sync_request( string $type ): QuoteRequest {
	$item = new PackageItem( 'sku', 'Товар', 1, Money::from_rubles( 10000 ), Money::from_rubles( 10000 ), 700, 12, 8, 4 );
	$package = Package::from_items( array( $item ), 300, Money::from_rubles( 10000 ), Money::from_rubles( 10000 ) );

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', region_name: 'Москва', city: 'Москва', postcode: '101000', fias_id: 'dest-fias' ),
		$package,
		'cod',
		Money::from_rubles( 10000 ),
		'2026-06-12',
		array( 'delivery_type' => $type, 'city_name' => 'Москва', 'selected_location_region' => 'Москва', 'selected_location_fias_id' => 'dest-fias' )
	);
}

$GLOBALS['wpdb'] = new wpdb();
$http = new CdekTariffsSyncFakeHttpClient();
[ $settings, $client ] = cdek_tariffs_sync_settings( $http );
$repository = new CdekTariffRepository( $GLOBALS['wpdb'] );
$sync = new CdekTariffSyncService( $client, $repository, new Logger() );

$rows = $sync->fetch_from_api();
cdek_tariffs_sync_assert( count( $rows ) === 2, 'CDEK alltariffs sync must normalize delivery modes into tariff rows.' );
cdek_tariffs_sync_assert( DeliveryType::PICKUP === (string) $rows[0]['delivery_type'] && DeliveryType::COURIER === (string) $rows[1]['delivery_type'], 'CDEK delivery modes must map warehouse destination to pickup and door destination to courier.' );
cdek_tariffs_sync_assert( 'Посылка склад-склад' === (string) $rows[0]['tariff_name_from_cdek'] && 'Посылка склад-дверь' === (string) $rows[1]['tariff_name_from_cdek'], 'CDEK alltariffs sync must combine tariff name and delivery mode name for site-readable rows.' );
cdek_tariffs_sync_assert( count( array_filter( $http->requests, static fn( array $request ): bool => 'GET' === $request['method'] && str_contains( $request['url'], '/v2/calculator/alltariffs' ) ) ) === 1, 'CDEK tariff sync must call GET /v2/calculator/alltariffs.' );

$result = $sync->sync_rows( $rows );
cdek_tariffs_sync_assert( 2 === $result['added'] && 2 === count( $repository->all() ), 'Initial CDEK tariff sync must add tariffs.' );
cdek_tariffs_sync_assert( null !== $repository->find_by_code( '136' ) && null !== $repository->find_by_code( '137' ), 'Synced tariffs must be findable by code.' );

$repository->save_admin_rows(
	array(
		array( 'tariff_code' => '136', 'custom_title' => 'СДЭК Эконом', 'delivery_type' => DeliveryType::PICKUP, 'admin_comment' => 'site title', 'is_active' => 1 ),
		array( 'tariff_code' => '137', 'custom_title' => 'СДЭК Курьер', 'delivery_type' => DeliveryType::COURIER, 'admin_comment' => 'courier title', 'is_active' => 0 ),
	)
);
$http->all_tariffs_groups[0]['tariff_name'] = 'Посылка обновленная';
$second = $sync->sync_rows( $sync->fetch_from_api() );
cdek_tariffs_sync_assert( 0 === $second['added'] && 2 === count( $repository->all() ), 'Repeated CDEK sync must not create duplicates.' );
$pickup = $repository->find_by_code( '136' );
$courier = $repository->find_by_code( '137' );
cdek_tariffs_sync_assert( is_array( $pickup ) && 'СДЭК Эконом' === (string) $pickup['custom_title'] && 'site title' === (string) $pickup['admin_comment'] && 1 === (int) $pickup['is_active'], 'CDEK sync must preserve custom title/comment/active for existing pickup tariff.' );
cdek_tariffs_sync_assert( is_array( $courier ) && 'СДЭК Курьер' === (string) $courier['custom_title'] && 0 === (int) $courier['is_active'], 'CDEK sync must preserve inactive state for existing courier tariff.' );

$carrier = new CdekCarrier( $settings, $client, new CdekLocationResolver( $client, new Logger() ), new Logger(), $repository );
$pickup_quote = $carrier->quote( cdek_tariffs_sync_request( DeliveryType::PICKUP ) );
cdek_tariffs_sync_assert( 1 === count( $pickup_quote->rates ), 'Managed active CDEK pickup tariff must produce one pickup rate.' );
cdek_tariffs_sync_assert( 'СДЭК до пункта выдачи, СДЭК Эконом - 2-4 дня' === $pickup_quote->rates[0]->title, 'Managed CDEK runtime title must use custom tariff title.' );
$courier_quote = $carrier->quote( cdek_tariffs_sync_request( DeliveryType::COURIER ) );
cdek_tariffs_sync_assert( array() === $courier_quote->rates, 'Inactive managed CDEK courier tariff must be skipped.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
cdek_tariffs_sync_assert( str_contains( $source, 'Загрузить тарифы из СДЭК' ) && str_contains( $source, 'preview_cdek_tariffs_sync' ) && str_contains( $source, 'confirm_cdek_tariffs_sync' ), 'CDEK admin tariffs tab must include API sync preview and confirmation actions.' );
cdek_tariffs_sync_assert( str_contains( $source, 'DeliveryQuoteCacheManager' ) && str_contains( $source, 'clear_delivery_quote_cache' ) && str_contains( $source, 'save_cdek_tariffs' ) && str_contains( $source, 'confirm_cdek_tariffs_sync' ), 'CDEK tariff save/sync must clear delivery quote cache.' );
$cache_manager_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Cache/DeliveryQuoteCacheManager.php' );
cdek_tariffs_sync_assert( str_contains( $cache_manager_source, 'shipping_for_package_' ) && str_contains( $cache_manager_source, 'wdc_platform_rates' ) && str_contains( $cache_manager_source, 'wdc_platform_selected_tariffs' ) && str_contains( $cache_manager_source, 'ensure_woocommerce_session' ), 'Delivery quote cache clear must include WooCommerce package rates and WDC runtime session caches.' );
cdek_tariffs_sync_assert( str_contains( $cache_manager_source, 'wdc_delivery_rates_cache_version' ) && str_contains( $cache_manager_source, 'add_cache_version_to_packages' ) && str_contains( $cache_manager_source, 'bump_delivery_rates_cache_version' ), 'Delivery quote cache clear must bump a global WooCommerce package cache version.' );

$cache_manager = new DeliveryQuoteCacheManager( null, $GLOBALS['wpdb'] );
$package_before = $cache_manager->add_cache_version_to_packages( array( array( 'contents' => array( 'demo' ) ) ) );
$version_before = (string) ( $package_before[0]['wdc_delivery_rates_cache_version'] ?? '' );
$cache_manager->clear_all_delivery_cache();
$package_after = $cache_manager->add_cache_version_to_packages( array( array( 'contents' => array( 'demo' ) ) ) );
$version_after = (string) ( $package_after[0]['wdc_delivery_rates_cache_version'] ?? '' );
cdek_tariffs_sync_assert( '' !== $version_before && '' !== $version_after && $version_before !== $version_after, 'Delivery rates cache version must change package hash input after cache reset.' );

echo "CDEK tariffs sync smoke test passed.\n";
