<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-dadata-suggestions-key' );

$GLOBALS['wdc_dadata_suggestions_options'] = array();
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();
$GLOBALS['wdc_dadata_suggestions_http_response_queue'] = array();

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
	if ( ! empty( $GLOBALS['wdc_dadata_suggestions_http_response_queue'] ) ) {
		return array_shift( $GLOBALS['wdc_dadata_suggestions_http_response_queue'] );
	}
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
$encryption = new EncryptionService();
$token_pool = new DaDataTokenPool( $settings, $encryption );
$token_pool->save_tokens_from_admin(
	array(
		'id' => array( 'first-token', 'second-token' ),
		'label' => array( 'Primary', 'Reserve' ),
		'token' => array( 'secret-api-key', 'reserve-api-key' ),
		'daily_limit' => array( 10000, 10000 ),
		'enabled' => array( 0 => '1', 1 => '1' ),
	)
);
$suggestion_settings = new AddressSuggestionSettings( $settings, $encryption, $token_pool );
$client = new DaDataSuggestionClient( $suggestion_settings, $token_pool, new Logger() );
dadata_suggestions_assert( 2 === $token_pool->total_tokens_count(), 'DaData suggestions must support multiple tokens.' );
dadata_suggestions_assert( 2 === $token_pool->available_tokens_count(), 'DaData suggestions must report available tokens.' );
dadata_suggestions_assert( 3 === $suggestion_settings->timeout(), 'DaData suggestions timeout must remain a global setting.' );
dadata_suggestions_assert( 10 === $suggestion_settings->count(), 'DaData suggestions count must remain a global setting.' );
$saved_tokens = $token_pool->tokens();
dadata_suggestions_assert( '********-key' === ( $saved_tokens[0]['masked_token'] ?? '' ), 'DaData token must be stored masked.' );
dadata_suggestions_assert( ! str_contains( serialize( $saved_tokens ), 'secret-api-key' ), 'DaData tokens must not be stored in plaintext.' );
$old_encrypted = (string) $saved_tokens[0]['encrypted_token'];
$token_pool->save_tokens_from_admin(
	array(
		'id' => array( 'first-token', 'second-token' ),
		'label' => array( 'Primary updated', 'Reserve' ),
		'token' => array( '', '' ),
		'daily_limit' => array( 0, 1000001 ),
		'enabled' => array( 0 => '1', 1 => '1' ),
	)
);
$saved_tokens = $token_pool->tokens();
dadata_suggestions_assert( $old_encrypted === (string) $saved_tokens[0]['encrypted_token'], 'Empty token input must preserve existing encrypted token.' );
dadata_suggestions_assert( 10000 === (int) $saved_tokens[0]['daily_limit'], 'Empty or zero daily limit must fall back to default.' );
dadata_suggestions_assert( 1000000 === (int) $saved_tokens[1]['daily_limit'], 'Daily limit must be capped at max value.' );
$token_pool->save_tokens_from_admin(
	array(
		'id' => array( 'first-token', 'second-token' ),
		'label' => array( 'Primary updated', 'Reserve' ),
		'token' => array( 'replacement-api-key', '' ),
		'daily_limit' => array( 1, 10000 ),
		'enabled' => array( 0 => '1', 1 => '1' ),
	)
);
$saved_tokens = $token_pool->tokens();
dadata_suggestions_assert( $old_encrypted !== (string) $saved_tokens[0]['encrypted_token'], 'New token input must replace encrypted token.' );
dadata_suggestions_assert( '********-key' === (string) $saved_tokens[0]['masked_token'], 'Replaced token must update masked value.' );
$token_pool->increment_usage( 'first-token' );
dadata_suggestions_assert( 1 === $token_pool->usage_today( 'first-token' ), 'Token usage counter must increment.' );
dadata_suggestions_assert( 0 === $token_pool->remaining_today( $token_pool->tokens()[0] ), 'Token remaining counter must honor daily limit.' );
dadata_suggestions_assert( 'second-token' === (string) ( $token_pool->next_available_token()['id'] ?? '' ), 'Token pool must skip exhausted tokens.' );

