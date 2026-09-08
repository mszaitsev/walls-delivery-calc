<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionAjax;
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
function wp_verify_nonce( string $nonce, string $action ): bool { return 'test-nonce' === $nonce; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_dadata_suggestions_http_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( ! empty( $GLOBALS['wdc_dadata_suggestions_http_response_queue'] ) ) {
		$queued = array_shift( $GLOBALS['wdc_dadata_suggestions_http_response_queue'] );
		if ( $queued instanceof Throwable ) {
			throw $queued;
		}
		return $queued;
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
							'geo_lat' => '55.030100',
							'geo_lon' => '82.920100',
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

$address_next_body = $client->body( 'address_next', '630099, Новосибирская обл, г Новосибирск, Красный пр-кт, д 25, 9', array( 'city_kladr_id' => '5400000100000', 'selected_level' => 'house', 'desired_level' => 'flat' ) );
dadata_suggestions_assert( 20 === $address_next_body['count'], 'Address next stage must request the maximum 20 suggestions.' );
dadata_suggestions_assert( ! isset( $address_next_body['from_bound'] ) && ! isset( $address_next_body['to_bound'] ) && ! isset( $address_next_body['restrict_value'] ), 'Address next stage must stay relaxed without strict bounds/restrict_value.' );

$response = $client->suggest( 'address', 'Красный 25', array( 'city_kladr_id' => '5400000100000' ) );
dadata_suggestions_assert( true === $response['success'], 'DaData suggestion client must accept mocked response.' );
dadata_suggestions_assert( 1 === count( $GLOBALS['wdc_dadata_suggestions_http_requests'] ), 'DaData suggestion client must perform one HTTP request.' );
$request = $GLOBALS['wdc_dadata_suggestions_http_requests'][0];
dadata_suggestions_assert( 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address' === $request['url'], 'DaData suggestion client must use Suggest API URL.' );
dadata_suggestions_assert( 'Token reserve-api-key' === $request['args']['headers']['Authorization'], 'DaData suggestion client must use the next available token.' );
dadata_suggestions_assert( ! isset( $request['args']['headers']['X-Secret'] ), 'DaData suggestion client must not send X-Secret.' );
dadata_suggestions_assert( is_array( json_decode( (string) $request['args']['body'], true ) ), 'DaData suggestion request body must be a JSON object.' );
dadata_suggestions_assert( 1 === $token_pool->usage_today( 'first-token' ), 'Skipped exhausted token must not increment usage.' );
dadata_suggestions_assert( 1 === $token_pool->usage_today( 'second-token' ), 'DaData usage must increment exactly once per HTTP attempt.' );
$last_request = $token_pool->last_request_today( 'second-token' );
dadata_suggestions_assert( 'address' === ( $last_request['stage'] ?? '' ), 'Last request audit must store stage.' );
dadata_suggestions_assert( true === ( $last_request['http_attempted'] ?? false ), 'Last request audit must mark HTTP attempted.' );
dadata_suggestions_assert( true === ( $last_request['counted'] ?? false ), 'Last request audit must mark counted request.' );
dadata_suggestions_assert( 200 === (int) ( $last_request['status_code'] ?? 0 ), 'Last request audit must store HTTP status.' );
dadata_suggestions_assert( isset( $last_request['query_hash'] ) && isset( $last_request['query_preview'] ), 'Last request audit must store safe query diagnostics.' );

$_POST = array( 'nonce' => 'test-nonce', 'level' => 'house' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $suggestion_settings, $client, new AddressSuggestionNormalizer() ), $token_pool ) )->handle_selection();
$selection_payload = json_decode( (string) ob_get_clean(), true );
dadata_suggestions_assert( true === ( $selection_payload['success'] ?? false ) && true === ( $selection_payload['counted'] ?? false ), 'Selection endpoint must count selected DaData suggestion.' );
dadata_suggestions_assert( 'suggestion_click' === ( $selection_payload['usage_type'] ?? '' ), 'Selection endpoint must default missing usage_type to suggestion_click.' );
dadata_suggestions_assert( 2 === $token_pool->usage_today( 'second-token' ), 'Selecting suggestion must increment last used token by additional +1.' );
dadata_suggestions_assert( 'selection' === ( $token_pool->last_request_today( 'second-token' )['stage'] ?? '' ), 'Selection usage must update diagnostics stage.' );
dadata_suggestions_assert( 'selection' === ( $token_pool->last_request_today( 'second-token' )['status_code'] ?? '' ), 'Selection usage must update diagnostics status.' );
dadata_suggestions_assert( 'suggestion_click' === ( $token_pool->last_request_today( 'second-token' )['error_code'] ?? '' ), 'Selection usage diagnostics must store suggestion_click usage type.' );

$_POST = array( 'nonce' => 'test-nonce', 'level' => 'house', 'usage_type' => 'final_selection' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $suggestion_settings, $client, new AddressSuggestionNormalizer() ), $token_pool ) )->handle_selection();
$final_selection_payload = json_decode( (string) ob_get_clean(), true );
dadata_suggestions_assert( true === ( $final_selection_payload['success'] ?? false ) && true === ( $final_selection_payload['counted'] ?? false ), 'Final selection endpoint call must count selected final DaData address.' );
dadata_suggestions_assert( 'final_selection' === ( $final_selection_payload['usage_type'] ?? '' ), 'Final selection endpoint must preserve usage_type=final_selection.' );
dadata_suggestions_assert( 3 === $token_pool->usage_today( 'second-token' ), 'Final house selection must add +2 total selection usage: suggestion_click and final_selection.' );
dadata_suggestions_assert( 'final_selection' === ( $token_pool->last_request_today( 'second-token' )['stage'] ?? '' ), 'Final selection usage must update diagnostics stage.' );
dadata_suggestions_assert( 'selection' === ( $token_pool->last_request_today( 'second-token' )['status_code'] ?? '' ), 'Final selection usage must keep fire-and-forget selection status.' );
dadata_suggestions_assert( 'final_selection' === ( $token_pool->last_request_today( 'second-token' )['error_code'] ?? '' ), 'Final selection usage diagnostics must store final_selection usage type.' );

