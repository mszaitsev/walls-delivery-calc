<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\ValueObjects;

defined( 'ABSPATH' ) || exit;

final class Region {
	public function __construct(
		public readonly string $region_code,
		public readonly string $region_name,
		public readonly string $region_type = '',
		public readonly string $region_fias_id = '',
		public readonly string $region_kladr_id = ''
	) {
	}

	/**
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'region_code'     => $this->region_code,
			'region_name'     => $this->region_name,
			'region_type'     => $this->region_type,
			'region_fias_id'  => $this->region_fias_id,
			'region_kladr_id' => $this->region_kladr_id,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['region_code'] ?? '' ),
			(string) ( $data['region_name'] ?? '' ),
			(string) ( $data['region_type'] ?? '' ),
			(string) ( $data['region_fias_id'] ?? '' ),
			(string) ( $data['region_kladr_id'] ?? '' )
		);
	}
}
