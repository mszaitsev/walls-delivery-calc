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

$_POST = array( 'level' => 'house' );
ob_start();
( new AddressSuggestionAjax( new WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService( $suggestion_settings, $client, new AddressSuggestionNormalizer() ), $token_pool ) )->handle_selection();
$selection_payload = json_decode( (string) ob_get_clean(), true );
dadata_suggestions_assert( true === ( $selection_payload['success'] ?? false ) && true === ( $selection_payload['counted'] ?? false ), 'Selection endpoint must count selected DaData suggestion.' );
dadata_suggestions_assert( 'suggestion_click' === ( $selection_payload['usage_type'] ?? '' ), 'Selection endpoint must default missing usage_type to suggestion_click.' );
dadata_suggestions_assert( 2 === $token_pool->usage_today( 'second-token' ), 'Selecting suggestion must increment last used token by additional +1.' );
dadata_suggestions_assert( 'selection' === ( $token_pool->last_request_today( 'second-token' )['stage'] ?? '' ), 'Selection usage must update diagnostics stage.' );
dadata_suggestions_assert( 'selection' === ( $token_pool->last_request_today( 'second-token' )['status_code'] ?? '' ), 'Selection usage must update diagnostics status.' );
dadata_suggestions_assert( 'suggestion_click' === ( $token_pool->last_request_today( 'second-token' )['error_code'] ?? '' ), 'Selection usage diagnostics must store suggestion_click usage type.' );

$_POST = array( 'level' => 'house', 'usage_type' => 'final_selection' );
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
$_POST = array( 'level' => 'street', 'usage_type' => 'final_selection' );
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
$_POST = array( 'level' => 'street' );
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
$_POST = array( 'level' => 'street', 'usage_type' => 'suggestion_click' );
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
foreach ( array( '9', '75' ) as $level ) {
	$item = $normalizer->normalize( array( 'value' => 'test', 'data' => array( 'fias_level' => $level, 'house' => '1' ) ) );
	dadata_suggestions_assert( true === $item['isDeliverable'], 'Normalizer must mark fias_level ' . $level . ' as deliverable.' );
}