$token_pool->set_last_used_token_id( '' );
$_POST = array( 'nonce' => 'test-nonce', 'level' => 'street', 'usage_type' => 'final_selection' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $suggestion_settings, $client, new AddressSuggestionNormalizer() ), $token_pool ) )->handle_selection();
$missing_selection_payload = json_decode( (string) ob_get_clean(), true );
dadata_suggestions_assert( true === ( $missing_selection_payload['success'] ?? false ) && false === ( $missing_selection_payload['counted'] ?? true ), 'Selection endpoint must not fail when last token id is missing.' );
dadata_suggestions_assert( 'final_selection' === ( $missing_selection_payload['usage_type'] ?? '' ), 'Final selection endpoint must return counted=false when last token id is missing.' );

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
dadata_suggestions_assert( 1 === $quota_pool->usage_today( 'quota-first' ), 'Quota response must increment first token exactly once.' );
dadata_suggestions_assert( 1 === $quota_pool->usage_today( 'quota-second' ), 'Retry must increment second token exactly once.' );
dadata_suggestions_assert( true === $quota_pool->is_exhausted_today( 'quota-first' ), 'Quota response must mark first token exhausted without overwriting usage count.' );
dadata_suggestions_assert( 'dadata_daily_limit_exhausted' === ( $quota_pool->last_request_today( 'quota-first' )['error_code'] ?? '' ), 'Quota audit must record limit error code.' );

$timeout_settings = new SettingsRepository();
$timeout_settings->replace( array_merge( $timeout_settings->all(), array( 'dadata_suggestions_enabled' => true, 'dadata_suggestions_tokens' => array() ) ) );
$timeout_pool = new DaDataTokenPool( $timeout_settings, new EncryptionService() );
$timeout_pool->save_tokens_from_admin(
	array(
		'id' => array( 'timeout-token' ),
		'label' => array( 'Timeout' ),
		'token' => array( 'timeout-api-key' ),
		'daily_limit' => array( 10000 ),
		'enabled' => array( 0 => '1' ),
	)
);
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();
$GLOBALS['wdc_dadata_suggestions_http_response_queue'] = array( new RuntimeException( 'timeout' ) );
$timeout_response = ( new DaDataSuggestionClient( new AddressSuggestionSettings( $timeout_settings, new EncryptionService(), $timeout_pool ), $timeout_pool, new Logger() ) )->suggest( 'address', 'timeout query' );
dadata_suggestions_assert( 'dadata_timeout' === $timeout_response['error_code'], 'Timeout response must return dadata_timeout.' );
dadata_suggestions_assert( 1 === $timeout_pool->usage_today( 'timeout-token' ), 'Timeout/error must increment selected token once after HTTP attempt.' );
dadata_suggestions_assert( 'dadata_timeout' === ( $timeout_pool->last_request_today( 'timeout-token' )['error_code'] ?? '' ), 'Timeout audit must record error code.' );