$city_body = $client->body( 'city', 'Новосибирск' );
dadata_suggestions_assert( array( array( 'country_iso_code' => 'RU' ) ) === $city_body['locations'], 'City stage must restrict locations to RU.' );
dadata_suggestions_assert( array( 'value' => 'city' ) === $city_body['from_bound'], 'City stage must start from city.' );
dadata_suggestions_assert( array( 'value' => 'settlement' ) === $city_body['to_bound'], 'City stage must end at settlement.' );

$address_body = $client->body( 'address', 'Красный 25', array( 'city_kladr_id' => '5400000100000' ) );
dadata_suggestions_assert( array( array( 'country_iso_code' => 'RU' ) ) === $address_body['locations'], 'Address stage must keep RU locations only.' );
dadata_suggestions_assert( ! isset( $address_body['locations_boost'] ), 'Address stage must not use locations_boost.' );
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
dadata_suggestions_assert( 'Token reserve-api-key' === $request['args']['headers']['Authorization'], 'DaData suggestion client must use the next available token.' );
dadata_suggestions_assert( ! isset( $request['args']['headers']['X-Secret'] ), 'DaData suggestion client must not send X-Secret.' );
dadata_suggestions_assert( is_array( json_decode( (string) $request['args']['body'], true ) ), 'DaData suggestion request body must be a JSON object.' );

$empty_settings = new SettingsRepository();
$empty_settings->replace( array_merge( $empty_settings->all(), array( 'dadata_suggestions_enabled' => true, 'dadata_suggestions_tokens' => array() ) ) );
$empty_pool = new DaDataTokenPool( $empty_settings, new EncryptionService() );
$empty_client = new DaDataSuggestionClient( new AddressSuggestionSettings( $empty_settings, new EncryptionService(), $empty_pool ), $empty_pool, new Logger() );
$empty_response = $empty_client->suggest( 'address', 'test' );
dadata_suggestions_assert( 'no_available_dadata_token' === $empty_response['error_code'], 'Client must return no_available_dadata_token when no enabled tokens exist.' );

$exhausted_settings = new SettingsRepository();
$exhausted_settings->replace( array_merge( $exhausted_settings->all(), array( 'dadata_suggestions_enabled' => true ) ) );
$exhausted_pool = new DaDataTokenPool( $exhausted_settings, new EncryptionService() );
$exhausted_pool->save_tokens_from_admin(
	array(
		'id' => array( 'only-token' ),
		'label' => array( 'Only' ),
		'token' => array( 'only-api-key' ),
		'daily_limit' => array( 1 ),
		'enabled' => array( 0 => '1' ),
	)
);
$exhausted_pool->increment_usage( 'only-token' );
$exhausted_response = ( new DaDataSuggestionClient( new AddressSuggestionSettings( $exhausted_settings, new EncryptionService(), $exhausted_pool ), $exhausted_pool, new Logger() ) )->suggest( 'address', 'test' );
dadata_suggestions_assert( 'dadata_daily_limit_exhausted' === $exhausted_response['error_code'], 'Client must return dadata_daily_limit_exhausted when all tokens reached daily limit.' );

