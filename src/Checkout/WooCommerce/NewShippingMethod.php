<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Core\PluginEnvironment;
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
	private static ?SettingsRepository $configured_settings = null;
	private static ?PluginEnvironment $configured_environment = null;
	private static ?Logger $configured_logger = null;

	private CheckoutOrchestrator $orchestrator;
	private WooCommercePackageMapper $package_mapper;
	private WooCommerceRateMapper $rate_mapper;
	private CheckoutSessionManager $session_manager;
	private RuleRepository $rule_repository;
	private SettingsRepository $settings;
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
		Logger $logger
	): void {
		self::$configured_orchestrator    = $orchestrator;
		self::$configured_package_mapper = $package_mapper;
		self::$configured_rate_mapper    = $rate_mapper;
		self::$configured_session_manager = $session_manager;
		self::$configured_rule_repository = $rule_repository;
		self::$configured_settings       = $settings;
		self::$configured_environment    = $environment;
		self::$configured_logger         = $logger;
	}

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = $instance_id;
		$this->method_title       = 'WDC Platform Delivery';
		$this->method_description = 'WDC platform delivery powered by the new checkout orchestration pipeline.';
		$this->enabled            = 'yes';
		$this->title              = 'WDC Platform Delivery';
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->orchestrator     = self::$configured_orchestrator ?? $this->fallback_orchestrator();
		$this->package_mapper   = self::$configured_package_mapper ?? new WooCommercePackageMapper();
		$this->rate_mapper      = self::$configured_rate_mapper ?? new WooCommerceRateMapper();
		$this->session_manager  = self::$configured_session_manager ?? new CheckoutSessionManager();
		$this->rule_repository  = self::$configured_rule_repository ?? new RuleRepository();
		$this->settings         = self::$configured_settings ?? new SettingsRepository();
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
					'delivery_type' => $this->session_manager->selected_delivery_type(),
					'sort_mode'     => $sort,
				)
			);

			$result = $this->orchestrator->calculate( $request, $this->checkout_rules(), $sort, true );
			$stored = array();

			foreach ( $result->rates as $rate ) {
				$mapped = $this->rate_mapper->map( $rate, $result->fallback_used );
				$stored[ $mapped['id'] ] = array_merge(
					$mapped['meta_data'],
					array(
						'rate_id'                  => $rate->rate_id,
						'planned_delivery_comment' => $rate->planned_delivery_comment,
						'fallback_used'            => $result->fallback_used,
					)
				);
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
				)
			);
		} catch ( \Throwable $exception ) {
			$this->log_exception( $exception );
			$this->add_rate(
				array(
					'id'        => 'fallback:checkout-exception',
					'label'     => 'WDC Platform Delivery',
					'cost'      => '0',
					'meta_data' => array(
						'carrier_key'     => 'fallback',
						'delivery_type'   => 'unknown',
						'fallback_used'   => true,
						'comments'        => array( 'Checkout fallback used after calculation error.' ),
						'crossed_price'   => null,
					),
				)
			);
		}
	}

	private function sort_mode(): string {
		$mode = $this->settings->get_string( 'checkout_sort_mode', RateSorter::CHEAPEST );

		return RateSorter::FASTEST === $mode ? RateSorter::FASTEST : RateSorter::CHEAPEST;
	}

	/**
	 * @return array<int,Rule>
	 */
	private function checkout_rules(): array {
		try {
			$rules = $this->rule_repository->get_enabled_rules();
			if ( array() !== $rules ) {
				return $rules;
			}
		} catch ( \Throwable $exception ) {
			$this->log_exception( $exception );
		}

		return $this->demo_rules();
	}

	/**
	 * @return array<int,Rule>
	 */
	private function demo_rules(): array {
		$path = $this->environment instanceof PluginEnvironment ? $this->environment->plugin_dir() . 'database/demo/rules-demo.json' : dirname( __DIR__, 3 ) . '/database/demo/rules-demo.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		return array_map( static fn ( array $rule ): Rule => Rule::from_array( $rule ), array_filter( $data, 'is_array' ) );
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