$selection_limit_settings = new SettingsRepository();
$selection_limit_settings->replace( array_merge( $selection_limit_settings->all(), array( 'dadata_suggestions_enabled' => true, 'dadata_suggestions_tokens' => array() ) ) );
$selection_limit_pool = new DaDataTokenPool( $selection_limit_settings, new EncryptionService() );
$selection_limit_pool->save_tokens_from_admin(
	array(
		'id' => array( 'selection-first', 'selection-second' ),
		'label' => array( 'Selection first', 'Selection second' ),
		'token' => array( 'selection-first-key', 'selection-second-key' ),
		'daily_limit' => array( 2, 10000 ),
		'enabled' => array( 0 => '1', 1 => '1' ),
	)
);
$selection_limit_settings_obj = new AddressSuggestionSettings( $selection_limit_settings, new EncryptionService(), $selection_limit_pool );
$selection_limit_client = new DaDataSuggestionClient( $selection_limit_settings_obj, $selection_limit_pool, new Logger() );
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();
$selection_limit_client->suggest( 'address', 'selection limit' );
dadata_suggestions_assert( 1 === $selection_limit_pool->usage_today( 'selection-first' ), 'First suggest must increment first token once before selection.' );
$_POST = array( 'nonce' => 'test-nonce', 'level' => 'street' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $selection_limit_settings_obj, $selection_limit_client, new AddressSuggestionNormalizer() ), $selection_limit_pool ) )->handle_selection();
ob_get_clean();
dadata_suggestions_assert( 0 === $selection_limit_pool->remaining_today( $selection_limit_pool->tokens()[0] ), 'Selection usage must decrease remaining today.' );
$GLOBALS['wdc_dadata_suggestions_http_requests'] = array();
$selection_limit_client->suggest( 'address', 'after selection limit' );
dadata_suggestions_assert( 'Token selection-second-key' === $GLOBALS['wdc_dadata_suggestions_http_requests'][0]['args']['headers']['Authorization'], 'If selection exhausts first token, next suggest must use second token.' );

$street_selection_settings = new SettingsRepository();
$street_selection_settings->replace( array_merge( $street_selection_settings->all(), array( 'dadata_suggestions_enabled' => true, 'dadata_suggestions_tokens' => array() ) ) );
$street_selection_pool = new DaDataTokenPool( $street_selection_settings, new EncryptionService() );
$street_selection_pool->save_tokens_from_admin(
	array(
		'id' => array( 'street-selection-token' ),
		'label' => array( 'Street selection' ),
		'token' => array( 'street-selection-key' ),
		'daily_limit' => array( 10000 ),
		'enabled' => array( 0 => '1' ),
	)
);
$street_selection_pool->set_last_used_token_id( 'street-selection-token' );
$_POST = array( 'nonce' => 'test-nonce', 'level' => 'street', 'usage_type' => 'suggestion_click' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( new AddressSuggestionSettings( $street_selection_settings, new EncryptionService(), $street_selection_pool ), new DaDataSuggestionClient( new AddressSuggestionSettings( $street_selection_settings, new EncryptionService(), $street_selection_pool ), $street_selection_pool, new Logger() ), new AddressSuggestionNormalizer() ), $street_selection_pool ) )->handle_selection();
ob_get_clean();
dadata_suggestions_assert( 1 === $street_selection_pool->usage_today( 'street-selection-token' ), 'Street selection must count only the suggestion_click usage.' );
dadata_suggestions_assert( 'selection' === ( $street_selection_pool->last_request_today( 'street-selection-token' )['stage'] ?? '' ), 'Street selection must not count final_selection usage.' );

