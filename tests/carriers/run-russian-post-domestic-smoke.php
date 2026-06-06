<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCourierTariffProbeService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'wdc-rpd-postcode-test-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		/** @var array<int,array<string,mixed>> */
		public array $rows = array();
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $service_settings = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && empty( $row['deleted'] ) ) {
						return $row;
					}
				}
			}

			return null;
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$service_id = (int) $matches[1];
				return array_values( array_filter( $this->service_settings, static fn ( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) === $service_id ) );
			}

			return array();
		}
	}
}

$GLOBALS['wdc_rpd_options'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_wc_session'] = new class {
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
};
$GLOBALS['wdc_rpd_wc'] = new class {
	public mixed $session;
	public function __construct() { $this->session = $GLOBALS['wdc_rpd_wc_session']; }
};

function rpd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_rpd_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['wdc_rpd_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_rpd_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_rpd_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function add_query_arg( array $params, string $url ): string { return $url . '?' . http_build_query( $params ); }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-05-26 10:00:00 UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_remote_get( string $url, array $args = array() ): mixed {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
	$GLOBALS['wdc_rpd_requests'][] = $params;
	$GLOBALS['wdc_rpd_request_urls'][] = $url;
	if ( isset( $params['mailtype'] ) ) {
		$to = (string) ( $params['to'] ?? '' );
		if ( '630201' === $to ) {
			return new WP_Error( 'probe transport failed' );
		}
		if ( '630202' === $to ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => 'not-json' );
		}
		if ( '630203' === $to ) {
			return array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'errors' => array( array( 'code' => 2007, 'msg' => 'no courier delivery' ) ) ) ) );
		}
		if ( '630204' === $to ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'pay' => 12000 ) ) );
		}
		if ( in_array( $to, array( '630205', '630206', '630207', '630208' ), true ) ) {
			$codes = array( '630205' => 2005, '630206' => 2008, '630207' => 2009, '630208' => 2010 );
			return array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'errors' => array( array( 'code' => $codes[ $to ], 'msg' => 'courier unavailable ' . $codes[ $to ] ) ) ) ) );
		}
		if ( '630209' === $to ) {
			return array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'errors' => array( array( 'code' => 9999, 'msg' => 'unexpected tariff error' ) ) ) ) );
		}
		if ( '630210' === $to ) {
			return array( 'response' => array( 'code' => 500 ), 'body' => json_encode( array( 'message' => 'server failed' ) ) );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'paynds' => 12345, 'pay' => 12000 ) ) );
	}
	if ( ! empty( $params['force_errorcode'] ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'errorcode' => 42, 'errormsg' => 'bad domestic request' ) ) );
	}
	$object = (int) ( $params['object'] ?? 0 );
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'pay' => ( 400 + $object % 100 ) * 100,
				'nds' => 0,
				'paynds' => ( 400 + $object % 100 ) * 100,
				'delivery' => array( 'min' => 5, 'max' => 6 ),
				'transtype' => 1,
				'delivery-to' => (string) ( $params['to'] ?? '' ),
				'items' => array(
					array(
						'name' => 'base',
						'serviceon' => 1,
						'tariff' => array( 'valnds' => 1234 ),
						'delivery' => array( 'min' => 2, 'max' => 4 ),
						'pay' => 80,
						'nds' => 20,
						'paynds' => 100,
					),
				),
			)
		),
	);
}
function wp_remote_post( string $url, array $args = array() ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'suggestions' => array(
					array(
						'data' => array(
							'fias_id' => 'fias-nsk',
							'city' => 'Novosibirsk',
							'settlement' => '',
							'postal_code' => '630099',
						),
					),
				),
			)
		),
	);
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
function WC(): object { return $GLOBALS['wdc_rpd_wc']; }
function rpd_service_setting_format( string $key ): string {
	return match ( $key ) {
		'insurance_enabled', 'cache_until_end_of_day', 'fallback_enabled', 'debug' => 'bool',
		'timeout', 'vat_rate' => 'number',
		'from_postcodes', 'tariff_variants' => 'json',
		default => 'string',
	};
}
function rpd_encode_service_setting( mixed $value, string $format ): string {
	return match ( $format ) {
		'json' => wp_json_encode( $value ) ?: 'null',
		'bool' => ! empty( $value ) ? '1' : '0',
		'number' => (string) $value,
		default => (string) $value,
	};
}
function rpd_replace_service_settings( wpdb $db, array $values ): void {
	$rows = array();
	foreach ( $values as $key => $value ) {
		$format = rpd_service_setting_format( (string) $key );
		$rows[] = array(
			'service_id' => 1,
			'setting_key' => (string) $key,
			'setting_value' => rpd_encode_service_setting( $value, $format ),
			'value_format' => $format,
		);
	}
	$db->service_settings = $rows;
}

