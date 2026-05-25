<?php
declare(strict_types=1);

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMapping;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['rp_country_options'] = array();
$GLOBALS['rp_country_tables_created'] = array();

function country_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['rp_country_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['rp_country_options'][ $key ] = $value; return true; }
function current_time( string $type ): string { return '2026-05-25 12:00:00'; }
function wp_date( string $format ): string { return date( $format, strtotime( '2026-05-25 12:00:00' ) ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\\-]/', '', (string) $value ) ?? '' ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_get( string $url, array $args = array() ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'country' => array(
					array( 'id' => 40, 'name' => 'АВСТРИЯ', 'parcel' => array( 'block' => 0 ) ),
					array( 'id' => 31, 'name' => 'АЗЕРБАЙДЖАН', 'parcel' => array( 'block' => 1 ) ),
					array( 'id' => 840, 'name' => 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ', 'parcel' => array( 'block' => 0 ) ),
					array( 'id' => 76, 'name' => 'БРАЗИЛИЯ', 'parcel' => array( 'block' => 0 ) ),
					array( 'id' => 999, 'name' => 'НЕВЕРЛЕНД', 'parcel' => array( 'block' => 0 ) ),
					array( 'id' => 643, 'name' => 'РОССИЯ', 'parcel' => array( 'block' => 0 ) ),
				),
			)
		),
	);
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
function current_user_can( string $capability ): bool { return AdminMenu::CAPABILITY === $capability; }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce; }
function wp_nonce_field( string $action, string $name, bool $referer = true, bool $display = true ): string { $html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; if ( $display ) { echo $html; } return $html; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_url( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function add_action( string $hook, mixed $callback ): void {}
function add_submenu_page( mixed ...$args ): void {}
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $params, string $url ): string { return $url . '?' . http_build_query( $params ); }
function absint( mixed $value ): int { return abs( (int) $value ); }

function dbDelta( string $sql ): void {
	$GLOBALS['rp_country_tables_created'][] = $sql;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $rp_rows = array();

		public function get_charset_collate(): string { return ''; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$this->rp_rows[] = $data;
			return true;
		}
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->rp_rows as $i => $row ) {
				$match = true;
				foreach ( $where as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						$match = false;
					}
				}
				if ( $match ) {
					$this->rp_rows[ $i ] = array_merge( $row, $data );
				}
			}
			return true;
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'COUNT(*) AS total' ) ) {
				return array(
					'total' => count( $this->rp_rows ),
					'matched' => array_sum( array_column( $this->rp_rows, 'matched' ) ),
					'api_available' => array_sum( array_column( $this->rp_rows, 'api_available' ) ),
					'enabled' => array_sum( array_column( $this->rp_rows, 'effective_enabled' ) ),
					'skipped' => count( array_filter( $this->rp_rows, static fn( array $r ): bool => empty( $r['matched'] ) ) ),
					'manual_enabled' => count( array_filter( $this->rp_rows, static fn( array $r ): bool => 'enabled' === ( $r['manual_mode'] ?? '' ) ) ),
					'manual_disabled' => count( array_filter( $this->rp_rows, static fn( array $r ): bool => 'disabled' === ( $r['manual_mode'] ?? '' ) ) ),
					'last_checked_at' => '2026-05-25 12:00:00',
				);
			}
			if ( preg_match( "/wc_country_code = '([^']+)'/", $query, $m ) ) {
				foreach ( $this->rp_rows as $row ) {
					if ( $row['wc_country_code'] === $m[1] ) {
						return $row;
					}
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array {
			$rows = $this->rp_rows;
			if ( str_contains( $query, 'effective_enabled = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $r ): bool => ! empty( $r['effective_enabled'] ) ) );
			}
			if ( str_contains( $query, 'effective_enabled = 0' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $r ): bool => empty( $r['effective_enabled'] ) ) );
			}
			return $rows;
		}
		public function get_col( string $query ): array { return array_values( array_column( array_filter( $this->rp_rows, static fn( array $r ): bool => ! empty( $r['effective_enabled'] ) ), 'wc_country_code' ) ); }
		public function get_var( string $query ): mixed { return count( $this->rp_rows ); }
		public function query( string $query ): bool { if ( str_contains( $query, 'CREATE TABLE' ) ) { $GLOBALS['rp_country_tables_created'][] = $query; } if ( str_starts_with( $query, 'DELETE' ) ) { $this->rp_rows = array(); } return true; }
	}
}

