<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\DaData\DaDataLogger;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-dadata-suggestions-key' );

$GLOBALS['wdc_dadata_suggestions_options'] = array();
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dadata_suggestions_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dadata_suggestions_options'][ $key ] = $value; return true; }
function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_dadata_suggestions_http_requests'][] = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => 200 ),
		'body' => wp_json_encode(
			array(
				'suggestions' => array(
					array(
						'value' => 'г Новосибирск, Красный пр-кт, д 25',
						'unrestricted_value' => '630099, Новосибирская обл, г Новосибирск, Красный пр-кт, д 25',
						'data' => array(
							'fias_level' => '8',
							'region' => 'Новосибирская',
							'region_with_type' => 'Новосибирская обл',
							'region_fias_id' => 'region-fias',
							'region_kladr_id' => '5400000000000',
							'city' => 'Новосибирск',
							'city_with_type' => 'г Новосибирск',
							'city_fias_id' => 'city-fias',
							'city_kladr_id' => '5400000100000',
							'street' => 'Красный',
							'street_with_type' => 'Красный пр-кт',
							'street_fias_id' => 'street-fias',
							'street_kladr_id' => '54000001000123400',
							'house' => '25',
							'house_fias_id' => 'house-fias',
							'house_kladr_id' => 'house-kladr',
							'fias_id' => 'house-fias',
							'kladr_id' => 'house-kladr',
							'postal_code' => '630099',
						),
					),
				),
			)
		),
	);
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
	}
}

final class WdcDaDataSuggestionsSession {
	private array $data = array();
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
}

final class WdcDaDataSuggestionsWooCommerce {
	public WdcDaDataSuggestionsSession $session;
	public function __construct() { $this->session = new WdcDaDataSuggestionsSession(); }
}

function WC(): WdcDaDataSuggestionsWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WdcDaDataSuggestionsWooCommerce();
	}
	return $wc;
}