$normalizer = new AddressSuggestionNormalizer();
$street_item = $normalizer->normalize( array( 'value' => 'Красный пр-кт', 'data' => array( 'fias_level' => '7', 'street_with_type' => 'Красный пр-кт' ) ) );
dadata_suggestions_assert( 'street' === $street_item['level'], 'Normalizer must detect street suggestions.' );
dadata_suggestions_assert( false === $street_item['isDeliverable'], 'Street without house must not be deliverable.' );
$house_item = $normalizer->normalize( $response['suggestions'][0] );
dadata_suggestions_assert( 'house' === $house_item['level'], 'Normalizer must detect house suggestions.' );
dadata_suggestions_assert( true === $house_item['isDeliverable'], 'Normalizer must mark fias_level 8 as deliverable.' );
dadata_suggestions_assert( '55.030100' === (string) ( $house_item['data']['geo_lat'] ?? '' ) && '82.920100' === (string) ( $house_item['data']['geo_lon'] ?? '' ), 'Normalizer must preserve server-returned DaData geo coordinates for trusted evidence.' );
$evidence_session = new CheckoutSessionManager();
$cached_items = $evidence_session->cache_dadata_address_suggestions(
	'billing',
	array(
		'selected_location_id' => '123',
		'selected_location_fias_id' => 'city-fias',
	),
	array( $house_item )
);
$selection_token = (string) ( $cached_items[0]['selection_token'] ?? '' );
dadata_suggestions_assert( '' !== $selection_token && $selection_token === (string) ( $cached_items[0]['selectionToken'] ?? '' ), 'Server must attach an opaque selection token to DaData suggestions.' );
dadata_suggestions_assert( false === $evidence_session->confirm_dadata_address_evidence( $selection_token, 'shipping' ), 'Selection token must be scoped to the active checkout prefix.' );
dadata_suggestions_assert( true === $evidence_session->confirm_dadata_address_evidence( $selection_token, 'billing' ), 'Server-confirmed selection token must create trusted checkout address evidence.' );
$trusted_evidence = $evidence_session->trusted_dadata_address_evidence();
dadata_suggestions_assert( 'billing' === (string) ( $trusted_evidence['prefix'] ?? '' ) && '123' === (string) ( $trusted_evidence['selected_location_id'] ?? '' ) && 'city-fias' === (string) ( $trusted_evidence['city_fias_id'] ?? '' ), 'Trusted evidence must keep prefix and selected location identity.' );
dadata_suggestions_assert( 'Красный пр-кт' === (string) ( $trusted_evidence['street_with_type'] ?? '' ) && '25' === (string) ( $trusted_evidence['house'] ?? '' ) && '55.030100' === (string) ( $trusted_evidence['geo_lat'] ?? '' ), 'Trusted evidence must keep only normalized safe address fields needed for pricing.' );
dadata_suggestions_assert( ! str_contains( serialize( $trusted_evidence ), '630099, Новосибирская обл' ) && ! str_contains( serialize( $trusted_evidence ), 'secret-api-key' ), 'Trusted evidence must not store raw unrestricted DaData payload or credentials.' );
foreach ( array( '9', '75' ) as $level ) {
	$item = $normalizer->normalize( array( 'value' => 'test', 'data' => array( 'fias_level' => $level, 'house' => '1' ) ) );
	dadata_suggestions_assert( true === $item['isDeliverable'], 'Normalizer must mark fias_level ' . $level . ' as deliverable.' );
}