$settings = new SettingsRepository();
$service_db = new wpdb();
$service_db->services = array(
	array(
		'id' => 1,
		'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
		'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
		'service_type' => 'api',
		'title' => RussianPostDomesticSettings::TITLE,
		'enabled' => 1,
		'deleted' => 0,
	)
);
rpd_replace_service_settings( $service_db, array( 'insurance_enabled' => false, 'default_from_postcode' => '630005' ) );
$service_settings = new DeliveryServiceSettingsRepository( $service_db );
$domestic_settings = new RussianPostDomesticSettings( $settings, new DeliveryServiceRepository( $service_db ), $service_settings );
$encryption = new EncryptionService();
$settings->set(
	DaDataTokenPool::OPTION_KEY,
	array(
		array(
			'id' => 'token-1',
			'encrypted_token' => $encryption->encrypt( 'dadata-token' ),
			'masked_token' => '********',
			'daily_limit' => 100,
			'enabled' => true,
		),
	)
);
$postcode_client = new DaDataPostcodeClient( new DaDataTokenPool( $settings, $encryption ), new Logger(), 3 );
$carrier = new RussianPostDomesticCarrier( $domestic_settings, new RussianPostDomesticApiClient( $domestic_settings, new Logger() ), new RussianPostDomesticTariffVariantResolver(), new Logger(), $postcode_client );
$default_objects = array_map( static fn( DomesticTariffVariant $variant ): int => $variant->object_code, ( new RussianPostDomesticTariffVariantResolver() )->defaults() );
rpd_assert( ! array_intersect( array( 27030, 27020, 28030, 28020 ), $default_objects ), 'Deprecated domestic variants must not be created by defaults.' );
$saved_settings = $domestic_settings->all( RussianPostDomesticSettings::SERVICE_KEY );
$saved_settings['tariff_variants'] = array(
	DomesticTariffVariant::from_array(
		array(
			'object_code' => 27030,
			'title' => 'Legacy Посылка стандарт',
			'enabled' => true,
			'delivery_type' => DeliveryType::PICKUP,
			'requires_declared_value' => false,
			'always_available' => false,
			'sort_order' => 1,
		)
	)->to_array(),
);
$saved_variants = ( new RussianPostDomesticTariffVariantResolver() )->variants( $saved_settings, DeliveryType::PICKUP, 1000 );
rpd_assert( 1 === count( $saved_variants ) && 27030 === $saved_variants[0]->object_code, 'Saved domestic tariff variants must load from unified service settings JSON.' );
$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000 );
$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::PICKUP ) );
$quote = $carrier->quote( $request );

