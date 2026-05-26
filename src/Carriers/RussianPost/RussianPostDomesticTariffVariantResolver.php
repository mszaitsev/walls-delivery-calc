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
					&& ( $variant->always_available || $insurance_enabled === $variant->requires_declared_value )
			)
		);
		usort( $variants, static fn ( DomesticTariffVariant $a, DomesticTariffVariant $b ): int => $a->sort_order <=> $b->sort_order ?: $a->object_code <=> $b->object_code );

		return $variants;
	}

	/**
	 * @return array<int,DomesticTariffVariant>
	 */
	public function defaults(): array {
		$pickup = array(
			array( 27030, 'Посылка стандарт', false ),
			array( 27020, 'Посылка стандарт с объявленной ценностью', true ),
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
			array( 28030, 'Посылка курьер EMS', false ),
			array( 28020, 'Посылка курьер EMS с объявленной ценностью', true ),
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
