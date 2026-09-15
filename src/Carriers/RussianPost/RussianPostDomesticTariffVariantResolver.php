<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class RussianPostDomesticTariffVariantResolver {
	/**
	 * @param array<string,mixed> $settings
	 * @return array<int,DomesticTariffVariant>
	 */
	public function variants( array $settings, string $delivery_type, int $weight_g ): array {
		$configured = is_array( $settings['tariff_variants'] ?? null ) ? $settings['tariff_variants'] : array();
		$has_configured = array() !== $configured;
		$variants = array() !== $configured
			? array_map( static fn ( array $row ): DomesticTariffVariant => DomesticTariffVariant::from_array( $row ), array_filter( $configured, 'is_array' ) )
			: $this->defaults();
		$insurance_enabled = ! empty( $settings['insurance_enabled'] );
		$variants = array_values(
			array_filter(
				$variants,
				static fn ( DomesticTariffVariant $variant ): bool =>
					$variant->enabled
					&& $variant->delivery_type === $delivery_type
					&& $variant->supports_weight( $weight_g )
					&& ( $has_configured || $variant->always_available || $insurance_enabled === $variant->requires_declared_value )
			)
		);
		usort( $variants, static fn ( DomesticTariffVariant $a, DomesticTariffVariant $b ): int => $a->sort_order <=> $b->sort_order ?: $a->object_code <=> $b->object_code );

		return $variants;
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<int,array<string,mixed>>
	 */
	public function diagnostics( array $settings, string $delivery_type, int $weight_g ): array {
		$configured = is_array( $settings['tariff_variants'] ?? null ) ? $settings['tariff_variants'] : array();
		$has_configured = array() !== $configured;
		$variants = $has_configured
			? array_map( static fn ( array $row ): DomesticTariffVariant => DomesticTariffVariant::from_array( $row ), array_filter( $configured, 'is_array' ) )
			: $this->defaults();
		$insurance_enabled = ! empty( $settings['insurance_enabled'] );
		$result = array();
		foreach ( $variants as $variant ) {
			$reason = 'included';
			if ( ! $variant->enabled ) {
				$reason = 'filtered_by_settings';
			} elseif ( $variant->delivery_type !== $delivery_type ) {
				$reason = 'filtered_by_delivery_type';
			} elseif ( ! $variant->supports_weight( $weight_g ) ) {
				$reason = 'filtered_by_weight';
			} elseif ( ! $has_configured && ! ( $variant->always_available || $insurance_enabled === $variant->requires_declared_value ) ) {
				$reason = 'filtered_by_insurance';
			}

			$result[] = array(
				'object_code' => $variant->object_code,
				'title' => $variant->title,
				'reason' => $reason,
				'enabled' => $variant->enabled,
				'delivery_type' => $variant->delivery_type,
				'requires_declared_value' => $variant->requires_declared_value,
				'always_available' => $variant->always_available,
				'min_weight_g' => $variant->min_weight_g,
				'max_weight_g' => $variant->max_weight_g,
				'sort_order' => $variant->sort_order,
			);
		}

		return $result;
	}

	/**
	 * @return array<int,DomesticTariffVariant>
	 */
	public function defaults(): array {
		$pickup = array(
			array( 4030, 'Посылка нестандартная', false ),
			array( 4020, 'Посылка нестандартная с объявленной ценностью', true ),
			array( 47030, 'Посылка 1 класса', false ),
			array( 47020, 'Посылка 1 класса с объявленной ценностью', true ),
			array( 54020, 'ЕКОМ Маркетплейс с объявленной ценностью', true, true ),
			array( 23030, 'Посылка онлайн', false ),
			array( 23020, 'Посылка онлайн с объявленной ценностью', true ),
		);
		$courier = array(
			array( 24030, 'Курьер онлайн', false ),
			array( 24020, 'Курьер онлайн с объявленной ценностью', true ),
			array( 7030, 'EMS', false ),
			array( 7020, 'EMS с объявленной ценностью', true ),
			array( 41030, 'EMS РТ', false ),
			array( 52030, 'EMS Тендер обыкновенное', false ),
		);
		$result = array();
		$sort = 10;
		foreach ( $pickup as $item ) {
			$result[] = new DomesticTariffVariant( $item[0], $item[1], true, DeliveryType::PICKUP, $item[2], (bool) ( $item[3] ?? false ), null, null, $sort );
			$sort += 10;
		}
		foreach ( $courier as $item ) {
			$result[] = new DomesticTariffVariant( $item[0], $item[1], true, DeliveryType::COURIER, $item[2], (bool) ( $item[3] ?? false ), null, null, $sort );
			$sort += 10;
		}

		return $result;
	}
}
