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
		private readonly array $parcels,
		public readonly array $diagnostics
	) {
	}

	/**
	 * @return array<int,PackagingParcel>
	 */
	public function parcels(): array {
		return $this->parcels;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array_merge(
			$this->diagnostics,
			array(
				'parcels' => array_map(
					static fn( PackagingParcel $parcel ): array => $parcel->to_array(),
					$this->parcels
				),
			)
		);
	}
}