final class WdcDaDataSuggestionsOrder {
	public array $meta = array();
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function dadata_suggestions_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new SettingsRepository();
$settings->replace(
	array_merge(
		$settings->all(),
		array(
			'dadata_suggestions_enabled' => true,
			'dadata_suggestions_count' => 10,
		)
	)
);
$suggestion_settings = new AddressSuggestionSettings( $settings, new EncryptionService() );
$suggestion_settings->save_api_key( 'secret-api-key' );
$client = new DaDataSuggestionClient( $suggestion_settings, new DaDataLogger( new Logger() ) );

$city_body = $client->body( 'city', 'Новосибирск' );
dadata_suggestions_assert( array( array( 'country_iso_code' => 'RU' ) ) === $city_body['locations'], 'City stage must restrict locations to RU.' );
dadata_suggestions_assert( array( 'value' => 'city' ) === $city_body['from_bound'], 'City stage must start from city.' );
dadata_suggestions_assert( array( 'value' => 'settlement' ) === $city_body['to_bound'], 'City stage must end at settlement.' );

$address_body = $client->body( 'address', 'Красный 25', array( 'city_kladr_id' => '5400000100000' ) );
dadata_suggestions_assert( array( array( 'country_iso_code' => 'RU' ) ) === $address_body['locations'], 'Address stage must keep RU locations only.' );
dadata_suggestions_assert( array( array( 'kladr_id' => '5400000100000' ) ) === $address_body['locations_boost'], 'Address stage must use city KLADR as locations_boost.' );
dadata_suggestions_assert( array( 'value' => 'street' ) === $address_body['from_bound'], 'Address stage must start from street.' );
dadata_suggestions_assert( array( 'value' => 'house' ) === $address_body['to_bound'], 'Address stage must end at house.' );

$house_body = $client->body( 'house_after_street', '25', array( 'street_fias_id' => 'street-fias' ) );
dadata_suggestions_assert( array( array( 'fias_id' => 'street-fias' ) ) === $house_body['locations'], 'House stage must restrict by street FIAS ID.' );
dadata_suggestions_assert( true === $house_body['restrict_value'], 'House stage must restrict value.' );
dadata_suggestions_assert( 20 === $house_body['count'], 'House stage must request up to 20 houses.' );

$resolve_body = $client->body( 'resolve', '630099, Новосибирская обл, г Новосибирск, Красный пр-кт, д 25' );
dadata_suggestions_assert( 1 === $resolve_body['count'], 'Resolve stage must use count=1.' );

$response = $client->suggest( 'address', 'Красный 25', array( 'city_kladr_id' => '5400000100000' ) );
dadata_suggestions_assert( true === $response['success'], 'DaData suggestion client must accept mocked response.' );
dadata_suggestions_assert( 1 === count( $GLOBALS['wdc_dadata_suggestions_http_requests'] ), 'DaData suggestion client must perform one HTTP request.' );
$request = $GLOBALS['wdc_dadata_suggestions_http_requests'][0];
dadata_suggestions_assert( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address' === $request['url'], 'DaData suggestion client must use Suggest API URL.' );
dadata_suggestions_assert( 'Token secret-api-key' === $request['args']['headers']['Authorization'], 'DaData suggestion client must send Authorization token.' );
dadata_suggestions_assert( ! isset( $request['args']['headers']['X-Secret'] ), 'DaData suggestion client must not send X-Secret.' );
dadata_suggestions_assert( is_array( json_decode( (string) $request['args']['body'], true ) ), 'DaData suggestion request body must be a JSON object.' );

$normalizer = new AddressSuggestionNormalizer();
$street_item = $normalizer->normalize( array( 'value' => 'Красный пр-кт', 'data' => array( 'fias_level' => '7', 'street_with_type' => 'Красный пр-кт' ) ) );
dadata_suggestions_assert( 'street' === $street_item['level'], 'Normalizer must detect street suggestions.' );
dadata_suggestions_assert( false === $street_item['isDeliverable'], 'Street without house must not be deliverable.' );
$house_item = $normalizer->normalize( $response['suggestions'][0] );
dadata_suggestions_assert( 'house' === $house_item['level'], 'Normalizer must detect house suggestions.' );
dadata_suggestions_assert( true === $house_item['isDeliverable'], 'Normalizer must mark fias_level 8 as deliverable.' );
foreach ( array( '9', '75' ) as $level ) {
	$item = $normalizer->normalize( array( 'value' => 'test', 'data' => array( 'fias_level' => $level, 'house' => '1' ) ) );
	dadata_suggestions_assert( true === $item['isDeliverable'], 'Normalizer must mark fias_level ' . $level . ' as deliverable.' );
}

$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
foreach ( array( 'shipping_city', 'billing_city', 'shipping_address_1', 'billing_address_1', 'shipping_address_2', 'billing_address_2', 'house_after_street', 'resolve', 'dadata_status', 'dadata_unrestricted_value', 'dadata_region_fias_id', 'dadata_city_kladr_id', 'dadata_street_fias_id', 'dadata_house_fias_id', 'dadata_fias_level', 'update_checkout', 'wdc_platform_dadata_address_suggest' ) as $needle ) {
	dadata_suggestions_assert( str_contains( $js, $needle ), 'Frontend suggestions JS must contain ' . $needle . '.' );
}
dadata_suggestions_assert( ! str_contains( $js, 'api_key' ) && ! str_contains( $js, 'secret-api-key' ), 'Frontend suggestions JS must not contain API key names or values.' );
dadata_suggestions_assert( str_contains( $js, 'field( prefix, \'city\' ).val( data.city || data.settlement' ), 'Selected house must update city from selected address.' );
dadata_suggestions_assert( str_contains( $js, "'manual'" ), 'Frontend must support manual fallback status.' );

$registrar = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-address-suggestions' ), 'ShippingMethodRegistrar must enqueue address suggestions assets.' );
dadata_suggestions_assert( str_contains( $registrar, "'enabled'  => " . '$this->suggestions_enabled()' ), 'Frontend config must expose only enabled state.' );
dadata_suggestions_assert( ! str_contains( $registrar, "'api_key'" ) && ! str_contains( $registrar, '"api_key"' ), 'ShippingMethodRegistrar must not localize the DaData API key.' );

$GLOBALS['wdc_dadata_suggestions_options'] = array();
$disabled_settings = new SettingsRepository();
$disabled_service_settings = new AddressSuggestionSettings( $disabled_settings, new EncryptionService() );
dadata_suggestions_assert( false === $disabled_service_settings->enabled(), 'DaData suggestions must be disabled by default and not break checkout.' );

$session = new CheckoutSessionManager();
$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key' => 'demo',
			'rate_id' => 'demo:courier',
			'delivery_type' => 'courier',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$order = new WdcDaDataSuggestionsOrder();
( new OrderShippingMetaPersister( $session ) )->persist(
	$order,
	array(
		'shipping_dadata_status' => 'resolved',
		'shipping_dadata_city' => 'Другой город',
		'shipping_dadata_city_kladr_id' => '5200000100000',
		'shipping_dadata_fias_id' => 'house-fias',
		'shipping_dadata_unrestricted_value' => 'Другой город, ул Тестовая, д 1',
		'shipping_postcode' => '630099',
		'shipping_address_1' => 'Тестовая, 1',
	)
);
dadata_suggestions_assert( 'Другой город' === $order->meta['_shipping_dadata_city'], 'Order meta must persist shipping DaData hidden fields.' );
dadata_suggestions_assert( 'dadata' === $order->meta['_wdc_platform_normalization_source'], 'Resolved DaData selection must set WDC source to dadata.' );
dadata_suggestions_assert( true === $order->meta['_wdc_platform_normalized'], 'Resolved DaData selection must set normalized=true.' );
dadata_suggestions_assert( '630099' === $order->meta['_wdc_platform_resolved_postcode'], 'Resolved DaData selection must persist resolved postcode.' );
dadata_suggestions_assert( 'house-fias' === $order->meta['_wdc_platform_fias_id'], 'Resolved DaData selection must persist FIAS ID.' );

$manual_order = new WdcDaDataSuggestionsOrder();
( new OrderShippingMetaPersister( $session ) )->persist(
	$manual_order,
	array(
		'billing_dadata_status' => 'manual',
		'billing_address_1' => 'Свободный адрес',
	)
);
dadata_suggestions_assert( 'manual' === $manual_order->meta['_billing_dadata_status'], 'Manual fallback status must be saved.' );
dadata_suggestions_assert( 'manual' === $manual_order->meta['_wdc_platform_normalization_source'], 'Manual fallback must set compatible WDC source.' );
dadata_suggestions_assert( true === $manual_order->meta['_wdc_platform_address_fallback_used'], 'Manual fallback must mark fallback used.' );

echo "DaData suggestions smoke test passed.\n";
