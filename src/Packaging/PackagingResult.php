<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

defined( 'ABSPATH' ) || exit;

final class PackagingResult {
	/**
	 * @param array<int,PackagingParcel> $parcels
	 * @param array<string,mixed>        $diagnostics
	 */
	public function __construct(
		public readonly array $parcels,
		public readonly array $diagnostics
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array_merge( $this->diagnostics, array( 'parcels' => $this->parcels ) );
	}
}