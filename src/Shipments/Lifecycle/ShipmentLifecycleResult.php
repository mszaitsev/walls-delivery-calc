<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Lifecycle;

defined( 'ABSPATH' ) || exit;

final class ShipmentLifecycleResult {
	public const PHASE_COMPLETED = 'completed';
	public const PHASE_SUBMISSION_REQUIRED = 'submission_required';
	public const PHASE_POLLING_REQUIRED = 'polling_required';
	public const PHASE_PENDING = 'pending';
	public const PHASE_FAILED = 'failed';
	public const PHASE_TERMINAL = 'terminal';

	/**
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly string $phase,
		public readonly bool $accepted = true,
		public readonly bool $submit_required = false,
		public readonly bool $poll_required = false,
		public readonly string $continuation_token = '',
		public readonly string $message = '',
		public readonly int $poll_interval_ms = 5000,
		public readonly int $poll_max_attempts = 14,
		public readonly string $purpose = 'registration',
		public readonly bool $stop_on_error = false,
		public readonly array $meta = array()
	) {
		$this->validate();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'phase' => $this->phase,
			'accepted' => $this->accepted,
			'submit_required' => $this->submit_required,
			'poll_required' => $this->poll_required,
			'continuation_token' => $this->continuation_token,
			'message' => $this->message,
			'poll_interval_ms' => $this->poll_interval_ms,
			'poll_max_attempts' => $this->poll_max_attempts,
			'purpose' => $this->purpose,
			'stop_on_error' => $this->stop_on_error,
			'meta' => $this->meta,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['phase'] ?? self::PHASE_COMPLETED ),
			(bool) ( $data['accepted'] ?? true ),
			(bool) ( $data['submit_required'] ?? false ),
			(bool) ( $data['poll_required'] ?? false ),
			(string) ( $data['continuation_token'] ?? '' ),
			(string) ( $data['message'] ?? '' ),
			(int) ( $data['poll_interval_ms'] ?? 5000 ),
			(int) ( $data['poll_max_attempts'] ?? 14 ),
			(string) ( $data['purpose'] ?? 'registration' ),
			(bool) ( $data['stop_on_error'] ?? false ),
			is_array( $data['meta'] ?? null ) ? $data['meta'] : array()
		);
	}

	private function validate(): void {
		if ( ! in_array( $this->phase, self::phases(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid shipment lifecycle phase.' );
		}
		if ( $this->submit_required && '' === trim( $this->continuation_token ) ) {
			throw new \InvalidArgumentException( 'Shipment lifecycle submit phase requires continuation_token.' );
		}
		if ( $this->poll_interval_ms < 0 ) {
			throw new \InvalidArgumentException( 'Shipment lifecycle poll interval must be non-negative.' );
		}
		if ( $this->poll_max_attempts < 0 ) {
			throw new \InvalidArgumentException( 'Shipment lifecycle poll attempts must be non-negative.' );
		}
	}

	/**
	 * @return array<int,string>
	 */
	private static function phases(): array {
		return array(
			self::PHASE_COMPLETED,
			self::PHASE_SUBMISSION_REQUIRED,
			self::PHASE_POLLING_REQUIRED,
			self::PHASE_PENDING,
			self::PHASE_FAILED,
			self::PHASE_TERMINAL,
		);
	}
}
