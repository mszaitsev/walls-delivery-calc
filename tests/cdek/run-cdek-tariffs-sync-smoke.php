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
		public bool $wdc_force_sql_table = false;
		/** @var array<string,mixed> */
		public array $options = array();
		/** @var array<int,array<string,mixed>> */
		public array $cdek_tariffs = array();
		/** @var array<int,array<string,mixed>> */
		public array $sql_cdek_tariffs = array();
		/** @var array<string,mixed> */
		public array $last_insert_data = array();
		/** @var array<int,string> */
		public array $last_insert_formats = array();
		/** @var array<string,mixed> */
		public array $last_update_data = array();
		/** @var array<int,string> */
		public array $last_update_formats = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function query( string $query ): bool { return true; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			foreach ( $this->sql_cdek_tariffs as $row ) {
				if ( preg_match( "/WHERE tariff_code = '([^']+)'/i", $query, $matches ) && (string) $row['tariff_code'] === $matches[1] ) {
					return $row;
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array { return $this->wdc_force_sql_table ? array_values( $this->sql_cdek_tariffs ) : array(); }
		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->last_insert_data = $data;
			$this->last_insert_formats = $format;
			$this->sql_cdek_tariffs[] = $data;
			return true;
		}
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$this->last_update_data = $data;
			$this->last_update_formats = $format;
			foreach ( $this->sql_cdek_tariffs as $index => $row ) {
				if ( (string) ( $row['tariff_code'] ?? '' ) === (string) ( $where['tariff_code'] ?? '' ) ) {
					$this->sql_cdek_tariffs[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}
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
					array( 'delivery_mode' => 4, 'delivery_mode_name' => 'склад-склад', 'tariff_code' => 136, 'weight_min' => 0.1, 'weight_max' => 30, 'weight_calc_max' => 50, 'length_min' => 10, 'length_max' => 120, 'width_min' => 10, 'width_max' => 80, 'height_min' => 1, 'height_max' => 80 ),
					array( 'delivery_mode' => 3, 'delivery_mode_name' => 'склад-дверь', 'tariff_code' => 137, 'weight_min' => null, 'weight_max' => '', 'weight_calc_max' => 100, 'length_min' => 1, 'length_max' => 200, 'width_min' => 1, 'width_max' => 100, 'height_min' => 1, 'height_max' => 100 ),
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
cdek_tariffs_sync_assert( 4 === (int) $rows[0]['delivery_mode'] && 3 === (int) $rows[1]['delivery_mode'], 'CDEK alltariffs sync must keep exact delivery_mode values.' );
cdek_tariffs_sync_assert( 'Посылка склад-склад' === (string) $rows[0]['tariff_name_from_cdek'] && 'Посылка склад-дверь' === (string) $rows[1]['tariff_name_from_cdek'], 'CDEK alltariffs sync must combine tariff name and delivery mode name for site-readable rows.' );
cdek_tariffs_sync_assert( count( array_filter( $http->requests, static fn( array $request ): bool => 'GET' === $request['method'] && str_contains( $request['url'], '/v2/calculator/alltariffs' ) ) ) === 1, 'CDEK tariff sync must call GET /v2/calculator/alltariffs.' );

$result = $sync->sync_rows( $rows );
cdek_tariffs_sync_assert( 2 === $result['added'] && 2 === count( $repository->all() ), 'Initial CDEK tariff sync must add tariffs.' );
cdek_tariffs_sync_assert( null !== $repository->find_by_code( '136' ) && null !== $repository->find_by_code( '137' ), 'Synced tariffs must be findable by code.' );
$synced_pickup = $repository->find_by_code( '136' );
cdek_tariffs_sync_assert( is_array( $synced_pickup ) && 4 === (int) $synced_pickup['delivery_mode'], 'CDEK repository must store pickup delivery_mode.' );
cdek_tariffs_sync_assert( is_array( $synced_pickup ) && 0.1 === $synced_pickup['weight_min'] && 30.0 === $synced_pickup['weight_max'] && 50.0 === $synced_pickup['weight_calc_max'], 'CDEK sync must store weight limits.' );
cdek_tariffs_sync_assert( is_array( $synced_pickup ) && 10.0 === $synced_pickup['length_min'] && 120.0 === $synced_pickup['length_max'] && 10.0 === $synced_pickup['width_min'] && 80.0 === $synced_pickup['width_max'] && 1.0 === $synced_pickup['height_min'] && 80.0 === $synced_pickup['height_max'], 'CDEK sync must store dimension limits.' );
$synced_courier = $repository->find_by_code( '137' );
cdek_tariffs_sync_assert( is_array( $synced_courier ) && 3 === (int) $synced_courier['delivery_mode'], 'CDEK repository must store courier delivery_mode.' );
cdek_tariffs_sync_assert( is_array( $synced_courier ) && null === $synced_courier['weight_min'] && null === $synced_courier['weight_max'] && 100.0 === $synced_courier['weight_calc_max'], 'CDEK sync must store empty API limits as null.' );

$format_for_key = static function ( array $data, array $formats, string $key ): ?string {
	$keys = array_keys( $data );
	$index = array_search( $key, $keys, true );
	return is_int( $index ) ? ( $formats[ $index ] ?? null ) : null;
};
$sql_db = new wpdb();
$sql_db->wdc_force_sql_table = true;
$sql_repository = new CdekTariffRepository( $sql_db );
$sql_repository->upsert_from_sync(
	array(
		'tariff_code' => '200',
		'tariff_name_from_cdek' => 'Null limits',
		'delivery_type' => DeliveryType::PICKUP,
		'delivery_mode' => 4,
		'weight_min' => null,
		'weight_max' => '',
		'weight_calc_max' => 12.5,
	)
);
cdek_tariffs_sync_assert( null === $sql_db->last_insert_data['weight_min'] && null === $sql_db->last_insert_data['weight_max'] && 12.5 === $sql_db->last_insert_data['weight_calc_max'], 'CDEK SQL insert must keep null limits as null and numeric limits as numbers.' );
cdek_tariffs_sync_assert( 4 === (int) $sql_db->last_insert_data['delivery_mode'] && '%d' === $format_for_key( $sql_db->last_insert_data, $sql_db->last_insert_formats, 'delivery_mode' ), 'CDEK SQL insert must persist delivery_mode as integer.' );
cdek_tariffs_sync_assert( '%f' !== $format_for_key( $sql_db->last_insert_data, $sql_db->last_insert_formats, 'weight_min' ) && '%f' !== $format_for_key( $sql_db->last_insert_data, $sql_db->last_insert_formats, 'weight_max' ) && '%f' === $format_for_key( $sql_db->last_insert_data, $sql_db->last_insert_formats, 'weight_calc_max' ), 'CDEK SQL insert must not format null limits as floats.' );
$sql_repository->upsert_from_sync(
	array(
		'tariff_code' => '200',
		'tariff_name_from_cdek' => 'Null limits updated',
		'delivery_type' => DeliveryType::PICKUP,
		'weight_min' => '',
		'weight_max' => null,
		'weight_calc_max' => '',
	)
);
cdek_tariffs_sync_assert( null === $sql_db->last_update_data['weight_min'] && null === $sql_db->last_update_data['weight_max'] && null === $sql_db->last_update_data['weight_calc_max'], 'CDEK SQL update must keep empty API limits as SQL null values.' );
cdek_tariffs_sync_assert( '%f' !== $format_for_key( $sql_db->last_update_data, $sql_db->last_update_formats, 'weight_min' ) && '%f' !== $format_for_key( $sql_db->last_update_data, $sql_db->last_update_formats, 'weight_calc_max' ), 'CDEK SQL update must not format null limits as floats.' );

$repository->save_admin_rows(
	array(
		array( 'tariff_code' => '136', 'custom_title' => 'СДЭК Эконом', 'delivery_type' => DeliveryType::PICKUP, 'delivery_mode' => 4, 'admin_comment' => 'site title', 'is_active' => 1 ),
		array( 'tariff_code' => '137', 'custom_title' => 'СДЭК Курьер', 'delivery_type' => DeliveryType::COURIER, 'delivery_mode' => 1, 'admin_comment' => 'courier title', 'is_active' => 0 ),
	)
);
$admin_edited_courier = $repository->find_by_code( '137' );
cdek_tariffs_sync_assert( is_array( $admin_edited_courier ) && 1 === (int) $admin_edited_courier['delivery_mode'], 'CDEK admin tariff save must allow manual delivery_mode edit.' );
$http->all_tariffs_groups[0]['tariff_name'] = 'Посылка обновленная';
$second = $sync->sync_rows( $sync->fetch_from_api() );
cdek_tariffs_sync_assert( 0 === $second['added'] && 2 === count( $repository->all() ), 'Repeated CDEK sync must not create duplicates.' );
$pickup = $repository->find_by_code( '136' );
$courier = $repository->find_by_code( '137' );
cdek_tariffs_sync_assert( is_array( $pickup ) && 'СДЭК Эконом' === (string) $pickup['custom_title'] && 'site title' === (string) $pickup['admin_comment'] && 1 === (int) $pickup['is_active'], 'CDEK sync must preserve custom title/comment/active for existing pickup tariff.' );
cdek_tariffs_sync_assert( is_array( $courier ) && 'СДЭК Курьер' === (string) $courier['custom_title'] && 0 === (int) $courier['is_active'] && 3 === (int) $courier['delivery_mode'], 'CDEK sync must preserve inactive state and refresh delivery_mode for existing courier tariff.' );

$carrier = new CdekCarrier( $settings, $client, new CdekLocationResolver( $client, new Logger() ), new Logger(), $repository );
$pickup_quote = $carrier->quote( cdek_tariffs_sync_request( DeliveryType::PICKUP ) );
cdek_tariffs_sync_assert( 1 === count( $pickup_quote->rates ), 'Managed active CDEK pickup tariff must produce one pickup rate.' );
cdek_tariffs_sync_assert( 'СДЭК до пункта выдачи, СДЭК Эконом - 2-4 дня' === $pickup_quote->rates[0]->title, 'Managed CDEK runtime title must use custom tariff title.' );
$courier_quote = $carrier->quote( cdek_tariffs_sync_request( DeliveryType::COURIER ) );
cdek_tariffs_sync_assert( array() === $courier_quote->rates, 'Inactive managed CDEK courier tariff must be skipped.' );

$repository->upsert_from_sync( array( 'tariff_code' => 139, 'tariff_name_from_cdek' => 'Альфа ПВЗ', 'delivery_type' => DeliveryType::PICKUP ) );
$repository->save_admin_rows( array( array( 'tariff_code' => '139', 'custom_title' => '', 'delivery_type' => DeliveryType::PICKUP, 'admin_comment' => '', 'is_active' => 1 ) ) );
$sorted = $repository->all();
cdek_tariffs_sync_assert( '139' === (string) $sorted[0]['tariff_code'] && '136' === (string) $sorted[1]['tariff_code'] && '137' === (string) $sorted[2]['tariff_code'], 'CDEK tariff admin rows must sort active first, then CDEK name, then code.' );
cdek_tariffs_sync_assert( 'дверь-дверь' === CdekTariffSyncService::normalize_cdek_string_static( 'Ð´Ð²ÐµÑÑ-Ð´Ð²ÐµÑÑ' ), 'CDEK mojibake normalizer must fix obvious UTF-8 mojibake.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
cdek_tariffs_sync_assert( str_contains( $source, 'Загрузить тарифы из СДЭК' ) && str_contains( $source, 'preview_cdek_tariffs_sync' ) && str_contains( $source, 'confirm_cdek_tariffs_sync' ), 'CDEK admin tariffs tab must include API sync preview and confirmation actions.' );
cdek_tariffs_sync_assert( str_contains( $source, 'DeliveryQuoteCacheManager' ) && str_contains( $source, 'clear_delivery_quote_cache' ) && str_contains( $source, 'save_cdek_tariffs' ) && str_contains( $source, 'confirm_cdek_tariffs_sync' ), 'CDEK tariff save/sync must clear delivery quote cache.' );
cdek_tariffs_sync_assert( str_contains( $source, 'Ограничения' ) && str_contains( $source, 'до ПВЗ' ) && str_contains( $source, 'до двери' ) && str_contains( $source, 'DeliveryType::PICKUP' ) && str_contains( $source, 'DeliveryType::COURIER' ), 'CDEK tariff admin table must show limits and Russian delivery type labels while keeping technical values.' );
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