$quota_settings = new SettingsRepository();
$quota_settings->replace( array_merge( $quota_settings->all(), array( 'dadata_suggestions_enabled' => true ) ) );
$quota_pool = new DaDataTokenPool( $quota_settings, new EncryptionService() );
$quota_pool->save_tokens_from_admin(
	array(
		'id' => array( 'quota-first', 'quota-second' ),
		'label' => array( 'Quota first', 'Quota second' ),
		'token' => array( 'quota-first-key', 'quota-second-key' ),
		'daily_limit' => array( 10000, 10000 ),
		'enabled' => array( 0 => '1', 1 => '1' ),
	)
);
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();
$GLOBALS['wdc_dadata_suggestions_http_response_queue'] = array(
	array( 'response' => array( 'code' => 429 ), 'body' => '{"message":"Daily limit exceeded"}' ),
	array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'suggestions' => array() ) ) ),
);
$quota_response = ( new DaDataSuggestionClient( new AddressSuggestionSettings( $quota_settings, new EncryptionService(), $quota_pool ), $quota_pool, new Logger() ) )->suggest( 'address', 'test' );
dadata_suggestions_assert( true === $quota_response['success'], 'Client must retry with the next token after quota response.' );
dadata_suggestions_assert( 2 === count( $GLOBALS['wdc_dadata_suggestions_http_requests'] ), 'Quota retry must send a second request.' );
dadata_suggestions_assert( 'Token quota-second-key' === $GLOBALS['wdc_dadata_suggestions_http_requests'][1]['args']['headers']['Authorization'], 'Quota retry must use second token.' );

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
foreach ( array( 'activeCheckoutPrefix', 'openAddressPicker', 'address picker opened', "mousedown' + namespace + ' focus' + namespace + ' click", 'selectorFor( activePrefix, \'address_1\' )', 'firstUsable( activePrefix, \'address_1\' )', 'firstUsable( prefix, \'city\' )', 'firstUsable( prefix, \'address_2\' )', 'textarea[name="', 'shipping', 'billing', 'address_1', 'postcode', '.wdc-address-picker-search', 'modal search input', 'addressPickerState', 'selectedStreet', 'house_after_street', 'resolve', 'street_selected', 'resolved', 'Использовать введенный адрес', 'manual fallback selected', 'Изменить улицу', 'wdc-address-picker-change-street', 'dadata_status', 'dadata_unrestricted_value', 'dadata_region_fias_id', 'dadata_city_kladr_id', 'dadata_street_fias_id', 'dadata_house_fias_id', 'dadata_fias_level', 'update_checkout', 'updated_checkout', 'wc_fragments_refreshed', 'wdc_platform_dadata_address_suggest', 'address suggestions script loaded', 'config enabled', 'config disabled', 'DaData подсказки:', 'tokens ready:', 'total tokens:', 'available tokens:', 'encryption ready:', 'active mode:', 'active address field:', 'active city field:', 'modal opened:', 'last stage:', 'last query:', 'shipping mode active', 'billing mode active', 'using address field selector', 'address field found', 'address field not found', 'ajax request start', 'ajax success items count', 'ajax fail', 'street selected', 'house selected', 'resolve request start', 'resolve request success', 'debounceDelay = 300', 'itemStore', 'data-key', 'setHiddenData', 'no_available_dadata_token', 'dadata_daily_limit_exhausted', 'Подсказки адреса временно недоступны. Введите адрес вручную.' ) as $needle ) {
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
dadata_suggestions_assert( str_contains( $js, 'openingQuery' ), 'Frontend must build opening query from checkout fields.' );
dadata_suggestions_assert( str_contains( $js, 'cleanQueryPart' ), 'Frontend must sanitize opening query parts.' );
dadata_suggestions_assert( str_contains( $js, 'checkoutFieldValue' ), 'Opening query must read checkout field values.' );
dadata_suggestions_assert( str_contains( $js, "field.find( 'option:selected' )" ), 'Opening query must read selected state option text for select fields.' );
dadata_suggestions_assert( str_contains( $js, "searchInput().val( openingQuery( activePrefix ) );" ), 'Address picker must seed search from region, city, and address.' );
dadata_suggestions_assert( str_contains( $js, "var region = checkoutFieldValue( prefix, 'state' );" ), 'Opening query region must come from checkout state field.' );
dadata_suggestions_assert( str_contains( $js, "var city = checkoutFieldValue( prefix, 'city' );" ), 'Opening query city must come from checkout city field.' );
dadata_suggestions_assert( str_contains( $js, "var address = checkoutFieldValue( prefix, 'address_1' );" ), 'Opening query address must come from checkout address_1 field.' );
dadata_suggestions_assert( str_contains( $js, "parts.join( ', ' ) + ', '" ), 'Opening query must keep trailing comma when address is empty.' );
dadata_suggestions_assert( str_contains( $js, 'opening query built' ) && str_contains( $js, "regionSource: 'checkout_state'" ) && str_contains( $js, "citySource: 'checkout_city'" ) && str_contains( $js, "addressSource: 'checkout_address_1'" ), 'Opening query debug log must show checkout field sources.' );
dadata_suggestions_assert( str_contains( $js, "'' === query.trim()" ) && str_contains( $js, 'stateFor( prefix ).selectedStreet = null;' ), 'Clearing modal search must reset selected street and address mode.' );
dadata_suggestions_assert( str_contains( $js, 'formatStreetHouse' ) && str_contains( $js, 'formatAddressWithoutRegionCity' ) && str_contains( $js, 'formatFullAddressWithoutCountry' ), 'Frontend must format final address lines.' );
dadata_suggestions_assert( str_contains( $js, 'localLocationMatchesDadata' ), 'Frontend must compare selected local location with DaData result.' );
dadata_suggestions_assert( str_contains( $js, 'wdc_platform_location_display_name' ) && str_contains( $js, 'wdc_platform_location_region_name' ) && str_contains( $js, 'wdc_platform_location_postcode' ), 'Frontend must keep WDC-compatible location hidden fields in sync.' );

$opening_start = strpos( $js, 'function openingQuery' );
$opening_end = strpos( $js, 'function houseWithType' );
$opening_body = false !== $opening_start && false !== $opening_end ? substr( $js, $opening_start, $opening_end - $opening_start ) : '';
dadata_suggestions_assert( '' !== $opening_body, 'Opening query helper must be present.' );
dadata_suggestions_assert( ! str_contains( $opening_body, 'wdc_platform_location_display_name' ), 'Opening query must not use hidden display_name.' );
dadata_suggestions_assert( ! str_contains( $opening_body, 'showSelectedNotice' ), 'Opening query must not use selected notice text.' );
dadata_suggestions_assert( ! str_contains( $opening_body, 'lastResolved' ) && ! str_contains( $opening_body, 'selectedStreet' ), 'Opening query must not use previous resolved suggestion or selected street.' );

$test_clean_query_part = static function ( string $value ): string {
	$cleaned = trim( preg_replace( '/\s+/', ' ', trim( $value, " \t\n\r\0\x0B," ) ) ?? '' );
	if ( preg_match( '/^(.+?)\s+-\s+(.+)$/u', $cleaned, $matches ) ) {
		return trim( $matches[1] );
	}
	return $cleaned;
};
$test_opening_query = static function ( string $region, string $city, string $address ) use ( $test_clean_query_part ): string {
	$region = $test_clean_query_part( $region );
	$city = $test_clean_query_part( $city );
	$address = $test_clean_query_part( $address );
	$parts = array_values( array_filter( array( $region, $city ), static fn ( string $part ): bool => '' !== $part ) );
	if ( '' !== $address ) {
		$parts[] = $address;
		return implode( ', ', $parts );
	}
	return array() !== $parts ? implode( ', ', $parts ) . ', ' : '';
};
dadata_suggestions_assert( 'Новосибирская область, Новосибирск, ул Демьяна Бедного' === $test_opening_query( 'Новосибирская область', 'Новосибирск', 'ул Демьяна Бедного' ), 'Opening query example with address must use visible checkout values.' );
dadata_suggestions_assert( 'Новосибирская область, Новосибирск, ' === $test_opening_query( 'Новосибирская область', 'Новосибирск', '' ), 'Opening query example with empty address must keep trailing comma.' );
dadata_suggestions_assert( 'Новосибирская область, Новосибирск, ул Демьяна Бедного' === $test_opening_query( 'Новосибирская область', 'Новосибирск - Новосибирская область', 'ул Демьяна Бедного' ), 'Opening query cleanup must strip city display suffix if it appears.' );

$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.css' );
foreach ( array( '.wdc-address-picker-overlay', '.wdc-address-picker-panel', '.wdc-address-picker-search', '.wdc-address-picker-results', '.wdc-address-picker-item', '.wdc-address-picker-empty', '.wdc-address-picker-hint', '.wdc-address-picker-selected', 'max-width: 1300px', 'column-count: 2', '@media (max-width: 900px)', 'column-count: 1' ) as $needle ) {
	dadata_suggestions_assert( str_contains( $css, $needle ), 'Frontend suggestions CSS must contain ' . $needle . '.' );
}

$registrar = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-address-suggestions' ), 'ShippingMethodRegistrar must enqueue address suggestions assets.' );
dadata_suggestions_assert( ! str_contains( $registrar, 'checkout-address-normalization.js' ), 'ShippingMethodRegistrar must not enqueue post-factum address normalization JS.' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-city-selector' ), 'ShippingMethodRegistrar must keep local city selector assets.' );
dadata_suggestions_assert( str_contains( $registrar, 'address_suggestions_config' ), 'ShippingMethodRegistrar must expose address suggestions config.' );
dadata_suggestions_assert( str_contains( $registrar, "'nonce'" ), 'Address suggestions config must include nonce.' );
dadata_suggestions_assert( str_contains( $registrar, "'min_chars'" ), 'Address suggestions config must include min_chars.' );
dadata_suggestions_assert( str_contains( $registrar, "'strings'" ), 'Address suggestions config must include strings.' );
dadata_suggestions_assert( str_contains( $registrar, "'stages'" ), 'Address suggestions config must include stages.' );
dadata_suggestions_assert( str_contains( $registrar, "'actions'" ), 'Address suggestions config must include actions.' );
dadata_suggestions_assert( str_contains( $registrar, "'suggestions_requested'" ), 'Address suggestions config must include suggestions_requested.' );
dadata_suggestions_assert( str_contains( $registrar, "'tokens_ready'" ), 'Address suggestions config must include tokens_ready.' );
dadata_suggestions_assert( str_contains( $registrar, "'total_tokens_count'" ), 'Address suggestions config must include total_tokens_count.' );
dadata_suggestions_assert( str_contains( $registrar, "'available_tokens_count'" ), 'Address suggestions config must include available_tokens_count.' );
dadata_suggestions_assert( str_contains( $registrar, "'encryption_ready'" ), 'Address suggestions config must include encryption_ready.' );
dadata_suggestions_assert( str_contains( $registrar, 'if ( $this->suggestions_requested() )' ), 'Address suggestions assets must enqueue when DaData suggestions are requested.' );
dadata_suggestions_assert( ! str_contains( $registrar, "'api_key'" ) && ! str_contains( $registrar, '"api_key"' ) && ! str_contains( $registrar, 'api_key_ready' ), 'ShippingMethodRegistrar must not localize the DaData API key.' );

$checkout_normalizer = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Address/CheckoutAddressNormalizer.php' );
dadata_suggestions_assert( ! str_contains( $checkout_normalizer, 'dadata_normalizer' ), 'CheckoutAddressNormalizer pipeline must not include DaData post-factum normalizer.' );

$settings_page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsAdminPage.php' );
dadata_suggestions_assert( ! str_contains( $settings_page, 'dadata_enabled' ), 'Settings page must not expose separate DaData normalizer toggle.' );
dadata_suggestions_assert( ! str_contains( $settings_page, 'dadata_api_token" name="dadata_api_token' ), 'Settings page must not expose separate DaData normalizer token.' );
dadata_suggestions_assert( str_contains( $settings_page, 'dadata_suggestions_tokens' ), 'Settings page must expose the DaData suggestions token list.' );
dadata_suggestions_assert( str_contains( $settings_page, 'Суточный лимит запросов' ), 'Settings page must expose daily request limit per token.' );
dadata_suggestions_assert( str_contains( $settings_page, 'Токены не добавлены. Нажмите' ), 'Settings page must show an empty token list message.' );

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
