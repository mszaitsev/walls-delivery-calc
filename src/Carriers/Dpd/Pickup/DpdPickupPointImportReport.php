<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointImportReport {
	/**
	 * @param array<int,string> $errors
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $started_at,
		public readonly string $finished_at,
		public readonly int $fetched_count,
		public readonly int $normalized_count,
		public readonly int $saved_count,
		public readonly int $skipped_invalid,
		public readonly int $marked_inactive,
		public readonly array $errors,
		public readonly string $message,
		public readonly string $context = 'manual',
		public readonly string $status = ''
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'source' => $this->source,
			'started_at' => $this->started_at,
			'finished_at' => $this->finished_at,
			'fetched_count' => $this->fetched_count,
			'normalized_count' => $this->normalized_count,
			'saved_count' => $this->saved_count,
			'skipped_invalid' => $this->skipped_invalid,
			'marked_inactive' => $this->marked_inactive,
			'errors' => $this->errors,
			'message' => $this->message,
			'context' => $this->context,
			'status' => '' !== $this->status ? $this->status : ( array() === $this->errors ? 'success' : 'error' ),
		);
	}

	/**
	 * @param array<int,self> $reports
	 */
	public static function combine( array $reports ): self {
		$started = '';
		$finished = '';
		$fetched = 0;
		$normalized = 0;
		$saved = 0;
		$skipped = 0;
		$marked_inactive = 0;
		$errors = array();
		$sources = array();
		$contexts = array();
		$statuses = array();
		foreach ( $reports as $report ) {
			$sources[] = $report->source;
			$contexts[] = $report->context;
			$statuses[] = '' !== $report->status ? $report->status : ( array() === $report->errors ? 'success' : 'error' );
			$started = '' === $started ? $report->started_at : min( $started, $report->started_at );
			$finished = max( $finished, $report->finished_at );
			$fetched += $report->fetched_count;
			$normalized += $report->normalized_count;
			$saved += $report->saved_count;
			$skipped += $report->skipped_invalid;
			$marked_inactive += $report->marked_inactive;
			$errors = array_merge( $errors, $report->errors );
		}

		return new self(
			implode( '+', $sources ),
			$started,
			$finished,
			$fetched,
			$normalized,
			$saved,
			$skipped,
			$marked_inactive,
			$errors,
			array() === $errors ? 'DPD pickup points import completed.' : 'DPD pickup points import completed with errors.',
			implode( '+', array_values( array_unique( array_filter( $contexts ) ) ) ) ?: 'manual',
			in_array( 'error', $statuses, true ) ? 'error' : 'success'
		);
	}
}