rpd_assert( $quote->has_available_rates(), 'Domestic pickup quote must return rates when postcode exists.' );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4030', $objects, true ) && in_array( '23030', $objects, true ), 'Non-declared pickup variants must work when insurance is disabled.' );
rpd_assert( ! in_array( '27030', $objects, true ) && ! in_array( '4020', $objects, true ) && ! in_array( '27020', $objects, true ) && ! in_array( '23020', $objects, true ), 'Deprecated/default-declared pickup variants must be hidden when insurance is disabled.' );
rpd_assert( in_array( '54020', $objects, true ), '54020 must remain always available.' );
rpd_assert( 99 === (int) $GLOBALS['wdc_rpd_requests'][0]['pack'], 'Domestic tariff requests must force pack=99.' );
rpd_assert( ! isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Non-declared variants must not send sumoc.' );
rpd_assert( $quote->rates[0]->requires_pickup_point && empty( $quote->rates[0]->meta['no_pickup_selection'] ), 'Pickup variants must require checkout pickup selection.' );
$item_summary = $quote->rates[0]->meta['items_summary'][0] ?? array();
rpd_assert( 1 === (int) ( $item_summary['serviceon'] ?? 0 ) && 1234 === (int) ( $item_summary['tariff']['valnds'] ?? 0 ) && 2 === (int) ( $item_summary['delivery']['min'] ?? 0 ) && 4 === (int) ( $item_summary['delivery']['max'] ?? 0 ), 'Domestic items summary must include serviceon, tariff.valnds, and delivery min/max.' );

$configured_tariffs = array(
	DomesticTariffVariant::from_array(
		array(
			'object_code' => 23020,
			'title' => 'Посылка онлайн с объявленной ценностью',
			'enabled' => true,
			'delivery_type' => DeliveryType::PICKUP,
			'requires_declared_value' => true,
			'sort_order' => 1,
		)
	)->to_array(),
	DomesticTariffVariant::from_array(
		array(
			'object_code' => 23030,
			'title' => 'Посылка онлайн',
			'enabled' => true,
			'delivery_type' => DeliveryType::PICKUP,
			'requires_declared_value' => false,
			'sort_order' => 2,
		)
	)->to_array(),
	DomesticTariffVariant::from_array(
		array(
			'object_code' => 47030,
			'title' => 'Посылка 1 класса',
			'enabled' => true,
			'delivery_type' => DeliveryType::PICKUP,
			'requires_declared_value' => false,
			'sort_order' => 3,
		)
	)->to_array(),
);
$configured_settings = array_merge( $domestic_settings->all(), array( 'insurance_enabled' => false, 'tariff_variants' => $configured_tariffs ) );
$configured_variants = ( new RussianPostDomesticTariffVariantResolver() )->variants( $configured_settings, DeliveryType::PICKUP, 1000 );
$configured_objects = array_map( static fn ( DomesticTariffVariant $variant ): int => $variant->object_code, $configured_variants );
rpd_assert( in_array( 23020, $configured_objects, true ), 'Explicitly enabled declared-value tariff must not be hidden when insurance_enabled=false.' );
rpd_assert( in_array( 47030, $configured_objects, true ), 'Explicitly enabled 47030 tariff must not be filtered when weight matches.' );
rpd_replace_service_settings( $service_db, $configured_settings );
$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$configured_package = Package::from_items( array( new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000 ) ), 0, Money::from_rubles( 1500 ), Money::from_rubles( 1000 ) );
$configured_quote = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $configured_package, 'card', Money::from_rubles( 1500 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::PICKUP ) ) );
$configured_quote_objects = array_map( static fn( $rate ): string => $rate->tariff_key, $configured_quote->rates );
rpd_assert( in_array( '23020', $configured_quote_objects, true ) && in_array( '23030', $configured_quote_objects, true ) && in_array( '47030', $configured_quote_objects, true ), 'Explicitly configured pickup tariffs must all be quoted when API returns prices.' );
rpd_assert( '23020' === (string) ( $GLOBALS['wdc_rpd_requests'][0]['object'] ?? '' ) && isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ) && 100000 === (int) $GLOBALS['wdc_rpd_requests'][0]['sumoc'], '23020 request params must include sumoc from package item totals.' );
rpd_assert( '23030' === (string) ( $GLOBALS['wdc_rpd_requests'][1]['object'] ?? '' ) && ! isset( $GLOBALS['wdc_rpd_requests'][1]['sumoc'] ), '23030 request params must not include sumoc.' );
$base_domestic_settings = array_merge( $domestic_settings->all(), array( 'insurance_enabled' => false, 'tariff_variants' => array() ) );
rpd_replace_service_settings( $service_db, $base_domestic_settings );

