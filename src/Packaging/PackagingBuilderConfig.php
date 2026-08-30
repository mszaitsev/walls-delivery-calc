<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

defined( 'ABSPATH' ) || exit;

final class PackagingBuilderConfig {
	public function __construct(
		public readonly int $default_weight_g,
		public readonly float $default_length_cm,
		public readonly float $default_width_cm,
		public readonly float $default_height_cm,
		public readonly float $default_declared_value_rub,
		public readonly ?float $max_parcel_length_cm = null,
		public readonly ?float $max_parcel_width_cm = null,
		public readonly ?float $max_parcel_height_cm = null
	) {
	}

	public static function defaults(): self {
		return new self( 500, 20.0, 15.0, 10.0, 1.0 );
	}

	public function has_parcel_limits(): bool {
		return null !== $this->max_parcel_length_cm && $this->max_parcel_length_cm > 0
			&& null !== $this->max_parcel_width_cm && $this->max_parcel_width_cm > 0
			&& null !== $this->max_parcel_height_cm && $this->max_parcel_height_cm > 0;
	}

	public function parcel_dimensions_allowed( float $length_cm, float $width_cm, float $height_cm ): bool {
		if ( ! $this->has_parcel_limits() ) {
			return true;
		}
		$parcel = array( max( 0.0, $length_cm ), max( 0.0, $width_cm ), max( 0.0, $height_cm ) );
		$limit = array( (float) $this->max_parcel_length_cm, (float) $this->max_parcel_width_cm, (float) $this->max_parcel_height_cm );
		rsort( $parcel, SORT_NUMERIC );
		rsort( $limit, SORT_NUMERIC );

		return $parcel[0] <= $limit[0] && $parcel[1] <= $limit[1] && $parcel[2] <= $limit[2];
	}
}