$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-address-suggestions.js' );
foreach ( array( 'activeCheckoutPrefix', 'openAddressPicker', 'address picker opened', "mousedown' + namespace + ' focus' + namespace + ' click", 'selectorFor( activePrefix, \'address_1\' )', 'firstUsable( activePrefix, \'address_1\' )', 'firstUsable( prefix, \'city\' )', 'firstUsable( prefix, \'address_2\' )', 'textarea[name="', 'shipping', 'billing', 'address_1', 'postcode', '.wdc-address-picker-search', 'modal search input', 'addressPickerState', 'address_next', 'street_selected', 'resolved', 'Использовать введенный адрес', 'manual fallback selected', 'dadata_status', 'dadata_unrestricted_value', 'dadata_region_fias_id', 'dadata_city_kladr_id', 'dadata_street_fias_id', 'dadata_house_fias_id', 'dadata_fias_level', 'update_checkout', 'updated_checkout', 'wc_fragments_refreshed', 'wdc_platform_dadata_address_suggest', 'wdc_platform_dadata_suggestion_selected', 'selection_action', 'trackSelectionUsage', 'selection usage counted', 'selection usage failed', 'address suggestions script loaded', 'config enabled', 'config disabled', 'DaData подсказки:', 'tokens ready:', 'total tokens:', 'available tokens:', 'encryption ready:', 'active mode:', 'active address field:', 'active city field:', 'modal opened:', 'last stage:', 'last query:', 'shipping mode active', 'billing mode active', 'using address field selector', 'address field found', 'address field not found', 'ajax request start', 'ajax success items count', 'ajax fail', 'street selected', 'house selected', 'lower-level request after house selection', 'debounceDelay = 300', 'itemStore', 'data-key', 'setHiddenData', 'ensureTrailingComma', 'Уточните номер дома', 'Уточните квартиру, помещение или офис', 'no_available_dadata_token', 'dadata_daily_limit_exhausted', 'Подсказки адреса временно недоступны. Введите адрес вручную.' ) as $needle ) {
	dadata_suggestions_assert( str_contains( $js, $needle ), 'Frontend suggestions JS must contain ' . $needle . '.' );
}
dadata_suggestions_assert( ! str_contains( $js, 'secret-api-key' ) && ! str_contains( $js, 'Authorization' ), 'Frontend suggestions JS must not contain API key values or Authorization headers.' );
dadata_suggestions_assert( ! str_contains( $js, 'var ADDRESS_SELECTOR' ), 'Frontend suggestions JS must not define a combined ADDRESS_SELECTOR.' );
dadata_suggestions_assert( ! str_contains( $js, '#shipping_address_1,input[name="shipping_address_1"],textarea[name="shipping_address_1"],#billing_address_1' ), 'Frontend suggestions JS must not mix shipping and billing address selectors.' );
dadata_suggestions_assert( ! str_contains( $js, ".on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, addressSelector" ), 'Frontend suggestions JS must not search from WooCommerce address_1 input.' );
dadata_suggestions_assert( str_contains( $js, ".on( 'input' + namespace + ' keyup' + namespace + ' paste' + namespace, '.wdc-address-picker-search'" ), 'Frontend suggestions JS must search from modal input.' );
dadata_suggestions_assert( str_contains( $js, "trackSelectionUsage( selectedItem, 'suggestion_click' );" ) && str_contains( $js, 'selectItem( selectedItem );' ), 'Suggestion click usage call must be fire-and-forget before applying selected item.' );
dadata_suggestions_assert( str_contains( $js, "trackSelectionUsage( item, 'final_selection' );" ), 'Final selection usage must be called from the final address apply path.' );
dadata_suggestions_assert( str_contains( $js, "usage_type: usageType || 'suggestion_click'" ), 'Selection usage AJAX must send usage_type and default to suggestion_click.' );
dadata_suggestions_assert( ! str_contains( $js, 'house_after_street' ), 'Frontend suggestions JS must not automatically use house_after_street mode.' );
dadata_suggestions_assert( ! str_contains( $js, 'selectedStreet' ), 'Frontend suggestions JS must not keep sticky selectedStreet state.' );
dadata_suggestions_assert( ! str_contains( $js, 'Изменить улицу' ) && ! str_contains( $js, 'wdc-address-picker-change-street' ), 'Frontend suggestions JS must not show change-street mode UI.' );
dadata_suggestions_assert( str_contains( $js, "var stage = state.awaitingFlatSelection ? 'address_next' : 'address';" ), 'Frontend search stage must use address_next only while typing flats after house selection.' );
dadata_suggestions_assert( str_contains( $js, 'searchInput().val( ensureTrailingComma( item.unrestrictedValue || item.value || item.label || data.street_with_type || \'\' ) );' ), 'Selecting street must keep full visible query plus trailing comma.' );
dadata_suggestions_assert( str_contains( $js, "shippingChecked && ( visibleUsable( selectorFor( 'shipping', 'address_1' )" ), 'activeCheckoutPrefix must use shipping only when shipping fields are visible and ship-to-different is checked.' );
dadata_suggestions_assert( str_contains( $js, "firstUsable( activePrefix, 'address_1' )" ), 'renderDebugBlock must use the active checkout prefix.' );
dadata_suggestions_assert( str_contains( $js, '$( document.body ).off( namespace );' ), 'bind must clear delegated handlers before rebinding active selectors.' );
dadata_suggestions_assert( ! str_contains( $js, "change' + namespace" ) && ! str_contains( $js, "blur' + namespace" ), 'Frontend suggestions JS must not use blur/change to trigger search.' );
dadata_suggestions_assert( str_contains( $js, 'firstUsable( prefix, \'city\' ).val( data.city || data.settlement' ), 'Selected house must update city from selected address.' );
dadata_suggestions_assert( str_contains( $js, "'manual'" ), 'Frontend must support manual fallback status.' );
dadata_suggestions_assert( str_contains( $js, 'openingQuery' ), 'Frontend must build opening query from checkout fields.' );
dadata_suggestions_assert( str_contains( $js, 'requestLowerLevelAfterHouse' ) && str_contains( $js, "request( 'address_next', query, prefix" ), 'House suggestion must request lower-level suggestions before finalizing.' );
dadata_suggestions_assert( str_contains( $js, 'selectedHouseItem' ) && str_contains( $js, 'selectedHouseBaseQuery' ) && str_contains( $js, 'selectedHouseDisplayBase' ) && str_contains( $js, 'selectedHouseContext' ) && str_contains( $js, 'awaitingFlatSelection' ) && str_contains( $js, 'nextLevelMode' ), 'Frontend must keep selected house state while looking up flats.' );
dadata_suggestions_assert( str_contains( $js, "state.awaitingFlatSelection ? 'address_next' : 'address'" ) && str_contains( $js, 'state.selectedHouseContext' ), 'Frontend flat lookup mode must keep searching through address_next with selected house context.' );
dadata_suggestions_assert( str_contains( $js, 'nextLevelQuery = ensureTrailingComma( query );' ) && ! str_contains( $js, "flatQuery = ensureTrailingComma( query ) + 'кв ';" ), 'Frontend must seed the input with only the selected house base and separator, without automatic apartment prefix.' );
dadata_suggestions_assert( str_contains( $js, 'queryMatchesSelectedHouseBase' ) && str_contains( $js, "! queryMatchesSelectedHouseBase( query, state )" ) && str_contains( $js, "stage = 'address';" ), 'Frontend must clear flat lookup mode and return to normal address search when the selected house base changes.' );
dadata_suggestions_assert( str_contains( $js, 'Квартиры не найдены. Выберите из списка или продолжите ввод.' ) && str_contains( $js, 'clearHouseLookupState' ), 'Frontend must keep flat lookup mode from silently finalizing and must clear it explicitly.' );
dadata_suggestions_assert( str_contains( $js, 'Уточните квартиру, помещение или офис' ) && str_contains( $js, 'wdc-address-picker-house-finalize' ) && str_contains( $js, 'showFlatHintWithHouseFinalize' ), 'Frontend must render a keyboard-accessible house-level finalize action in flat lookup mode.' );
dadata_suggestions_assert( str_contains( $js, 'function finalizeHouseWithoutFlat()' ) && str_contains( $js, 'state.selectedHouseItem' ) && str_contains( $js, 'houseLevelItem( item )' ) && str_contains( $js, 'applyResolved( prefix, houseItem );' ), 'House finalize action must resolve the selected DaData house item, not manual fallback.' );
dadata_suggestions_assert( str_contains( $js, "'flat'," ) && str_contains( $js, "'room_number'," ) && str_contains( $js, "'premise_type_full'" ) && str_contains( $js, "data.flat = '';" ) && str_contains( $js, "clone.level = 'house';" ), 'House-level finalize helper must clear flat/room/premise fields and keep level=house.' );
dadata_suggestions_assert( str_contains( $js, 'lowerLevelItems' ) && str_contains( $js, "applyResolved( prefix, item );" ), 'House suggestion must finalize only after lower-level suggestions are checked.' );
dadata_suggestions_assert( str_contains( $js, 'renderResults( lower, query );' ), 'House suggestion with lower-level items must render flats/rooms instead of finalizing immediately.' );
dadata_suggestions_assert( str_contains( $js, "'flat' === item.level || 'room' === item.level || 'premise' === item.level" ), 'Flat/room/premise suggestions must be final selectable levels.' );
dadata_suggestions_assert( str_contains( $js, 'cleanQueryPart' ), 'Frontend must sanitize opening query parts.' );
dadata_suggestions_assert( str_contains( $js, 'checkoutFieldValue' ), 'Opening query must read checkout field values.' );
dadata_suggestions_assert( str_contains( $js, "field.find( 'option:selected' )" ), 'Opening query must read selected state option text for select fields.' );
dadata_suggestions_assert( str_contains( $js, "searchInput().val( openingQuery( activePrefix ) );" ), 'Address picker must seed search from region, city, and address.' );
dadata_suggestions_assert( str_contains( $js, "var region = checkoutFieldValue( prefix, 'state' );" ), 'Opening query region must come from checkout state field.' );
dadata_suggestions_assert( str_contains( $js, "var city = checkoutFieldValue( prefix, 'city' );" ), 'Opening query city must come from checkout city field.' );
dadata_suggestions_assert( str_contains( $js, "var address = checkoutFieldValue( prefix, 'address_1' );" ), 'Opening query address must come from checkout address_1 field.' );
dadata_suggestions_assert( str_contains( $js, "parts.join( ', ' ) + ', '" ), 'Opening query must keep trailing comma when address is empty.' );
dadata_suggestions_assert( str_contains( $js, 'opening query built' ) && str_contains( $js, "regionSource: 'checkout_state'" ) && str_contains( $js, "citySource: 'checkout_city'" ) && str_contains( $js, "addressSource: 'checkout_address_1'" ), 'Opening query debug log must show checkout field sources.' );
dadata_suggestions_assert( ! str_contains( $js, "street_fias_id:" ), 'Frontend search context must not reuse old street_fias_id.' );
dadata_suggestions_assert( str_contains( $js, 'formatStreetHouse' ) && str_contains( $js, 'formatAddressWithoutRegionCity' ) && str_contains( $js, 'formatFullAddressWithoutCountry' ), 'Frontend must format final address lines.' );
dadata_suggestions_assert( str_contains( $js, 'localLocationMatchesDadata' ), 'Frontend must compare selected local location with DaData result.' );
dadata_suggestions_assert( str_contains( $js, 'wdc_platform_location_display_name' ) && str_contains( $js, 'wdc_platform_location_region_name' ) && str_contains( $js, 'wdc_platform_location_postcode' ), 'Frontend must keep WDC-compatible location hidden fields in sync.' );
$manual_start = strpos( $js, 'function manualFallback' );
$manual_end = strpos( $js, 'function bind' );
$manual_body = false !== $manual_start && false !== $manual_end ? substr( $js, $manual_start, $manual_end - $manual_start ) : '';
dadata_suggestions_assert( '' !== $manual_body && ! str_contains( $manual_body, 'trackSelectionUsage' ), 'Manual fallback must not count as DaData suggestion selection.' );

$opening_start = strpos( $js, 'function openingQuery' );
$opening_end = strpos( $js, 'function houseWithType' );
$opening_body = false !== $opening_start && false !== $opening_end ? substr( $js, $opening_start, $opening_end - $opening_start ) : '';
dadata_suggestions_assert( '' !== $opening_body, 'Opening query helper must be present.' );
dadata_suggestions_assert( str_contains( $opening_body, 'wdc_platform_location_fias_id' ) && str_contains( $opening_body, 'wdc_platform_location_display_name' ), 'Opening query must prefer selected local location when fias_id exists.' );
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
foreach ( array( '.wdc-address-picker-overlay', '.wdc-address-picker-panel', '.wdc-address-picker-search', '.wdc-address-picker-results', '.wdc-address-picker-item', '.wdc-address-picker-empty', '.wdc-address-picker-hint', '.wdc-address-picker-selected', '.wdc-address-picker-house-finalize', 'max-width: 1300px', 'column-count: 2', '@media (max-width: 900px)', 'column-count: 1' ) as $needle ) {
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
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder() ) )->persist(
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
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder() ) )->persist(
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
