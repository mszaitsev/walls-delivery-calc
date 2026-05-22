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
use WallsShop\WDC\Locations\DaData\DaDataCredentials;
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
dadata_suggestions_assert( DaDataCredentials::TOKEN_ENCRYPTED_KEY === AddressSuggestionSettings::API_KEY_ENCRYPTED, 'DaData suggestions and normalizer must use the same encrypted credential key.' );
dadata_suggestions_assert( DaDataCredentials::TOKEN_MASKED_KEY === AddressSuggestionSettings::API_KEY_MASKED, 'DaData suggestions and normalizer must use the same masked credential key.' );
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
foreach ( array( 'activeCheckoutPrefix', 'openAddressPicker', 'address picker opened', "mousedown' + namespace + ' focus' + namespace + ' click", 'selectorFor( activePrefix, \'address_1\' )', 'firstUsable( activePrefix, \'address_1\' )', 'firstUsable( prefix, \'city\' )', 'firstUsable( prefix, \'address_2\' )', 'textarea[name="', 'shipping', 'billing', 'address_1', 'postcode', '.wdc-address-picker-search', 'modal search input', 'addressPickerState', 'selectedStreet', 'house_after_street', 'resolve', 'street_selected', 'resolved', 'Использовать введенный адрес', 'manual fallback selected', 'Изменить улицу', 'wdc-address-picker-change-street', 'dadata_status', 'dadata_unrestricted_value', 'dadata_region_fias_id', 'dadata_city_kladr_id', 'dadata_street_fias_id', 'dadata_house_fias_id', 'dadata_fias_level', 'update_checkout', 'updated_checkout', 'wc_fragments_refreshed', 'wdc_platform_dadata_address_suggest', 'address suggestions script loaded', 'config enabled', 'config disabled', 'DaData подсказки:', 'api key ready:', 'encryption ready:', 'active mode:', 'active address field:', 'active city field:', 'modal opened:', 'last stage:', 'last query:', 'shipping mode active', 'billing mode active', 'using address field selector', 'address field found', 'address field not found', 'ajax request start', 'ajax success items count', 'ajax fail', 'street selected', 'house selected', 'resolve request start', 'resolve request success', 'debounceDelay = 300', 'itemStore', 'data-key', 'setHiddenData' ) as $needle ) {
	dadata_suggestions_assert( str_contains( $js, $needle ), 'Frontend suggestions JS must contain ' . $needle . '.' );
}
dadata_suggestions_assert( ! str_contains( $js, 'secret-api-key' ) && ! str_contains( $js, 'Authorization' ), 'Frontend suggestions JS must not contain API key values or Authorization headers.' );
dadata_suggestions_assert( ! str_contains( $js, 'var ADDRESS_SELECTOR' ), 'Frontend suggestions JS must not define a combined ADDRESS_SELECTOR.' );
dadata_suggestions_assert( ! str_contains( $js, '#shipping_address_1,input[name="shipping_address_1"],textarea[name="shipping_address_1"],#billing_address_1' ), 'Frontend suggestions JS must not mix shipping and billing address selectors.' );
dadata_suggestions_assert( ! str_contains( $js, ".on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, addressSelector" ), 'Frontend suggestions JS must not search from WooCommerce address_1 input.' );
dadata_suggestions_assert( str_contains( $js, ".on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, '.wdc-address-picker-search'" ), 'Frontend suggestions JS must search from modal input.' );
dadata_suggestions_assert( str_contains( $js, "shippingChecked && ( visibleUsable( selectorFor( 'shipping', 'address_1' )" ), 'activeCheckoutPrefix must use shipping only when shipping fields are visible and ship-to-different is checked.' );
dadata_suggestions_assert( str_contains( $js, "firstUsable( activePrefix, 'address_1' )" ), 'renderDebugBlock must use the active checkout prefix.' );
dadata_suggestions_assert( str_contains( $js, '$( document.body ).off( namespace );' ), 'bind must clear delegated handlers before rebinding active selectors.' );
dadata_suggestions_assert( ! str_contains( $js, "change' + namespace" ) && ! str_contains( $js, "blur' + namespace" ), 'Frontend suggestions JS must not use blur/change to trigger search.' );
dadata_suggestions_assert( str_contains( $js, 'firstUsable( prefix, \'city\' ).val( data.city || data.settlement' ), 'Selected house must update city from selected address.' );
dadata_suggestions_assert( str_contains( $js, "'manual'" ), 'Frontend must support manual fallback status.' );

$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.css' );
foreach ( array( '.wdc-address-picker-overlay', '.wdc-address-picker-panel', '.wdc-address-picker-search', '.wdc-address-picker-results', '.wdc-address-picker-item', '.wdc-address-picker-empty', '.wdc-address-picker-hint', '.wdc-address-picker-selected', 'max-width: 1300px', 'column-count: 2', '@media (max-width: 900px)', 'column-count: 1' ) as $needle ) {
	dadata_suggestions_assert( str_contains( $css, $needle ), 'Frontend suggestions CSS must contain ' . $needle . '.' );
}

$registrar = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-address-suggestions' ), 'ShippingMethodRegistrar must enqueue address suggestions assets.' );
dadata_suggestions_assert( str_contains( $registrar, 'address_suggestions_config' ), 'ShippingMethodRegistrar must expose address suggestions config.' );
dadata_suggestions_assert( str_contains( $registrar, "'nonce'" ), 'Address suggestions config must include nonce.' );
dadata_suggestions_assert( str_contains( $registrar, "'min_chars'" ), 'Address suggestions config must include min_chars.' );
dadata_suggestions_assert( str_contains( $registrar, "'strings'" ), 'Address suggestions config must include strings.' );
dadata_suggestions_assert( str_contains( $registrar, "'stages'" ), 'Address suggestions config must include stages.' );
dadata_suggestions_assert( str_contains( $registrar, "'actions'" ), 'Address suggestions config must include actions.' );
dadata_suggestions_assert( str_contains( $registrar, "'suggestions_requested'" ), 'Address suggestions config must include suggestions_requested.' );
dadata_suggestions_assert( str_contains( $registrar, "'api_key_ready'" ), 'Address suggestions config must include api_key_ready.' );
dadata_suggestions_assert( str_contains( $registrar, "'encryption_ready'" ), 'Address suggestions config must include encryption_ready.' );
dadata_suggestions_assert( str_contains( $registrar, 'if ( $this->suggestions_requested() )' ), 'Address suggestions assets must enqueue when DaData suggestions are requested.' );
dadata_suggestions_assert( ! str_contains( $registrar, "'api_key'" ) && ! str_contains( $registrar, '"api_key"' ), 'ShippingMethodRegistrar must not localize the DaData API key.' );

$ajax = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/AddressSuggestionAjax.php' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_' . self::ACTION" ), 'AddressSuggestionAjax must register logged-in AJAX action.' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_nopriv_' . self::ACTION" ), 'AddressSuggestionAjax must register guest AJAX action.' );

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
