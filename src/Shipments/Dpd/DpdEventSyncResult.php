<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdEventSyncResult {
	/** @param array<string,mixed> $extra */
	public function __construct(
		public bool $success = true,
		public string $message = '',
		public int $packages = 0,
		public int $events = 0,
		public int $updated = 0,
		public int $unchanged = 0,
		public int $unmatched = 0,
		public string $confirm_status = 'disabled',
		public bool $result_complete = true,
		public array $extra = array()
	) {}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array_merge(
			array(
				'success' => $this->success,
				'message' => $this->message,
				'packages' => $this->packages,
				'events' => $this->events,
				'updated' => $this->updated,
				'unchanged' => $this->unchanged,
				'unmatched' => $this->unmatched,
				'confirm_status' => $this->confirm_status,
				'resultComplete' => $this->result_complete,
			),
			$this->extra
		);
	}
}