$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
// Exercise the public boundary against DB rows, with no production HTTP calls.
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public array $locations = array();
		public array $regions = array();
	}
}
$db = new wpdb();
$db->locations = array( array( 'id' => 1, 'country_code' => 'RU', 'fias_id' => 'city-fias', 'active' => 1, 'city_name' => 'Новосибирск' ) );
$inline_client = new class implements \WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface {
	public array $calls = array();
	public int $extra = 0;
	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->calls[] = compact( 'stage', 'query', 'context' );
		$rows = array();
		foreach ( array_merge( array( 'city-fias', 'wrong-city', 'city-fias' ), array_fill( 0, $this->extra, 'city-fias' ) ) as $index => $city ) {
			$rows[] = array( 'value' => 'г Новосибирск, ул Ленина, д 10', 'data' => array( 'city_fias_id' => $city, 'street_with_type' => 'ул Ленина', 'house' => 2 === $index ? '10' : '', 'fias_level' => 2 === $index ? '8' : '7' ) );
		}
		return array( 'success' => true, 'suggestions' => $rows );
	}
};
$inline_service = new \WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $suggestion_settings, $inline_client, $normalizer );
$inline_ajax = new AddressSuggestionAjax( $inline_service, null, new CheckoutSessionManager(), new \WallsShop\WDC\Locations\Storage\LocationRepository( $db ) );
$run_inline = static function ( array $context, string $nonce = 'test-nonce' ) use ( $inline_ajax ): array {
	$_POST = array( 'nonce' => $nonce, 'query' => 'Ленина', 'context' => $context );
	ob_start(); $inline_ajax->handle(); return json_decode( (string) ob_get_clean(), true );
};
$canonical = array( 'country_code' => 'RU', 'selected_location_id' => '1', 'selected_location_fias_id' => 'city-fias' );
$result = $run_inline( $canonical );
dadata_suggestions_assert( 1 === count( $inline_client->calls ) && 'address_inline' === $inline_client->calls[0]['stage'], 'Inline lookup makes one city-scoped request without loose retries.' );
dadata_suggestions_assert( 2 === count( $result['items'] ) && 'ул Ленина' === $result['items'][0]['input_value'] && false === $result['items'][0]['is_final'], 'Street DTO excludes city and foreign-city results.' );
dadata_suggestions_assert( 'ул Ленина, д 10' === $result['items'][1]['input_value'] && true === $result['items'][1]['is_final'], 'House DTO is final and excludes city.' );
foreach ( array( array( 'selected_source' => 'manual' ), array( 'country_code' => 'KZ' ), array( 'selected_location_id' => '99' ), array( 'selected_location_fias_id' => 'wrong-city' ), array( 'selected_location_id' => '', 'selected_location_fias_id' => '' ) ) as $override ) {
	$run_inline( array_merge( $canonical, $override ) );
}
$run_inline( $canonical, 'invalid' );
dadata_suggestions_assert( 1 === count( $inline_client->calls ), 'Manual, non-RU, missing/invalid canonical identity and nonce make no DaData call.' );
$db->locations[0]['active'] = 0;
$run_inline( $canonical );
dadata_suggestions_assert( 1 === count( $inline_client->calls ), 'Inactive DB location makes no DaData call.' );
$db->locations[0]['active'] = 1;
$inline_client->extra = 12;
$limited = $run_inline( $canonical );
dadata_suggestions_assert( 8 === count( $limited['items'] ), 'Public response caps excessive provider results at eight.' );
$inline_body = $client->body( 'address_inline', 'Ленина', array( 'location_fias_id' => 'city-fias' ) );
$land_only = $normalizer->normalize( array( 'data' => array( 'street_with_type' => 'ул Ленина', 'fias_level' => '75', 'stead' => '10' ) ) );
dadata_suggestions_assert( false === $land_only['is_final'], 'Land metadata without a house must not finalize a street-only input.' );
dadata_suggestions_assert( 8 === $inline_body['count'] && true === $inline_body['restrict_value'] && 'city-fias' === $inline_body['locations'][0]['fias_id'] && 'street' === $inline_body['from_bound']['value'] && 'house' === $inline_body['to_bound']['value'], 'Production DaData request is bounded and constrained street-to-house.' );
$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.css' );
dadata_suggestions_assert( ! str_contains( $js . $css, 'wdc-address-picker' ), 'Old checkout address modal is removed.' );
dadata_suggestions_assert( str_contains( $js, 'address_inline' ) && str_contains( $css, '.wdc-address-autocomplete' ), 'Checkout uses city-scoped inline autocomplete.' );

$registrar = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-address-suggestions' ), 'ShippingMethodRegistrar must enqueue address suggestions assets.' );
dadata_suggestions_assert( ! str_contains( $registrar, 'checkout-address-normalization.js' ), 'ShippingMethodRegistrar must not enqueue post-factum address normalization JS.' );
dadata_suggestions_assert( str_contains( $registrar, 'wdc-platform-city-selector' ), 'ShippingMethodRegistrar must keep local city selector assets.' );
dadata_suggestions_assert( str_contains( $registrar, 'address_suggestions_config' ), 'ShippingMethodRegistrar must expose address suggestions config.' );
dadata_suggestions_assert( str_contains( $registrar, "'nonce'" ), 'Address suggestions config must include nonce.' );
dadata_suggestions_assert( str_contains( $registrar, "'min_chars'" ), 'Address suggestions config must include min_chars.' );
dadata_suggestions_assert( str_contains( $registrar, "'strings'" ), 'Address suggestions config must include strings.' );
dadata_suggestions_assert( str_contains( $registrar, "'selection_action'" ), 'Address suggestions config must include selection_action.' );
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
dadata_suggestions_assert( str_contains( $settings_page, 'Последняя попытка' ) && str_contains( $settings_page, 'Последний статус' ), 'Settings page must show last DaData request diagnostics.' );

$ajax = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/AddressSuggestionAjax.php' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_' . self::ACTION" ), 'AddressSuggestionAjax must register logged-in AJAX action.' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_nopriv_' . self::ACTION" ), 'AddressSuggestionAjax must register guest AJAX action.' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_' . self::SELECTION_ACTION" ), 'AddressSuggestionAjax must register logged-in selection AJAX action.' );
dadata_suggestions_assert( str_contains( $ajax, "add_action( 'wp_ajax_nopriv_' . self::SELECTION_ACTION" ), 'AddressSuggestionAjax must register guest selection AJAX action.' );

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
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist(
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
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist(
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