rpd_replace_service_settings( $service_db, array_merge( $domestic_settings->all(), array( 'insurance_enabled' => true ) ) );
$GLOBALS['wdc_rpd_requests'] = array();
$quote = $carrier->quote( $request );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4020', $objects, true ) && in_array( '23020', $objects, true ) && ! in_array( '27020', $objects, true ), 'Declared-value variants must work when insurance is enabled and deprecated 27020 must stay out of defaults.' );
rpd_assert( in_array( '54020', $objects, true ), '54020 must remain available when insurance is enabled.' );
rpd_assert( isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Declared-value variants must send sumoc.' );

rpd_replace_service_settings( $service_db, array_merge( $domestic_settings->all(), array( 'insurance_enabled' => false ) ) );
$courier = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::COURIER ) ) );
$courier_objects = array_map( static fn( $rate ): string => $rate->tariff_key, $courier->rates );
rpd_assert( in_array( '41030', $courier_objects, true ) && in_array( '52030', $courier_objects, true ) && in_array( '7030', $courier_objects, true ) && ! in_array( '28030', $courier_objects, true ), '41030, 52030, and 7030 must be available without insurance; deprecated 28030 must stay out of defaults.' );
rpd_assert( ! in_array( '28020', $courier_objects, true ) && ! in_array( '7020', $courier_objects, true ), 'Declared-value EMS courier variants must be hidden without insurance.' );
rpd_assert( DeliveryType::COURIER === $courier->rates[0]->delivery_type && $courier->rates[0]->requires_courier_address, 'Courier variants must use courier delivery type.' );
rpd_replace_service_settings( $service_db, array_merge( $domestic_settings->all(), array( 'insurance_enabled' => true ) ) );
$courier_insured = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::COURIER ) ) );
$courier_insured_objects = array_map( static fn( $rate ): string => $rate->tariff_key, $courier_insured->rates );
rpd_assert( in_array( '7020', $courier_insured_objects, true ) && ! in_array( '28020', $courier_insured_objects, true ), 'Declared-value EMS courier variants must be available with insurance and deprecated 28020 must stay out of defaults.' );
rpd_replace_service_settings( $service_db, array_merge( $domestic_settings->all(), array( 'insurance_enabled' => false ) ) );

