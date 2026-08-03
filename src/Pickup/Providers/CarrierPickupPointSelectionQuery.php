<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

defined( 'ABSPATH' ) || exit;

final class CarrierPickupPointSelectionQuery {
	public function __construct(
		public readonly CarrierPickupPointQuery $query,
		public readonly string $point_code
	) {
	}

	/** @return array<int,string> */
	public function validate(): array {
		$errors = $this->query->validate();
		if ( '' === trim( $this->point_code ) ) {
			$errors[] = 'point_code is required';
		}

		return $errors;
	}
}
