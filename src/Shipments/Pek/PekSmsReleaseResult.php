<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

defined( 'ABSPATH' ) || exit;

final class PekSmsReleaseResult {
	public function __construct(
		public readonly bool $success,
		public readonly int $effective_limit_kopecks = 0,
		public readonly bool $geography_confirmed = false,
		public readonly bool $counterpart_confirmed = false,
		public readonly string $message = ''
	) {
	}

	/** @return array<string,mixed> */
	public function to_safe_array(): array {
		return array(
			'success' => $this->success,
			'effective_limit_kopecks' => $this->effective_limit_kopecks,
			'geography_confirmed' => $this->geography_confirmed,
			'counterpart_confirmed' => $this->counterpart_confirmed,
			'message' => $this->success ? '' : $this->message,
		);
	}
}
