<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Admin\PekAdminNoticeStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminPage;
use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekConnectionDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function pek_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function current_time( string $type ): int|string { return 'timestamp' === $type ? 1785652800 : '2026-08-02 12:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_ui_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_ui_options'][ $option ] = $value; return true; }
function get_current_user_id(): int { return 11; }
function get_transient( string $key ): mixed { return $GLOBALS['pek_ui_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['pek_ui_transients'][ $key ] = array( 'value' => $value, 'expiration' => $expiration ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_ui_transients'][ $key ] ); return true; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function selected( mixed $selected, mixed $current, bool $display = true ): string { return (string) $selected === (string) $current ? ' selected="selected"' : ''; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
function submit_button( string $text, string $type = 'primary' ): void { echo '<button class="button button-' . esc_attr( $type ) . '" type="submit">' . esc_html( $text ) . '</button>'; }

final class PekUiFakeHttp implements PekHttpClientInterface {
	public function request( string $method, string $url, array $args ): array {
		return array( 'status' => 200, 'body' => '[]' );
	}
}

$GLOBALS['pek_ui_options'] = array();
$GLOBALS['pek_ui_transients'] = array();
define( 'APP_ENCRYPTION_KEY', 'pek-ui-key' );

$settings_repository = new SettingsRepository();
$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, new EncryptionService() );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
$settings->save_diagnostic_result(
	array(
		'success' => false,
		'classifier_mismatches' => array(
			array(
				'country' => 'RU',
				'expected' => '643',
				'actual' => '999',
			),
		),
	)
);
$cache = new PekSenderWarehouseSearchCache();
$cache->save_for_current_user(
	array(
		'success' => true,
		'message' => 'found',
		'items' => array(),
		'requested' => array( 'address' => 'Новосибирск', 'departmentOperation' => 2, 'type' => 3 ),
	)
);
$notice_store = new PekAdminNoticeStore();
$notice_store->save_for_current_user( 'success', 'Saved <safe>' );
$api = new PekApiClient( $settings, $credentials, new PekUiFakeHttp(), new PekRequestBudget( $settings ) );
$page = new PekAdminPage( $settings, $credentials, new PekConnectionDiagnosticService( $settings, $credentials, $api ), new PekSenderWarehouseService( $api, $settings, $cache ), $notice_store );
$service = DeliveryService::from_array( array( 'id' => 5, 'service_key' => PekSettings::SERVICE_KEY, 'carrier_key' => PekSettings::CARRIER_KEY, 'title' => 'ПЭК' ) );

set_error_handler(
	static function ( int $severity, string $message ): bool {
		throw new RuntimeException( $message, $severity );
	}
);
ob_start();
$page->render_embedded( $service );
$html = (string) ob_get_clean();
restore_error_handler();

pek_ui_assert( str_contains( $html, 'RU' ) && str_contains( $html, '643' ) && str_contains( $html, '999' ), 'PEK diagnostic classifier mismatch must render country/expected/actual.' );
pek_ui_assert( ! str_contains( $html, '>Array<' ) && ! str_contains( $html, 'Array to string conversion' ), 'PEK diagnostic nested arrays must not render as Array or warning text.' );
pek_ui_assert( str_contains( $html, 'Saved &lt;safe&gt;' ), 'PEK admin notice must render escaped content.' );
$search_form_pos = strpos( $html, 'name="wdc_delivery_services_action" value="search_pek_sender_warehouse"' );
$search_field_pos = strpos( $html, 'id="pek_warehouse_search_address"' );
$table_pos = strpos( $html, '<table class="form-table" role="presentation">', $search_form_pos === false ? 0 : $search_form_pos );
pek_ui_assert( false !== $search_form_pos && false !== $table_pos && false !== $search_field_pos && $table_pos < $search_field_pos, 'PEK warehouse search field must be inside a form-table.' );
pek_ui_assert( array() === $notice_store->consume_for_current_user(), 'PEK admin notice must be consumed by render.' );

echo "PEK admin UI smoke OK\n";