$GLOBALS['wpdb'] = new wpdb();

final class RpCountriesSmokeCountries {
	public function get_countries(): array {
		return array(
			'AT' => 'Австрия',
			'AZ' => 'Азербайджан',
			'AL' => 'Albania',
			'US' => 'United States',
			'RU' => 'Russia',
		);
	}
}
function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public RpCountriesSmokeCountries $countries;
			public function __construct() { $this->countries = new RpCountriesSmokeCountries(); }
		};
	}
	return $wc;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0016_create_russian_post_country_mappings.php';
$migration();
country_smoke_assert( [] !== $GLOBALS['rp_country_tables_created'] && str_contains( $GLOBALS['rp_country_tables_created'][0], 'wdc_russian_post_country_mappings' ), 'Migration creates mapping table.' );

$settings_repo = new SettingsRepository();
$rp_settings = new RussianPostSettings( $settings_repo );
$logger = new Logger();
$client = new RussianPostApiClient( $rp_settings, $logger );
$repo = new RussianPostCountryMappingRepository( $GLOBALS['wpdb'] );
$service = new RussianPostCountryMappingService( $repo, $client, $logger );
$stats = $service->refresh_from_api();

country_smoke_assert( 6 === $stats['raw_api_count'], 'refresh_from_api counts raw API countries.' );
country_smoke_assert( $stats['indexed_by_name_count'] > 0, 'API countries without ISO2 are indexed by normalized name.' );
country_smoke_assert( null === $repo->find_by_wc_country_code( 'RU' ), 'RU excluded.' );
country_smoke_assert( $repo->find_by_wc_country_code( 'AT' )?->effective_enabled, 'Matched country with parcel and no block is enabled in auto.' );
country_smoke_assert( '40' === $repo->find_by_wc_country_code( 'AT' )?->rp_country_id, 'rp_country_id is filled from API id.' );
country_smoke_assert( '' === $repo->find_by_wc_country_code( 'AT' )?->rp_iso2, 'Empty rp_iso2 is allowed when API does not provide ISO2.' );
country_smoke_assert( 'name' === $repo->find_by_wc_country_code( 'AT' )?->match_source, 'Austria matches by normalized name.' );
country_smoke_assert( ! $repo->find_by_wc_country_code( 'AZ' )?->effective_enabled, 'Parcel block disables auto country.' );
country_smoke_assert( $repo->find_by_wc_country_code( 'US' )?->effective_enabled && 'alias' === $repo->find_by_wc_country_code( 'US' )?->match_source, 'Alias matching works for United States.' );
country_smoke_assert( false === $repo->find_by_wc_country_code( 'AL' )?->matched && '' === $repo->find_by_wc_country_code( 'AL' )?->rp_country_id, 'Unmatched WooCommerce row is saved without Russian Post country.' );
country_smoke_assert( 2 === count( $stats['unmatched_api_countries'] ), 'Unmatched API countries are returned for manual mapping.' );
country_smoke_assert( ! in_array( '', array_column( $GLOBALS['wpdb']->rp_rows, 'wc_country_code' ), true ), 'Unmatched API countries are not stored as RP-only rows.' );
country_smoke_assert( ! in_array( '76', array_map( 'strval', array_column( $GLOBALS['wpdb']->rp_rows, 'rp_country_id' ) ), true ), 'Unmatched API countries are not stored in mapping table.' );
$manual_options = $service->manual_mapping_options();
$manual_option_codes = array_column( $manual_options, 'wc_country_code' );
country_smoke_assert( ! in_array( 'AT', $manual_option_codes, true ) && ! in_array( 'US', $manual_option_codes, true ), 'Matched WC countries are excluded from manual_mapping_options().' );
country_smoke_assert( in_array( 'AL', $manual_option_codes, true ), 'Unmatched WC country without Russian Post id is available in manual_mapping_options().' );

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	RussianPostCountriesAdminPage::PAGE_SLUG => RussianPostCountriesAdminPage::PAGE_SLUG,
	'wdc_russian_post_countries_nonce' => 'nonce',
	'wdc_rp_country_action' => 'refresh',
);
$admin = new RussianPostCountriesAdminPage( $repo, $service );
ob_start();
$admin->render_page();
$refresh_html = (string) ob_get_clean();
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
country_smoke_assert( str_contains( $refresh_html, 'Страны Почты России, требующие ручного сопоставления' ) && str_contains( $refresh_html, 'БРАЗИЛИЯ' ), 'Unmatched API countries are shown in manual mapping UI after refresh.' );