$probe = new RussianPostCourierTariffProbeService( new Logger() );
$GLOBALS['wdc_rpd_request_urls'] = array();
$probe_success = $probe->probe( '630200' );
$probe_success_url = (string) ( $GLOBALS['wdc_rpd_request_urls'][0] ?? '' );
$probe_success_query = (string) parse_url( $probe_success_url, PHP_URL_QUERY );
parse_str( $probe_success_query, $probe_success_params );
rpd_assert( str_contains( $probe_success_url, '/v2/calculate/tariff?json&mailtype=24' ) && ! str_contains( $probe_success_url, '?html' ) && ! str_contains( $probe_success_url, 'json=' ), 'Russian Post courier tariff probe must call the explicit bare ?json endpoint.' );
rpd_assert( '24' === (string) ( $probe_success_params['mailtype'] ?? '' ) && '3' === (string) ( $probe_success_params['mailctg'] ?? '' ) && '1' === (string) ( $probe_success_params['directctg'] ?? '' ) && '630005' === (string) ( $probe_success_params['from'] ?? '' ) && '630200' === (string) ( $probe_success_params['to'] ?? '' ) && '1000' === (string) ( $probe_success_params['weight'] ?? '' ) && '1000' === (string) ( $probe_success_params['weightpay'] ?? '' ), 'Russian Post courier tariff probe URL must include required tariff parameters.' );
rpd_assert( preg_match( '/^\d{8}$/', (string) ( $probe_success_params['date'] ?? '' ) ) === 1 && preg_match( '/^\d{4}$/', (string) ( $probe_success_params['time'] ?? '' ) ) === 1, 'Russian Post courier tariff probe must use YYYYMMDD date and HHMM JSON time.' );
rpd_assert( ! empty( $probe_success['success'] ) && empty( $probe_success['unavailable'] ) && empty( $probe_success['api_error'] ) && 12345 === (int) ( $probe_success['paynds'] ?? 0 ) && '630200' === $probe_success['postal_code'], 'Russian Post courier tariff probe must accept successful paynds JSON.' );
$probe_wp_error = $probe->probe( '630201' );
rpd_assert( empty( $probe_wp_error['success'] ) && ! empty( $probe_wp_error['api_error'] ) && 'http_error' === $probe_wp_error['error_code'], 'Russian Post courier tariff probe must treat WP_Error as API error.' );
$probe_invalid_json = $probe->probe( '630202' );
rpd_assert( empty( $probe_invalid_json['success'] ) && ! empty( $probe_invalid_json['api_error'] ) && 'invalid_json' === $probe_invalid_json['error_code'], 'Russian Post courier tariff probe must treat invalid JSON as API error.' );
$probe_2007 = $probe->probe( '630203' );
rpd_assert( empty( $probe_2007['success'] ) && ! empty( $probe_2007['unavailable'] ) && empty( $probe_2007['api_error'] ) && '2007' === $probe_2007['error_code'], 'Russian Post courier tariff probe must treat HTTP 400 error 2007 as normal unavailable.' );
$probe_no_paynds = $probe->probe( '630204' );
rpd_assert( empty( $probe_no_paynds['success'] ) && ! empty( $probe_no_paynds['api_error'] ) && 'empty_price' === $probe_no_paynds['error_code'], 'Russian Post courier tariff probe must treat HTTP 200 without paynds as API error.' );
foreach ( array( '630205' => '2005', '630206' => '2008', '630207' => '2009', '630208' => '2010' ) as $postcode => $error_code ) {
	$probe_unavailable = $probe->probe( (string) $postcode );
	rpd_assert( empty( $probe_unavailable['success'] ) && ! empty( $probe_unavailable['unavailable'] ) && empty( $probe_unavailable['api_error'] ) && $error_code === (string) ( $probe_unavailable['error_code'] ?? '' ), 'Russian Post courier tariff probe must treat HTTP 400 error ' . $error_code . ' as normal unavailable.' );
}
$probe_unexpected_400 = $probe->probe( '630209' );
rpd_assert( empty( $probe_unexpected_400['success'] ) && empty( $probe_unexpected_400['unavailable'] ) && ! empty( $probe_unexpected_400['api_error'] ) && '9999' === (string) ( $probe_unexpected_400['error_code'] ?? '' ), 'Russian Post courier tariff probe must treat unexpected HTTP 400 error code as API error.' );
$probe_500 = $probe->probe( '630210' );
rpd_assert( empty( $probe_500['success'] ) && ! empty( $probe_500['api_error'] ) && 'http_status_500' === (string) ( $probe_500['error_code'] ?? '' ), 'Russian Post courier tariff probe must treat HTTP 500 as API error.' );

$location_db = new wpdb();
$location_db->rows = array(
	10 => array( 'id' => 10, 'active' => 1, 'country_code' => 'RU', 'postal_code' => '630000', 'russianpost_courier_calc_postal_code' => '630005' ),
);
$carrier_with_locations = new RussianPostDomesticCarrier( $domestic_settings, new RussianPostDomesticApiClient( $domestic_settings, new Logger() ), new RussianPostDomesticTariffVariantResolver(), new Logger(), $postcode_client, new LocationRepository( $location_db ) );
$GLOBALS['wdc_rpd_transients'] = array();
$GLOBALS['wdc_rpd_requests'] = array();
$technical_courier = $carrier_with_locations->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630109' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::COURIER, 'selected_location_id' => 10 ) ) );
rpd_assert( $technical_courier->has_available_rates() && '630109' === (string) ( $GLOBALS['wdc_rpd_requests'][0]['to'] ?? '' ), 'Domestic courier tariff request must use checkout postcode when selected_location_id points to a different base postcode mapping.' );
rpd_assert( '630109' === $technical_courier->destination->postcode && '630109' === (string) ( $technical_courier->raw_reference['postcode'] ?? '' ), 'Domestic courier technical postcode must not change visible checkout postcode.' );

