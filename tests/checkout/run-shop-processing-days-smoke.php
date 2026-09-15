<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_shop_processing_options'] = array();
$GLOBALS['wdc_shop_processing_transients'] = array();
$GLOBALS['wdc_shop_processing_order_queries'] = array();
$GLOBALS['wdc_shop_processing_order_total'] = 0;
$GLOBALS['wdc_shop_processing_throw_query'] = false;
$GLOBALS['wdc_shop_processing_scripts'] = array();
$GLOBALS['wdc_shop_processing_localized_scripts'] = array();

function shop_processing_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['wdc_shop_processing_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_shop_processing_options'][ $key ] = $value;

	return true;
}

function get_transient( string $key ): mixed {
	return $GLOBALS['wdc_shop_processing_transients'][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['wdc_shop_processing_transients'][ $key ] = $value;

	return true;
}

function wc_get_orders( array $args ): object {
	$GLOBALS['wdc_shop_processing_order_queries'][] = $args;
	if ( $GLOBALS['wdc_shop_processing_throw_query'] ) {
		throw new RuntimeException( 'query failed' );
	}

	return (object) array(
		'orders'        => array( 123 ),
		'total'         => $GLOBALS['wdc_shop_processing_order_total'],
		'max_num_pages' => 1,
	);
}

function wc_get_order_statuses(): array {
	return array(
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
	);
}

function wc_get_logger(): object {
	return new class {
		public function log( string $level, string $message, array $context = array() ): void {}
	};
}

function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, bool $in_footer = false ): void {
	$GLOBALS['wdc_shop_processing_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
}

function wp_localize_script( string $handle, string $object_name, array $l10n ): void {
	$GLOBALS['wdc_shop_processing_localized_scripts'][ $handle ][ $object_name ] = $l10n;
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function wp_create_nonce( string $action ): string {
	return 'nonce-' . $action;
}

function current_time( string $type = 'mysql' ): string {
	return '2026-05-21 12:00:00';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<string,array<string,mixed>> */
		public array $calendar_days = array();
		/** @var array<string,array<string,mixed>> */
		public array $delivery_services = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_results( string $query, mixed $output = null ): array {
			return array();
		}

		public function get_var( string $query ): int {
			if ( preg_match( "/calendar_type = '([^']+)' AND YEAR\\(calendar_date\\) = ([0-9]+)/", $query, $matches ) ) {
				$count = 0;
				foreach ( $this->calendar_days as $row ) {
					if ( $row['calendar_type'] === $matches[1] && str_starts_with( (string) $row['calendar_date'], $matches[2] . '-' ) ) {
						$count++;
					}
				}

				return $count;
			}

			return 0;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			return true;
		}

		public function replace( string $table, array $data, array $format = array() ): bool {
			if ( str_contains( $table, 'wdc_calendar_days' ) ) {
				$this->calendar_days[ $data['calendar_type'] . '|' . $data['calendar_date'] ] = $data;
			}

			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/calendar_type = '([^']+)' AND calendar_date = '([^']+)'/", $query, $matches ) ) {
				return $this->calendar_days[ $matches[1] . '|' . $matches[2] ] ?? null;
			}
			if ( preg_match( "/service_key = '([^']+)' AND deleted = 0 LIMIT 1/", $query, $matches ) ) {
				return $this->delivery_services[ $matches[1] ] ?? null;
			}

			return null;
		}
	}
}

