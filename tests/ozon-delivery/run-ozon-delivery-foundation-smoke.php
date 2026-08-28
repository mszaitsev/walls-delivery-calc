<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
define( 'APP_ENCRYPTION_KEY', 'ozon-delivery-smoke-key' );
define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
function oz_assert( bool $ok, string $message ): void { if ( ! $ok ) { fwrite( STDERR, "[FAIL] {$message}\n" ); exit( 1 ); } }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['oz_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['oz_options'][ $name ] = $value; return true; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php'; ( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryAccessTokenService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiResponse;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
final class OzFakeHttp implements OzonDeliveryHttpClientInterface { public array $requests = array(); public function __construct( private OzonDeliveryApiResponse $response ) {} public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse { $this->requests[] = compact( 'method', 'url', 'args' ); return $this->response; } }
$GLOBALS['oz_options'] = array(); $repo = new SettingsRepository(); $settings = new OzonDeliverySettings( $repo ); $credentials = new OzonDeliveryCredentials( $repo, new EncryptionService() );
oz_assert( OzonDeliverySettings::CARRIER_KEY === 'ozon_delivery' && OzonDeliverySettings::SERVICE_KEY === 'ozon_delivery', 'stable Ozon keys are required.' );
oz_assert( array_key_exists( OzonDeliverySettings::CLIENT_ID_KEY, $repo->defaults() ), 'SettingsRepository must compose Ozon defaults.' );
oz_assert( $credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => ' client-id ', 'ozon_delivery_client_secret' => 'secret-value' ) ), 'encrypted credential save must succeed.' );
$stored = get_option( 'wdc_core_settings', array() ); oz_assert( ! str_contains( serialize( $stored ), 'secret-value' ) && $credentials->client_id() === 'client-id' && $credentials->client_secret() === 'secret-value', 'secret must be encrypted and client ID sanitized.' );
$before = $stored[ OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY ]; $credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => 'client-id' ) ); oz_assert( $before === get_option( 'wdc_core_settings', array() )[ OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY ], 'blank secret must preserve stored secret.' );
$credentials->save_from_admin( array( 'ozon_delivery_clear_client_secret' => '1' ) ); oz_assert( ! $credentials->has_client_secret(), 'explicit clear must remove secret.' );
$credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => 'client-id', 'ozon_delivery_client_secret' => 'secret-value' ) );
$http = new OzFakeHttp( new OzonDeliveryApiResponse( 200, '{"access_token":"temporary-token","ignored":"field"}' ) ); $tokens = new OzonDeliveryAccessTokenService( $credentials, $http, new OzonDeliveryMessageSanitizer() ); oz_assert( $tokens->obtain() === 'temporary-token', 'token response must accept non-empty access_token.' );
$request = $http->requests[0]; $body = json_decode( $request['args']['body'], true ); oz_assert( $request['method'] === 'POST' && $request['url'] === OzonDeliverySettings::TOKEN_URL && $request['args']['headers']['Content-Type'] === 'application/json' && $body['grant_type'] === 'client_credentials' && $body['scope'] === OzonDeliverySettings::TOKEN_SCOPE, 'token request must match official contract.' );
$diagnostic = new OzonDeliveryConnectionDiagnosticService( $credentials, $tokens, $settings ); $result = $diagnostic->run(); oz_assert( $result['success'] && $result['oauth_token_received'] && ! $result['application_api_checked'] && ! str_contains( serialize( $result ), 'temporary-token' ), 'diagnostic must only validate OAuth and not expose token.' );
foreach ( array( '{}', '[]', '{"access_token":""}', '{bad' ) as $body ) { try { ( new OzonDeliveryAccessTokenService( $credentials, new OzFakeHttp( new OzonDeliveryApiResponse( 200, $body ) ), new OzonDeliveryMessageSanitizer() ) )->obtain(); oz_assert( false, 'invalid token response must fail.' ); } catch ( OzonDeliveryApiException ) {} }
$html_response = new OzonDeliveryApiResponse( 403, '<html>secret-value temporary-token</html>', array( 'content-type' => 'text/html; charset=utf-8' ) );
try { ( new OzonDeliveryAccessTokenService( $credentials, new OzFakeHttp( $html_response ), new OzonDeliveryMessageSanitizer() ) )->obtain(); oz_assert( false, 'HTML OAuth failure must fail.' ); } catch ( OzonDeliveryApiException $e ) { oz_assert( 403 === $e->http_status && 'oauth_token' === $e->operation && 'text/html' === $e->metadata['response_content_type'] && false === $e->metadata['response_json'] && 'invalid_json' === $e->metadata['response_root'] && ! str_contains( serialize( $e->metadata ), 'secret-value' ) && ! str_contains( serialize( $e->metadata ), 'temporary-token' ), 'OAuth evidence must retain only safe structural metadata.' ); }
$unknown_response = new OzonDeliveryApiResponse( 401, '{"error":"secret-value","error_description":"temporary-token","safe_key":"ignored"}', array( 'content-type' => 'application/json' ) );
try { ( new OzonDeliveryAccessTokenService( $credentials, new OzFakeHttp( $unknown_response ), new OzonDeliveryMessageSanitizer() ) )->obtain(); oz_assert( false, 'unknown OAuth failure must fail.' ); } catch ( OzonDeliveryApiException $e ) { oz_assert( array( 'error', 'error_description', 'safe_key' ) === $e->metadata['response_keys'] && ! str_contains( serialize( $e->metadata ), 'secret-value' ) && ! str_contains( serialize( $e->metadata ), 'temporary-token' ), 'unknown OAuth JSON must preserve names, not values.' ); }
$safe = ( new OzonDeliveryMessageSanitizer() )->sanitize( 'Bearer temporary-token client_secret=secret-value phone +79990001122 x@y.test' ); oz_assert( ! str_contains( $safe, 'temporary-token' ) && ! str_contains( $safe, 'secret-value' ) && ! str_contains( $safe, '79990001122' ) && ! str_contains( $safe, 'x@y.test' ), 'sanitizer must redact secrets and personal data.' );
echo "Ozon Delivery foundation smoke passed.\n";