$mapped_checkout_db = new wpdb();
$mapped_checkout_db->rows = array(
	10 => array( 'id' => 10, 'active' => 1, 'country_code' => 'RU', 'postal_code' => '630000', 'russianpost_courier_calc_postal_code' => '630109' ),
);
$carrier_with_checkout_mapping = new RussianPostDomesticCarrier( $domestic_settings, new RussianPostDomesticApiClient( $domestic_settings, new Logger() ), new RussianPostDomesticTariffVariantResolver(), new Logger(), $postcode_client, new LocationRepository( $mapped_checkout_db ) );
$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$mapped_checkout_courier = $carrier_with_checkout_mapping->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630000' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::COURIER, 'selected_location_id' => 10 ) ) );
rpd_assert( $mapped_checkout_courier->has_available_rates() && '630109' === (string) ( $GLOBALS['wdc_rpd_requests'][0]['to'] ?? '' ), 'Domestic courier tariff request must use mapping for the actual checkout postcode when present.' );

$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$unmapped_checkout_courier = $carrier_with_checkout_mapping->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Moscow', postcode: '101000' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::COURIER, 'selected_location_id' => 10 ) ) );
rpd_assert( $unmapped_checkout_courier->has_available_rates() && '101000' === (string) ( $GLOBALS['wdc_rpd_requests'][0]['to'] ?? '' ), 'Domestic courier tariff request must fall back to checkout postcode when no mapping exists.' );

$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$technical_pickup = $carrier_with_checkout_mapping->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630000' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::PICKUP, 'selected_location_id' => 10 ) ) );
rpd_assert( $technical_pickup->has_available_rates() && '630000' === (string) ( $GLOBALS['wdc_rpd_requests'][0]['to'] ?? '' ), 'Domestic pickup tariff request must ignore russianpost courier technical postcode.' );

$GLOBALS['wdc_rpd_requests'] = array();
$enriched = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '', fias_id: 'fias-nsk' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::PICKUP ) ) );
rpd_assert( $enriched->has_available_rates() && '630099' === (string) ( $enriched->raw_reference['postcode'] ?? '' ), 'Domestic carrier must use DaData postcode enrichment fallback when postcode is empty.' );

$missing = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '999999999', fias_id: 'fias-nsk' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::SERVICE_KEY, 'delivery_type' => DeliveryType::PICKUP ) ) );
rpd_assert( ! $missing->has_available_rates() && 'postcode_required' === $missing->error_code, 'Domestic carrier must not silently calculate without a real postcode.' );

$api_client = new RussianPostDomesticApiClient( $domestic_settings, new Logger() );
$api_error = $api_client->calculate_tariff( array( 'object' => 4030, 'from' => '630005', 'to' => '630099', 'weight' => 1000, 'date' => '20260526', 'pack' => 99, 'force_errorcode' => 1 ) );
rpd_assert( empty( $api_error['success'] ) && 'api_error' === (string) ( $api_error['error_code'] ?? '' ), 'Domestic API client must treat errorcode/errormsg as API errors.' );
$variant_with_comment = DomesticTariffVariant::from_array( array_merge( ( new RussianPostDomesticTariffVariantResolver() )->defaults()[0]->to_array(), array( 'admin_comment' => 'internal note' ) ) );
rpd_assert( 'internal note' === $variant_with_comment->admin_comment && 'internal note' === $variant_with_comment->to_array()['admin_comment'], 'Domestic tariff admin_comment must save and load in tariff variant JSON.' );
rpd_assert( ! array_key_exists( 'admin_comment', $quote->rates[0]->meta['domestic_tariff_variant'] ?? array() ), 'Domestic tariff admin_comment must not be exposed to checkout/order runtime meta.' );

