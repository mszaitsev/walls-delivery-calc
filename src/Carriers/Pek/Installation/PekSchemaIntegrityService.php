<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Installation;

use RuntimeException;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;

defined( 'ABSPATH' ) || exit;

final class PekSchemaIntegrityService {
	private object $wpdb;

	public function __construct(
		?object $wpdb = null,
		private ?PekLocationMappingRepository $mappings = null,
		private ?PekTerminalRepository $terminals = null
	) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
		$this->mappings ??= new PekLocationMappingRepository( $this->wpdb );
		$this->terminals ??= new PekTerminalRepository( $this->wpdb );
	}

	/** @return array<string,array<string,bool>|bool> */
	public function repair(): array {
		$tables = array(
			'location_mappings' => $this->table_name( 'wdc_pek_location_mappings' ),
			'terminals' => $this->table_name( 'wdc_pek_terminals' ),
		);
		$before = array(
			'location_mappings' => $this->table_exists( $tables['location_mappings'] ),
			'terminals' => $this->table_exists( $tables['terminals'] ),
		);
		$repaired = array(
			'location_mappings' => false,
			'terminals' => false,
		);

		if ( ! $before['location_mappings'] ) {
			$this->mappings->install_schema();
			$repaired['location_mappings'] = true;
		}
		if ( ! $before['terminals'] ) {
			$this->terminals->install_schema();
			$repaired['terminals'] = true;
		}

		$after = array(
			'location_mappings' => $this->table_exists( $tables['location_mappings'] ),
			'terminals' => $this->table_exists( $tables['terminals'] ),
		);
		if ( ! $after['location_mappings'] ) {
			throw new RuntimeException( 'PEK schema postcondition failed: location mappings table missing.' );
		}
		if ( ! $after['terminals'] ) {
			throw new RuntimeException( 'PEK schema postcondition failed: terminals table missing.' );
		}

		return array(
			'success' => true,
			'checked' => $after,
			'repaired' => $repaired,
		);
	}

	private function table_name( string $suffix ): string {
		return (string) ( $this->wpdb->prefix ?? '' ) . $suffix;
	}

	private function table_exists( string $table ): bool {
		$this->clear_last_error();
		$like = method_exists( $this->wpdb, 'esc_like' ) ? $this->wpdb->esc_like( $table ) : addcslashes( $table, '_%\\' );
		$sql = method_exists( $this->wpdb, 'prepare' )
			? $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
			: "SHOW TABLES LIKE '" . addslashes( $like ) . "'";
		$result = $this->wpdb->get_var( $sql );
		$this->throw_on_sql_error( 'PEK schema integrity lookup failed.' );

		return is_scalar( $result ) && (string) $result === $table;
	}

	private function clear_last_error(): void {
		if ( property_exists( $this->wpdb, 'last_error' ) ) {
			$this->wpdb->last_error = '';
		}
	}

	private function throw_on_sql_error( string $message ): void {
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			throw new RuntimeException( $message );
		}
	}
}
