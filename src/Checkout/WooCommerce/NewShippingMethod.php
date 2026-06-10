<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class NewShippingMethod extends \WC_Shipping_Method {
	public const METHOD_ID = 'wdc_platform_delivery';

	private static ?CheckoutOrchestrator $configured_orchestrator = null;
	private static ?WooCommercePackageMapper $configured_package_mapper = null;
	private static ?WooCommerceRateMapper $configured_rate_mapper = null;
	private static ?CheckoutSessionManager $configured_session_manager = null;
	private static ?RuleRepository $configured_rule_repository = null;
	private static ?DeliveryServiceManager $configured_service_manager = null;
	private static ?SettingsRepository $configured_settings_repository = null;
	private static ?PluginEnvironment $configured_environment = null;
	private static ?Logger $configured_logger = null;

	private CheckoutOrchestrator $orchestrator;
	private WooCommercePackageMapper $package_mapper;
	private WooCommerceRateMapper $rate_mapper;
	private CheckoutSessionManager $session_manager;
	private RuleRepository $rule_repository;
	private ?DeliveryServiceManager $service_manager;
	private SettingsRepository $settings_repository;
	private ?PluginEnvironment $environment;
	private ?Logger $logger;

	public static function configure(
		CheckoutOrchestrator $orchestrator,
		WooCommercePackageMapper $package_mapper,
		WooCommerceRateMapper $rate_mapper,
		CheckoutSessionManager $session_manager,
		RuleRepository $rule_repository,
		SettingsRepository $settings,
		PluginEnvironment $environment,
		Logger $logger,
		?DeliveryServiceManager $service_manager = null
	): void {
		self::$configured_orchestrator    = $orchestrator;
		self::$configured_package_mapper = $package_mapper;
		self::$configured_rate_mapper    = $rate_mapper;
		self::$configured_session_manager = $session_manager;
		self::$configured_rule_repository = $rule_repository;
		self::$configured_service_manager = $service_manager;
		self::$configured_settings_repository = $settings;
		self::$configured_environment    = $environment;
		self::$configured_logger         = $logger;
	}

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = $instance_id;
		$this->method_title       = __( 'Калькулятор доставки w.ALL.s', 'walls-delivery-calc' );
		$this->method_description = __( 'Новая система расчета доставки WDC.', 'walls-delivery-calc' );
		$this->enabled            = 'yes';
		$this->title              = __( 'Калькулятор доставки w.ALL.s', 'walls-delivery-calc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->orchestrator     = self::$configured_orchestrator ?? $this->fallback_orchestrator();
		$this->package_mapper   = self::$configured_package_mapper ?? new WooCommercePackageMapper();
		$this->rate_mapper      = self::$configured_rate_mapper ?? new WooCommerceRateMapper();
		$this->session_manager  = self::$configured_session_manager ?? new CheckoutSessionManager();
		$this->rule_repository  = self::$configured_rule_repository ?? new RuleRepository();
		$this->service_manager  = self::$configured_service_manager;
		$this->settings_repository = self::$configured_settings_repository ?? new SettingsRepository();
		$this->environment      = self::$configured_environment;
		$this->logger           = self::$configured_logger;
	}

	/**
	 * @param array<string,mixed> $package
	 */
	public function calculate_shipping( $package = array() ): void {
		try {
			$sort = $this->sort_mode();
			$this->session_manager->save_sort_mode( $sort );

			$request = $this->package_mapper->map(
				is_array( $package ) ? $package : array(),
				array(
					'pickup_selection' => $this->session_manager->pickup_selection(),
					'sort_mode'     => $sort,
				)
			);

			$result = $this->orchestrator->calculate( $request, $this->checkout_rules(), $sort, true, array( $this, 'checkout_rules_for_carrier' ) );
			$stored = array();

			foreach ( $this->rates_for_wc( $result->rates ) as $rate ) {
				$mapped = $this->rate_mapper->map( $rate, $result->fallback_used );
				$stored_rate = array_merge(
					$mapped['meta_data'],
					array(
						'rate_id'                  => $rate->rate_id,
						'label'                    => $mapped['label'],
						'cost'                     => $mapped['cost'],
						'planned_delivery_comment' => $rate->planned_delivery_comment,
						'delivery_days'            => $rate->delivery_days->to_array(),
						'fallback_used'            => $result->fallback_used,
						'service_title'            => $rate->service_name,
						'rules_source'             => (string) ( $rate->meta['rules_source'] ?? 'none' ),
						'round_up_applied'         => ! empty( $rate->meta['round_up_applied'] ),
						'minimum_price_applied'    => ! empty( $rate->meta['minimum_price_applied'] ),
					)
				);
				$stored[ $mapped['id'] ] = $stored_rate;
				$stored[ self::METHOD_ID . ':' . $mapped['id'] ] = $stored_rate;
				$this->add_rate( $mapped );
			}

			$this->session_manager->save_rates( $stored );
			$this->session_manager->save_debug(
				array(
					'rates_count'    => count( $result->rates ),
					'rates'          => array_map( static fn ( object $rate ): array => method_exists( $rate, 'to_array' ) ? $rate->to_array() : array(), $result->rates ),
					'cache_hits'     => $result->cache_hits,
					'fallback_used'  => $result->fallback_used,
					'carrier_errors' => $result->carrier_errors,
					'audit'          => $result->audit,
					'raw_checkout_city' => (string) ( ( is_array( $package ) && is_array( $package['destination'] ?? null ) ) ? ( $package['destination']['city'] ?? '' ) : '' ),
					'sort_mode'      => $sort,
				)
			);
		} catch ( \Throwable $exception ) {
			$this->log_exception( $exception );
			$this->add_rate(
				array(
					'id'        => 'fallback:checkout-exception',
					'label'     => __( 'Калькулятор доставок', 'walls-delivery-calc' ),
					'cost'      => '0',
					'meta_data' => array(
						'carrier_key'     => 'fallback',
						'delivery_type'   => 'unknown',
						'fallback_used'   => true,
						'comments'        => array( __( 'Использован резервный вариант после ошибки расчета.', 'walls-delivery-calc' ) ),
						'crossed_price'   => null,
					),
				)
			);
		}
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<int,DeliveryRate>
	 */
	private function rates_for_wc( array $rates ): array {
		$grouped = array();
		$output = array();
		foreach ( $rates as $rate ) {
			if ( $rate instanceof DeliveryRate && ! empty( $rate->meta['tariff_selector_group'] ) ) {
				$group_id = (string) ( $rate->meta['checkout_group_id'] ?? '' );
				if ( '' === $group_id ) {
					$group_id = RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key
						? RussianPostDomesticSettings::checkout_group_id( $rate->delivery_type )
						: $rate->service_key . ':' . $rate->delivery_type;
				}
				$grouped[ $group_id ][] = $rate;
				continue;
			}
			$output[] = $rate;
		}
		foreach ( $grouped as $group_id => $items ) {
			$output[] = $this->tariff_selector_rate( $group_id, $items );
		}

		return $output;
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 */
	private function tariff_selector_rate( string $group_id, array $rates ): DeliveryRate {
		$selected = $this->session_manager->selected_tariff( $group_id );
		$selected_object = (string) ( $selected['object_code'] ?? '' );
		$active = $rates[0];
		$selected_found = false;
		foreach ( $rates as $rate ) {
			if ( (string) $rate->tariff_key === $selected_object ) {
				$active = $rate;
				$selected_found = true;
				break;
			}
		}
		$variants = count( $rates ) > 1 ? array_map(
			fn ( DeliveryRate $rate ): array => array(
				'rate_id' => $rate->rate_id,
				'object_code' => $rate->tariff_key,
				'title' => $rate->tariff_name,
				'delivery_type' => $rate->delivery_type,
				'price_rub' => $rate->price->get_rubles(),
				'cost' => (string) $rate->price->get_rubles(),
				'crossed_price' => $rate->crossed_price?->to_array(),
				'delivery_days' => $rate->delivery_days->to_array(),
				'planned_delivery_comment' => $this->delivery_comment( $rate->delivery_days ),
				'comments' => $rate->comments,
				'rate_meta' => $rate->meta,
			),
			$rates
		) : array();
		if ( ! $selected_found ) {
			$this->session_manager->save_selected_tariff(
				$group_id,
				array(
					'object_code' => $active->tariff_key,
					'title' => $active->tariff_name,
					'delivery_days' => $active->delivery_days->to_array(),
					'final_price_rub' => $active->price->get_rubles(),
				)
			);
		}

		$method_title = $this->domestic_method_title( $active );

		return new DeliveryRate(
			$group_id,
			$active->carrier_key,
			$active->carrier_name,
			$active->service_key,
			$active->service_name,
			$active->tariff_key,
			$active->tariff_name,
			$active->delivery_type,
			$method_title,
			$active->price,
			$active->original_price,
			$active->crossed_price,
			$active->delivery_days,
			$active->planned_delivery_date,
			$this->delivery_comment( $active->delivery_days ),
			$active->comments,
			$active->disabled,
			$active->disabled_reason,
			$active->requires_pickup_point,
			$active->requires_courier_address,
			array_merge(
				$active->meta,
				array(
					'tariff_variants' => $variants,
					'domestic_tariff_grouped' => RussianPostDomesticSettings::CARRIER_KEY === $active->carrier_key,
					'checkout_group_id' => $group_id,
					'pickup_method_title' => (string) ( $active->meta['pickup_method_title'] ?? RussianPostDomesticSettings::PICKUP_SERVICE_TITLE ),
					'courier_method_title' => (string) ( $active->meta['courier_method_title'] ?? RussianPostDomesticSettings::COURIER_SERVICE_TITLE ),
					'selected_tariff_object' => $active->tariff_key,
					'selected_tariff_title' => $active->tariff_name,
					'selected_tariff_rate_id' => $active->rate_id,
					'final_price_rub' => $active->price->get_rubles(),
				)
			)
		);
	}

	private function domestic_method_title( DeliveryRate $rate ): string {
		$prefix = $this->domestic_method_prefix( $rate );
		if ( RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key || '' !== $prefix ) {
			return $this->method_title_from_parts( $prefix, $rate->tariff_name, $this->delivery_comment( $rate->delivery_days ) );
		}

		$tariff = trim( $rate->tariff_name );
		if ( '' === $tariff ) {
			$title = $rate->service_name;
		} else {
			$title = $rate->service_name . ': ' . $tariff;
		}
		$days = $this->delivery_comment( $rate->delivery_days );

		return '' !== $days ? $title . ' - ' . $days : $title;
	}

	private function method_title_from_parts( string $service_title, string $tariff_title, string $delivery_days ): string {
		$title = trim( $service_title );
		$tariff_title = trim( $tariff_title );
		if ( '' !== $tariff_title && ! str_contains( $title, $tariff_title ) ) {
			$title = '' !== $title ? $title . ', ' . $tariff_title : $tariff_title;
		}

		$delivery_days = trim( $delivery_days );
		if ( '' !== $delivery_days && ! str_contains( $title, $delivery_days ) ) {
			$title = '' !== $title ? $title . ' - ' . $delivery_days : $delivery_days;
		}

		return $title;
	}

	private function domestic_method_prefix( DeliveryRate $rate ): string {
		$key = DeliveryType::COURIER === $rate->delivery_type ? 'courier_method_title' : 'pickup_method_title';
		$default = DeliveryType::COURIER === $rate->delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE;
		$title = trim( (string) ( $rate->meta[ $key ] ?? '' ) );

		if ( RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key ) {
			return '' !== $title ? $title : $default;
		}

		return $title;
	}

	private function delivery_comment( DateRange $range ): string {
		return DeliveryDaysFormatter::format( $range );
	}

	private function sort_mode(): string {
		$session_mode = $this->session_manager->selected_sort_mode();
		$mode         = '' !== $session_mode ? $session_mode : $this->settings_repository->get_string( 'checkout_sort_mode', RateSorter::CHEAPEST );

		return RateSorter::FASTEST === $mode ? RateSorter::FASTEST : RateSorter::CHEAPEST;
	}

	/**
	 * @return array<int,Rule>
	 */
	private function checkout_rules(): array {
		try {
			return $this->rule_repository->get_default_rules();
		} catch ( \Throwable $exception ) {
			$this->log_exception( $exception );
		}

		return array();
	}

	/**
	 * @return array<int,Rule>
	 */
	public function checkout_rules_for_carrier( string $carrier_key ): array {
		try {
			if ( method_exists( $this->rule_repository, 'get_rules_for_carrier_with_default_fallback' ) ) {
				return $this->rule_repository->get_rules_for_carrier_with_default_fallback( $carrier_key );
			}
		} catch ( \Throwable $exception ) {
			$this->log_exception( $exception );
		}

		return $this->checkout_rules();
	}

	private function log_exception( \Throwable $exception ): void {
		if ( $this->logger instanceof Logger ) {
			$this->logger->error( 'WDC checkout shipping calculation failed.', array( 'error' => $exception->getMessage() ) );
		}
	}

	private function fallback_orchestrator(): CheckoutOrchestrator {
		throw new \RuntimeException( 'WDC platform shipping method is not configured.' );
	}
}
