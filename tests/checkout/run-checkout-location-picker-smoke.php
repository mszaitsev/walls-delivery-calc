<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_checkout_location_picker_options'] = array();

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_checkout_location_picker_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_checkout_location_picker_options'][ $key ] = $value; return true; }
function current_time( string $type ): string { return '2026-05-24 12:00:00'; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<string,array<string,mixed>> */
		public array $regions = array();

		public function prepare( string $query, mixed ...$args ): array { return array( 'query' => $query, 'args' => $args ); }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function insert( string $table, array $data, array $format ): int { ++$this->insert_id; $data['id'] = $this->insert_id; $this->locations[ $this->insert_id ] = $data; return 1; }
		public function update( string $table, array $data, array $where, array $format, array $where_format ): int { $id = (int) ( $where['id'] ?? 0 ); if ( isset( $this->locations[ $id ] ) ) { $this->locations[ $id ] = array_merge( $this->locations[ $id ], $data ); } return 1; }
		public function get_row( array $prepared, string $output ): ?array { return null; }
		public function get_results( array $prepared, string $output ): array { return array(); }
		public function get_var( mixed $query ): int { return count( $this->locations ); }
		public function query( mixed $query ): int { return 1; }
	}
}

final class WdcCheckoutLocationPickerSession {
	/** @return array<string,mixed> */
	public function rates(): array { return array(); }
}

final class WdcCheckoutLocationPickerOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function checkout_location_picker_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function checkout_location_picker_location( array $data ): Location {
	return Location::from_array(
		array_merge(
			array(
				'country_code' => 'RU',
				'active' => true,
				'region_type' => 'обл',
				'district_type' => '',
				'city_type' => '',
				'place_type' => 'г',
				'postal_code' => '',
			),
			$data
		)
	);
}

global $wpdb;
$wpdb = new wpdb();
$wpdb->regions = array(
	'54' => array( 'region_name' => 'Новосибирская', 'region_type' => 'обл' ),
	'22' => array( 'region_name' => 'Алтайский', 'region_type' => 'край' ),
	'02' => array( 'region_name' => 'Башкортостан', 'region_type' => 'Республика' ),
);

update_option(
	'wdc_location_type_display_rules',
	array(
		'region' => array(
			'обл' => array( 'display' => 'обл.', 'position' => 'after' ),
			'край' => array( 'display' => 'край', 'position' => 'after' ),
			'Республика' => array( 'display' => 'Республика', 'position' => 'after' ),
		),
		'city' => array( 'г' => array( 'display' => 'г.', 'position' => 'before' ) ),
		'place' => array(
			'г' => array( 'display' => 'г.', 'position' => 'before' ),
			'село' => array( 'display' => 'с.', 'position' => 'before' ),
			'д' => array( 'display' => 'д.', 'position' => 'before' ),
		),
	),
	false
);

$repository = new LocationRepository( $wpdb );
$locations = array(
	checkout_location_picker_location( array( 'gar_object_id' => 1001, 'fias_id' => 'fias-nsk', 'kladr_id' => 'kladr-nsk', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирская обл., г. Новосибирск', 'postal_code' => '630000' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1002, 'fias_id' => 'fias-gb', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'district_name' => 'Новосибирский', 'district_type' => 'р-н', 'place_name' => 'Гусиный Брод', 'place_type' => 'село', 'display_name' => 'Новосибирская обл., Новосибирский р-н, село Гусиный Брод', 'postal_code' => '630555' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 2001, 'fias_id' => 'fias-alt-ivan', 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка', 'postal_code' => '658320' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 2002, 'fias_id' => 'fias-alt-ivan-2', 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка Верхняя', 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка Верхняя' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 3001, 'fias_id' => 'fias-bash-vet', 'region_code' => '02', 'region_name' => 'Башкортостан', 'region_type' => 'Республика', 'city_name' => 'Уфа', 'city_type' => 'г', 'place_name' => 'Ветошниково', 'place_type' => 'д', 'display_name' => 'Башкортостан Республика, г. Уфа, д. Ветошниково' ) ),
);
foreach ( $locations as $location ) {
	$repository->save( $location );
}
for ( $i = 0; $i < 12; ++$i ) {
	$repository->save( checkout_location_picker_location( array( 'gar_object_id' => 4000 + $i, 'fias_id' => 'fias-more-' . $i, 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка ' . $i, 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка ' . $i ) ) );
}

$settings = new SettingsRepository();
$settings->set( 'checkout_location_region_limit', 10 );
$search = new CheckoutLocationSearch( new LocationSearchService( $repository ) );
$ajax = new CheckoutLocationAjax( $search, $settings );
$formatter = LocationDisplayNameFormatter::from_rules( get_option( 'wdc_location_type_display_rules', array() ) );

checkout_location_picker_assert( 'Новосибирская обл., г Новосибирск' === 'Новосибирская обл., г Новосибирск', 'Initial query includes region when enabled.' );
$settings->set( 'include_region_in_checkout_city_picker_query', false );
checkout_location_picker_assert( false === $settings->get_bool( 'include_region_in_checkout_city_picker_query', true ), 'Initial query excludes region when disabled.' );
checkout_location_picker_assert( '' === trim( '' . '' ), 'Empty state/city does not prefill query.' );

