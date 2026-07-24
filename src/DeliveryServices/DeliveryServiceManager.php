<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceManager {
	public function __construct(
		private DeliveryServiceRepository $services,
		private DeliveryServiceCountryRepository $countries,
		private RuleRepository $rules,
		private RussianPostCountryDirectory $russian_post_countries
	) {
	}

	public function ensure_builtin_services(): void {
		$this->services->ensure_russian_post_service();
		$service = $this->services->ensure_russian_post_domestic_service();
		if ( null !== $service->id ) {
			$this->countries->replace_countries( (int) $service->id, array( 'RU' ) );
		}
		$cdek_existed = $this->services->cdek_service_exists();
		$cdek = $this->services->ensure_cdek_service();
		if ( ! $cdek_existed && null !== $cdek->id ) {
			$this->countries->replace_countries( (int) $cdek->id, CdekSettings::SUPPORTED_COUNTRIES );
		}
		$dpd = $this->services->ensure_dpd_service();
		if ( null !== $dpd->id ) {
			$this->countries->replace_countries( (int) $dpd->id, array( 'RU' ) );
		}
		$yandex_delivery = $this->services->ensure_yandex_delivery_service();
		if ( null !== $yandex_delivery->id ) {
			$this->countries->replace_countries( (int) $yandex_delivery->id, array( 'RU' ) );
		}
	}

	public function service_available_for_country( DeliveryService $service, string $country_code ): bool {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code ) {
			return false;
		}

		return match ( $service->availability_mode ) {
			DeliveryService::AVAILABILITY_CARRIER_DIRECTORY => $this->carrier_directory_available( $service, $country_code ),
			DeliveryService::AVAILABILITY_ALL_COUNTRIES => true,
			DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED => ! in_array( $country_code, $this->countries->countries( (int) $service->id ), true ),
			default => in_array( $country_code, $this->countries->countries( (int) $service->id ), true ),
		};
	}

	/**
	 * @return array{rules:array<int,\WallsShop\WDC\Rules\Domain\Rule>,source:string}
	 */
	public function rules_for_service( DeliveryService $service ): array {
		return $this->rules->get_rules_for_service_with_default_fallback( $service->service_key, $service->use_default_rules_when_no_service_rules );
	}

	public function post_process_rate( DeliveryRate $rate, DeliveryService $service ): DeliveryRate {
		if ( $rate->disabled || $rate->price->is_zero() || ! empty( $rate->meta['fallback'] ) || ! empty( $rate->meta['skip_service_post_processing'] ) ) {
			return $this->rate_with_meta( $rate, array( 'round_up_applied' => false, 'minimum_price_applied' => false ) );
		}

		$price = $rate->price;
		$minimum_applied = false;
		$round_applied = false;
		$minimum = Money::from_rubles( max( 0, $service->minimum_price_rub ), $price->get_currency() );
		if ( $minimum->get_kopecks() > 0 && $price->get_kopecks() < $minimum->get_kopecks() ) {
			$price = $minimum;
			$minimum_applied = true;
		}

		if ( $service->round_up_to_ruble && 0 !== $price->get_kopecks() % 100 ) {
			$price = Money::from_kopecks( (int) ( ceil( $price->get_kopecks() / 100 ) * 100 ), $price->get_currency() );
			$round_applied = true;
		}

		if ( $price->get_kopecks() === $rate->price->get_kopecks() ) {
			return $this->rate_with_meta( $rate, array( 'round_up_applied' => $round_applied, 'minimum_price_applied' => $minimum_applied ) );
		}

		return new DeliveryRate(
			$rate->rate_id,
			$rate->carrier_key,
			$rate->carrier_name,
			$rate->service_key,
			$rate->service_name,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$rate->title,
			$price,
			$rate->original_price ?? $rate->price,
			$rate->crossed_price,
			$rate->delivery_days,
			$rate->planned_delivery_date,
			$rate->planned_delivery_comment,
			$rate->comments,
			$rate->disabled,
			$rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			array_merge( $rate->meta, array( 'round_up_applied' => $round_applied, 'minimum_price_applied' => $minimum_applied ) ),
			$rate->original_cost ?? $rate->price,
			$rate->original_delivery_days ?? $rate->delivery_days
		);
	}

	public function find_by_service_key( string $service_key ): ?DeliveryService {
		return $this->services->find_by_service_key( $service_key );
	}

	private function carrier_directory_available( DeliveryService $service, string $country_code ): bool {
		if ( 'russian_post' === $service->carrier_key ) {
			return 'RU' !== $country_code;
		}
		if ( 'russian_post_domestic' === $service->carrier_key ) {
			return 'RU' === $country_code;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function rate_with_meta( DeliveryRate $rate, array $meta ): DeliveryRate {
		return new DeliveryRate(
			$rate->rate_id,
			$rate->carrier_key,
			$rate->carrier_name,
			$rate->service_key,
			$rate->service_name,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$rate->title,
			$rate->price,
			$rate->original_price,
			$rate->crossed_price,
			$rate->delivery_days,
			$rate->planned_delivery_date,
			$rate->planned_delivery_comment,
			$rate->comments,
			$rate->disabled,
			$rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			array_merge( $rate->meta, $meta ),
			$rate->original_cost ?? $rate->price,
			$rate->original_delivery_days ?? $rate->delivery_days
		);
	}
}
