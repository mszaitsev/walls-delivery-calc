<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportReport {
	/** @var array<string,mixed> */
	private array $data;

	public function __construct( string $source, string $source_file ) {
		$now = $this->now();
		$this->data = array(
			'phase' => '',
			'status' => '',
			'source' => $source,
			'source_file' => $source_file,
			'total_rows' => 0,
			'ru_rows' => 0,
			'foreign_rows' => 0,
			'foreign_am_rows' => 0,
			'foreign_by_rows' => 0,
			'foreign_kz_rows' => 0,
			'foreign_kg_rows' => 0,
			'foreign_locations_inserted' => 0,
			'foreign_locations_updated' => 0,
			'foreign_save_failed' => 0,
			'foreign_mapping_conflicts' => 0,
			'skipped_non_ru' => 0,
			'skipped_invalid' => 0,
			'matched_by_fias' => 0,
			'matched_by_kladr' => 0,
			'matched_by_name' => 0,
			'saved_candidates' => 0,
			'finalized_mappings' => 0,
			'finalized_changes' => 0,
			'unchanged_mappings' => 0,
			'conflicts' => 0,
			'ambiguous' => 0,
			'unmatched' => 0,
			'errors_total' => 0,
			'errors' => array(),
			'started_at' => $now,
			'finished_at' => '',
			'last_message' => '',
		);
	}

	public function increment( string $key, int $by = 1 ): void {
		if ( ! array_key_exists( $key, $this->data ) || ! is_numeric( $this->data[ $key ] ) ) {
			return;
		}

		$this->data[ $key ] = max( 0, (int) $this->data[ $key ] + $by );
	}

	public function add_error( string $message ): void {
		$errors = is_array( $this->data['errors'] ) ? $this->data['errors'] : array();
		$errors[] = $message;
		$this->data['errors'] = array_slice( $errors, -20 );
		$this->increment( 'errors_total' );
	}

	public function finish(): void {
		$this->data['finished_at'] = $this->now();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
