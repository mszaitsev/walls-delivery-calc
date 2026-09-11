<?php
declare(strict_types=1);

$wordpress_root = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wdc-rp-background-file-api-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$file_api_dir = $wordpress_root . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes';
if ( ! mkdir( $file_api_dir, 0700, true ) && ! is_dir( $file_api_dir ) ) {
	throw new RuntimeException( 'Unable to create WordPress File API fixture directory.' );
}
file_put_contents(
	$file_api_dir . DIRECTORY_SEPARATOR . 'file.php',
	<<<'PHP'
<?php
$GLOBALS['wdc_rp_file_api_loaded'] = ($GLOBALS['wdc_rp_file_api_loaded'] ?? 0) + 1;
function wp_tempnam( string $filename = '' ): string|false {
	return tempnam( sys_get_temp_dir(), 'wdc-rp-file-api-' );
}
PHP
);

defined( 'ABSPATH' ) || define( 'ABSPATH', $wordpress_root . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_file_api_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wp_remote_get( string $url, array $args = array() ): array {
	file_put_contents( (string) ( $args['filename'] ?? '' ), 'background-download' );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '' );
}
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_response_message( array $response ): string { return (string) ( $response['response']['message'] ?? '' ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function is_wp_error( mixed $value ): bool { return false; }

rp_file_api_assert( ! function_exists( 'wp_tempnam' ), 'Background fixture must start without wp-admin/includes/file.php loaded.' );

$client_reflection = new ReflectionClass( WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient::class );
$client = $client_reflection->newInstanceWithoutConstructor();
$wp_download = $client_reflection->getMethod( 'download_with_wp_http' );
$wp_download->setAccessible( true );
$download = $wp_download->invoke( $client, 'https://example.invalid/passport.zip', 'ALL', 'token', 'basic', 30 );

rp_file_api_assert( 1 === (int) ( $GLOBALS['wdc_rp_file_api_loaded'] ?? 0 ), 'Background download must load wp-admin/includes/file.php itself.' );
rp_file_api_assert( function_exists( 'wp_tempnam' ) && ! empty( $download['success'] ) && is_file( (string) ( $download['temp_file'] ?? '' ) ), 'WP HTTP background download must create its temp file without an admin request bootstrap.' );
@unlink( (string) ( $download['temp_file'] ?? '' ) );

$client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiClient.php' );
rp_file_api_assert( 2 === substr_count( $client_source, 'if ( ! $this->ensure_wordpress_file_api() )' ), 'Both cURL and WP HTTP download backends must ensure WordPress File API availability.' );

if ( class_exists( ZipArchive::class ) ) {
	$zip_file = tempnam( sys_get_temp_dir(), 'wdc-rp-file-api-zip-' );
	$zip = new ZipArchive();
	$zip->open( $zip_file, ZipArchive::OVERWRITE );
	$zip->addFromString( 'passport.json', '{"passportElements":[]}' );
	$zip->close();

	$importer_reflection = new ReflectionClass( WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter::class );
	$importer = $importer_reflection->newInstanceWithoutConstructor();
	$extract = $importer_reflection->getMethod( 'extract_first_payload_from_zip' );
	$extract->setAccessible( true );
	$extracted = $extract->invoke( $importer, $zip_file );
	rp_file_api_assert( ! empty( $extracted['success'] ) && is_file( (string) ( $extracted['payload_file'] ?? '' ) ), 'ZIP extraction must create its payload temp file through the ensured WordPress File API.' );
	@unlink( (string) ( $extracted['payload_file'] ?? '' ) );
	@unlink( $zip_file );
}

@unlink( $file_api_dir . DIRECTORY_SEPARATOR . 'file.php' );
@rmdir( $file_api_dir );
@rmdir( dirname( $file_api_dir ) );
@rmdir( $wordpress_root );

echo "Russian Post background WordPress File API smoke passed.\n";
