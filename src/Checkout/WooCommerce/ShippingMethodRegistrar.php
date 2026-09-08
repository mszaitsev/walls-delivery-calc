<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionAjax;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class ShippingMethodRegistrar {
	public function __construct(
		private SettingsRepository $settings,
		private CheckoutOrchestrator $orchestrator,
		private WooCommercePackageMapper $package_mapper,
		private WooCommerceRateMapper $rate_mapper,
		private CheckoutSessionManager $session_manager,
		private RuleRepository $rule_repository,
		private PluginEnvironment $environment,
		private Logger $logger,
		private ?AddressSuggestionSettings $suggestion_settings = null,
		private ?DaDataTokenPool $token_pool = null,
		private ?DeliveryServiceManager $service_manager = null,
		private ?LocationCountryIndexService $location_country_index = null
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'woocommerce_shipping_chosen_method', array( $this, 'preserve_chosen_wdc_method' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wdc_select_domestic_tariff', array( $this, 'select_domestic_tariff' ) );
		add_action( 'wp_ajax_nopriv_wdc_select_domestic_tariff', array( $this, 'select_domestic_tariff' ) );
	}

	/**
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public function register_shipping_method( array $methods ): array {
		if ( ! class_exists( '\WC_Shipping_Method' ) ) {
			return $methods;
		}

		NewShippingMethod::configure(
			$this->orchestrator,
			$this->package_mapper,
			$this->rate_mapper,
			$this->session_manager,
			$this->rule_repository,
			$this->settings,
			$this->environment,
			$this->logger,
			$this->service_manager
		);

		$methods[ NewShippingMethod::METHOD_ID ] = NewShippingMethod::class;

		return $methods;
	}

	/**
	 * @param array<string,mixed> $rates
	 */
	public function preserve_chosen_wdc_method( string $default, array $rates, string $chosen_method ): string {
		$chosen_method = trim( $chosen_method );
		if ( '' === $chosen_method ) {
			return $default;
		}

		$fresh_method_id = $this->fresh_wdc_rate_id( $chosen_method, $rates );

		return '' !== $fresh_method_id ? $fresh_method_id : $default;
	}

	/**
	 * @param array<string,mixed> $rates
	 */
	private function fresh_wdc_rate_id( string $chosen_method, array $rates ): string {
		$chosen_rate_id = $this->session_manager->normalize_rate_id( $chosen_method );
		if ( '' === $chosen_rate_id ) {
			return '';
		}
		$legacy_wdc_choice = $this->is_legacy_wdc_shipping_method_choice( $chosen_method );

		foreach ( $rates as $rate_id => $rate ) {
			$candidate_id = is_string( $rate_id ) ? $rate_id : '';
			if ( '' === $candidate_id && is_object( $rate ) && method_exists( $rate, 'get_id' ) ) {
				$candidate_id = (string) $rate->get_id();
			}
			$candidate_id = trim( $candidate_id );
			if ( '' === $candidate_id || ! $this->is_fresh_wdc_rate( $candidate_id, $rate ) ) {
				continue;
			}
			if ( $candidate_id === $chosen_method ) {
				return $candidate_id;
			}
			if ( $legacy_wdc_choice && $chosen_rate_id === $this->session_manager->normalize_rate_id( $candidate_id ) ) {
				return $candidate_id;
			}
		}

		return '';
	}

	private function is_legacy_wdc_shipping_method_choice( string $method_id ): bool {
		return str_starts_with( $method_id, NewShippingMethod::METHOD_ID . ':' )
			|| str_starts_with( $method_id, 'wdc_platform:' );
	}

	private function is_fresh_wdc_rate( string $candidate_id, mixed $rate ): bool {
		$meta = WooCommerceRateMetaNormalizer::meta( $rate );
		if ( array() === $meta ) {
			return false;
		}

		$meta_rate_id = $this->session_manager->normalize_rate_id( (string) ( $meta['rate_id'] ?? '' ) );
		if ( '' === $meta_rate_id || $meta_rate_id !== $this->session_manager->normalize_rate_id( $candidate_id ) ) {
			return false;
		}

		if ( true === ( $meta['wdc_rate'] ?? false ) && 'platform' === (string) ( $meta['wdc_source'] ?? '' ) ) {
			return true;
		}

		foreach ( array( 'carrier_key', 'service_key', 'delivery_type' ) as $key ) {
			if ( '' === trim( (string) ( $meta[ $key ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style(
			'wdc-platform-checkout-rates',
			$this->environment->plugin_url() . 'assets/frontend/checkout-rates.css',
			array(),
			$this->environment->version()
		);
		wp_enqueue_style(
			'wdc-platform-pickup-foundation',
			$this->environment->plugin_url() . 'assets/frontend/pickup-foundation.css',
			array( 'wdc-platform-checkout-rates' ),
			$this->environment->version()
		);
		if ( $this->suggestions_requested() ) {
			wp_enqueue_style(
				'wdc-platform-address-suggestions',
				$this->environment->plugin_url() . 'assets/frontend/checkout-address-suggestions.css',
				array( 'wdc-platform-checkout-rates' ),
				$this->environment->version()
			);
		}
		wp_enqueue_style(
			'wdc-platform-city-selector',
			$this->environment->plugin_url() . 'assets/frontend/checkout-city-selector.css',
			array( 'wdc-platform-checkout-rates' ),
			$this->environment->version()
		);
		if ( function_exists( 'wp_enqueue_script' ) ) {
			$city_selector_dependencies = array( 'jquery' );
			if ( function_exists( 'wp_script_is' ) && wp_script_is( 'wc-checkout', 'registered' ) ) {
				$city_selector_dependencies[] = 'wc-checkout';
			}

			wp_enqueue_script(
				'wdc-platform-city-selector',
				$this->environment->plugin_url() . 'assets/frontend/checkout-city-selector.js',
				$city_selector_dependencies,
				$this->environment->version(),
				true
			);
			if ( function_exists( 'wp_localize_script' ) ) {
				wp_localize_script(
					'wdc-platform-city-selector',
					'wdcPlatformCitySelector',
					$this->city_selector_config()
				);
			}
			wp_enqueue_script(
				'wdc-platform-checkout-sort',
				$this->environment->plugin_url() . 'assets/frontend/checkout-sort.js',
				array( 'jquery' ),
				$this->environment->version(),
				true
			);
			wp_enqueue_script(
				'wdc-platform-checkout-address-fields',
				$this->environment->plugin_url() . 'assets/frontend/checkout-address-fields.js',
				$city_selector_dependencies,
				$this->environment->version(),
				true
			);
			wp_enqueue_script(
				'wdc-platform-courier-address-summary',
				$this->environment->plugin_url() . 'assets/frontend/courier-address-summary.js',
				array( 'jquery' ),
				$this->environment->version(),
				true
			);
			wp_enqueue_style(
				'wdc-platform-domestic-tariffs',
				$this->environment->plugin_url() . 'assets/frontend/domestic-tariff-selector.css',
				array( 'wdc-platform-checkout-rates' ),
				$this->environment->version()
			);
			wp_enqueue_script(
				'wdc-platform-domestic-tariffs',
				$this->environment->plugin_url() . 'assets/frontend/domestic-tariff-selector.js',
				array( 'jquery' ),
				$this->environment->version(),
				true
			);
			if ( function_exists( 'wp_localize_script' ) ) {
				wp_localize_script(
					'wdc-platform-domestic-tariffs',
					'wdcPlatformDomesticTariffs',
					array(
						'ajax_url' => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
						'nonce' => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wdc_select_domestic_tariff' ) : '',
						'action' => 'wdc_select_domestic_tariff',
					)
				);
			}
			if ( $this->suggestions_requested() ) {
				wp_enqueue_script(
					'wdc-platform-address-suggestions',
					$this->environment->plugin_url() . 'assets/frontend/checkout-address-suggestions.js',
					array( 'jquery' ),
					$this->environment->version(),
					true
				);
				if ( function_exists( 'wp_localize_script' ) ) {
					wp_localize_script(
						'wdc-platform-address-suggestions',
						'wdcPlatformAddressSuggestions',
						$this->address_suggestions_config()
					);
				}
			}
		}
	}

	public function select_domestic_tariff(): void {
		if ( function_exists( 'check_ajax_referer' ) ) {
			check_ajax_referer( 'wdc_select_domestic_tariff', 'nonce' );
		}
		$service_key = isset( $_POST['service_key'] ) ? sanitize_key( wp_unslash( $_POST['service_key'] ) ) : '';
		$checkout_group_id = isset( $_POST['checkout_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['checkout_group_id'] ) ) : '';
		$delivery_type = isset( $_POST['delivery_type'] ) ? RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $_POST['delivery_type'] ) ) ) : DeliveryType::PICKUP;
		$object_code = isset( $_POST['object_code'] ) ? sanitize_text_field( wp_unslash( $_POST['object_code'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( RussianPostDomesticSettings::SERVICE_KEY === $service_key ) {
			$checkout_group_id = RussianPostDomesticSettings::checkout_group_id( $delivery_type );
		}
		if ( '' !== $service_key && '' !== $object_code ) {
			$this->session_manager->save_selected_tariff(
				'' !== $checkout_group_id ? $checkout_group_id : $service_key,
				array(
					'object_code' => $object_code,
					'title' => $title,
				)
			);
		}
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( array( 'service_key' => $service_key, 'checkout_group_id' => $checkout_group_id, 'delivery_type' => $delivery_type, 'object_code' => $object_code ) );
		}
	}

	private function suggestions_enabled(): bool {
		return $this->suggestion_settings instanceof AddressSuggestionSettings && $this->suggestion_settings->enabled() && $this->suggestion_settings->encryption_ready() && $this->suggestion_settings->has_any_configured_token();
	}

	private function suggestions_requested(): bool {
		return $this->suggestion_settings instanceof AddressSuggestionSettings && $this->suggestion_settings->enabled();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function address_suggestions_config(): array {
		return array(
			'ajax_url'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'     => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( AddressSuggestionAjax::NONCE_ACTION ) : '',
			'min_chars' => 3,
			'debug'     => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && $this->settings->get_bool( 'show_checkout_debug_panel', false ),
			'suggestions_requested' => $this->suggestions_requested(),
			'enabled'   => $this->suggestions_enabled(),
			'tokens_ready' => $this->suggestion_settings instanceof AddressSuggestionSettings && $this->suggestion_settings->has_any_configured_token(),
			'total_tokens_count' => $this->token_pool instanceof DaDataTokenPool ? $this->token_pool->total_tokens_count() : 0,
			'available_tokens_count' => $this->token_pool instanceof DaDataTokenPool ? $this->token_pool->available_tokens_count() : 0,
			'encryption_ready' => $this->suggestion_settings instanceof AddressSuggestionSettings && $this->suggestion_settings->encryption_ready(),
			'action'    => AddressSuggestionAjax::ACTION,
			'selection_action' => AddressSuggestionAjax::SELECTION_ACTION,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function city_selector_config(): array {
		return array(
			'ajax_url'  => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'     => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( CheckoutLocationAjax::NONCE_ACTION ) : '',
			'min_chars' => 3,
			'include_region_in_query' => $this->settings->get_bool( 'include_region_in_checkout_city_picker_query', true ),
			'checkout_location_search_limit' => $this->city_location_limit(),
			'location_region_limit' => max( 3, min( 50, $this->settings->get_int( 'checkout_location_region_limit', 10 ) ) ),
			'supported_location_countries' => $this->location_country_index instanceof LocationCountryIndexService ? $this->location_country_index->countries() : array(),
			'manual_city_context' => $this->manual_city_context_config(),
			'resolve_action' => CheckoutLocationAjax::RESOLVE_ACTION,
			'debug'     => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && $this->settings->get_bool( 'show_checkout_debug_panel', false ),
			'strings'   => array(
				'start'     => __( 'Начните вводить населенный пункт', 'walls-delivery-calc' ),
				'not_found' => __( 'Населённый пункт не найден. Проверьте введённое название.', 'walls-delivery-calc' ),
				'error'     => __( 'Не удалось выполнить поиск. Проверьте подключение и попробуйте ещё раз.', 'walls-delivery-calc' ),
				'searching' => __( 'Идёт поиск, подождите несколько секунд', 'walls-delivery-calc' ),
			),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function manual_city_context_config(): array {
		$context = $this->session_manager->city_context();
		if ( 'manual' !== (string) ( $context['source'] ?? '' ) ) {
			return array();
		}
		$city = trim( (string) ( $context['city_name'] ?? $context['display_name'] ?? '' ) );
		$country_code = strtoupper( trim( (string) ( $context['country_code'] ?? '' ) ) );
		if ( '' === $city || '' === $country_code ) {
			return array();
		}

		return array(
			'source' => 'manual',
			'country_code' => $country_code,
			'city_name' => $city,
			'region_name' => trim( (string) ( $context['region_name'] ?? '' ) ),
		);
	}

	private function city_location_limit(): int {
		return max( 10, min( 500, $this->settings->get_int( 'checkout_location_search_limit', 100 ) ) );
	}

}
