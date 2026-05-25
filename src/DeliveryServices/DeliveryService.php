<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

defined( 'ABSPATH' ) || exit;

final class DeliveryService {
	public const TYPE_API = 'api';
	public const TYPE_FIXED = 'fixed';
	public const TYPE_WEIGHT_BASED = 'weight_based';

	public const AVAILABILITY_CARRIER_DIRECTORY = 'carrier_directory';
	public const AVAILABILITY_SELECTED_COUNTRIES = 'selected_countries';
	public const AVAILABILITY_ALL_COUNTRIES = 'all_countries';
	public const AVAILABILITY_ALL_EXCEPT_SELECTED = 'all_except_selected';
	public const PACKAGING_WEIGHT_TOTAL_WEIGHT = 'total_weight';
	public const PACKAGING_WEIGHT_PACKAGE_ITEM = 'package_item';

	public function __construct(
		public readonly ?int $id,
		public readonly string $service_key,
		public readonly string $carrier_key,
		public readonly string $service_type,
		public readonly string $title,
		public readonly bool $enabled = true,
		public readonly string $availability_mode = self::AVAILABILITY_SELECTED_COUNTRIES,
		public readonly bool $use_default_rules_when_no_service_rules = true,
		public readonly bool $round_up_to_ruble = true,
		public readonly float $minimum_price_rub = 1.0,
		public readonly bool $include_packaging_weight = true,
		public readonly string $packaging_weight_mode = self::PACKAGING_WEIGHT_TOTAL_WEIGHT,
		public readonly int $sort_order = 100,
		public readonly bool $deleted = false,
		public readonly string $created_at = '',
		public readonly string $updated_at = ''
	) {
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function from_array( array $row ): self {
		return new self(
			array_key_exists( 'id', $row ) && null !== $row['id'] ? (int) $row['id'] : null,
			(string) ( $row['service_key'] ?? '' ),
			(string) ( $row['carrier_key'] ?? '' ),
			(string) ( $row['service_type'] ?? self::TYPE_API ),
			(string) ( $row['title'] ?? '' ),
			(bool) (int) ( $row['enabled'] ?? 1 ),
			(string) ( $row['availability_mode'] ?? self::AVAILABILITY_SELECTED_COUNTRIES ),
			(bool) (int) ( $row['use_default_rules_when_no_service_rules'] ?? 1 ),
			(bool) (int) ( $row['round_up_to_ruble'] ?? 1 ),
			(float) ( $row['minimum_price_rub'] ?? 1.0 ),
			(bool) (int) ( $row['include_packaging_weight'] ?? 1 ),
			self::normalize_packaging_weight_mode( (string) ( $row['packaging_weight_mode'] ?? self::PACKAGING_WEIGHT_TOTAL_WEIGHT ) ),
			(int) ( $row['sort_order'] ?? 100 ),
			(bool) (int) ( $row['deleted'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id' => $this->id,
			'service_key' => $this->service_key,
			'carrier_key' => $this->carrier_key,
			'service_type' => $this->service_type,
			'title' => $this->title,
			'enabled' => $this->enabled,
			'availability_mode' => $this->availability_mode,
			'use_default_rules_when_no_service_rules' => $this->use_default_rules_when_no_service_rules,
			'round_up_to_ruble' => $this->round_up_to_ruble,
			'minimum_price_rub' => $this->minimum_price_rub,
			'include_packaging_weight' => $this->include_packaging_weight,
			'packaging_weight_mode' => $this->packaging_weight_mode,
			'sort_order' => $this->sort_order,
			'deleted' => $this->deleted,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
		);
	}

	public static function normalize_packaging_weight_mode( string $mode ): string {
		return in_array( $mode, array( self::PACKAGING_WEIGHT_TOTAL_WEIGHT, self::PACKAGING_WEIGHT_PACKAGE_ITEM ), true ) ? $mode : self::PACKAGING_WEIGHT_TOTAL_WEIGHT;
	}
}
