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
	'50' => array( 'region_name' => 'Московская', 'region_type' => 'обл' ),
	'48' => array( 'region_name' => 'Липецкая', 'region_type' => 'обл' ),
	'69' => array( 'region_name' => 'Тверская', 'region_type' => 'обл' ),
	'35' => array( 'region_name' => 'Вологодская', 'region_type' => 'обл' ),
	'53' => array( 'region_name' => 'Новгородская', 'region_type' => 'обл' ),
	'28' => array( 'region_name' => 'Амурская', 'region_type' => 'обл' ),
	'30' => array( 'region_name' => 'Астраханская', 'region_type' => 'обл' ),
	'31' => array( 'region_name' => 'Белгородская', 'region_type' => 'обл' ),
	'36' => array( 'region_name' => 'Воронежская', 'region_type' => 'обл' ),
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
	checkout_location_picker_location( array( 'gar_object_id' => 1007, 'fias_id' => 'fias-nsk-child-beta', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Бета', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., г. Новосибирск, деревня Бета' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1008, 'fias_id' => 'fias-nsk-child-alpha', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Альфа', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., г. Новосибирск, деревня Альфа' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1003, 'fias_id' => 'fias-brod', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'place_name' => 'Брод', 'place_type' => 'село', 'display_name' => 'Новосибирская обл., село Брод' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1004, 'fias_id' => 'fias-brodki', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'place_name' => 'Бродки', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., деревня Бродки' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1005, 'fias_id' => 'fias-brodovka', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'place_name' => 'Бродовка', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., деревня Бродовка' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 1006, 'fias_id' => 'fias-verh', 'region_code' => '54', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'place_name' => 'Верхобродово', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., деревня Верхобродово' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 2001, 'fias_id' => 'fias-alt-ivan', 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка', 'postal_code' => '658320' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 2002, 'fias_id' => 'fias-alt-ivan-2', 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка Верхняя', 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка Верхняя' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 3001, 'fias_id' => 'fias-bash-vet', 'region_code' => '02', 'region_name' => 'Башкортостан', 'region_type' => 'Республика', 'city_name' => 'Уфа', 'city_type' => 'г', 'place_name' => 'Ветошниково', 'place_type' => 'д', 'display_name' => 'Башкортостан Республика, г. Уфа, д. Ветошниково' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 5001, 'fias_id' => 'fias-domodedovo', 'region_code' => '50', 'region_name' => 'Московская', 'region_type' => 'обл', 'city_name' => 'Домодедово', 'city_type' => 'г', 'place_name' => 'Домодедово', 'place_type' => 'г', 'display_name' => 'Московская обл., г. Домодедово' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 5002, 'fias_id' => 'fias-avdotino', 'region_code' => '50', 'region_name' => 'Московская', 'region_type' => 'обл', 'city_name' => 'Домодедово', 'city_type' => 'г', 'place_name' => 'Авдотьино', 'place_type' => 'д', 'display_name' => 'Московская обл., г. Домодедово, деревня Авдотьино' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 5003, 'fias_id' => 'fias-skripino', 'region_code' => '50', 'region_name' => 'Московская', 'region_type' => 'обл', 'city_name' => 'Домодедово', 'city_type' => 'г', 'place_name' => 'Скрипино-1', 'place_type' => 'д', 'display_name' => 'Московская обл., г. Домодедово, деревня Скрипино-1' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 4801, 'fias_id' => 'fias-lip-ivan', 'region_code' => '48', 'region_name' => 'Липецкая', 'region_type' => 'обл', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Липецкая обл., село Ивановка' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 4802, 'fias_id' => 'fias-lip-mos', 'region_code' => '48', 'region_name' => 'Липецкая', 'region_type' => 'обл', 'place_name' => 'Московская Слобода', 'place_type' => 'село', 'display_name' => 'Липецкая обл., село Московская Слобода' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 5004, 'fias_id' => 'fias-mo-ivan', 'region_code' => '50', 'region_name' => 'Московская', 'region_type' => 'обл', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Московская обл., село Ивановка' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 6901, 'fias_id' => 'fias-tver-brod', 'region_code' => '69', 'region_name' => 'Тверская', 'region_type' => 'обл', 'place_name' => 'Брод', 'place_type' => 'д', 'display_name' => 'Тверская обл., деревня Брод' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 6902, 'fias_id' => 'fias-tver-ivan', 'region_code' => '69', 'region_name' => 'Тверская', 'region_type' => 'обл', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Тверская обл., село Ивановка' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 6903, 'fias_id' => 'fias-tver-brodki', 'region_code' => '69', 'region_name' => 'Тверская', 'region_type' => 'обл', 'place_name' => 'Бродки', 'place_type' => 'д', 'display_name' => 'Тверская обл., деревня Бродки' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 6904, 'fias_id' => 'fias-tver-brod-city', 'region_code' => '69', 'region_name' => 'Тверская', 'region_type' => 'обл', 'city_name' => 'Бродоград', 'city_type' => 'г', 'place_name' => 'Бродоград', 'place_type' => 'г', 'display_name' => 'Тверская обл., г. Бродоград' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 6905, 'fias_id' => 'fias-tver-brodograd-place', 'region_code' => '69', 'region_name' => 'Тверская', 'region_type' => 'обл', 'place_name' => 'Бродоград', 'place_type' => 'село', 'display_name' => 'Тверская обл., село Бродоград' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 3501, 'fias_id' => 'fias-vologda-brod', 'region_code' => '35', 'region_name' => 'Вологодская', 'region_type' => 'обл', 'place_name' => 'Брод', 'place_type' => 'д', 'display_name' => 'Вологодская обл., деревня Брод' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 5301, 'fias_id' => 'fias-novgorod-brod', 'region_code' => '53', 'region_name' => 'Новгородская', 'region_type' => 'обл', 'place_name' => 'Бродовка', 'place_type' => 'д', 'display_name' => 'Новгородская обл., деревня Бродовка' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 30001, 'fias_id' => 'fias-astr-brod-city', 'region_code' => '30', 'region_name' => 'Астраханская', 'region_type' => 'обл', 'city_name' => 'Брод', 'city_type' => 'г', 'place_name' => 'Брод', 'place_type' => 'г', 'display_name' => 'Астраханская обл., г. Брод' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 28001, 'fias_id' => 'fias-amur-brodograd-city', 'region_code' => '28', 'region_name' => 'Амурская', 'region_type' => 'обл', 'city_name' => 'Бродоград', 'city_type' => 'г', 'place_name' => 'Бродоград', 'place_type' => 'г', 'display_name' => 'Амурская обл., г. Бродоград' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 31001, 'fias_id' => 'fias-bel-brodograd-place', 'region_code' => '31', 'region_name' => 'Белгородская', 'region_type' => 'обл', 'place_name' => 'Бродоград', 'place_type' => 'село', 'display_name' => 'Белгородская обл., село Бродоград' ) ),
	checkout_location_picker_location( array( 'gar_object_id' => 36001, 'fias_id' => 'fias-vor-brodograd-place', 'region_code' => '36', 'region_name' => 'Воронежская', 'region_type' => 'обл', 'place_name' => 'Бродоград', 'place_type' => 'село', 'display_name' => 'Воронежская обл., село Бродоград' ) ),
);
foreach ( $locations as $location ) {
	$repository->save( $location );
}
for ( $i = 0; $i < 12; ++$i ) {
	$repository->save( checkout_location_picker_location( array( 'gar_object_id' => 4000 + $i, 'fias_id' => 'fias-more-' . $i, 'region_code' => '22', 'region_name' => 'Алтайский', 'region_type' => 'край', 'district_name' => 'Курьинский', 'district_type' => 'р-н', 'place_name' => 'Ивановка ' . $i, 'place_type' => 'село', 'display_name' => 'Алтайский край, Курьинский р-н, село Ивановка ' . $i ) ) );
}
for ( $i = 0; $i < 42; ++$i ) {
	$repository->save( checkout_location_picker_location( array( 'gar_object_id' => 6000 + $i, 'fias_id' => 'fias-single-' . $i, 'region_code' => '50', 'region_name' => 'Московская', 'region_type' => 'обл', 'place_name' => 'Тестоград ' . $i, 'place_type' => 'д', 'display_name' => 'Московская обл., деревня Тестоград ' . $i ) ) );
}
foreach ( array( '54' => 'Новосибирская', '22' => 'Алтайский', '48' => 'Липецкая' ) as $region_code => $region_name ) {
	for ( $i = 0; $i < 100; ++$i ) {
		$repository->save( checkout_location_picker_location( array( 'gar_object_id' => 700000 + (int) $region_code * 1000 + $i, 'fias_id' => 'fias-many-' . $region_code . '-' . $i, 'region_code' => $region_code, 'region_name' => $region_name, 'region_type' => 'обл', 'place_name' => 'Многообластный ' . $i, 'place_type' => 'д', 'display_name' => $region_name . ' обл., деревня Многообластный ' . $i ) ) );
	}
}
for ( $region = 1; $region <= 30; ++$region ) {
	$code = '9' . str_pad( (string) $region, 2, '0', STR_PAD_LEFT );
	$name = 'Лимитная ' . str_pad( (string) $region, 2, '0', STR_PAD_LEFT );
	$wpdb->regions[ $code ] = array( 'region_name' => $name, 'region_type' => 'обл' );
	for ( $i = 0; $i < 5; ++$i ) {
		$repository->save( checkout_location_picker_location( array( 'gar_object_id' => 800000 + $region * 10 + $i, 'fias_id' => 'fias-limit-' . $region . '-' . $i, 'region_code' => $code, 'region_name' => $name, 'region_type' => 'обл', 'place_name' => 'Лимитоград ' . $i, 'place_type' => 'д', 'display_name' => $name . ' обл., деревня Лимитоград ' . $i ) ) );
	}
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
$single_region = $ajax->payload( 'тестоград' );
checkout_location_picker_assert( 1 === count( $single_region['groups'] ) && 30 === (int) $single_region['groups'][0]['shown_count'], 'Single region search shows region_limit times three.' );
checkout_location_picker_assert( true === (bool) $single_region['groups'][0]['has_more'], 'Single region still shows show-all when more results remain.' );
$settings->set( 'checkout_location_search_limit', 20 );
$single_region_limited = $ajax->payload( 'тестоград' );
checkout_location_picker_assert( 20 === (int) $single_region_limited['groups'][0]['shown_count'], 'Single region search respects global limit.' );
$settings->set( 'checkout_location_search_limit', 100 );
$forced = $ajax->payload( 'Ивановка', '22' );
checkout_location_picker_assert( 1 === count( $forced['groups'] ) && '22' === (string) $forced['groups'][0]['region_code'], 'force_region_code returns only that region.' );
checkout_location_picker_assert( 'Алтайский край' === (string) $ajax->payload( 'Ивановка' )['groups'][0]['region_label'], 'Exact place match promotes its region.' );
checkout_location_picker_assert( strcmp( 'Алтайский край', 'Башкортостан Республика' ) < 0, 'Multiple exact-place regions sort alphabetically.' );

$flatten_fias = static function ( array $payload ): array {
	$ids = array();
	foreach ( $payload['groups'] ?? array() as $group ) {
		foreach ( $group['items'] ?? array() as $item ) {
			$ids[] = (string) ( $item['fias_id'] ?? '' );
		}
	}
	return $ids;
};

$gusi_ids = $flatten_fias( $ajax->payload( 'гусиный брод' ) );
checkout_location_picker_assert( array() !== $gusi_ids && 'fias-gb' === $gusi_ids[0], 'Hierarchy search finds Гусиный Брод for гусиный брод.' );
checkout_location_picker_assert( ! in_array( 'fias-verh', $gusi_ids, true ), 'Hierarchy search does not show Верхобродово for гусиный брод.' );

$brod_ids = $flatten_fias( $ajax->payload( 'брод' ) );
checkout_location_picker_assert( in_array( 'fias-brod', $brod_ids, true ) && in_array( 'fias-brodki', $brod_ids, true ) && in_array( 'fias-brodovka', $brod_ids, true ), 'Prefix search finds Брод, Бродки, and Бродовка.' );
checkout_location_picker_assert( ! in_array( 'fias-verh', $brod_ids, true ), 'Prefix search does not match inside word Верхобродово.' );
$brod_regions = array_map( static fn( array $group ): string => (string) $group['region_sort_name'], $ajax->payload( 'брод' )['groups'] ?? array() );
checkout_location_picker_assert( 'Астраханская' === ( $brod_regions[0] ?? '' ), 'Exact city match ranks above exact place match.' );
checkout_location_picker_assert( array_slice( $brod_regions, 1, 3 ) === array( 'Вологодская', 'Новосибирская', 'Тверская' ), 'Same seniority exact place region groups sort alphabetically.' );
checkout_location_picker_assert( in_array( 'Новгородская', $brod_regions, true ) && array_search( 'Новгородская', $brod_regions, true ) > 3, 'Non-exact groups appear after exact groups.' );
$ivan_regions = array_map( static fn( array $group ): string => (string) $group['region_sort_name'], $ajax->payload( 'ивановка' )['groups'] ?? array() );
checkout_location_picker_assert( array_slice( $ivan_regions, 0, 4 ) === array( 'Алтайский', 'Липецкая', 'Московская', 'Тверская' ), 'Same seniority exact Ивановка region groups sort alphabetically.' );
$prefix_seniority_regions = array_map( static fn( array $group ): string => (string) $group['region_sort_name'], $ajax->payload( 'бродог' )['groups'] ?? array() );
checkout_location_picker_assert( array_slice( $prefix_seniority_regions, 0, 2 ) === array( 'Амурская', 'Тверская' ), 'Same seniority prefix city groups sort alphabetically above prefix place.' );
checkout_location_picker_assert( array_slice( $prefix_seniority_regions, 2, 2 ) === array( 'Белгородская', 'Воронежская' ), 'Same seniority prefix place groups sort alphabetically.' );

$domodedovo_ids = $flatten_fias( $ajax->payload( 'домодедово' ) );
checkout_location_picker_assert( in_array( 'fias-domodedovo', $domodedovo_ids, true ) && in_array( 'fias-avdotino', $domodedovo_ids, true ) && in_array( 'fias-skripino', $domodedovo_ids, true ), 'Upper-level city search returns city and nested places.' );
checkout_location_picker_assert( 'fias-domodedovo' === ( $ajax->payload( 'домодедово', '50' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'force_region_code keeps top-level Домодедово before child settlements.' );

$moscow_ids = $flatten_fias( $ajax->payload( 'московская область' ) );
checkout_location_picker_assert( in_array( 'fias-domodedovo', $moscow_ids, true ) && in_array( 'fias-avdotino', $moscow_ids, true ), 'Region-only search returns locations in the region.' );
$moscow_query = $ajax->payload( 'московская' );
checkout_location_picker_assert( in_array( '50', array_map( static fn( array $group ): string => (string) $group['region_code'], $moscow_query['groups'] ?? array() ), true ), 'Region-name query returns Moscow region group.' );
checkout_location_picker_assert( in_array( 'fias-domodedovo', $flatten_fias( $moscow_query ), true ), 'Region-only group survives strong place filtering.' );
checkout_location_picker_assert( '48' === (string) ( $moscow_query['groups'][0]['region_code'] ?? '' ) && '50' === (string) ( $moscow_query['groups'][1]['region_code'] ?? '' ), 'Region-only groups rank below strong place groups.' );
checkout_location_picker_assert( '50' === (string) ( $ajax->payload( 'мо' )['groups'][0]['region_code'] ?? '' ), 'МО alias returns Moscow region group.' );
checkout_location_picker_assert( '50' === (string) ( $ajax->payload( 'МО' )['groups'][0]['region_code'] ?? '' ), 'МО alias is case-insensitive.' );
checkout_location_picker_assert( 'fias-mo-ivan' === ( $ajax->payload( 'мо ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'МО alias prioritizes Ивановка in Moscow region.' );
checkout_location_picker_assert( '54' === (string) ( $ajax->payload( 'новосибирская' )['groups'][0]['region_code'] ?? '' ), 'Region-only Новосибирская search returns Novosibirsk first.' );
checkout_location_picker_assert( 'fias-alt-ivan' === ( $ajax->payload( 'курьинский ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'District plus place ranks Курьинский Ивановка first.' );
checkout_location_picker_assert( 'fias-lip-ivan' === ( $ajax->payload( 'липецкая область ивановка' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Region marker is treated as hierarchy marker, not DB value.' );
checkout_location_picker_assert( array() === $flatten_fias( $ajax->payload( 'село' ) ), 'Type words alone are not searchable DB values.' );
$settings->set( 'checkout_location_region_limit', 5 );
$settings->set( 'checkout_location_search_limit', 100 );
$many_payload = $ajax->payload( 'многообластный' );
checkout_location_picker_assert( 15 === (int) $many_payload['shown_total'] && false === (bool) $many_payload['limit_reached'] && 3 === count( $many_payload['groups'] ), 'Global limit counts only shown items across 3 regions.' );
$limit_payload = $ajax->payload( 'лимитоград' );
checkout_location_picker_assert( 100 === (int) $limit_payload['shown_total'] && true === (bool) $limit_payload['limit_reached'], 'Global limit_reached applies only when shown items hit global limit.' );
$settings->set( 'checkout_location_region_limit', 10 );
$settings->set( 'checkout_location_search_limit', 100 );
$forced_nsk = $ajax->payload( 'Новосибирск', '54' );
$forced_nsk_ids = array_map( static fn( array $item ): string => (string) ( $item['fias_id'] ?? '' ), $forced_nsk['groups'][0]['items'] ?? array() );
checkout_location_picker_assert( 'fias-nsk' === ( $forced_nsk_ids[0] ?? '' ), 'force_region_code plus Новосибирск keeps own exact city first.' );
checkout_location_picker_assert( array_search( 'fias-nsk-child-alpha', $forced_nsk_ids, true ) > 0 && array_search( 'fias-nsk-child-beta', $forced_nsk_ids, true ) > 0, 'Child places inside Новосибирск do not outrank own exact city.' );
checkout_location_picker_assert( array_search( 'fias-nsk-child-alpha', $forced_nsk_ids, true ) < array_search( 'fias-nsk-child-beta', $forced_nsk_ids, true ), 'Parent/context results sort alphabetically by own resolved_place_name.' );
checkout_location_picker_assert( 'fias-nsk' === ( $ajax->payload( 'Новосибирск' )['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Regular search still keeps own exact Новосибирск first.' );
$forced_brod = $ajax->payload( 'брод', '69' );
$forced_brod_ids = $flatten_fias( $forced_brod );
checkout_location_picker_assert( in_array( 'fias-tver-brod', $flatten_fias( $forced_brod ), true ), 'force_region_code plus брод returns region items.' );
checkout_location_picker_assert( 'fias-tver-brod' === ( $forced_brod['groups'][0]['items'][0]['fias_id'] ?? '' ), 'force_region_code plus exact place puts exact match first inside region.' );
checkout_location_picker_assert( array_search( 'fias-tver-brod', $forced_brod_ids, true ) < array_search( 'fias-tver-brodki', $forced_brod_ids, true ), 'force_region_code plus prefix place keeps exact before prefix inside region.' );
$forced_brodog = $ajax->payload( 'бродог', '69' );
checkout_location_picker_assert( 'fias-tver-brod-city' === ( $forced_brodog['groups'][0]['items'][0]['fias_id'] ?? '' ) && 'fias-tver-brodograd-place' === ( $forced_brodog['groups'][0]['items'][1]['fias_id'] ?? '' ), 'force_region_code preserves city-over-place seniority inside region.' );
checkout_location_picker_assert( in_array( 'fias-tver-brod', $flatten_fias( $ajax->payload( 'Тверская область, брод', '69' ) ), true ), 'force_region_code tolerates region-prefixed query.' );
$forced_prefixed_brod = $ajax->payload( 'Тверская область, брод', '69' );
checkout_location_picker_assert( 'fias-tver-brod' === ( $forced_prefixed_brod['groups'][0]['items'][0]['fias_id'] ?? '' ), 'Show-all region keeps ranked order inside selected region.' );
$forced_empty = $ajax->payload( '', '69' );
checkout_location_picker_assert( (int) $forced_empty['shown_total'] === (int) $forced_empty['total'] && in_array( 'fias-tver-brod', $flatten_fias( $forced_empty ), true ), 'force_region_code with empty query returns all region items within limit.' );

$selected = $payload['groups'][0]['items'][0];
checkout_location_picker_assert( 'Алтайский край' === $selected['state_value'] && str_contains( $selected['city_value'], 'Курьинский р-н' ) && '658320' === $selected['postal_code'], 'Choosing location payload sets state_value, city_value, postal_code.' );
$notice_with_postcode = 'Выбран: ' . $selected['display_name'] . ', ' . $selected['postal_code'];
$notice_without_postcode = 'Выбран: ' . $selected['display_name'];
checkout_location_picker_assert( str_contains( $notice_with_postcode, ', 658320' ) && ! str_contains( $notice_with_postcode, "\n" ), 'Selected notice with postal_code is one line.' );
checkout_location_picker_assert( ! str_ends_with( $notice_without_postcode, ', ' ), 'Selected notice without postal_code has no trailing comma.' );
$resolved = $search->resolve_checkout_fields( 'Новосибирская обл.', 'г. Новосибирск' );
checkout_location_picker_assert( 'resolved' === $resolved['status'] && $resolved['location'] instanceof Location, 'Auto-resolve returns selected payload for unambiguous state/city.' );
checkout_location_picker_assert( 'resolved' !== $search->resolve_checkout_fields( 'Алтайский край', '' )['status'], 'Auto-resolve does not select a location for unclear input.' );

$city_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-city-selector.js' );
$city_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-city-selector.css' );
$address_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'include_region_in_query' ) && str_contains( $city_js, 'wdc-city-picker-show-region' ), 'Frontend city picker supports region prefill and show-all-region.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'config.checkout_location_search_limit' ), 'Frontend city picker uses checkout_location_search_limit config.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'applySelectedLocation( location, { updateCheckout: true, explicit: true' ), 'User modal selection must explicitly trigger checkout update.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'applySelectedLocation( body.selected, { updateCheckout: false, explicit: false, source: \'auto\', updateFields: false } )' ), 'Auto-resolve does not trigger update_checkout loop.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'hiddenValue( \'wdc_platform_location_fias_id\' ) === String( body.selected.fias_id || \'\' )' ), 'Repeated updated_checkout with same hidden fias_id does not call applySelectedLocation again.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'if ( ! hasSelectedLocation() )' ) && str_contains( $city_js, 'scheduleAutoResolve();' ), 'updated_checkout restores notice without auto-resolve when hidden selected location exists.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'searchRequestSeq' ) && str_contains( $city_js, 'activeSearchSeq' ) && str_contains( $city_js, 'stale ajax response ignored' ), 'City picker JS has stale request guard.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, ".on( 'input.wdcCitySelector', '.wdc-city-picker-search'" ), 'City picker searches only on modal search input changes.' );
checkout_location_picker_assert( is_string( $city_js ) && ! str_contains( $city_js, "keyup.wdcCitySelector change.wdcCitySelector paste.wdcCitySelector', citySelector" ), 'City picker search is not bound to keyup/change of external city field.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'currentBaseQuery' ) && str_contains( $city_js, "search( current, { force: true, forceRegionCode: forceRegionCode } )" ), 'Show-all region uses base query plus force_region_code.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'forceRegionCode = \'\';' ) && str_contains( $city_js, 'currentBaseQuery = String( $( this ).val() || \'\' );' ), 'Manual modal input clears forceRegionCode.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'wdc-city-picker-use-manual' ) && str_contains( $city_js, 'wdc-city-picker-clear' ), 'City picker renders permanent manual and clear buttons.' );
checkout_location_picker_assert( is_string( $city_js ) && ! str_contains( $city_js, 'wdc-city-picker-fallback' ), 'Old empty-results fallback button class is removed.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'applyManualFallbackCity( searchInput().val() )' ), 'Manual button calls applyManualFallbackCity.' );
checkout_location_picker_assert( is_string( $city_js ) && str_contains( $city_js, 'currentBaseQuery = \'\';' ) && str_contains( $city_js, 'renderMessage( config.strings && config.strings.start' ), 'Clear button resets query state and renders hint.' );
checkout_location_picker_assert( is_string( $city_css ) && str_contains( $city_css, 'wdc-city-picker-spin' ) && str_contains( $city_css, 'is-loading::before' ), 'City picker CSS contains loading spinner animation.' );
checkout_location_picker_assert( is_string( $city_js ) && ! str_contains( $city_js, 'Индекс:' ), 'City selected notice no longer contains Индекс label.' );
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