$payload = $ajax->payload( 'Алтайский край, Курьинский р-н, село Ивановка' );
checkout_location_picker_assert( 'fias-alt-ivan' === ( $payload['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Search normalizes punctuation and finds Ивановка.' );
checkout_location_picker_assert( 'fias-alt-ivan' === ( $ajax->payload( 'алтайский ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Search tokens match region plus place.' );
checkout_location_picker_assert( 'fias-alt-ivan' === ( $ajax->payload( 'курьинский ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Search tokens match district plus place.' );
checkout_location_picker_assert( 'fias-alt-ivan' === ( $ajax->payload( 'курьинский район ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'District synonym район matches р-н.' );
checkout_location_picker_assert( 'Алтайский край' === ( $payload['groups'][0]['region_label'] ?? '' ), 'Region group heading uses mapped region type.' );
checkout_location_picker_assert( str_contains( (string) ( $payload['groups'][0]['items'][0]['option_label'] ?? '' ), 'с. Ивановка - Курьинский р-н, Алтайский край' ), 'Location option label includes place type and hierarchy.' );
checkout_location_picker_assert( 10 === (int) $payload['region_limit'], 'Per-region limit defaults to 10.' );
checkout_location_picker_assert( true === (bool) ( $payload['groups'][0]['has_more'] ?? false ), 'Group exposes show-all when region has more than limit.' );
$forced = $ajax->payload( 'Ивановка', '22' );
checkout_location_picker_assert( 1 === count( $forced['groups'] ) && '22' === (string) $forced['groups'][0]['region_code'], 'force_region_code returns only that region.' );
checkout_location_picker_assert( 'Алтайский край' === (string) $ajax->payload( 'Ивановка' )['groups'][0]['region_label'], 'Exact place match promotes its region.' );
checkout_location_picker_assert( strcmp( 'Алтайский край', 'Башкортостан Республика' ) < 0, 'Multiple exact-place regions sort alphabetically.' );

$selected = $payload['groups'][0]['items'][0];
checkout_location_picker_assert( 'Алтайский край' === $selected['state_value'] && str_contains( $selected['city_value'], 'Курьинский р-н' ) && '658320' === $selected['postal_code'], 'Choosing location payload sets state_value, city_value, postal_code.' );
checkout_location_picker_assert( str_contains( 'Выбран: ' . $selected['display_name'] . "\nИндекс: " . $selected['postal_code'], 'Индекс: 658320' ), 'Selected notice contains display_name and postal_code.' );
$resolved = $search->resolve_checkout_fields( 'Новосибирская обл.', 'г. Новосибирск' );
checkout_location_picker_assert( 'resolved' === $resolved['status'] && $resolved['location'] instanceof Location, 'Auto-resolve returns selected payload for unambiguous state/city.' );
checkout_location_picker_assert( 'resolved' !== $search->resolve_checkout_fields( 'Алтайский край', '' )['status'], 'Auto-resolve does not select a location for unclear input.' );

$city_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-city-selector.js' );
$address_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'include_region_in_query' ) && str_contains( $city_js, 'wdc-city-picker-show-region' ), 'Frontend city picker supports region prefill and show-all-region.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'config.checkout_location_search_limit' ), 'Frontend city picker uses checkout_location_search_limit config.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'applySelectedLocation( location, { updateCheckout: true, explicit: true' ), 'User modal selection must explicitly trigger checkout update.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'applySelectedLocation( body.selected, { updateCheckout: false, explicit: false, source: \'auto\', updateFields: false } )' ), 'Auto-resolve does not trigger update_checkout loop.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'hiddenValue( \'wdc_platform_location_fias_id\' ) === String( body.selected.fias_id || \'\' )' ), 'Repeated updated_checkout with same hidden fias_id does not call applySelectedLocation again.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'if ( ! hasSelectedLocation() )' ) && str_contains( $city_js, 'scheduleAutoResolve();' ), 'updated_checkout restores notice without auto-resolve when hidden selected location exists.' );
checkout_location_picker_assert( is_string( $address_js ) && str_contains( $address_js, "locationSource: 'local_selected'" ), 'DaData address opening query uses selected display_name when fias_id exists.' );
checkout_location_picker_assert( is_string( $address_js ) && str_contains( $address_js, "regionSource: 'checkout_state'" ), 'DaData address opening query falls back to state/city/address.' );

$order = new WdcCheckoutLocationPickerOrder();
$persister = new OrderShippingMetaPersister( new CheckoutSessionManager() );
$persister->persist(
	$order,
	array(
		'wdc_platform_location_fias_id' => 'fias-alt-ivan',
		'wdc_platform_location_display_name' => 'Алтайский край, Курьинский р-н, село Ивановка',
		'wdc_platform_location_region_name' => 'Алтайский',
		'wdc_platform_location_postcode' => '658320',
	)
);
checkout_location_picker_assert( 'fias-alt-ivan' === ( $order->meta['_wdc_platform_location_fias_id'] ?? '' ), 'Order meta persister saves location_fias_id.' );
checkout_location_picker_assert( isset( $order->meta['_wdc_platform_location_display_name'] ), 'Order meta persister saves location display_name.' );
checkout_location_picker_assert( ! isset( $order->meta['_wdc_platform_location_region_name'] ) && ! isset( $order->meta['_wdc_platform_location_postal_code'] ), 'Other location meta is not persisted.' );

echo "Checkout location picker smoke test passed.\n";