$directory = new RussianPostCountryDirectory( $client, $logger, $repo, $service, $rp_settings );
$at_country = $directory->get_country( 'AT' );
country_smoke_assert( '40' === ( $at_country['carrier_country_id'] ?? '' ), 'runtime get_country("AT") returns carrier_country_id=40 for fixture.' );

$manual_payload = array_values( array_filter( $stats['unmatched_api_countries'], static fn( array $row ): bool => '999' === (string) ( $row['rp_country_id'] ?? '' ) ) );
country_smoke_assert( [] !== $manual_payload, 'Unmatched API payload contains manual mapping candidate.' );
$blocked_manual_result = $service->apply_manual_mappings( $stats['unmatched_api_countries'], array( (string) $manual_payload[0]['key'] => 'AT' ) );
country_smoke_assert( 0 === $blocked_manual_result['updated'] && '40' === $repo->find_by_wc_country_code( 'AT' )?->rp_country_id, 'Manual mapping backend skips already matched WC countries.' );
$manual_result = $service->apply_manual_mappings( $stats['unmatched_api_countries'], array( (string) $manual_payload[0]['key'] => 'AL' ) );
country_smoke_assert( 1 === $manual_result['updated'], 'Manual mapping updates WooCommerce row.' );
country_smoke_assert( 'manual' === $repo->find_by_wc_country_code( 'AL' )?->match_source && '999' === $repo->find_by_wc_country_code( 'AL' )?->rp_country_id, 'Manual mapping stores match_source and Russian Post id.' );
country_smoke_assert( 'сопоставлено вручную 25.05.2026' === $repo->find_by_wc_country_code( 'AL' )?->manual_comment, 'Manual mapping writes mapping comment.' );
country_smoke_assert( '999' === ( $directory->get_country( 'AL' )['carrier_country_id'] ?? '' ), 'After manual mapping runtime get_country(WC_CODE) works.' );
$manual_options = $service->manual_mapping_options();
country_smoke_assert( ! in_array( 'AL', array_column( $manual_options, 'wc_country_code' ), true ), 'Manual matched WC country is excluded from manual_mapping_options().' );

$repo->set_manual_mode( 'AT', RussianPostCountryMapping::MODE_DISABLED, 'manual off' );
country_smoke_assert( ! $repo->find_by_wc_country_code( 'AT' )?->effective_enabled, 'Manual disabled overrides API enabled.' );
country_smoke_assert( 'manual off' === $repo->find_by_wc_country_code( 'AT' )?->manual_comment, 'Manual disabled writes comment.' );
$repo->set_manual_mode( 'AT', RussianPostCountryMapping::MODE_AUTO, 'should clear' );
country_smoke_assert( '' === $repo->find_by_wc_country_code( 'AT' )?->manual_comment, 'Auto clears manual_comment.' );
$repo->set_manual_mode( 'AT', RussianPostCountryMapping::MODE_DISABLED, 'manual off' );