function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}

	return $result;
}
function wp_nonce_field( string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">';
	if ( $display ) {
		echo $field;
	}

	return $field;
}
function submit_button( string $text = 'Save Changes' ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function sanitize_key( mixed $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' ); }
function wp_unslash( mixed $value ): mixed { return $value; }

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wpdb'] = new wpdb();

use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
use WallsShop\WDC\Checkout\Runtime\ShopProcessingDaysResolver;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\ShopProcessingOrderQueueCounter;

function shop_processing_settings_with_dynamic( int $capacity, int $extra_days, array $statuses = array( 'wc-processing', 'wc-on-hold' ) ): SettingsRepository {
	$settings = new SettingsRepository();
	$settings->set( SettingsRepository::SHOP_PROCESSING_MODE_KEY, SettingsRepository::SHOP_PROCESSING_MODE_DYNAMIC );
	$settings->set( SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDERS_PER_DAY_KEY, $capacity );
	$settings->set( SettingsRepository::SHOP_PROCESSING_DYNAMIC_EXTRA_DAYS_KEY, $extra_days );
	$settings->set( SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDER_STATUSES_KEY, $statuses );

	return $settings;
}

function shop_processing_resolve( int $orders, int $capacity, int $extra_days ): int {
	$GLOBALS['wdc_shop_processing_transients'] = array();
	$settings = shop_processing_settings_with_dynamic( $capacity, $extra_days );
	$counter = new ShopProcessingOrderQueueCounter( new Logger(), static fn( array $statuses ): int => $orders );

	return ( new ShopProcessingDaysResolver( $settings, $counter ) )->resolve();
}

$cases = array(
	array( 0, 20, 0, 0 ),
	array( 1, 20, 0, 1 ),
	array( 20, 20, 0, 1 ),
	array( 21, 20, 0, 2 ),
	array( 40, 20, 0, 2 ),
	array( 41, 20, 0, 3 ),
	array( 0, 20, 1, 1 ),
	array( 1, 20, 1, 2 ),
	array( 20, 20, 1, 2 ),
	array( 21, 20, 1, 3 ),
	array( 40, 20, 1, 3 ),
	array( 41, 20, 1, 4 ),
	array( 0, 20, 2, 2 ),
	array( 1, 20, 2, 3 ),
	array( 20, 20, 2, 3 ),
	array( 21, 20, 2, 4 ),
);
foreach ( $cases as $case ) {
	shop_processing_assert( $case[3] === shop_processing_resolve( $case[0], $case[1], $case[2] ), 'Dynamic shop processing formula must match the expected matrix.' );
}
shop_processing_assert( 16 === shop_processing_resolve( 15, 0, 1 ), 'Invalid capacity must be clamped to 1 and avoid divide-by-zero.' );

$GLOBALS['wdc_shop_processing_options'] = array();
$legacy_settings = new SettingsRepository();
$legacy_counter_calls = 0;
$legacy_resolver = new ShopProcessingDaysResolver( $legacy_settings, new ShopProcessingOrderQueueCounter( new Logger(), static function () use ( &$legacy_counter_calls ): int { $legacy_counter_calls++; return 99; } ) );
shop_processing_assert( SettingsRepository::SHOP_PROCESSING_MODE_FIXED === $legacy_settings->shop_processing_mode(), 'Missing mode must default to fixed.' );
shop_processing_assert( 2 === $legacy_resolver->resolve() && 0 === $legacy_counter_calls, 'Missing mode must preserve fixed default processing days without querying orders.' );
$legacy_settings->set( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, 5 );
$legacy_settings->set( SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDERS_PER_DAY_KEY, 1 );
$legacy_settings->set( SettingsRepository::SHOP_PROCESSING_DYNAMIC_EXTRA_DAYS_KEY, 2 );
shop_processing_assert( 5 === $legacy_resolver->resolve(), 'Dynamic settings must not affect fixed mode.' );

$GLOBALS['wdc_shop_processing_options'] = array();
$GLOBALS['wdc_shop_processing_transients'] = array();
$GLOBALS['wdc_shop_processing_order_queries'] = array();
$GLOBALS['wdc_shop_processing_order_total'] = 21;
$counter = new ShopProcessingOrderQueueCounter( new Logger() );
shop_processing_assert( 21 === $counter->count( array( 'processing', 'wc-on-hold' ) ), 'First dynamic count must use WooCommerce order query.' );
shop_processing_assert( 1 === count( $GLOBALS['wdc_shop_processing_order_queries'] ), 'First count must issue exactly one query.' );
$query = $GLOBALS['wdc_shop_processing_order_queries'][0];
shop_processing_assert( array( 'wc-on-hold', 'wc-processing' ) === $query['status'] && 1 === $query['limit'] && true === $query['paginate'] && 'ids' === $query['return'], 'Order count query must use HPOS-compatible paginated wc_get_orders IDs boundary.' );
shop_processing_assert( 21 === $counter->count( array( 'wc-on-hold', 'wc-processing' ) ), 'Second dynamic count in TTL must use cached value.' );
shop_processing_assert( 1 === count( $GLOBALS['wdc_shop_processing_order_queries'] ), 'Cache hit must not issue another order query.' );
$GLOBALS['wdc_shop_processing_order_total'] = 40;
$counter->invalidate();
shop_processing_assert( 40 === $counter->count( array( 'wc-processing', 'wc-on-hold' ) ), 'Status-change invalidation must force a fresh query.' );
shop_processing_assert( 2 === count( $GLOBALS['wdc_shop_processing_order_queries'] ), 'Invalidation must bypass the previous transient generation.' );
shop_processing_assert( 0 === $counter->count( array() ), 'Empty statuses must be an empty queue and must not query all orders.' );
shop_processing_assert( 2 === count( $GLOBALS['wdc_shop_processing_order_queries'] ), 'Empty statuses must not call wc_get_orders().' );
$counter->invalidate();
$GLOBALS['wdc_shop_processing_throw_query'] = true;
shop_processing_assert( 40 === $counter->count( array( 'wc-processing', 'wc-on-hold' ) ), 'Query failure must fall back to the last successful cached count.' );
$GLOBALS['wdc_shop_processing_throw_query'] = false;

$settings = shop_processing_settings_with_dynamic( 20, 1 );
$timezone = new TimezoneService();
$formatter = new DeliveryDateFormatter();
$normalizer = new DeliveryLeadTimeNormalizer(
	new ShopProcessingDaysResolver( $settings, new ShopProcessingOrderQueueCounter( new Logger(), static fn( array $statuses ): int => 21 ) ),
	new DeliveryServiceSettingsRepository(),
	new DeliveryDateCalculator( new CalendarService( new CalendarRepository(), new YearGenerator(), $settings, $timezone ), $timezone, $formatter ),
	$formatter
);
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Москва' ), Package::from_items( array(), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ), '', Money::from_rubles( 1000 ), '2026-05-21' );
$rate = new DeliveryRate( 'rate', 'carrier', 'Carrier', 'svc', 'Svc', 'tariff', 'Tariff', DeliveryType::COURIER, 'Svc', Money::from_rubles( 100 ), null, null, DateRange::range( 1, 1 ) );
$normalized = $normalizer->normalize( $rate, null, $request );
shop_processing_assert( 3 === $normalized->meta['shop_processing_working_days'] && 4 === $normalized->meta['shop_processing_calendar_days'], 'Dynamic resolver output must enter the existing shop calendar pipeline.' );

$admin = ( new ReflectionClass( DeliveryServicesAdminPage::class ) )->newInstanceWithoutConstructor();
$admin_reflection = new ReflectionClass( DeliveryServicesAdminPage::class );
$services_property = $admin_reflection->getProperty( 'services' );
$services_property->setAccessible( true );
$services_property->setValue( $admin, new DeliveryServiceRepository( $GLOBALS['wpdb'] ) );
$global_settings_property = $admin_reflection->getProperty( 'global_settings' );
$global_settings_property->setAccessible( true );
$global_settings_property->setValue( $admin, $settings );
$counter_property = $admin_reflection->getProperty( 'shop_processing_queue_counter' );
$counter_property->setAccessible( true );
$counter_property->setValue( $admin, $counter );
$environment_property = $admin_reflection->getProperty( 'environment' );
$environment_property->setAccessible( true );
$environment_property->setValue( $admin, new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), 'https://example.test/wp-content/plugins/wdc/', '0.155.9' ) );
$render = $admin_reflection->getMethod( 'render_global_delivery_settings_form' );
$render->setAccessible( true );
ob_start();
$render->invoke( $admin );
$html = ob_get_clean() ?: '';
shop_processing_assert( str_contains( $html, 'wdc_shop_processing_mode' ) && str_contains( $html, 'Фиксированное количество дней' ) && str_contains( $html, 'Динамически по количеству заказов' ), 'Delivery Services admin must render processing mode select.' );
shop_processing_assert( str_contains( $html, 'wdc_shop_processing_working_days' ) && str_contains( $html, 'wdc_shop_processing_dynamic_orders_per_day' ), 'Delivery Services admin must render fixed and dynamic capacity fields.' );
shop_processing_assert( str_contains( $html, 'data-wdc-shop-processing-mode="fixed"' ), 'Fixed shop processing row must expose an explicit visibility marker.' );
shop_processing_assert( 3 === substr_count( $html, 'data-wdc-shop-processing-mode="dynamic"' ), 'Dynamic shop processing rows must expose explicit visibility markers.' );
shop_processing_assert( str_contains( $html, 'wc-processing' ) && str_contains( $html, 'wc-on-hold' ) && str_contains( $html, 'multiple' ), 'Delivery Services admin must render WooCommerce status multi-select from canonical statuses.' );
shop_processing_assert( str_contains( $html, '>0 дней<' ) && str_contains( $html, '>1 день<' ) && str_contains( $html, '>2 дня<' ), 'Dynamic extra days select must expose exactly 0/1/2 labels.' );

