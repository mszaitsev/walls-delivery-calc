<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

defined( 'ABSPATH' ) || exit;

final class GarImportResult {
	/**
	 * @param array<int,string> $errors
	 */
	public function __construct(
		public bool $success = false,
		public int $rows_read = 0,
		public int $stage_rows = 0,
		public int $regions_imported = 0,
		public int $locations_imported = 0,
		public int $aliases_imported = 0,
		public int $skipped_rows = 0,
		public array $errors = array(),
		public string $started_at = '',
		public string $finished_at = ''
	) {
	}

	public function finish( bool $success ): self {
		$this->success = $success;
		$this->finished_at = current_time( 'mysql' );

		return $this;
	}
}
