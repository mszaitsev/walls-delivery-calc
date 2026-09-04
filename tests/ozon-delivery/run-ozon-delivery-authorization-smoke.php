<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ );
function oa_assert( bool $ok, string $message ): void { if ( ! $ok ) { fwrite( STDERR, "[FAIL] {$message}\n" ); exit( 1 ); } }
$GLOBALS['oa_responses'] = array();
$GLOBALS['oa_requests'] = array();
function wp_remote_request( string $url, array $args ): array { $GLOBALS['oa_requests'][] = array( 'url' => $url, 'args' => $args ); return array_shift( $GLOBALS['oa_responses'] ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) $response['status']; }
function wp_remote_retrieve_headers( array $response ): mixed { return $response['headers'] ?? array(); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
final class OaHeaderCollection { /** @param array<string,array<int,string>> $values */ public function __construct( private array $values ) {} /** @return array<int,string> */ public function getValues( string $name ): array { return $this->values[strtolower( $name )] ?? array(); } }
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Api\WpOzonDeliveryHttpClient;
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/WpOzonDeliveryHttpClient.php' ) ?: '';
$token = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/OzonDeliveryAccessTokenService.php' ) ?: '';
$settings = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/OzonDeliverySettings.php' ) ?: '';
$cache = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Api/OzonDeliveryTokenCache.php' ) ?: '';
oa_assert( str_contains( $token, "'grant_type' => 'client_credentials'") && str_contains( $token, "'scope' => OzonDeliverySettings::TOKEN_SCOPE" ) && str_contains( $settings, "array( 'delivery-api.all' )" ) && ! str_contains( $settings, 'delivery-api.delivery' ), 'OAuth must use the approved client_credentials scope.' );
oa_assert( str_contains( $source, 'array( 302, 307 )' ) && str_contains( $source, "'Cookie' => \$cookie_header" ) && str_contains( $source, 'cookie_jar' ) && str_contains( $source, "'redirection' => 0" ), 'transport must own documented DDoS 302/307 multi-cookie handoff.' );
oa_assert( str_contains( $source, 'MAX_REDIRECTS = 12' ) && str_contains( $source, 'redirect_rejected' ), 'redirect hop limit and fail-closed rejection are required.' );
oa_assert( str_contains( $source, "'xapi.ozon.ru', 'api-delivery.ozon.ru'") && str_contains( $source, 'required_host === strtolower' ), 'redirect must remain HTTPS on the confirmed source Ozon host.' );
oa_assert( str_contains( $cache, 'encrypted_access_token' ) && str_contains( $cache, 'SAFETY_MARGIN_SECONDS = 60' ) && ! str_contains( $cache, 'refresh_token' ) && ! str_contains( $token, "'grant_type' => 'refresh_token'") && str_contains( $token, "expires_at( \$data['expires_in'] ?? null )" ), 'token cache must be encrypted, bounded, and must not implement refresh or relative TTL assumptions.' );
$transport = new WpOzonDeliveryHttpClient( 15, new OzonDeliveryMessageSanitizer() );
$GLOBALS['oa_responses'] = array( array( 'status' => 302, 'headers' => array( 'location' => 'https://xapi.ozon.ru/challenge', 'set-cookie' => '__Secure-ETC=value1; Path=/; Secure; HttpOnly' ) ), array( 'status' => 307, 'headers' => array( 'location' => 'https://xapi.ozon.ru/challenge?__rr=1', 'set-cookie' => array( '__Secure-ETC=value2; Path=/; Secure; HttpOnly', 'abt_data=valueA; Path=/; Secure' ) ) ), array( 'status' => 200, 'body' => '{}' ) );
$transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array( 'headers' => array( 'Authorization' => 'Bearer temporary-token' ), 'body' => 'sensitive-token-body' ) );
oa_assert( count( $GLOBALS['oa_requests'] ) === 3 && ( $GLOBALS['oa_requests'][1]['args']['headers']['Cookie'] ?? '' ) === '__Secure-ETC=value1' && ( $GLOBALS['oa_requests'][2]['args']['headers']['Cookie'] ?? '' ) === '__Secure-ETC=value2; abt_data=valueA' && ! str_contains( (string) $GLOBALS['oa_requests'][2]['args']['headers']['Cookie'], 'Path' ), 'redirect cookie jar must replace same-name cookies, add new cookies and exclude attributes.' );
$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array( array( 'status' => 307, 'headers' => array( 'location' => 'https://xapi.ozon.ru/challenge-307', 'set-cookie' => 'testcookie=two; Path=/' ) ), array( 'status' => 200, 'body' => '{}' ) );
$transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array() );
oa_assert( count( $GLOBALS['oa_requests'] ) === 2 && ( $GLOBALS['oa_requests'][1]['args']['headers']['Cookie'] ?? '' ) === 'testcookie=two', '307 must hand Set-Cookie to the next same-host request.' );
$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array( array( 'status' => 307, 'headers' => new OaHeaderCollection( array( 'location' => array( 'https://xapi.ozon.ru/headers-object' ), 'set-cookie' => array( 'one=1; Path=/', 'two=2; HttpOnly' ) ) ) ), array( 'status' => 200, 'body' => '{}' ) ); $transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array() ); oa_assert( ( $GLOBALS['oa_requests'][1]['args']['headers']['Cookie'] ?? '' ) === 'one=1; two=2', 'Requests-compatible header collections must preserve multiple Set-Cookie values.' );
$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array( array( 'status' => 200, 'body' => '{}' ) ); $transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array() ); oa_assert( 1 === count( $GLOBALS['oa_requests'] ) && ! isset( $GLOBALS['oa_requests'][0]['args']['headers']['Cookie'] ), 'an independent request must begin with an empty cookie jar.' );
$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array_fill( 0, 5, array( 'status' => 302, 'headers' => array( 'location' => 'https://xapi.ozon.ru/challenge' ) ) ); $GLOBALS['oa_responses'][] = array( 'status' => 200, 'body' => '{}' ); $transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array() ); oa_assert( 6 === count( $GLOBALS['oa_requests'] ), 'valid same-host Ozon redirect chains longer than three hops must be accepted.' );
foreach ( array( '', 'http://xapi.ozon.ru/challenge', 'https://attacker.example/challenge' ) as $location ) {
	$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array( array( 'status' => 302, 'headers' => array( 'location' => $location ) ) );
	try { $transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token', array( 'headers' => array( 'Authorization' => 'Bearer temporary-token' ), 'body' => 'sensitive-token-body' ) ); oa_assert( false, 'unsafe redirect must fail.' ); } catch ( OzonDeliveryApiException $e ) { oa_assert( $e->safe_code === 'redirect_rejected' && count( $GLOBALS['oa_requests'] ) === 1, 'unsafe redirect must not receive sensitive follow-up request.' ); }
}
$GLOBALS['oa_requests'] = array(); $GLOBALS['oa_responses'] = array_fill( 0, 13, array( 'status' => 302, 'headers' => array( 'location' => 'https://xapi.ozon.ru/challenge' ) ) );
try { $transport->request( 'POST', 'https://xapi.ozon.ru/oauth/token' ); oa_assert( false, 'redirect loop must fail.' ); } catch ( OzonDeliveryApiException $e ) { oa_assert( $e->safe_code === 'redirect_rejected' && count( $GLOBALS['oa_requests'] ) === 13, 'redirect loop must stop at bounded hop limit.' ); }
echo "Ozon Delivery authorization smoke passed.\n";