$rule = new Rule( null, '+2 days', true, 10, 'default', '', RuleActionTypes::CHANGE_DELIVERY_DAYS, RuleOperationTypes::INCREASE, 2, RuleOperationBases::CALENDAR_DAYS, false, false );
$rate = $quote->rates[0];
$builder = new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) );
$built = $builder->apply(
	$rate,
	new RuleEvaluationContext( Money::from_rubles( 1000 ), $rate->price, $package, $request->destination, $rate->delivery_type, 'card', '2026-05-26', array(), array( 'original_delivery_min_days' => 5, 'original_delivery_max_days' => 6 ) ),
	array( $rule )
);
rpd_assert( 7 === $built['rate']->delivery_days->min_days && 8 === $built['rate']->delivery_days->max_days && DateRange::UNIT_CALENDAR_DAYS === $built['rate']->delivery_days->unit, 'Delivery ranges must support 5-6 + 2 => 7-8.' );

$session_manager = new CheckoutSessionManager();
$pickup_group_id = RussianPostDomesticSettings::checkout_group_id( DeliveryType::PICKUP );
$courier_group_id = RussianPostDomesticSettings::checkout_group_id( DeliveryType::COURIER );
$session_manager->save_selected_tariff( $pickup_group_id, array( 'object_code' => '47030', 'title' => 'Посылка 1 класса' ) );
$method_reflection = new ReflectionClass( NewShippingMethod::class );
$method = $method_reflection->newInstanceWithoutConstructor();
$session_property = $method_reflection->getProperty( 'session_manager' );
$session_property->setAccessible( true );
$session_property->setValue( $method, $session_manager );
$selector_method = $method_reflection->getMethod( 'tariff_selector_rate' );
$selector_method->setAccessible( true );
$rate_23030 = new DeliveryRate( RussianPostDomesticSettings::rate_id( DeliveryType::PICKUP, '23030' ), RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::SERVICE_KEY, RussianPostDomesticSettings::TITLE, '23030', 'Посылка онлайн', DeliveryType::PICKUP, 'Посылка онлайн', Money::from_rubles( 450 ), null, null, DateRange::range( 5, 6 ), '', '5-6 дн.', array(), false, '', false, false, array( 'tariff_selector_group' => true ) );
$rate_47030 = new DeliveryRate( RussianPostDomesticSettings::rate_id( DeliveryType::PICKUP, '47030' ), RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::SERVICE_KEY, RussianPostDomesticSettings::TITLE, '47030', 'Посылка 1 класса', DeliveryType::PICKUP, 'Посылка 1 класса', Money::from_rubles( 659 ), null, Money::from_rubles( 700 ), DateRange::single( 3 ), '', '2-3 дн.', array(), false, '', false, false, array( 'tariff_selector_group' => true ) );
$selected_rate = $selector_method->invoke( $method, $pickup_group_id, array( $rate_23030, $rate_47030 ) );
rpd_assert( $selected_rate instanceof DeliveryRate && 659.0 === $selected_rate->price->get_rubles() && '47030' === (string) ( $selected_rate->meta['selected_tariff_object'] ?? '' ), 'Selected domestic tariff must drive WC rate cost and selected object.' );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ' / ОПС - 3 дня' === $selected_rate->title, 'Domestic grouped method title must include pickup group and delivery days.' );
rpd_assert( '3 дня' === (string) ( $selected_rate->meta['tariff_variants'][1]['planned_delivery_comment'] ?? '' ), 'Domestic selector variant row must use final delivery days comment.' );
rpd_assert( is_array( $selected_rate->meta['tariff_variants'][1]['crossed_price'] ?? null ), 'Domestic selector variant row must keep crossed price per variant.' );
$selected_rate_again = $selector_method->invoke( $method, $pickup_group_id, array( $rate_23030, $rate_47030 ) );
$selected_session = $session_manager->selected_tariff( $pickup_group_id );
rpd_assert( $selected_rate_again instanceof DeliveryRate && 659.0 === $selected_rate_again->price->get_rubles() && '47030' === (string) ( $selected_session['object_code'] ?? '' ), 'Repeated tariff selector calculation must not reset valid selected tariff to the first variant.' );
$single_rate = $selector_method->invoke( $method, $pickup_group_id, array( $rate_23030 ) );
rpd_assert( array() === ( $single_rate->meta['tariff_variants'] ?? array() ) && RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ' / ОПС - 5-6 дней' === $single_rate->title, 'Single-tariff domestic service must not expose radio selector variants.' );
$mapper = new WooCommerceRateMapper();
$mapped_single_rate = $mapper->map( $single_rate );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ' / ОПС - 5-6 дней' === $mapped_single_rate['label'], 'Single-tariff domestic grouped label must include planned delivery comment.' );
rpd_assert( true === ( $mapped_single_rate['meta_data']['domestic_tariff_grouped'] ?? false ), 'Single-tariff domestic grouped meta must keep grouped marker for checkout rendering.' );
$single_wc_rate = new class( $mapped_single_rate['meta_data'] ) {
	/** @param array<string,mixed> $meta */
	public function __construct( private array $meta ) {}
	/** @return array<string,mixed> */
	public function get_meta_data(): array { return $this->meta; }
};
ob_start();
( new CheckoutRateRenderer( $session_manager ) )->render( $single_wc_rate );
$single_rate_html = (string) ob_get_clean();
rpd_assert( ! str_contains( $single_rate_html, 'wdc-platform-delivery-comment' ) && ! str_contains( $single_rate_html, $single_rate->planned_delivery_comment ), 'Single-tariff domestic grouped planned delivery comment must stay in the label only.' );
$mapped_multi_rate = $mapper->map( $selected_rate );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ' / ОПС - 3 дня' === $mapped_multi_rate['label'], 'Multi-tariff domestic grouped label must include selected tariff delivery days.' );
rpd_assert( '1 день' === DeliveryDaysFormatter::format( DateRange::single( 1 ) ) && '3 дня' === DeliveryDaysFormatter::format( DateRange::single( 3 ) ) && '5 дней' === DeliveryDaysFormatter::format( DateRange::single( 5 ) ) && '21 день' === DeliveryDaysFormatter::format( DateRange::single( 21 ) ), 'Delivery days formatter must use Russian singular/plural forms.' );
rpd_assert( '1-3 дня' === DeliveryDaysFormatter::format( DateRange::range( 1, 3 ) ) && '3-5 дней' === DeliveryDaysFormatter::format( DateRange::range( 3, 5 ) ), 'Delivery days formatter must use Russian range suffixes.' );
$rates_for_wc = $method_reflection->getMethod( 'rates_for_wc' );
$rates_for_wc->setAccessible( true );
$rate_24030 = new DeliveryRate( RussianPostDomesticSettings::rate_id( DeliveryType::COURIER, '24030' ), RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::SERVICE_KEY, RussianPostDomesticSettings::TITLE, '24030', 'Курьер онлайн', DeliveryType::COURIER, 'Курьер онлайн', Money::from_rubles( 800 ), null, null, DateRange::single( 1 ), '', '1 дн.', array(), false, '', false, true, array( 'tariff_selector_group' => true ) );
$grouped_rates = $rates_for_wc->invoke( $method, array( $rate_23030, $rate_47030, $rate_24030 ) );
rpd_assert( 2 === count( $grouped_rates ) && RussianPostDomesticSettings::SERVICE_KEY === $grouped_rates[0]->service_key && RussianPostDomesticSettings::SERVICE_KEY === $grouped_rates[1]->service_key && $pickup_group_id === $grouped_rates[0]->rate_id && $courier_group_id === $grouped_rates[1]->rate_id, 'Pickup and courier domestic checkout groups must share one service key and separate group ids.' );
rpd_assert( RussianPostDomesticSettings::COURIER_SERVICE_TITLE . ' - 1 день' === $grouped_rates[1]->title, 'Courier grouped method title must include courier group and delivery days.' );

echo "Russian Post domestic smoke OK\n";
