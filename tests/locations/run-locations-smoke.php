<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;

		/** @var array<int, array<string,mixed>> */
		public array $rows = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, array $format ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
				$this->rows[ $id ]['id'] = $id;
				return 1;
			}

			return 0;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$query = $prepared['query'];
			$value = (string) ( $prepared['args'][0] ?? '' );

			foreach ( $this->rows as $row ) {
				if ( str_contains( $query, 'WHERE id =' ) && (int) $row['id'] === (int) $value ) {
					return $row;
				}

				if ( str_contains( $query, 'WHERE fias_id =' ) && (string) $row['fias_id'] === $value ) {
					return $row;
				}

				if ( str_contains( $query, 'WHERE gar_id =' ) && (string) $row['gar_id'] === $value ) {
					return $row;
				}
			}

			return null;
		}

		public function get_results( array $prepared, string $output ): array {
			$query = trim( (string) $prepared['args'][0], '%' );
			$limit = (int) ( $prepared['args'][1] ?? 20 );
			$rows = array_filter(
				$this->rows,
				static fn( array $row ): bool => 1 === (int) $row['active'] && str_contains( (string) $row['searchable_text'], $query )
			);

			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );

			return array_slice( array_values( $rows ), 0, $limit );
		}

		public function get_var( mixed $query ): int {
			return count( $this->rows );
		}

		public function query( mixed $query ): int {
			if ( is_string( $query ) && str_starts_with( $query, 'DELETE FROM' ) ) {
				$this->rows = array();
			}

			return 1;
		}
	}
}

function current_time( string $type ): string {
	return '2026-05-21 12:00:00';
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function locations_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
$importer = new LocationImportService( $repository );
$search = new LocationSearchService( $repository );

$imported = $importer->import_from_json_file( dirname( __DIR__, 2 ) . '/database/demo/locations-demo.json' );
locations_smoke_assert( $imported >= 15, 'Demo dataset must import at least 15 locations.' );
locations_smoke_assert( $repository->count_all() > 0, 'Repository count must be greater than zero.' );

$novos = $search->search( 'новос' );
locations_smoke_assert( count( $novos ) > 0, 'Search "новос" must return results.' );
locations_smoke_assert( '' !== $novos[0]->display_name, 'Search result display_name must be present.' );

$grouped = $search->grouped( 'новос' );
locations_smoke_assert( isset( $grouped['Новосибирская область'] ), 'Grouped search must contain Novosibirsk region.' );

$exact = $search->search( 'новосибирск', 5 );
locations_smoke_assert( isset( $exact[0] ) && 'Новосибирск — Новосибирская область' === $exact[0]->display_name, 'Exact city match must rank first.' );

$fallback = ( new FallbackAddressNormalizer() )->normalize( 'Новосибирск, Красный проспект, 1' );
locations_smoke_assert( false === $fallback->success, 'Fallback normalizer must not report success.' );
locations_smoke_assert( 'fallback' === $fallback->source, 'Fallback normalizer source must be fallback.' );
locations_smoke_assert( 'Новосибирск, Красный проспект, 1' === $fallback->address->raw_address, 'Fallback normalizer must preserve raw address.' );

echo "Locations smoke test passed.\n";