country_smoke_assert( [] === $directory->get_country( 'AT' ), 'get_country returns only effective enabled.' );
$carrier = new RussianPostInternationalCarrier( $rp_settings, $client, $directory, $logger );
$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 1000 );
$quote = $carrier->quote( new QuoteRequest( 'AT', new Address( country_code: 'AT', city: 'Vienna', street: 'Test', house: '1', raw_address: 'Test 1' ), Package::from_items( array( $item ), 0, Money::from_rubles( 100 ), Money::from_rubles( 100 ) ), 'card', Money::from_rubles( 100 ), '2026-05-25' ) );
country_smoke_assert( $quote->has_available_rates() && 'unsupported_country_AT' === $quote->rates[0]->meta['fallback_reason'], 'quote() uses fallback for disabled country mapping.' );

$preview = $service->preview_bulk_lists( array( 'АВСТРИЯ' ), array() );
country_smoke_assert( ! empty( $preview['success'] ) && 1 === count( $preview['available']['changes'] ), 'Bulk available preview detects changes.' );
$preview = $service->preview_bulk_lists( array(), array( 'United States' ) );
country_smoke_assert( ! empty( $preview['success'] ) && 1 === count( $preview['unavailable']['changes'] ), 'Bulk unavailable preview detects changes.' );
$preview = $service->preview_bulk_lists( array( 'AT' ), array( 'Австрия' ) );
country_smoke_assert( empty( $preview['success'] ) && 'duplicate_rows' === $preview['error'], 'Duplicate country in both lists returns error.' );
$preview = $service->preview_bulk_lists( array( 'Atlantis' ), array() );
country_smoke_assert( array( 'Atlantis' ) === $preview['unrecognized'], 'Unrecognized rows reported.' );
$preview = $service->preview_bulk_lists( array( 'БРАЗИЛИЯ' ), array() );
country_smoke_assert( array( 'БРАЗИЛИЯ' ) === $preview['unrecognized'], 'Bulk lists ignore countries absent from mapping table.' );
$preview = $service->preview_bulk_lists( array( 'АВСТРИЯ' ), array() );
$apply = $service->apply_bulk_preview( $preview );
country_smoke_assert( 'изменено вручную 25.05.2026' === $apply['manual_comment'], 'Manual comment saved with DD.MM.YYYY date.' );
country_smoke_assert( 'изменено вручную 25.05.2026' === $repo->find_by_wc_country_code( 'AT' )?->manual_comment, 'Manual comment persisted.' );

$admin = new RussianPostCountriesAdminPage( $repo, $service );
ob_start();
$admin->render_page();
$html = (string) ob_get_clean();
country_smoke_assert( ! str_contains( $html, '<script>alert(1)</script>' ), 'Admin output escapes or sanitizes country names from API.' );
country_smoke_assert( str_contains( $html, 'Источник сопоставления' ) && str_contains( $html, '>alias<' ), 'Admin table shows match_source.' );
country_smoke_assert( ! str_contains( $html, 'ISO2 Почты' ), 'Admin table has no Russian Post ISO2 column.' );
country_smoke_assert( str_contains( $html, 'wdc-rp-country-enabled' ) && str_contains( $html, 'wdc-rp-country-disabled' ) && str_contains( $html, '✅' ) && str_contains( $html, '❌' ), 'WooCommerce code column shows enabled/disabled marker.' );

$legacy_diff = function_exists( 'shell_exec' ) ? trim( (string) shell_exec( 'git diff --name-only -- includes' ) ) : '';
country_smoke_assert( '' === $legacy_diff, 'legacy includes/* must not be modified.' );

echo "Russian Post countries smoke test passed.\n";
