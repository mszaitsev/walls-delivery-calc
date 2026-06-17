<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTerminalCodeTariffDiagnosticResult {
	/**
	 * @param array<int,string> $errors
	 * @param array<int,string> $warnings
	 * @param array<int,array<string,mixed>> $parcels3_options
	 * @param array<int,array<string,mixed>> $parcels2_options
	 * @param array<int,array<string,mixed>> $comparison
	 * @param array<string,mixed> $parcels3_payload
	 * @param array<string,mixed> $parcels2_payload
	 * @param array<string,mixed> $terminal_selection
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly bool $success,
		public readonly array $errors = array(),
		public readonly array $warnings = array(),
		public readonly array $parcels3_options = array(),
		public readonly array $parcels2_options = array(),
		public readonly array $comparison = array(),
		public readonly array $parcels3_payload = array(),
		public readonly array $parcels2_payload = array(),
		public readonly mixed $parcels3_raw_response = null,
		public readonly mixed $parcels2_raw_response = null,
		public readonly array $terminal_selection = array(),
		public readonly array $meta = array()
	) {
	}
}
