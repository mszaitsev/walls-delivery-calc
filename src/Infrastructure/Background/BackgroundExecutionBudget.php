<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Background;

defined( 'ABSPATH' ) || exit;

class BackgroundExecutionBudget {
	private float $started_at = 0.0;
	private int $units_processed = 0;
	private string $stop_reason = '';

	/** @var callable */
	private $clock;

	/** @var callable */
	private $memory_usage;

	public function __construct(
		private float $soft_time_budget_seconds,
		private int $max_units,
		private ?int $memory_threshold_bytes = null,
		?callable $clock = null,
		?callable $memory_usage = null
	) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
		$this->memory_usage = $memory_usage ?? static fn(): int => memory_get_usage( true );
		$this->start();
	}

	public function start(): void {
		$this->started_at = (float) ( $this->clock )();
		$this->units_processed = 0;
		$this->stop_reason = '';
	}

	public function can_continue(): bool {
		if ( $this->elapsed_seconds() >= $this->soft_time_budget_seconds ) {
			$this->stop_reason = 'time_budget';
			return false;
		}
		if ( $this->units_processed >= $this->max_units ) {
			$this->stop_reason = 'unit_budget';
			return false;
		}
		if ( null !== $this->memory_threshold_bytes && (int) ( $this->memory_usage )() >= $this->memory_threshold_bytes ) {
			$this->stop_reason = 'memory_budget';
			return false;
		}

		return true;
	}

	public function mark_unit_processed(): void {
		++$this->units_processed;
	}

	public function elapsed_seconds(): float {
		return max( 0.0, (float) ( $this->clock )() - $this->started_at );
	}

	public function units_processed(): int {
		return $this->units_processed;
	}

	public function stop_reason(): string {
		return $this->stop_reason;
	}

	public static function memory_threshold_from_limit( string $memory_limit, float $fraction = 0.8 ): ?int {
		$memory_limit = trim( $memory_limit );
		if ( '' === $memory_limit || '-1' === $memory_limit ) {
			return null;
		}

		$unit = strtolower( substr( $memory_limit, -1 ) );
		$value = (float) $memory_limit;
		$multiplier = match ( $unit ) {
			'g' => 1024 ** 3,
			'm' => 1024 ** 2,
			'k' => 1024,
			default => 1,
		};
		$bytes = (int) floor( $value * $multiplier );
		if ( $bytes <= 0 ) {
			return null;
		}

		return (int) floor( $bytes * min( 1.0, max( 0.1, $fraction ) ) );
	}
}