$GLOBALS['wpdb']->delivery_services['existing_service'] = array(
	'id' => 1,
	'service_key' => 'existing_service',
	'carrier_key' => 'manual',
	'service_type' => DeliveryService::TYPE_MANUAL,
	'title' => 'Existing service',
	'deleted' => 0,
);
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => DeliveryServicesAdminPage::MENU_SLUG );
$admin->enqueue_assets();
shop_processing_assert( isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Shop processing visibility script must enqueue on the Delivery Services list page.' );
shop_processing_assert( str_ends_with( (string) $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing']['src'], '/assets/admin/delivery-services-shop-processing.js' ), 'Shop processing visibility script must use the dedicated admin asset.' );
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => DeliveryServicesAdminPage::MENU_SLUG, 'tab' => 'foo' );
$admin->enqueue_assets();
shop_processing_assert( isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Irrelevant tab parameter must not suppress list-page shop processing script enqueue.' );
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => DeliveryServicesAdminPage::MENU_SLUG, 'service' => 'nonexistent' );
$admin->enqueue_assets();
shop_processing_assert( isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Unknown service parameter must keep list-page shop processing script enqueue.' );
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => DeliveryServicesAdminPage::MENU_SLUG, 'service' => 'russian_post' );
$admin->enqueue_assets();
shop_processing_assert( isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Unknown predefined-looking service without a repository row must still render the list-page shop processing script.' );
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => DeliveryServicesAdminPage::MENU_SLUG, 'service' => 'existing_service', 'tab' => 'foo' );
$admin->enqueue_assets();
shop_processing_assert( ! isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Shop processing visibility script must not enqueue on a real service edit screen.' );
$GLOBALS['wdc_shop_processing_scripts'] = array();
$_GET = array( 'page' => 'wdc-platform-settings' );
$admin->enqueue_assets();
shop_processing_assert( ! isset( $GLOBALS['wdc_shop_processing_scripts']['wdc-delivery-services-shop-processing'] ), 'Shop processing visibility script must not enqueue outside Delivery Services.' );
$_GET = array();

$_POST = array(
	SettingsRepository::SHOP_PROCESSING_MODE_KEY => 'dynamic',
	SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY => '7',
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDERS_PER_DAY_KEY => '0',
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDER_STATUSES_KEY => array( 'processing', 'unknown-status', array( 'bad' ) ),
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_EXTRA_DAYS_KEY => '9',
);
$save = $admin_reflection->getMethod( 'save_global_delivery_settings' );
$save->setAccessible( true );
$before_generation = (int) get_option( 'wdc_shop_processing_queue_count_generation', 1 );
$save->invoke( $admin );
shop_processing_assert( SettingsRepository::SHOP_PROCESSING_MODE_DYNAMIC === $settings->shop_processing_mode(), 'Admin save must persist dynamic mode.' );
shop_processing_assert( 7 === $settings->shop_processing_working_days(), 'Admin save must preserve fixed setting roundtrip.' );
shop_processing_assert( 1 === $settings->shop_processing_dynamic_orders_per_day(), 'Admin save must clamp invalid dynamic capacity to 1.' );
shop_processing_assert( array( 'wc-processing' ) === $settings->shop_processing_dynamic_order_statuses(), 'Admin save must normalize statuses and reject values outside canonical WooCommerce statuses.' );
shop_processing_assert( 1 === $settings->shop_processing_dynamic_extra_days(), 'Admin save must normalize invalid extra days to safe default 1.' );
shop_processing_assert( (int) get_option( 'wdc_shop_processing_queue_count_generation', 1 ) > $before_generation, 'Admin settings save must invalidate dynamic order-count cache.' );

$_POST = array(
	SettingsRepository::SHOP_PROCESSING_MODE_KEY => 'fixed',
	SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY => '8',
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDERS_PER_DAY_KEY => '20',
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_ORDER_STATUSES_KEY => array( 'wc-on-hold', 'completed' ),
	SettingsRepository::SHOP_PROCESSING_DYNAMIC_EXTRA_DAYS_KEY => '2',
);
$save->invoke( $admin );
shop_processing_assert( SettingsRepository::SHOP_PROCESSING_MODE_FIXED === $settings->shop_processing_mode(), 'Admin save must persist fixed mode.' );
shop_processing_assert( 8 === $settings->shop_processing_working_days(), 'Admin save must preserve fixed field values when dynamic fields are hidden by JS.' );
shop_processing_assert( 20 === $settings->shop_processing_dynamic_orders_per_day(), 'Admin save must preserve dynamic capacity when fixed mode is selected.' );
shop_processing_assert( array( 'wc-on-hold', 'wc-completed' ) === $settings->shop_processing_dynamic_order_statuses(), 'Admin save must preserve dynamic statuses when fixed mode is selected.' );
shop_processing_assert( 2 === $settings->shop_processing_dynamic_extra_days(), 'Admin save must preserve dynamic extra days when fixed mode is selected.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Application/ShopProcessingOrderQueueCounter.php' );
shop_processing_assert( str_contains( $source, 'wc_get_orders' ) && str_contains( $source, "'paginate' => true" ) && str_contains( $source, "'return'   => 'ids'" ) && ! str_contains( $source, "'limit' => -1" ), 'Order queue counter must use a paginated WooCommerce count query and must not load every order.' );
$js_source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/delivery-services-shop-processing.js' ) );
shop_processing_assert( str_contains( $js_source, 'DOMContentLoaded' ) && str_contains( $js_source, "addEventListener( 'change'" ) && str_contains( $js_source, 'row.hidden' ), 'Shop processing visibility script must apply initial state and listen for mode changes.' );
shop_processing_assert( ! str_contains( $js_source, "value = ''" ) && ! str_contains( $js_source, '.remove(' ), 'Shop processing visibility script must not clear or remove hidden field values.' );

echo "Shop processing days smoke passed.\n";
