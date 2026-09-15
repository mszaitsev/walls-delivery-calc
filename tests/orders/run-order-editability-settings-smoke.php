<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\OrderEditabilityPolicy;

$GLOBALS['wdc_options'] = array();
$GLOBALS['wdc_filters'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = false ): bool { $GLOBALS['wdc_options'][ $key ] = $value; return true; }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['wdc_filters'][ $hook ][] = array( $callback, $priority, $accepted_args ); return true; }
function order_editability_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$settings = new SettingsRepository();
$policy = new OrderEditabilityPolicy( $settings );
order_editability_assert( false === $settings->get_bool( SettingsRepository::ALLOW_EDIT_ORDERS_IN_ALL_STATUSES_KEY ), 'Missing setting must default OFF.' );
order_editability_assert( false === $policy->filter( false ) && true === $policy->filter( true ), 'OFF must preserve WooCommerce editability.' );
$settings->set( SettingsRepository::ALLOW_EDIT_ORDERS_IN_ALL_STATUSES_KEY, true );
order_editability_assert( true === $policy->filter( false ), 'ON must make a normally locked order editable.' );
$policy->register();
$registered_filter = $GLOBALS['wdc_filters']['wc_order_is_editable'][0] ?? array();
order_editability_assert( 1 === count( $GLOBALS['wdc_filters']['wc_order_is_editable'] ?? array() ) && 2 === ( $registered_filter[2] ?? 0 ), 'Policy must register the WooCommerce filter exactly once with the order argument.' );
foreach ( array( true, false ) as $runtime_enabled ) {
	order_editability_assert( true === call_user_func( $registered_filter[0], false, null ), 'Admin policy must make the order editable with the WDC runtime ' . ( $runtime_enabled ? 'enabled.' : 'disabled.' ) );
}

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsAdminPage.php' );
$debug_position = strpos( $source, 'name="show_checkout_debug_panel"' );
$setting_position = strpos( $source, 'SettingsRepository::ALLOW_EDIT_ORDERS_IN_ALL_STATUSES_KEY' );
order_editability_assert( false !== $debug_position && false !== $setting_position && $setting_position > $debug_position, 'Editable-orders checkbox must be rendered immediately after checkout debug.' );
order_editability_assert( str_contains( $source, "SettingsRepository::ALLOW_EDIT_ORDERS_IN_ALL_STATUSES_KEY => ! empty" ), 'Settings sanitizer must persist the checkbox through the shared repository.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$admin_start = strpos( $plugin_source, 'private function register_admin_preparation_hooks()' );
$runtime_start = strpos( $plugin_source, 'private function register_order_runtime_hooks()' );
$runtime_end = strpos( $plugin_source, 'private function register_passive_runtime_bookkeeping_hooks()' );
order_editability_assert( false !== $admin_start && false !== $runtime_start && false !== $runtime_end, 'Plugin order/admin registration methods must remain discoverable.' );
$admin_registration = substr( $plugin_source, $admin_start, $runtime_start - $admin_start );
$runtime_registration = substr( $plugin_source, $runtime_start, $runtime_end - $runtime_start );
$policy_registration = '$this->container->get( OrderEditabilityPolicy::class )->register();';
order_editability_assert( str_contains( $admin_registration, $policy_registration ) && ! str_contains( $runtime_registration, $policy_registration ) && 1 === substr_count( $plugin_source, $policy_registration ), 'Order editability policy must register once through the unconditional admin path, outside the runtime-enabled order hooks.' );

echo "Order editability settings smoke OK\n";
