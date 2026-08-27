<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffSyncService;
use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdGeographyDiagnosticService;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdDaDataDeliveryFallbackService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyFtpClient;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointAutoSync;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportReport;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticGeographyAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticStatusAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminPage;
use WallsShop\WDC\Carriers\Pek\Admin\PekStatusAdminPage;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationManualOverrideV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostPickupDiagnosticsTab;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Carriers\Runtime\DpdQuoteCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Carriers\Runtime\YandexDeliveryCarrier;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Packaging\PackagingApplicationResult;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Admin\RuleAdminContext;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Cdek\CdekStatusMappingService;
use WallsShop\WDC\Shipments\Dpd\DpdStatusMapping;
use WallsShop\WDC\Shipments\YandexDelivery\YandexStatusMapping;

defined( 'ABSPATH' ) || exit;

final class DeliveryServicesAdminPage {
	public const MENU_SLUG = 'wdc-delivery-services';
	private const DPD_GEOGRAPHY_AJAX_STEP_LIMIT = 500;

	public function __construct(
		private DeliveryServiceRepository $services,
		private DeliveryServiceCountryRepository $countries,
		private RulesAdminPage $rules_admin,
		private RuleRepository $rules,
		private RussianPostPickupDiagnosticsTab $russian_post_pickup_diagnostics,
		private ?DeliveryServiceSettingsRepository $settings = null,
		private ?RussianPostSettings $russian_post_settings = null,
		private ?RussianPostCountriesAdminPage $russian_post_countries = null,
		private ?RussianPostInternationalCarrier $russian_post_carrier = null,
		private ?RuleAppliedRateBuilder $rule_builder = null,
		private ?DeliveryServiceManager $manager = null,
		private ?PackagingWeightCalculator $packaging_calculator = null,
		private ?RussianPostDomesticCarrier $russian_post_domestic_carrier = null,
		private ?RussianPostOtpravkaApiSettings $otpravka_settings = null,
		private ?RussianPostPickupImporter $pickup_importer = null,
		private ?PickupPointRepository $pickup_points = null,
		private ?RussianPostPickupPointRepository $russian_post_pickup_points = null,
		private ?RussianPostPickupImportStateService $pickup_import_state = null,
		private ?PluginEnvironment $environment = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null,
		private ?CdekSettings $cdek_settings = null,
		private ?CdekApiClient $cdek_api = null,
		private ?CdekTariffRepository $cdek_tariffs = null,
		private ?CdekTariffSyncService $cdek_tariff_sync = null,
		private ?DeliveryQuoteCacheManager $delivery_quote_cache_manager = null,
		private ?CdekStatusMappingService $cdek_status_mapping = null,
		private ?CdekCarrier $cdek_carrier = null,
		private ?DpdSettings $dpd_settings = null,
		private ?DpdApiClient $dpd_api = null,
		private ?DpdGeographyDiagnosticService $dpd_geography_diagnostics = null,
		private ?DpdGeographyImportService $dpd_geography_importer = null,
		private ?DpdGeographyFtpClient $dpd_geography_ftp = null,
		private ?DpdDaDataDeliveryFallbackService $dpd_dadata_fallback = null,
		private ?DpdPickupPointRepository $dpd_pickup_points = null,
		private ?DpdPickupPointImportService $dpd_pickup_importer = null,
		private ?DpdCityResolver $dpd_city_resolver = null,
		private ?LocationRepository $locations = null,
		private ?DpdStatusMapping $dpd_status_mapping = null,
		private ?DpdPickupPointAutoSync $dpd_pickup_autosync = null,
		private ?YandexDeliverySettings $yandex_delivery_settings = null,
		private ?YandexDeliveryConnectionDiagnosticService $yandex_delivery_diagnostics = null,
		private ?YandexDeliveryPickupPointV2Repository $yandex_delivery_pickup_v2_repository = null,
		private ?YandexDeliveryPickupPointV2RunnerService $yandex_delivery_pickup_v2_runner = null,
		private ?YandexDeliveryGeoV2Repository $yandex_delivery_geo_v2_repository = null,
		private ?YandexDeliveryGeoV2BuilderRunnerService $yandex_delivery_geo_v2_builder_runner = null,
		private ?YandexLocationMappingV2Repository $yandex_location_mapping_v2_repository = null,
		private ?YandexLocationMappingV2Runner $yandex_location_mapping_v2_runner = null,
		private ?YandexLocationManualOverrideV2Repository $yandex_location_manual_override_v2_repository = null,
		private ?YandexRegionMappingV2Repository $yandex_region_mapping_v2_repository = null,
		private ?YandexGeoV2RegionEnrichmentRunner $yandex_geo_v2_region_enrichment_runner = null,
		private ?YandexDeliveryGeoPipelineV2Runner $yandex_delivery_geo_pipeline_v2_runner = null,
		private ?YandexStatusMapping $yandex_status_mapping = null,
		private ?DpdQuoteCarrier $dpd_carrier = null,
		private ?YandexDeliveryCarrier $yandex_delivery_carrier = null,
		private ?SettingsRepository $global_settings = null,
		private ?JetLogisticGeographyAdminPage $jet_logistic_geography = null,
		private ?JetLogisticStatusAdminPage $jet_logistic_statuses = null,
		private ?PekAdminPage $pek_admin = null,
		private ?PekStatusAdminPage $pek_statuses = null,
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$this->russian_post_pickup_diagnostics->register();
		add_action( 'wp_ajax_wdc_russian_post_pickup_import_status', array( $this, 'ajax_pickup_import_status' ) );
		add_action( 'wp_ajax_wdc_dpd_geography_import_status', array( $this, 'ajax_dpd_geography_import_status' ) );
		add_action( 'wp_ajax_wdc_dpd_geography_import_step', array( $this, 'ajax_dpd_geography_import_step' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_pickup_v2_runner_start', array( $this, 'ajax_yandex_delivery_pickup_v2_runner_start' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_pickup_v2_runner_start_import', array( $this, 'ajax_yandex_delivery_pickup_v2_runner_start_import' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_pickup_v2_runner_step', array( $this, 'ajax_yandex_delivery_pickup_v2_runner_step' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_pickup_v2_runner_pause', array( $this, 'ajax_yandex_delivery_pickup_v2_runner_pause' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_pickup_v2_runner_reset', array( $this, 'ajax_yandex_delivery_pickup_v2_runner_reset' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_v2_builder_start', array( $this, 'ajax_yandex_delivery_geo_v2_builder_start' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_v2_builder_step', array( $this, 'ajax_yandex_delivery_geo_v2_builder_step' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_v2_builder_pause', array( $this, 'ajax_yandex_delivery_geo_v2_builder_pause' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_v2_builder_reset', array( $this, 'ajax_yandex_delivery_geo_v2_builder_reset' ) );
		add_action( 'wp_ajax_wdc_yandex_geo_v2_region_enrichment_start', array( $this, 'ajax_yandex_geo_v2_region_enrichment_start' ) );
		add_action( 'wp_ajax_wdc_yandex_geo_v2_region_enrichment_step', array( $this, 'ajax_yandex_geo_v2_region_enrichment_step' ) );
		add_action( 'wp_ajax_wdc_yandex_geo_v2_region_enrichment_pause', array( $this, 'ajax_yandex_geo_v2_region_enrichment_pause' ) );
		add_action( 'wp_ajax_wdc_yandex_geo_v2_region_enrichment_reset', array( $this, 'ajax_yandex_geo_v2_region_enrichment_reset' ) );
		add_action( 'wp_ajax_wdc_yandex_location_mapping_v2_start', array( $this, 'ajax_yandex_location_mapping_v2_start' ) );
		add_action( 'wp_ajax_wdc_yandex_location_mapping_v2_step', array( $this, 'ajax_yandex_location_mapping_v2_step' ) );
		add_action( 'wp_ajax_wdc_yandex_location_mapping_v2_pause', array( $this, 'ajax_yandex_location_mapping_v2_pause' ) );
		add_action( 'wp_ajax_wdc_yandex_location_mapping_v2_reset', array( $this, 'ajax_yandex_location_mapping_v2_reset' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_start', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_start' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_step', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_step' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_status', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_status' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_pause', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_pause' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_resume', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_resume' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_geo_pipeline_v2_reset', array( $this, 'ajax_yandex_delivery_geo_pipeline_v2_reset' ) );
		add_action( 'wp_ajax_wdc_yandex_delivery_source_station', array( $this, 'ajax_yandex_delivery_source_station' ) );
	}

	public function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$service = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::MENU_SLUG === $page && RussianPostDomesticSettings::SERVICE_KEY === $service && 'russian_post_pickup' === $tab ) {
			wp_enqueue_script(
				'wdc-russian-post-pickup-import-admin',
				$this->asset_url( 'assets/admin/russian-post-pickup-import.js' ),
				array(),
				$this->asset_version(),
				true
			);
			wp_localize_script(
				'wdc-russian-post-pickup-import-admin',
				'wdcRussianPostPickupImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wdc_russian_post_pickup_import_status' ),
				)
			);
		}
		if ( self::MENU_SLUG === $page && DpdSettings::SERVICE_KEY === $service && 'dpd_geography' === $tab ) {
			wp_enqueue_script(
				'wdc-dpd-geography-import-admin',
				$this->asset_url( 'assets/admin/dpd-geography-import.js' ),
				array(),
				$this->asset_version(),
				true
			);
			wp_localize_script(
				'wdc-dpd-geography-import-admin',
				'wdcDpdGeographyImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wdc_dpd_geography_import' ),
					'stepLimit' => self::DPD_GEOGRAPHY_AJAX_STEP_LIMIT,
					'stepDelayMs' => 250,
					'busyRetryMs' => 1500,
				)
			);
		}
		if ( self::MENU_SLUG === $page && YandexDeliverySettings::SERVICE_KEY === $service && 'yandex_delivery_pickup' === $tab ) {
			wp_enqueue_script(
				'wdc-yandex-delivery-pickup-v2-runner-admin',
				$this->asset_url( 'assets/admin/yandex-delivery-pickup-v2-runner.js' ),
				array(),
				$this->asset_version(),
				true
			);
			wp_localize_script(
				'wdc-yandex-delivery-pickup-v2-runner-admin',
				'wdcYandexDeliveryPickupV2Runner',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wdc_yandex_delivery_pickup_v2_runner' ),
					'initialState' => $this->yandex_delivery_pickup_v2_runner instanceof YandexDeliveryPickupPointV2RunnerService ? $this->yandex_delivery_pickup_v2_runner->current_state() : array(),
					'geoBuilderInitialState' => $this->yandex_delivery_geo_v2_builder_runner instanceof YandexDeliveryGeoV2BuilderRunnerService ? $this->yandex_delivery_geo_v2_builder_runner->current_state() : array(),
					'locationMappingInitialState' => $this->yandex_location_mapping_v2_runner instanceof YandexLocationMappingV2Runner ? $this->yandex_location_mapping_v2_runner->current_state() : array(),
					'geoRegionEnrichmentInitialState' => $this->yandex_geo_v2_region_enrichment_runner instanceof YandexGeoV2RegionEnrichmentRunner ? $this->yandex_geo_v2_region_enrichment_runner->current_state() : array(),
					'geoPipelineInitialState' => $this->yandex_delivery_geo_pipeline_v2_runner instanceof YandexDeliveryGeoPipelineV2Runner ? $this->yandex_delivery_geo_pipeline_v2_runner->current_state() : array(),
				)
			);
		}
	}

	public function add_menu_page(): void {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Службы доставки', 'walls-delivery-calc' ),
			__( 'Службы доставки', 'walls-delivery-calc' ),
			AdminMenu::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function ajax_pickup_import_status(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wdc_russian_post_pickup_import_status', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) ), 403 );
		}

		$state = $this->pickup_importer instanceof RussianPostPickupImporter
			? $this->pickup_importer->refresh_state_for_status()
			: ( $this->pickup_import_state instanceof RussianPostPickupImportStateService ? $this->pickup_import_state->current() : array() );

		wp_send_json_success( $state );
	}

	public function ajax_dpd_geography_import_status(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wdc_dpd_geography_import', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) ), 403 );
		}

		$state = $this->dpd_geography_importer instanceof DpdGeographyImportService
			? $this->dpd_geography_importer->current_state()
			: array();

		wp_send_json_success( $state );
	}

	public function ajax_dpd_geography_import_step(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wdc_dpd_geography_import', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$expected_byte_offset = isset( $_POST['expected_byte_offset'] ) ? max( 0, (int) $_POST['expected_byte_offset'] ) : null;
		$state = $this->dpd_geography_importer instanceof DpdGeographyImportService
			? $this->dpd_geography_importer->step( $job_id, self::DPD_GEOGRAPHY_AJAX_STEP_LIMIT, $expected_byte_offset )
			: array();

		wp_send_json_success( $state );
	}
	public function ajax_yandex_delivery_pickup_v2_runner_start(): void {
		$this->handle_yandex_delivery_pickup_v2_runner_ajax( 'start', fn(): array => $this->yandex_delivery_pickup_v2_runner->start_full_api_sync() );
	}

	public function ajax_yandex_delivery_pickup_v2_runner_start_import(): void {
		$this->handle_yandex_delivery_pickup_v2_runner_ajax( 'start_import', fn(): array => $this->yandex_delivery_pickup_v2_runner->start_import() );
	}

	public function ajax_yandex_delivery_pickup_v2_runner_step(): void {
		$this->handle_yandex_delivery_pickup_v2_runner_ajax( 'step', fn(): array => $this->yandex_delivery_pickup_v2_runner->run_import_step() );
	}

	public function ajax_yandex_delivery_pickup_v2_runner_pause(): void {
		$this->handle_yandex_delivery_pickup_v2_runner_ajax( 'pause', fn(): array => $this->yandex_delivery_pickup_v2_runner->pause() );
	}

	public function ajax_yandex_delivery_pickup_v2_runner_reset(): void {
		$this->handle_yandex_delivery_pickup_v2_runner_ajax( 'reset', fn(): array => $this->yandex_delivery_pickup_v2_runner->reset() );
	}

	/** @param callable():array<string,mixed> $callback */
	private function handle_yandex_delivery_pickup_v2_runner_ajax( string $last_action, callable $callback ): void {
		$this->register_yandex_pickup_v2_ajax_shutdown_guard();
		if ( ! $this->can_handle_yandex_delivery_pickup_v2_runner_ajax() ) {
			return;
		}
		try {
			$state = $callback();
			$state['last_action'] = $state['last_action'] ?? $last_action;
			wp_send_json_success( $state );
		} catch ( \Throwable $exception ) {
			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
					'last_action' => $last_action,
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
				),
				500
			);
		}
	}

	private function register_yandex_pickup_v2_ajax_shutdown_guard(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		register_shutdown_function(
			static function (): void {
				$error = error_get_last();
				if ( $error && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					if ( ! headers_sent() ) {
						wp_send_json_error(
							array(
								'message' => 'Fatal error: ' . (string) $error['message'],
								'file' => (string) $error['file'],
								'line' => (int) $error['line'],
							),
							500
						);
					}
				}
			}
		);
	}

	public function ajax_yandex_delivery_geo_v2_builder_start(): void {
		$this->handle_yandex_delivery_geo_v2_builder_ajax( fn(): array => $this->yandex_delivery_geo_v2_builder_runner->start() );
	}

	public function ajax_yandex_delivery_geo_v2_builder_step(): void {
		$this->handle_yandex_delivery_geo_v2_builder_ajax( fn(): array => $this->yandex_delivery_geo_v2_builder_runner->run_step() );
	}

	public function ajax_yandex_delivery_geo_v2_builder_pause(): void {
		$this->handle_yandex_delivery_geo_v2_builder_ajax( fn(): array => $this->yandex_delivery_geo_v2_builder_runner->pause() );
	}

	public function ajax_yandex_delivery_geo_v2_builder_reset(): void {
		$this->handle_yandex_delivery_geo_v2_builder_ajax( fn(): array => $this->yandex_delivery_geo_v2_builder_runner->reset() );
	}

	/** @param callable():array<string,mixed> $callback */
	private function handle_yandex_delivery_geo_v2_builder_ajax( callable $callback ): void {
		$this->register_yandex_pickup_v2_ajax_shutdown_guard();
		if ( ! $this->can_handle_yandex_delivery_geo_v2_builder_ajax() ) {
			return;
		}
		try {
			wp_send_json_success( $callback() );
		} catch ( \Throwable $exception ) {
			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
				),
				500
			);
		}
	}

	public function ajax_yandex_geo_v2_region_enrichment_start(): void {
		$this->handle_yandex_geo_v2_region_enrichment_ajax( fn(): array => $this->yandex_geo_v2_region_enrichment_runner->start() );
	}

	public function ajax_yandex_geo_v2_region_enrichment_step(): void {
		$this->handle_yandex_geo_v2_region_enrichment_ajax( fn(): array => $this->yandex_geo_v2_region_enrichment_runner->run_step() );
	}

	public function ajax_yandex_geo_v2_region_enrichment_pause(): void {
		$this->handle_yandex_geo_v2_region_enrichment_ajax( fn(): array => $this->yandex_geo_v2_region_enrichment_runner->pause() );
	}

	public function ajax_yandex_geo_v2_region_enrichment_reset(): void {
		$this->handle_yandex_geo_v2_region_enrichment_ajax( fn(): array => $this->yandex_geo_v2_region_enrichment_runner->reset() );
	}

	/** @param callable():array<string,mixed> $callback */
	private function handle_yandex_geo_v2_region_enrichment_ajax( callable $callback ): void {
		$this->register_yandex_pickup_v2_ajax_shutdown_guard();
		if ( ! $this->can_handle_yandex_geo_v2_region_enrichment_ajax() ) {
			return;
		}
		try {
			wp_send_json_success( $callback() );
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine() ), 500 );
		}
	}

	public function ajax_yandex_location_mapping_v2_start(): void {
		$this->handle_yandex_location_mapping_v2_ajax( fn(): array => $this->yandex_location_mapping_v2_runner->start() );
	}

	public function ajax_yandex_location_mapping_v2_step(): void {
		$this->handle_yandex_location_mapping_v2_ajax( fn(): array => $this->yandex_location_mapping_v2_runner->run_step() );
	}

	public function ajax_yandex_location_mapping_v2_pause(): void {
		$this->handle_yandex_location_mapping_v2_ajax( fn(): array => $this->yandex_location_mapping_v2_runner->pause() );
	}

	public function ajax_yandex_location_mapping_v2_reset(): void {
		$this->handle_yandex_location_mapping_v2_ajax( fn(): array => $this->yandex_location_mapping_v2_runner->reset() );
	}

	/** @param callable():array<string,mixed> $callback */
	private function handle_yandex_location_mapping_v2_ajax( callable $callback ): void {
		$this->register_yandex_pickup_v2_ajax_shutdown_guard();
		if ( ! $this->can_handle_yandex_location_mapping_v2_ajax() ) {
			return;
		}
		try {
			wp_send_json_success( $callback() );
		} catch ( \Throwable $exception ) {
			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
				),
				500
			);
		}
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_start(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->start() );
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_step(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->run_step() );
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_status(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->current_state() );
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_pause(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->pause() );
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_resume(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->resume() );
	}

	public function ajax_yandex_delivery_geo_pipeline_v2_reset(): void {
		$this->handle_yandex_delivery_geo_pipeline_v2_ajax( fn(): array => $this->yandex_delivery_geo_pipeline_v2_runner->reset() );
	}

	public function ajax_yandex_delivery_source_station(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_source_station', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
		}
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		if ( 'locations' === $mode ) {
			$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
			if ( ! $this->locations instanceof LocationRepository ) {
				wp_send_json_error( array( 'message' => __( 'Справочник населенных пунктов недоступен.', 'walls-delivery-calc' ) ), 500 );
			}
			$locations = strlen( trim( $query ) ) >= 2 ? $this->locations->search( $query, 20, 'RU' ) : array();
			wp_send_json_success(
				array(
					'locations' => array_map(
						fn( $location ): array => array(
							'id' => (int) ( $location->id ?? 0 ),
							'label' => $this->yandex_delivery_location_label( $location ),
						),
						array_values( array_filter( $locations, static fn( $location ): bool => null !== ( $location->id ?? null ) ) )
					),
					'message' => array() === $locations ? __( 'Населенные пункты не найдены.', 'walls-delivery-calc' ) : __( 'Выберите населенный пункт из списка.', 'walls-delivery-calc' ),
				)
			);
		}
		if ( 'points' === $mode ) {
			$data = $this->yandex_delivery_source_points_for_location( max( 0, (int) ( $_POST['location_id'] ?? 0 ) ) );
			wp_send_json_success(
				array(
					'yandex_geo_ids' => $data['yandex_geo_ids'],
					'points' => $this->yandex_delivery_source_point_ajax_rows( $data['points'] ),
					'message' => $this->yandex_delivery_source_points_message( (int) $data['location_id'], $data['yandex_geo_ids'], $data['points'] ),
				)
			);
		}

		wp_send_json_error( array( 'message' => __( 'Неизвестное действие выбора ПВЗ.', 'walls-delivery-calc' ) ), 400 );
	}

	/** @param callable():array<string,mixed> $callback */
	private function handle_yandex_delivery_geo_pipeline_v2_ajax( callable $callback ): void {
		$this->register_yandex_pickup_v2_ajax_shutdown_guard();
		if ( ! $this->can_handle_yandex_delivery_geo_pipeline_v2_ajax() ) {
			return;
		}
		try {
			wp_send_json_success( $callback() );
		} catch ( \Throwable $exception ) {
			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine(),
				),
				500
			);
		}
	}
	private function can_handle_yandex_delivery_geo_pipeline_v2_ajax(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_pickup_v2_runner', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! $this->yandex_delivery_geo_pipeline_v2_runner instanceof YandexDeliveryGeoPipelineV2Runner ) {
			wp_send_json_error( array( 'message' => __( 'Runner полного pipeline Яндекс ПВЗ/географии недоступен.', 'walls-delivery-calc' ) ), 500 );
			return false;
		}

		return true;
	}
	private function can_handle_yandex_geo_v2_region_enrichment_ajax(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_pickup_v2_runner', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! $this->yandex_geo_v2_region_enrichment_runner instanceof YandexGeoV2RegionEnrichmentRunner ) {
			wp_send_json_error( array( 'message' => __( 'Runner обогащения регионов geo_v2 недоступен.', 'walls-delivery-calc' ) ), 500 );
			return false;
		}

		return true;
	}

	private function can_handle_yandex_location_mapping_v2_ajax(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_pickup_v2_runner', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! $this->yandex_location_mapping_v2_runner instanceof YandexLocationMappingV2Runner ) {
			wp_send_json_error( array( 'message' => __( 'Mapper location mapping v2 недоступен.', 'walls-delivery-calc' ) ), 500 );
			return false;
		}

		return true;
	}
	private function can_handle_yandex_delivery_geo_v2_builder_ajax(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_pickup_v2_runner', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! $this->yandex_delivery_geo_v2_builder_runner instanceof YandexDeliveryGeoV2BuilderRunnerService ) {
			wp_send_json_error( array( 'message' => __( 'Builder geoId v2 недоступен.', 'walls-delivery-calc' ) ), 500 );
			return false;
		}

		return true;
	}
	private function can_handle_yandex_delivery_pickup_v2_runner_ajax(): bool {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( 'wdc_yandex_delivery_pickup_v2_runner', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия истекла. Обновите страницу.', 'walls-delivery-calc' ) ), 403 );
			return false;
		}
		if ( ! $this->yandex_delivery_pickup_v2_runner instanceof YandexDeliveryPickupPointV2RunnerService ) {
			wp_send_json_error( array( 'message' => __( 'Runner Яндекс ПВЗ/география недоступен.', 'walls-delivery-calc' ) ), 500 );
			return false;
		}

		return true;
	}

	/** @return array{location_id:int,yandex_geo_ids:array<int,int>,points:array<int,array<string,mixed>>} */
	private function yandex_delivery_source_points_for_location( int $location_id ): array {
		$location_id = max( 0, $location_id );
		$geo_ids = $location_id > 0 && $this->yandex_location_mapping_v2_repository instanceof YandexLocationMappingV2Repository
			? $this->yandex_location_mapping_v2_repository->geo_ids_for_location( $location_id )
			: array();
		$points = array() !== $geo_ids && $this->yandex_delivery_pickup_v2_repository instanceof YandexDeliveryPickupPointV2Repository
			? $this->yandex_delivery_pickup_v2_repository->source_dropoff_points_by_geo_ids( $geo_ids )
			: array();

		return array( 'location_id' => $location_id, 'yandex_geo_ids' => $geo_ids, 'points' => $points );
	}

	private function yandex_delivery_location_label( object $location ): string {
		$label = method_exists( $location, 'resolved_display_name' ) ? (string) $location->resolved_display_name() : (string) ( $location->display_name ?? '' );
		$id = (int) ( $location->id ?? 0 );

		return trim( $label ) . ( $id > 0 ? ' (location_id: ' . $id . ')' : '' );
	}

	private function yandex_delivery_source_point_label( array $point ): string {
		$station_id = $this->sanitize_yandex_platform_station_id( (string) ( $point['platform_station_id'] ?? '' ) );
		$name = trim( (string) ( $point['name'] ?? '' ) );
		$address = trim( (string) ( $point['full_address'] ?? '' ) );

		return trim( ( '' !== $name ? $name . ' — ' : '' ) . ( '' !== $address ? $address : $station_id ) );
	}

	/** @param array<int,array<string,mixed>> $points @return array<int,array<string,string>> */
	private function yandex_delivery_source_point_ajax_rows( array $points ): array {
		$rows = array();
		foreach ( $points as $point ) {
			$station_id = $this->sanitize_yandex_platform_station_id( (string) ( $point['platform_station_id'] ?? '' ) );
			if ( '' === $station_id ) {
				continue;
			}
			$rows[] = array(
				'platform_station_id' => $station_id,
				'label' => $this->yandex_delivery_source_point_label( $point ),
				'address' => trim( (string) ( $point['full_address'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/** @param array<int,int> $yandex_geo_ids @param array<int,array<string,mixed>> $points */
	private function yandex_delivery_source_points_message( int $location_id, array $yandex_geo_ids, array $points ): string {
		if ( $location_id <= 0 ) {
			return __( 'Выберите населенный пункт, чтобы загрузить ПВЗ сдачи.', 'walls-delivery-calc' );
		}
		if ( array() === $yandex_geo_ids ) {
			return __( 'Для выбранного населенного пункта нет связанных yandex_geo_id в location mapping.', 'walls-delivery-calc' );
		}
		$geo_ids_text = implode( ', ', array_map( 'strval', $yandex_geo_ids ) );
		if ( array() === $points ) {
			return __( 'Для связанных yandex_geo_id нет активных ПВЗ, доступных для сдачи отправлений.', 'walls-delivery-calc' );
		}

		return sprintf( __( 'Найдено ПВЗ сдачи: %1$d; yandex_geo_id: %2$s.', 'walls-delivery-calc' ), count( $points ), $geo_ids_text );
	}

	private function asset_url( string $path ): string {
		if ( $this->environment instanceof PluginEnvironment ) {
			return $this->environment->plugin_url() . ltrim( $path, '/' );
		}

		return defined( 'WDC_PLUGIN_URL' ) ? WDC_PLUGIN_URL . ltrim( $path, '/' ) : $path;
	}

	private function asset_version(): string {
		if ( $this->environment instanceof PluginEnvironment ) {
			return $this->environment->version();
		}

		return defined( 'WDC_VERSION' ) ? WDC_VERSION : '1';
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( AdminMenu::CAPABILITY ) || ( $_POST['wdc_delivery_services_action'] ?? '' ) === '' ) {
			return;
		}

		check_admin_referer( 'wdc_delivery_services' );
		$action = sanitize_key( wp_unslash( $_POST['wdc_delivery_services_action'] ) );
		if ( 'save_yandex_geo_pipeline_v2_schedule' === $action ) {
			$this->handle_yandex_geo_pipeline_v2_schedule_action();
			return;
		}
		if ( in_array( $action, array(
			'sync_yandex_region_mapping_v2',
			'save_yandex_region_mapping_v2',
			'save_yandex_location_manual_override_v2',
			'deactivate_yandex_location_manual_override_v2'
		), true ) ) {
			$this->handle_yandex_region_mapping_v2_action( $action );
			return;
		}
		if ( in_array( $action, array( 'save_jet_settings', 'check_jet_connection', 'import_jet_geography_remote', 'import_jet_geography_csv', 'save_jet_geography_override', 'create_jet_status_mapping', 'update_jet_status_mapping', 'delete_jet_status_mapping', 'check_jet_tracking' ), true ) ) {
			$this->handle_jet_logistic_action( $action );
			return;
		}
		if ( PekAdminPage::supports_action( $action ) ) {
			$this->handle_pek_action( $action );
			return;
		}
		if ( in_array( $action, array(
				'save_global_delivery_settings',
				'save',
				'save_main',
				'save_availability',
				'save_calculation',
				'save_tariffs',
				'save_cdek_tariffs',
				'bulk_cdek_tariffs',
				'preview_cdek_tariffs_sync',
				'confirm_cdek_tariffs_sync',
				'save_dpd_runtime_tariffs',
				'save_russian_post_pickup',
				'run_russian_post_pickup_import',
				'upload_russian_post_pickup_file_import',
				'upload_russian_post_pickup_zip_import',
				'reset_russian_post_pickup_import',
				'save_api_credentials',
				'save_shipments',
				'save_status_mapping',
				'save_cdek_statuses',
				'save_dpd_statuses',
				'save_pek_statuses',
				'save_yandex_delivery_statuses',
				'save_cdek_settings',
				'save_cdek_calculation',
				'check_cdek_connection',
				'save_dpd_settings',
				'check_dpd_connection',
				'save_dpd_geography_settings',
				'run_dpd_geography_ftp_import',
				'upload_dpd_geography_csv_import',
				'reset_dpd_geography_import',
				'force_cancel_dpd_geography_import',
				'check_dpd_geography',
				'save_dpd_city_mapping',
				'test_dpd_dadata_fallback',
				'save_dpd_tariff_settings',
				'save_dpd_pickup_autosync',
				'run_dpd_pickup_parcel_shops_import',
				'run_dpd_pickup_terminals_import',
				'run_dpd_pickup_all_import',
				'reset_dpd_pickup_result',
				'save_yandex_delivery_settings',
				'check_yandex_delivery_connection'
			), true ) ) {
			if ( 'save_global_delivery_settings' === $action ) {
				$this->save_global_delivery_settings();
				$this->clear_delivery_quote_cache();
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&updated=1' ) );
				exit;
			}
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$data = match ( $action ) {
				'save_main' => $this->sanitize_main_data(),
				'save_availability' => $this->sanitize_availability_data(),
				'save_calculation' => $this->sanitize_calculation_data(),
				default => $this->sanitize_service_data(),
			};
			if ( 'save_tariffs' === $action ) {
				$data = array();
			}
			if ( in_array( $action, array(
					'save_cdek_tariffs',
					'bulk_cdek_tariffs',
					'preview_cdek_tariffs_sync',
					'confirm_cdek_tariffs_sync',
					'save_dpd_runtime_tariffs',
					'save_russian_post_pickup',
					'run_russian_post_pickup_import',
					'upload_russian_post_pickup_file_import',
					'upload_russian_post_pickup_zip_import',
					'reset_russian_post_pickup_import',
					'save_api_credentials',
					'save_shipments',
					'save_status_mapping',
					'save_cdek_statuses',
					'save_dpd_statuses',
					'save_pek_statuses',
					'save_cdek_settings',
					'save_cdek_calculation',
					'check_cdek_connection',
					'save_dpd_settings',
					'check_dpd_connection',
					'save_dpd_geography_settings',
					'run_dpd_geography_ftp_import',
					'upload_dpd_geography_csv_import',
					'reset_dpd_geography_import',
					'force_cancel_dpd_geography_import',
					'check_dpd_geography',
					'save_dpd_city_mapping',
					'test_dpd_dadata_fallback',
					'save_dpd_tariff_settings',
					'save_dpd_pickup_autosync',
					'run_dpd_pickup_parcel_shops_import',
					'run_dpd_pickup_terminals_import',
					'run_dpd_pickup_all_import',
					'reset_dpd_pickup_result',
					'save_yandex_delivery_settings',
					'save_yandex_delivery_statuses',
					'check_yandex_delivery_connection'
				), true ) ) {
				$data = array();
			}
			if ( $id > 0 && array() !== $data ) {
				$this->services->update_service( $id, $data );
			} else {
				if ( array() !== $data ) {
					$id = $this->services->create_service( $data );
				}
			}
			if ( in_array( $action, array( 'save', 'save_main', 'save_availability' ), true ) ) {
				$countries = $this->countries_from_post();
				$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
				$current_service = $id > 0 ? $this->services->find_by_id( $id ) : null;
				if ( CdekSettings::SERVICE_KEY === $service_key || ( $current_service instanceof DeliveryService && CdekSettings::SERVICE_KEY === $current_service->service_key ) ) {
					$countries = array_values( array_intersect( array_map( 'strtoupper', $countries ), CdekSettings::SUPPORTED_COUNTRIES ) );
				}
				$this->countries->replace_countries( $id, $countries );
			}
			if ( 'save_main' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && null !== $service->id ) {
					$this->settings->set_setting( (int) $service->id, DeliveryServiceSettingsRepository::DELIVERY_DAYS_ARE_WORKING_KEY, isset( $_POST[ DeliveryServiceSettingsRepository::DELIVERY_DAYS_ARE_WORKING_KEY ] ), 'bool' );
					$this->clear_delivery_quote_cache();
				}
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_russian_post_domestic_main_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && CdekSettings::SERVICE_KEY === $service->service_key && null !== $service->id ) {
					$this->save_cdek_main_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && $this->is_yandex_delivery_service( $service ) && null !== $service->id ) {
					$this->save_yandex_delivery_main_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && $this->is_dpd_service( $service ) && $this->dpd_settings instanceof DpdSettings ) {
					$this->dpd_settings->save_runtime_titles_from_admin( $_POST );
					$this->clear_delivery_quote_cache();
				}
			}
			if ( 'save_calculation' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && RussianPostSettings::SERVICE_KEY === $service->service_key && null !== $service->id ) {
					$this->save_russian_post_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_russian_post_domestic_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && $this->is_yandex_delivery_service( $service ) && null !== $service->id ) {
					$this->save_yandex_delivery_calculation_settings( (int) $service->id );
				}
			}
			if ( 'save_shipments' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_shipment_service_settings( (int) $service->id, $service->service_key );
				}
			}
			if ( 'save_status_mapping' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_status_mapping_settings( (int) $service->id );
				}
			}
			if ( 'save_cdek_statuses' === $action && $this->cdek_status_mapping instanceof CdekStatusMappingService ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $this->is_cdek_service( $service ) ) {
					$mapping = isset( $_POST[ CdekStatusMappingService::MAPPING_KEY ] ) && is_array( $_POST[ CdekStatusMappingService::MAPPING_KEY ] )
						? $this->cdek_status_mapping->sanitize_mapping( wp_unslash( $_POST[ CdekStatusMappingService::MAPPING_KEY ] ) )
						: CdekStatusMappingService::default_mapping();
					$this->cdek_status_mapping->save_mapping( $mapping );
				}
			}
			if ( 'save_dpd_statuses' === $action && $this->dpd_status_mapping instanceof DpdStatusMapping ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $this->is_dpd_service( $service ) ) {
					$mapping = DpdStatusMapping::default_mapping();
					if ( empty( $_POST['dpd_statuses_reset'] ) && isset( $_POST[ DpdStatusMapping::MAPPING_KEY ] ) && is_array( $_POST[ DpdStatusMapping::MAPPING_KEY ] ) ) {
						$mapping = $this->dpd_status_mapping->sanitize_mapping( wp_unslash( $_POST[ DpdStatusMapping::MAPPING_KEY ] ) );
					}
					$this->dpd_status_mapping->save_mapping( $mapping );
				}
			}
			if ( 'save_pek_statuses' === $action && $this->pek_statuses instanceof PekStatusAdminPage ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && PekSettings::SERVICE_KEY === $service->service_key ) {
					$this->pek_statuses->save_from_post( $_POST );
				}
			}
			if ( 'save_yandex_delivery_statuses' === $action && $this->yandex_status_mapping instanceof YandexStatusMapping ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $this->is_yandex_delivery_service( $service ) ) {
					$mapping = YandexStatusMapping::default_mapping();
					if ( empty( $_POST['yandex_delivery_statuses_reset'] ) && isset( $_POST[ YandexStatusMapping::MAPPING_KEY ] ) && is_array( $_POST[ YandexStatusMapping::MAPPING_KEY ] ) ) {
						$mapping = $this->yandex_status_mapping->sanitize_mapping( wp_unslash( $_POST[ YandexStatusMapping::MAPPING_KEY ] ) );
					}
					$this->yandex_status_mapping->save_mapping( $mapping );
				}
			}
			if ( 'save_tariffs' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->settings->set_setting( (int) $service->id, 'tariff_variants', $this->sanitize_domestic_tariff_variants_from_post(), 'json' );
				}
			}
			if ( 'save_cdek_tariffs' === $action && $this->cdek_tariffs instanceof CdekTariffRepository ) {
				$this->cdek_tariffs->save_admin_rows( $this->sanitize_cdek_tariffs_from_post() );
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
			}
			if ( 'save_dpd_runtime_tariffs' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->save_runtime_tariffs_from_admin( $_POST );
				$this->clear_delivery_quote_cache();
			}
			if ( 'bulk_cdek_tariffs' === $action && $this->cdek_tariffs instanceof CdekTariffRepository ) {
				$count = $this->handle_cdek_tariffs_bulk_action();
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
				$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
				if ( '' !== $service_key ) {
					wp_safe_redirect(
						add_query_arg(
							array(
								'wdc_cdek_tariffs_notice' => $count > 0 ? ( ( 'delete_all' === sanitize_key( wp_unslash( $_POST['cdek_tariffs_bulk_action'] ?? '' ) ) ) ? 'deleted' : 'updated' ) : 'unchanged',
								'wdc_cdek_tariffs_count' => max( 0, $count ),
							),
							$this->service_tab_url_by_key( $service_key, 'tariffs' )
						)
					);
					exit;
				}
			}
			if ( 'preview_cdek_tariffs_sync' === $action && $this->cdek_tariff_sync instanceof CdekTariffSyncService ) {
				try {
					$preview = $this->cdek_tariff_sync->preview();
					set_transient( $this->cdek_tariff_preview_transient_key(), $preview, defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				} catch ( CdekApiException $exception ) {
					set_transient(
						$this->cdek_tariff_preview_transient_key(),
						array( 'error' => 'Не удалось загрузить тарифы СДЭК: ' . $exception->getMessage() ),
						( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ) * 10
					);
				}
			}
			if ( 'confirm_cdek_tariffs_sync' === $action && $this->cdek_tariff_sync instanceof CdekTariffSyncService ) {
				$preview = get_transient( $this->cdek_tariff_preview_transient_key() );
				$rows = is_array( $preview ) && is_array( $preview['rows'] ?? null ) ? $preview['rows'] : array();
				if ( array() !== $rows ) {
					$this->cdek_tariff_sync->sync_rows( $rows );
				}
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
			}
			if ( in_array( $action, array( 'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import' ), true ) && $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
				$this->otpravka_settings->save_from_admin( $_POST );
				$this->save_russian_post_pickup_type_settings( $id );
				if ( 'run_russian_post_pickup_import' === $action && $this->pickup_importer instanceof RussianPostPickupImporter ) {
					$this->pickup_importer->queue_background_import( $this->otpravka_settings->unload_type() );
				}
				if ( in_array( $action, array( 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import' ), true ) && $this->pickup_importer instanceof RussianPostPickupImporter ) {
					$this->handle_russian_post_pickup_file_upload();
				}
			}
			if ( 'reset_russian_post_pickup_import' === $action && $this->pickup_importer instanceof RussianPostPickupImporter ) {
				$this->pickup_importer->reset_stale_or_running_import();
			}
			if ( 'save_api_credentials' === $action && $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
				$this->otpravka_settings->save_from_admin( $_POST );
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ) {
					$this->save_russian_post_domestic_api_settings( (int) $service->id );
				}
			}
			if ( 'save_cdek_settings' === $action && $this->cdek_settings instanceof CdekSettings ) {
				$this->cdek_api?->clearAllTokenCaches();
				$this->cdek_settings->save_from_admin( $_POST );
				$this->cdek_api?->clearAllTokenCaches();
			}
			if ( 'save_cdek_calculation' === $action && $this->cdek_settings instanceof CdekSettings ) {
				$this->cdek_settings->save_tariff_calculation_from_admin( $_POST );
				$this->clear_delivery_quote_cache();
			}
			if ( 'check_cdek_connection' === $action && $this->cdek_settings instanceof CdekSettings && $this->cdek_api instanceof CdekApiClient ) {
				try {
					$result = $this->cdek_api->checkConnection();
					$this->cdek_settings->save_connection_result( true, (string) $result['message'] );
				} catch ( CdekApiException $exception ) {
					$this->cdek_settings->save_connection_result( false, 'Не удалось подключиться к СДЭК: ' . $exception->getMessage() );
				}
			}
			if ( 'save_dpd_settings' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->save_from_admin( $_POST );
			}
			if ( 'check_dpd_connection' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_api instanceof DpdApiClient ) {
				$result = $this->dpd_api->checkConnectionDryRun();
				$this->dpd_settings->save_connection_result( (bool) $result['success'], (string) $result['message'] );
			}
			if ( 'save_yandex_delivery_settings' === $action && $this->yandex_delivery_settings instanceof YandexDeliverySettings ) {
				$this->yandex_delivery_settings->save_from_admin( $_POST );
			}
			if ( 'check_yandex_delivery_connection' === $action && $this->yandex_delivery_settings instanceof YandexDeliverySettings && $this->yandex_delivery_diagnostics instanceof YandexDeliveryConnectionDiagnosticService ) {
				$result = $this->yandex_delivery_diagnostics->checkPickupPoint();
				$this->yandex_delivery_settings->save_connection_result( (bool) $result['success'], (string) $result['message'] );
			}
			if ( 'save_dpd_geography_settings' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->save_geography_settings_from_admin( $_POST );
				$this->dpd_settings->save_connection_result( true, 'DPD geography settings saved.' );
				$this->save_dpd_geography_action_result( 'success', 'DPD Geography settings', 'DPD geography settings saved.', array() );
			}
			if ( 'run_dpd_geography_ftp_import' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_geography_ftp instanceof DpdGeographyFtpClient && $this->dpd_geography_importer instanceof DpdGeographyImportService ) {
				$state = $this->dpd_geography_importer->start_from_ftp( $this->dpd_geography_ftp );
				$this->dpd_settings->save_connection_result( $this->dpd_import_action_succeeded( $state ), 'DPD geography FTP import job: ' . (string) ( $state['last_message'] ?? '' ) );
				$this->save_dpd_import_action_result( 'DPD SFTP import', $state );
			}
			if ( 'upload_dpd_geography_csv_import' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_geography_importer instanceof DpdGeographyImportService ) {
				$upload = $_FILES['dpd_geography_csv'] ?? null;
				$state = is_array( $upload ) ? $this->dpd_geography_importer->start_from_uploaded_file( $upload ) : array( 'phase' => 'failed', 'last_message' => 'Не удалось загрузить CSV-файл географии DPD.' );
				$this->dpd_settings->save_connection_result( $this->dpd_import_action_succeeded( $state ), 'DPD geography manual import job: ' . (string) ( $state['last_message'] ?? '' ) );
				$this->save_dpd_import_action_result( 'DPD Geography import', $state );
			}
			if ( 'reset_dpd_geography_import' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_geography_importer instanceof DpdGeographyImportService ) {
				$state = $this->dpd_geography_importer->reset();
				$reset_busy = 'busy' === (string) ( $state['operation_control']['outcome'] ?? ( $state['step_control']['outcome'] ?? '' ) );
				$reset_failed = 'failed' === (string) ( $state['phase'] ?? '' ) || 'error' === (string) ( $state['status'] ?? '' );
				$this->dpd_settings->save_connection_result( ! $reset_busy && ! $reset_failed, 'DPD geography import reset: ' . (string) ( $state['last_message'] ?? '' ) );
				$this->save_dpd_geography_action_result(
					$reset_failed ? 'error' : ( $reset_busy ? 'warning' : 'info' ),
					'DPD Geography import reset',
					$reset_busy ? (string) ( $state['last_message'] ?? 'DPD geography import reset is busy.' ) : ( 'cancelled' === (string) ( $state['phase'] ?? '' ) ? 'Импорт и служебные данные сброшены.' : (string) ( $state['last_message'] ?? 'DPD geography import reset finished.' ) ),
					array(
						'phase' => (string) ( $state['phase'] ?? '' ),
						'message' => (string) ( $state['last_message'] ?? '' ),
					)
				);
			}
			if ( 'force_cancel_dpd_geography_import' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_geography_importer instanceof DpdGeographyImportService ) {
				$state = $this->dpd_geography_importer->force_cancel();
				$this->dpd_settings->save_connection_result( true, 'DPD geography import force cancel: ' . (string) ( $state['last_message'] ?? '' ) );
				$this->save_dpd_geography_action_result(
					'warning',
					'DPD Geography import force cancel',
					(string) ( $state['last_message'] ?? 'DPD geography import was force-cancelled.' ),
					array(
						'phase' => (string) ( $state['phase'] ?? '' ),
						'message' => (string) ( $state['last_message'] ?? '' ),
					)
				);
			}
			if ( in_array( $action, array( 'check_dpd_geography', 'save_dpd_city_mapping' ), true ) && $this->dpd_settings instanceof DpdSettings && $this->dpd_geography_diagnostics instanceof DpdGeographyDiagnosticService ) {
				$location_id = isset( $_POST['dpd_geography_location_id'] ) ? max( 0, (int) $_POST['dpd_geography_location_id'] ) : 0;
				$city_id = isset( $_POST['dpd_geography_city_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dpd_geography_city_id'] ) ) : '';
				$result = 'save_dpd_city_mapping' === $action
					? $this->dpd_geography_diagnostics->save_manual_mapping( $location_id, $city_id )
					: $this->dpd_geography_diagnostics->diagnose_location_id( $location_id );
				$this->dpd_settings->save_connection_result(
					(bool) $result['success'],
					sprintf(
						'DPD geography: %s cityId=%s source=%s saved=%s multiple=%s resolver=%s matched_by=%s',
						(string) $result['message'],
						(string) $result['city_id'],
						(string) $result['source'],
						$result['saved'] ? 'yes' : 'no',
						$result['multiple'] ? 'yes' : 'no',
						$result['resolver_applied'] ? 'yes' : 'no',
						implode( ',', $result['matched_by'] )
					)
				);
				$this->save_dpd_geography_action_result(
					(bool) $result['success'] ? 'success' : 'warning',
					'save_dpd_city_mapping' === $action ? 'DPD cityId manual mapping' : 'DPD geography diagnostic',
					(string) $result['message'],
					array(
						'location_id' => $location_id,
						'cityId' => (string) $result['city_id'],
						'source' => (string) $result['source'],
						'saved' => ! empty( $result['saved'] ) ? 'yes' : 'no',
						'multiple' => ! empty( $result['multiple'] ) ? 'yes' : 'no',
						'resolver' => ! empty( $result['resolver_applied'] ) ? 'yes' : 'no',
						'matched_by' => is_array( $result['matched_by'] ?? null ) ? $result['matched_by'] : array(),
						'message' => (string) $result['message'],
					)
				);
			}
			if ( 'test_dpd_dadata_fallback' === $action && $this->dpd_settings instanceof DpdSettings && $this->dpd_dadata_fallback instanceof DpdDaDataDeliveryFallbackService ) {
				$location_id = isset( $_POST['dpd_geography_location_id'] ) ? max( 0, (int) $_POST['dpd_geography_location_id'] ) : 0;
				$result = $this->dpd_dadata_fallback->resolve_location_id( $location_id );
				$this->dpd_settings->save_connection_result(
					(bool) $result['success'],
					sprintf(
						'DPD DaData delivery fallback: %s cityId=%s location_id=%d token_id=%s',
						(string) $result['message'],
						(string) $result['city_id'],
						(int) $result['location_id'],
						(string) $result['token_id']
					)
				);
				$this->save_dpd_geography_action_result(
					(bool) $result['success'] ? 'success' : 'error',
					'DPD DaData fallback',
					(string) $result['message'],
					array(
						'location_id' => (int) $result['location_id'],
						'dadata_result' => (bool) $result['success'] ? 'found' : 'not_found',
						'dpd_id' => (string) $result['city_id'],
						'saved' => (bool) $result['success'] ? 'yes' : 'no',
						'token_usage' => '' !== (string) $result['token_id'] ? 'incremented' : 'not_available',
						'token_id' => (string) $result['token_id'],
						'message' => (string) $result['message'],
					)
				);
			}
			if ( 'save_dpd_tariff_settings' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->save_tariff_settings_from_admin( $_POST );
				$this->dpd_settings->save_event_settings_from_admin( $_POST );
			}
			if ( 'save_dpd_pickup_autosync' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->save_pickup_autosync_settings_from_admin( $_POST );
				if ( $this->dpd_pickup_autosync instanceof DpdPickupPointAutoSync ) {
					$this->dpd_pickup_autosync->reschedule();
				}
				$this->dpd_settings->save_pickup_action_result(
					array(
						'type' => 'success',
						'title' => 'DPD ПВЗ autosync',
						'message' => 'Настройки автоматического обновления ПВЗ сохранены.',
						'details' => array( 'times' => $this->dpd_settings->pickup_autosync_times() ),
					)
				);
			}
			if ( in_array( $action, array( 'run_dpd_pickup_parcel_shops_import', 'run_dpd_pickup_terminals_import', 'run_dpd_pickup_all_import' ), true ) && $this->dpd_settings instanceof DpdSettings && $this->dpd_pickup_importer instanceof DpdPickupPointImportService ) {
				$report = match ( $action ) {
					'run_dpd_pickup_parcel_shops_import' => $this->dpd_pickup_importer->import_parcel_shops(),
					'run_dpd_pickup_terminals_import' => $this->dpd_pickup_importer->import_terminals_self_delivery(),
					default => $this->dpd_pickup_importer->import_all(),
				};
				$this->save_dpd_pickup_action_result( $report );
			}
			if ( 'reset_dpd_pickup_result' === $action && $this->dpd_settings instanceof DpdSettings ) {
				$this->dpd_settings->clear_pickup_action_result();
				$this->dpd_settings->save_pickup_import_report( array() );
			}
		}

		if ( 'toggle' === $action ) {
			$this->services->update_service( (int) $_POST['id'], array( 'enabled' => empty( $_POST['enabled'] ) ? 1 : 0 ) );
		}

		if ( 'delete' === $action ) {
			$service = $this->services->find_by_id( (int) $_POST['id'] );
			if ( ! $service instanceof DeliveryService || ! $this->services->is_predefined_service_key( $service->service_key ) ) {
				$this->services->soft_delete_service( (int) $_POST['id'] );
			}
		}

		if ( 'reorder' === $action ) {
			$this->services->reorder( array_map( 'intval', explode( ',', (string) wp_unslash( $_POST['ordered_ids'] ?? '' ) ) ) );
		}

		if ( 'copy_default_rules' === $action ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$service = $this->services->find_by_service_key( $service_key );
			if ( $service instanceof DeliveryService ) {
				$this->copy_default_rules_to_service( $service );
				wp_safe_redirect( $this->service_rules_url( $service, array( 'wdc_rules_notice' => 'copied' ) ) );
				exit;
			}
		}

		if ( in_array( $action, array(
			'save_main',
			'save_availability',
			'save_calculation',
			'save_tariffs',
			'save_cdek_tariffs',
			'bulk_cdek_tariffs',
			'preview_cdek_tariffs_sync',
			'confirm_cdek_tariffs_sync',
			'save_dpd_runtime_tariffs',
			'save_russian_post_pickup',
			'run_russian_post_pickup_import',
			'upload_russian_post_pickup_file_import',
			'upload_russian_post_pickup_zip_import',
			'reset_russian_post_pickup_import',
			'save_api_credentials',
			'save_shipments',
			'save_status_mapping',
			'save_cdek_statuses',
			'save_dpd_statuses',
			'save_pek_statuses',
			'save_yandex_delivery_statuses',
			'save_cdek_settings',
			'save_cdek_calculation',
			'check_cdek_connection',
			'save_dpd_settings',
			'check_dpd_connection',
			'save_dpd_geography_settings',
			'run_dpd_geography_ftp_import',
			'upload_dpd_geography_csv_import',
			'reset_dpd_geography_import',
			'force_cancel_dpd_geography_import',
			'check_dpd_geography',
			'save_dpd_city_mapping',
			'test_dpd_dadata_fallback',
			'save_dpd_tariff_settings',
			'save_dpd_pickup_autosync',
			'run_dpd_pickup_parcel_shops_import',
			'run_dpd_pickup_terminals_import',
			'run_dpd_pickup_all_import',
			'reset_dpd_pickup_result',
			'save_yandex_delivery_settings',
			'check_yandex_delivery_connection'
		), true ) ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$tab = match ( $action ) {
				'save_availability' => 'main',
				'save_calculation' => 'calculation',
				'save_tariffs', 'save_cdek_tariffs', 'bulk_cdek_tariffs', 'preview_cdek_tariffs_sync', 'confirm_cdek_tariffs_sync', 'save_dpd_runtime_tariffs' => 'tariffs',
				'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import', 'reset_russian_post_pickup_import' => 'russian_post_pickup',
				'save_api_credentials' => 'api_credentials',
				'save_shipments' => 'shipments',
				'save_status_mapping' => 'status_mapping',
				'save_cdek_statuses' => 'cdek_statuses',
				'save_dpd_statuses' => 'dpd_statuses',
				'save_pek_statuses' => PekStatusAdminPage::TAB_KEY,
				'save_cdek_settings', 'check_cdek_connection' => 'cdek_settings',
				'save_dpd_settings', 'check_dpd_connection' => 'dpd_settings',
				'save_yandex_delivery_settings', 'check_yandex_delivery_connection' => 'yandex_delivery_settings',
				'save_yandex_delivery_statuses' => 'yandex_delivery_statuses',
				'save_dpd_geography_settings', 'run_dpd_geography_ftp_import', 'upload_dpd_geography_csv_import', 'reset_dpd_geography_import', 'force_cancel_dpd_geography_import', 'check_dpd_geography', 'save_dpd_city_mapping', 'test_dpd_dadata_fallback' => 'dpd_geography',
				'save_dpd_tariff_settings' => 'dpd_tariff',
				'save_dpd_pickup_autosync', 'run_dpd_pickup_parcel_shops_import', 'run_dpd_pickup_terminals_import', 'run_dpd_pickup_all_import', 'reset_dpd_pickup_result' => 'dpd_pickup',
				'save_cdek_calculation' => 'calculation',
				default => 'main',
			};
			if ( '' !== $service_key ) {
				$redirect_url = $this->service_tab_url_by_key( $service_key, $tab );
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	private function handle_pek_action( string $action ): void {
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$service = PekSettings::SERVICE_KEY === $service_key ? $this->services->find_by_service_key( $service_key ) : null;
		if ( $service instanceof DeliveryService && PekSettings::SERVICE_KEY === $service->service_key && ( $id <= 0 || (int) $service->id === $id ) && $this->pek_admin instanceof PekAdminPage ) {
			$this->pek_admin->handle_action( $action, $service, $_POST );
		}

		wp_safe_redirect( $this->pek_settings_redirect_url() );
		exit;
	}

	private function pek_settings_redirect_url(): string {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'service' => PekSettings::SERVICE_KEY,
				'tab' => PekAdminPage::TAB_KEY,
			),
			admin_url( 'admin.php' )
		);
	}

	private function handle_jet_logistic_action( string $action ): void {
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
		$service = $this->services->find_by_service_key( $service_key );
		if ( ! $service instanceof DeliveryService || JetLogisticSettings::SERVICE_KEY !== $service->service_key ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		try {
			$result = match ( $action ) {
				'save_jet_settings' => $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ? $this->jet_logistic_geography->save_settings_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент управления географией Jet Logistic недоступен.' ),
				'check_jet_connection' => $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ? $this->jet_logistic_geography->check_connection() : array( 'success' => false, 'message' => 'Компонент диагностики Jet Logistic недоступен.' ),
				'import_jet_geography_remote' => $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ? $this->jet_logistic_geography->import_remote_csv() : array( 'success' => false, 'message' => 'Компонент управления географией Jet Logistic недоступен.' ),
				'import_jet_geography_csv' => $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ? $this->jet_logistic_geography->import_uploaded_csv( $_FILES ) : array( 'success' => false, 'message' => 'Компонент управления географией Jet Logistic недоступен.' ),
				'save_jet_geography_override' => $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ? $this->jet_logistic_geography->save_override_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент управления географией Jet Logistic недоступен.' ),
				'create_jet_status_mapping' => $this->jet_logistic_statuses instanceof JetLogisticStatusAdminPage ? $this->jet_logistic_statuses->create_mapping_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент управления статусами Jet Logistic недоступен.' ),
				'update_jet_status_mapping' => $this->jet_logistic_statuses instanceof JetLogisticStatusAdminPage ? $this->jet_logistic_statuses->update_mapping_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент управления статусами Jet Logistic недоступен.' ),
				'delete_jet_status_mapping' => $this->jet_logistic_statuses instanceof JetLogisticStatusAdminPage ? $this->jet_logistic_statuses->delete_mapping_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент управления статусами Jet Logistic недоступен.' ),
				'check_jet_tracking' => $this->jet_logistic_statuses instanceof JetLogisticStatusAdminPage ? $this->jet_logistic_statuses->check_tracking_from_post( $_POST ) : array( 'success' => false, 'message' => 'Компонент диагностики статусов Jet Logistic недоступен.' ),
				default => array( 'success' => false, 'message' => 'Неизвестное административное действие Jet Logistic.' ),
			};
			if ( in_array( $action, array( 'save_jet_settings', 'import_jet_geography_remote', 'import_jet_geography_csv', 'save_jet_geography_override' ), true ) ) {
				$this->clear_delivery_quote_cache();
			}
		} catch ( \Throwable $exception ) {
			$result = array(
				'success' => false,
				'message' => 'import_jet_geography_remote' === $action
					? $exception->getMessage() . ' Можно загрузить файл вручную ниже.'
					: $this->jet_action_failure_message( $action ),
			);
		}

		$this->store_jet_admin_notice( $this->jet_notice_from_result( $result ) );

		$tab = match ( $action ) {
			'create_jet_status_mapping', 'update_jet_status_mapping', 'delete_jet_status_mapping', 'check_jet_tracking' => 'jet_statuses',
			default => 'jet_geography',
		};
		wp_safe_redirect( $this->jet_logistic_redirect_url( $action, $tab ) );
		exit;
	}

	private function jet_logistic_redirect_url( string $action, string $tab ): string {
		$args = array(
			'page' => self::MENU_SLUG,
			'service' => JetLogisticSettings::SERVICE_KEY,
			'tab' => $tab,
		);
		if ( 'jet_geography' === $tab ) {
			$args['jet_page'] = 'save_jet_geography_override' === $action ? $this->jet_positive_post_int( 'jet_page', 1 ) : 1;
			$args['jet_per_page'] = $this->jet_per_page_from_post();
		}

		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	private function jet_positive_post_int( string $key, int $default ): int {
		return max( 1, (int) ( $_POST[ $key ] ?? $default ) );
	}

	private function jet_per_page_from_post(): int {
		$per_page = (int) ( $_POST['jet_per_page'] ?? 100 );
		return in_array( $per_page, array( 25, 50, 100, 200 ), true ) ? $per_page : 100;
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private function jet_notice_from_result( array $result ): array {
		$details = is_array( $result['stats'] ?? null ) ? $result['stats'] : ( is_array( $result['details'] ?? null ) ? $result['details'] : array() );
		foreach ( array( 'checked_at', 'token_state', 'endpoint', 'method', 'http_status', 'api_response', 'code', 'tracking_number' ) as $diagnostic_key ) {
			if ( array_key_exists( $diagnostic_key, $result ) ) {
				$details[ $diagnostic_key ] = $result[ $diagnostic_key ];
			}
		}
		foreach ( array( 'rows_read', 'rows_unique', 'duplicates', 'duplicate_conflicts', 'legacy_identity_conflicts', 'legacy_override_migration_failures' ) as $counter_key ) {
			if ( array_key_exists( $counter_key, $result ) ) {
				$details = array( $counter_key => $result[ $counter_key ] ) + $details;
			}
		}

		return array(
			'type' => ! empty( $result['success'] ) ? 'success' : 'error',
			'message' => sanitize_text_field( (string) ( $result['message'] ?? ( ! empty( $result['success'] ) ? 'Операция Jet Logistic успешно выполнена.' : 'Не удалось выполнить операцию Jet Logistic.' ) ) ),
			'details' => $this->sanitize_jet_notice_details( $details, isset( $result['rows_unique'] ) ? 0 : (int) ( $result['rows'] ?? 0 ) ),
		);
	}

	private function jet_action_failure_message( string $action ): string {
		return match ( $action ) {
			'save_jet_settings' => 'Не удалось сохранить настройки Jet Logistic.',
			'check_jet_connection' => 'Не удалось проверить подключение Jet Logistic.',
			'import_jet_geography_csv' => 'Не удалось импортировать загруженный файл cities.csv.',
			'save_jet_geography_override' => 'Не удалось применить ручное сопоставление Jet Logistic.',
			'create_jet_status_mapping', 'update_jet_status_mapping' => 'Не удалось сохранить сопоставление статуса Jet Logistic.',
			'delete_jet_status_mapping' => 'Не удалось удалить сопоставление статуса Jet Logistic.',
			'check_jet_tracking' => 'Не удалось проверить номер груза Jet Logistic.',
			default => 'Не удалось выполнить операцию Jet Logistic.',
		};
	}

	/** @param array<string,mixed> $details @return array<string,mixed> */
	private function sanitize_jet_notice_details( array $details, int $rows ): array {
		$safe = array();
		if ( $rows > 0 ) {
			$safe['rows'] = $rows;
		}
		foreach ( $details as $key => $value ) {
			if ( is_scalar( $value ) && ! in_array( (string) $key, array( 'access_token', 'token', 'csv', 'body', 'response' ), true ) ) {
				$safe[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return $safe;
	}

	/** @param array<string,mixed> $notice */
	private function store_jet_admin_notice( array $notice ): void {
		set_transient( $this->jet_admin_notice_key(), $notice, 120 );
	}

	/** @return array<string,mixed> */
	private function consume_jet_admin_notice(): array {
		$key = $this->jet_admin_notice_key();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : array();
	}

	private function jet_admin_notice_key(): string {
		return 'wdc_jet_admin_notice_' . max( 0, (int) get_current_user_id() );
	}

	private function handle_russian_post_pickup_file_upload(): void {
		if ( ! $this->pickup_importer instanceof RussianPostPickupImporter ) {
			return;
		}
		$file = $_FILES['russian_post_pickup_file'] ?? ( $_FILES['russian_post_pickup_zip'] ?? null );
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->pickup_import_state?->failed(
				array(
					'source' => 'uploaded_file',
					'type' => $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ? $this->otpravka_settings->unload_type() : 'ALL',
					'errors' => array( 'Не удалось загрузить файл импорта ПВЗ или файл не выбран.' ),
				)
			);
			return;
		}

		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'russian-post-passport.zip' ) );
		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'zip', 'txt', 'json' ), true ) ) {
			$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Only ZIP, TXT, or JSON files are allowed for Russian Post pickup import.' ) ) );
			return;
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( function_exists( 'wp_check_filetype_and_ext' ) && '' !== $tmp_name ) {
			$checked = wp_check_filetype_and_ext( $tmp_name, $original_name, array( 'zip' => 'application/zip', 'txt' => 'text/plain', 'json' => 'application/json' ) );
			if ( empty( $checked['ext'] ) || ! in_array( (string) $checked['ext'], array( 'zip', 'txt', 'json' ), true ) ) {
				$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Uploaded file failed ZIP/TXT/JSON type validation.' ) ) );
				return;
			}
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . 'wdc-imports' : sys_get_temp_dir();
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $base_dir );
		} elseif ( ! is_dir( $base_dir ) ) {
			@mkdir( $base_dir, 0755, true );
		}
		$filename = function_exists( 'wp_unique_filename' ) ? wp_unique_filename( $base_dir, $original_name ) : uniqid( 'russian-post-passport-', true ) . '.zip';
		$target = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR . $filename;
		$moved = '' !== $tmp_name && is_uploaded_file( $tmp_name ) ? move_uploaded_file( $tmp_name, $target ) : ( '' !== $tmp_name && is_file( $tmp_name ) && @rename( $tmp_name, $target ) );
		if ( ! $moved || ! is_file( $target ) ) {
			$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Unable to store uploaded pickup import file.' ) ) );
			return;
		}

		$type = $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ? $this->otpravka_settings->unload_type() : 'ALL';
		$queued = 'zip' === $extension
			? $this->pickup_importer->queue_background_import_from_zip( $target, $type, $original_name )
			: $this->pickup_importer->queue_background_import_from_payload( $target, $type, $original_name );
		if ( ! $queued ) {
			$target_size = is_file( $target ) ? (int) filesize( $target ) : 0;
			if ( is_file( $target ) ) {
				if ( function_exists( 'wp_delete_file' ) ) {
					wp_delete_file( $target );
				} else {
					@unlink( $target );
				}
			}
			$this->pickup_import_state?->failed(
				array(
					'source' => 'zip' === $extension ? 'uploaded_zip' : 'uploaded_payload',
					'temp_zip_file' => 'zip' === $extension ? $target : '',
					'payload_file' => 'zip' === $extension ? '' : $target,
					'payload_size' => 'zip' === $extension ? 0 : $target_size,
					'original_upload_name' => $original_name,
					'uploaded_file_size' => $target_size,
					'errors' => array( 'Unable to queue pickup import. Another import may be running.' ),
				)
			);
		}
	}

	private function handle_russian_post_pickup_zip_upload(): void {
		$this->handle_russian_post_pickup_file_upload();
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$service_key = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		$service = '' !== $service_key ? $this->services->find_by_service_key( $service_key ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Службы доставки', 'walls-delivery-calc' ); ?></h1>
			<?php if ( $service instanceof DeliveryService ) : ?>
				<?php $this->render_edit_page( $service ); ?>
			<?php else : ?>
				<?php $this->render_table(); ?>
				<?php $this->render_create_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_table(): void {
		$services = $this->services->list_active();
		?>
		<form method="post" style="margin: 16px 0;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="reorder">
			<input type="hidden" name="ordered_ids" value="<?php echo esc_attr( implode( ',', array_map( static fn ( DeliveryService $service ): int => (int) $service->id, $services ) ) ); ?>">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Статус', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Название', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Тип', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Carrier', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Availability', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Countries', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Rules', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Округление', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Minimum', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Sort', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Действия', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $services as $service ) : ?>
						<tr>
							<td><?php echo esc_html( $service->enabled ? __( 'включена', 'walls-delivery-calc' ) : __( 'выключена', 'walls-delivery-calc' ) ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&service=' . rawurlencode( $service->service_key ) ) ); ?>"><?php echo esc_html( $service->title ); ?></a></td>
							<td><?php echo esc_html( $service->service_type ); ?></td>
							<td><?php echo esc_html( $service->carrier_key ); ?></td>
							<td><?php echo esc_html( $this->availability_mode_label( $service->availability_mode ) ); ?></td>
							<td><?php echo esc_html( $this->countries_summary( $service ) ); ?></td>
							<td><?php echo esc_html( $service->use_default_rules_when_no_service_rules ? __( 'own/default', 'walls-delivery-calc' ) : __( 'own only', 'walls-delivery-calc' ) ); ?></td>
							<td><?php echo esc_html( $service->round_up_to_ruble ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
							<td><?php echo esc_html( (string) $service->minimum_price_rub ); ?></td>
							<td><?php echo esc_html( (string) $service->sort_order ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
									<input type="hidden" name="wdc_delivery_services_action" value="toggle">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
									<input type="hidden" name="enabled" value="<?php echo esc_attr( $service->enabled ? '1' : '0' ); ?>">
									<button class="button"><?php echo esc_html( $service->enabled ? __( 'Выключить', 'walls-delivery-calc' ) : __( 'Включить', 'walls-delivery-calc' ) ); ?></button>
								</form>
								<?php if ( ! $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
									<input type="hidden" name="wdc_delivery_services_action" value="delete">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
									<button class="button button-link-delete"><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
								</form>
								<?php else : ?>
									<span class="description"><?php echo esc_html__( 'Системная служба', 'walls-delivery-calc' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php $this->render_global_delivery_settings_form(); ?>
		<?php
	}

	private function render_global_delivery_settings_form(): void {
		$value = $this->global_settings instanceof SettingsRepository ? $this->global_settings->shop_processing_working_days() : 2;
		?>
		<form method="post" style="max-width: 760px; margin: 20px 0;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_global_delivery_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wdc_shop_processing_working_days"><?php echo esc_html__( 'Рабочих дней на обработку заказа магазином', 'walls-delivery-calc' ); ?></label></th>
					<td>
						<input id="wdc_shop_processing_working_days" class="small-text" type="number" min="0" max="365" name="<?php echo esc_attr( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
						<p class="description"><?php echo esc_html__( 'Текущий день не учитывается. Рабочие и выходные дни определяются по «Календарю магазина».', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Сохранить настройки сроков', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_edit_page( DeliveryService $service ): void {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'main';
		$tabs = array(
			'main' => 'Основное',
			'calculation' => 'Расчет',
			'rules' => 'Правила',
		);
		if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) {
			$tabs['russian_post_countries'] = 'Страны Почты России';
		}
		if ( $this->is_domestic_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
			$tabs['russian_post_pickup'] = 'ПВЗ / ОПС';
			$tabs[ RussianPostPickupDiagnosticsTab::TAB_KEY ] = 'Диагностика базы ПВЗ';
			$tabs['api_credentials'] = 'Данные для входа';
			$tabs['shipments'] = 'Отправления';
			$tabs['status_mapping'] = 'Статусы / Mapping';
			$tabs['diagnostics'] = 'Диагностика';
		}
		if ( $this->is_cdek_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
			$tabs['cdek_settings'] = 'Данные для входа';
			$tabs['cdek_statuses'] = 'Статусы СДЭК';
		}
		if ( $this->is_yandex_delivery_service( $service ) ) {
			$tabs['yandex_delivery_settings'] = 'Данные для входа';
			$tabs['yandex_delivery_pickup'] = 'Яндекс ПВЗ/география';
			$tabs['yandex_delivery_statuses'] = 'Яндекс.Доставка';
		}
		if ( $this->is_dpd_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
			$tabs['dpd_settings'] = 'Данные для входа';
			$tabs['dpd_geography'] = 'DPD География';
			$tabs['dpd_pickup'] = 'DPD ПВЗ';
			$tabs['dpd_tariff'] = 'DPD Расчет';
			$tabs['dpd_statuses'] = 'Статусы DPD';
		}
		if ( JetLogisticSettings::SERVICE_KEY === $service->service_key ) {
			$tabs['jet_geography'] = 'География';
			$tabs['jet_statuses'] = 'Статусы';
		}
		if ( PekSettings::SERVICE_KEY === $service->service_key ) {
			$tabs[ PekAdminPage::TAB_KEY ] = 'ПЭК';
			$tabs[ PekStatusAdminPage::TAB_KEY ] = 'Статусы ПЭК';
		}
		?>
		<h2><?php echo esc_html( $service->title ); ?></h2>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_key => $tab ) : ?>
				<a class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&service=' . rawurlencode( $service->service_key ) . '&tab=' . rawurlencode( $tab_key ) ) ); ?>"><?php echo esc_html( $tab ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		match ( $current_tab ) {
			'calculation' => $this->render_calculation_tab( $service ),
			'rules' => $this->render_rules_tab( $service ),
			'tariffs' => $this->render_tariffs_tab( $service ),
			'russian_post_pickup' => $this->render_russian_post_pickup_tab( $service ),
			RussianPostPickupDiagnosticsTab::TAB_KEY => $this->render_russian_post_pickup_diagnostics_tab(),
			'api_credentials' => $this->render_api_credentials_tab( $service ),
			'shipments' => $this->render_shipments_tab( $service ),
			'status_mapping' => $this->render_status_mapping_tab( $service ),
			'diagnostics' => $this->render_diagnostics_tab( $service ),
			'cdek_settings' => $this->render_cdek_settings_tab( $service ),
			'dpd_settings' => $this->render_dpd_settings_tab( $service ),
			'yandex_delivery_settings' => $this->render_yandex_delivery_settings_tab( $service ),
			'yandex_delivery_pickup' => $this->render_yandex_delivery_pickup_v2_tab( $service ),
			'yandex_delivery_statuses' => $this->render_yandex_delivery_statuses_tab( $service ),
			'dpd_geography' => $this->render_dpd_geography_tab( $service ),
			'dpd_pickup' => $this->render_dpd_pickup_tab( $service ),
			'dpd_tariff' => $this->render_dpd_tariff_tab( $service ),
			'dpd_statuses' => $this->render_dpd_statuses_tab( $service ),
			'cdek_statuses' => $this->render_cdek_statuses_tab( $service ),
			'russian_post_countries' => $this->render_russian_post_countries_tab( $service ),
			'jet_geography' => $this->render_jet_geography_tab( $service ),
			'jet_statuses' => $this->render_jet_statuses_tab( $service ),
			PekAdminPage::TAB_KEY => $this->render_pek_settings_tab( $service ),
			PekStatusAdminPage::TAB_KEY => $this->render_pek_statuses_tab( $service ),
			default => $this->render_main_tab( $service ),
		};
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Назад к списку', 'walls-delivery-calc' ); ?></a></p>
		<?php
	}

	private function render_main_tab( DeliveryService $service ): void {
		$domestic = $this->is_domestic_service( $service ) ? $this->russian_post_domestic_values( $service ) : array();
		$cdek = CdekSettings::SERVICE_KEY === $service->service_key ? $this->cdek_main_values( $service ) : array();
		$yandex_delivery = $this->is_yandex_delivery_service( $service ) ? $this->yandex_delivery_main_values( $service ) : array();
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_main">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<table class="form-table" role="presentation">
				<?php if ( $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
					<?php $this->readonly_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ); ?>
					<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php else : ?>
					<?php $this->text_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ); ?>
				<?php endif; ?>
				<?php $this->text_row( 'title', __( 'Название', 'walls-delivery-calc' ), $service->title ); ?>
				<?php if ( $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
					<?php $this->readonly_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ); ?>
					<input type="hidden" name="carrier_key" value="<?php echo esc_attr( $service->carrier_key ); ?>">
				<?php else : ?>
					<?php $this->text_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ); ?>
				<?php endif; ?>
				<?php $this->select_row( 'service_type', __( 'Тип', 'walls-delivery-calc' ), $service->service_type, array( DeliveryService::TYPE_API, DeliveryService::TYPE_FIXED, DeliveryService::TYPE_WEIGHT_BASED ) ); ?>
				<?php $this->text_row( 'sort_order', __( 'Sort order', 'walls-delivery-calc' ), (string) $service->sort_order ); ?>
				<?php $this->checkbox_row( 'enabled', __( 'Включена', 'walls-delivery-calc' ), $service->enabled ); ?>
				<?php $this->checkbox_row( 'use_default_rules_when_no_service_rules', __( 'Fallback на default rules', 'walls-delivery-calc' ), $service->use_default_rules_when_no_service_rules ); ?>
				<?php $this->checkbox_row( DeliveryServiceSettingsRepository::DELIVERY_DAYS_ARE_WORKING_KEY, __( 'Служба доставки даёт срок в рабочих днях. Нужно пересчитать в календарные', 'walls-delivery-calc' ), null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ? $this->settings->delivery_days_are_working( (int) $service->id ) : false ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Доступность', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->select_assoc_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode, $this->availability_mode_options() ); ?>
				<?php if ( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $service->availability_mode ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Справочник перевозчика', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html__( 'Доступность определяется справочником перевозчика.', 'walls-delivery-calc' ); ?> <?php if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) : ?><a class="button" href="<?php echo esc_url( $this->service_tab_url( $service, 'russian_post_countries' ) ); ?>"><?php echo esc_html__( 'Открыть страны Почты России', 'walls-delivery-calc' ); ?></a><?php endif; ?></td></tr>
				<?php endif; ?>
				<?php if ( $this->is_domestic_service( $service ) ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></th><td><code>RU</code><p class="description"><?php echo esc_html__( 'Почта России по РФ доступна только для России.', 'walls-delivery-calc' ); ?></p><input type="hidden" name="countries" value="RU"></td></tr>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'pickup_method_title', __( 'Название варианта до ПВЗ / ОПС', 'walls-delivery-calc' ), (string) ( $domestic['pickup_method_title'] ?? RussianPostDomesticSettings::PICKUP_SERVICE_TITLE ) ); ?>
					<?php $this->text_row( 'courier_method_title', __( 'Название варианта курьером', 'walls-delivery-calc' ), (string) ( $domestic['courier_method_title'] ?? RussianPostDomesticSettings::COURIER_SERVICE_TITLE ) ); ?>
				<?php elseif ( CdekSettings::SERVICE_KEY === $service->service_key ) : ?>
					<?php $this->render_cdek_country_checkboxes( $service ); ?>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'pickup_method_title', __( 'Название варианта до пункта выдачи', 'walls-delivery-calc' ), (string) ( $cdek['pickup_method_title'] ?? CdekSettings::DEFAULT_PICKUP_METHOD_TITLE ) ); ?>
					<?php $this->text_row( 'courier_method_title', __( 'Название варианта курьером', 'walls-delivery-calc' ), (string) ( $cdek['courier_method_title'] ?? CdekSettings::DEFAULT_COURIER_METHOD_TITLE ) ); ?>
				<?php elseif ( $this->is_yandex_delivery_service( $service ) ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></th><td><code>RU</code><p class="description"><?php echo esc_html__( 'Яндекс Доставка на текущем этапе доступна только для России.', 'walls-delivery-calc' ); ?></p><input type="hidden" name="countries" value="RU"></td></tr>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY, __( 'Название варианта до пункта выдачи', 'walls-delivery-calc' ), (string) ( $yandex_delivery[YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY] ?? YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE ) ); ?>
					<?php $this->text_row( YandexDeliverySettings::COURIER_METHOD_TITLE_KEY, __( 'Название варианта курьером', 'walls-delivery-calc' ), (string) ( $yandex_delivery[YandexDeliverySettings::COURIER_METHOD_TITLE_KEY] ?? YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE ) ); ?>
				<?php elseif ( $this->is_dpd_service( $service ) && $this->dpd_settings instanceof DpdSettings ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></th><td><code>RU</code><p class="description"><?php echo esc_html__( 'DPD на текущем этапе доступен только для России.', 'walls-delivery-calc' ); ?></p><input type="hidden" name="countries" value="RU"></td></tr>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( DpdSettings::RUNTIME_PICKUP_TITLE_KEY, __( 'Название варианта до пункта выдачи', 'walls-delivery-calc' ), $this->dpd_settings->runtime_pickup_title() ); ?>
					<?php $this->text_row( DpdSettings::RUNTIME_COURIER_TITLE_KEY, __( 'Название варианта курьером', 'walls-delivery-calc' ), $this->dpd_settings->runtime_courier_title() ); ?>
				<?php elseif ( in_array( $service->availability_mode, array( DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ), true ) ) : ?>
					<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), implode( ',', $this->countries->countries( (int) $service->id ) ) ); ?>
				<?php else : ?>
					<input type="hidden" name="countries" value="<?php echo esc_attr( implode( ',', $this->countries->countries( (int) $service->id ) ) ); ?>">
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Сохранить службу', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_calculation_tab( DeliveryService $service ): void {
		$rp = RussianPostSettings::SERVICE_KEY === $service->service_key ? $this->russian_post_values( $service ) : array();
		$domestic = $this->is_domestic_service( $service ) ? $this->russian_post_domestic_values( $service ) : array();
		$yandex_delivery = $this->is_yandex_delivery_service( $service ) ? $this->yandex_delivery_calculation_values( $service ) : array();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_calculation">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->checkbox_row( 'round_up_to_ruble', __( 'Округлять вверх до рубля', 'walls-delivery-calc' ), $service->round_up_to_ruble ); ?>
				<?php $this->text_row( 'minimum_price_rub', __( 'Минимальная цена, руб.', 'walls-delivery-calc' ), (string) $service->minimum_price_rub ); ?>
				<?php $this->checkbox_row( 'include_packaging_weight', __( 'Учитывать вес упаковки', 'walls-delivery-calc' ), $service->include_packaging_weight ); ?>
				<?php $this->select_assoc_row( 'packaging_weight_mode', __( 'Способ учета веса упаковки', 'walls-delivery-calc' ), $service->packaging_weight_mode, $this->packaging_weight_mode_options() ); ?>
				<?php $this->textarea_row( 'pickup_customer_comment', __( 'Комментарий для покупателя — доставка до ПВЗ', 'walls-delivery-calc' ), $service->pickup_customer_comment ); ?>
				<?php $this->textarea_row( 'courier_customer_comment', __( 'Комментарий для покупателя — курьерская доставка', 'walls-delivery-calc' ), $service->courier_customer_comment ); ?>
				<?php if ( $this->is_domestic_service( $service ) ) : ?>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Почта России по РФ', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'rp_from_postcodes', __( 'Индекс отправки для расчета доставки', 'walls-delivery-calc' ), implode( ',', is_array( $domestic['from_postcodes'] ?? null ) ? $domestic['from_postcodes'] : array() ) ); ?>
					<?php $this->text_row( 'rp_return_postcode', __( 'Индекс возврата для расчета доставки', 'walls-delivery-calc' ), (string) ( $domestic['return_postcode'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_insurance_enabled', __( 'Использовать тарифы с объявленной ценностью', 'walls-delivery-calc' ), ! empty( $domestic['insurance_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_timeout', __( 'Таймаут API, сек', 'walls-delivery-calc' ), (string) ( $domestic['timeout'] ?? 20 ) ); ?>
					<?php $this->text_row( 'rp_vat_rate', __( 'Ставка НДС', 'walls-delivery-calc' ), (string) ( $domestic['vat_rate'] ?? 0.2 ) ); ?>
					<?php $this->checkbox_row( 'rp_fallback_enabled', __( 'Fallback', 'walls-delivery-calc' ), ! empty( $domestic['fallback_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_fallback_text', __( 'Fallback text', 'walls-delivery-calc' ), (string) ( $domestic['fallback_text'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_cache_until_end_of_day', __( 'Кэш до конца дня', 'walls-delivery-calc' ), ! empty( $domestic['cache_until_end_of_day'] ) ); ?>
					<?php $this->checkbox_row( 'rp_debug', __( 'Debug Почты России', 'walls-delivery-calc' ), ! empty( $domestic['debug'] ) ); ?>
				<?php endif; ?>
				<?php if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) : ?>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Почта России', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'rp_api_endpoint', __( 'API endpoint тарифа', 'walls-delivery-calc' ), (string) ( $rp['api_endpoint'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_country_endpoint', __( 'API endpoint стран', 'walls-delivery-calc' ), (string) ( $rp['country_endpoint'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_origin_postcode', __( 'Индекс отправления', 'walls-delivery-calc' ), (string) ( $rp['origin_postcode'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_object_code', __( 'Код объекта отправления', 'walls-delivery-calc' ), (string) ( $rp['object_code'] ?? 4031 ) ); ?>
					<?php $this->select_row( 'rp_isavia', __( 'Авиадоставка', 'walls-delivery-calc' ), (string) ( $rp['isavia'] ?? 0 ), array( '0', '1' ) ); ?>
					<?php $this->text_row( 'rp_timeout', __( 'Таймаут API, сек', 'walls-delivery-calc' ), (string) ( $rp['timeout'] ?? 20 ) ); ?>
					<?php $this->text_row( 'rp_vat_rate', __( 'Ставка НДС', 'walls-delivery-calc' ), (string) ( $rp['vat_rate'] ?? 0.2 ) ); ?>
					<?php $this->text_row( 'rp_max_package_weight_g', __( 'Максимальный вес сервиса, г', 'walls-delivery-calc' ), (string) ( $rp['max_package_weight_g'] ?? 19990 ) ); ?>
					<?php $this->checkbox_row( 'rp_fallback_enabled', __( 'Fallback', 'walls-delivery-calc' ), ! empty( $rp['fallback_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_fallback_text', __( 'Fallback text', 'walls-delivery-calc' ), (string) ( $rp['fallback_text'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_cache_until_end_of_day', __( 'Кэш до конца дня', 'walls-delivery-calc' ), ! empty( $rp['cache_until_end_of_day'] ) ); ?>
					<?php $this->checkbox_row( 'rp_auto_refresh_countries_if_empty', __( 'Автообновление стран, если пусто', 'walls-delivery-calc' ), ! empty( $rp['auto_refresh_countries_if_empty'] ) ); ?>
					<?php $this->checkbox_row( 'rp_debug', __( 'Debug Почты России', 'walls-delivery-calc' ), ! empty( $rp['debug'] ) ); ?>
				<?php endif; ?>
				<?php if ( $this->is_yandex_delivery_service( $service ) ) : ?>
					<?php $this->render_yandex_delivery_source_station_rows( $yandex_delivery ); ?>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Сохранить расчет', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php if ( $this->is_cdek_service( $service ) && $this->cdek_settings instanceof CdekSettings ) : ?>
		<form method="post" style="max-width: 860px; margin-top: 16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_calculation">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->render_cdek_tariff_calculation_rows(); ?>
			</table>
			<?php submit_button( __( 'Сохранить расчет СДЭК', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php endif; ?>
		<?php
	}


	private function render_yandex_delivery_source_station_rows( array $values ): void {
		$selected_station_id = $this->sanitize_yandex_platform_station_id( (string) ( $values[ YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ] ?? '' ) );
		$selected_location_id = max( 0, (int) ( $values[ YandexDeliverySettings::SOURCE_LOCATION_ID_KEY ] ?? 0 ) );
		$selected_point = '' !== $selected_station_id && $this->yandex_delivery_pickup_v2_repository instanceof YandexDeliveryPickupPointV2Repository
			? $this->yandex_delivery_pickup_v2_repository->find( $selected_station_id )
			: null;
		$selected_address = is_array( $selected_point ) ? trim( (string) ( $selected_point['full_address'] ?? '' ) ) : '';
		$selected_location = $selected_location_id > 0 && $this->locations instanceof LocationRepository ? $this->locations->find_by_id( $selected_location_id ) : null;
		$source_data = $this->yandex_delivery_source_points_for_location( $selected_location_id );
		$points = $source_data['points'];
		$yandex_geo_ids = $source_data['yandex_geo_ids'];
		$point_found = is_array( $selected_point );
		$point_available = $point_found && ! empty( $selected_point['active'] ) && ! empty( $selected_point['available_for_dropoff'] );
		$ajax_nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wdc_yandex_delivery_source_station' ) : '';
		?>
		<tr><th colspan="2"><h3><?php echo esc_html__( 'Точка сдачи отправлений Яндекс.Доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
		<?php if ( ! $this->locations instanceof LocationRepository || ! $this->yandex_location_mapping_v2_repository instanceof YandexLocationMappingV2Repository || ! $this->yandex_delivery_pickup_v2_repository instanceof YandexDeliveryPickupPointV2Repository ) : ?>
			<tr><th scope="row"><?php echo esc_html__( 'ПВЗ сдачи', 'walls-delivery-calc' ); ?></th><td><div class="notice notice-warning inline"><p><?php echo esc_html__( 'Локальные справочники населенных пунктов, маппинга или ПВЗ Яндекс.Доставки недоступны.', 'walls-delivery-calc' ); ?></p></div></td></tr>
		<?php else : ?>
			<tr>
				<th scope="row"><label for="yandex_delivery_source_location_search"><?php echo esc_html__( 'Город / населенный пункт сдачи', 'walls-delivery-calc' ); ?></label></th>
				<td>
					<input id="yandex_delivery_source_location_search" class="regular-text" type="text" placeholder="<?php echo esc_attr__( 'Начните вводить город', 'walls-delivery-calc' ); ?>">
					<button type="button" class="button" data-wdc-yandex-source-location-search><?php echo esc_html__( 'Найти', 'walls-delivery-calc' ); ?></button>
					<span class="spinner" data-wdc-yandex-source-spinner></span>
					<p class="description"><?php echo esc_html__( 'Населенный пункт выбирается из локального справочника WDC. По нему берутся связанные yandex_geo_id из завершенного location mapping.', 'walls-delivery-calc' ); ?></p>
					<select id="<?php echo esc_attr( YandexDeliverySettings::SOURCE_LOCATION_ID_KEY ); ?>" name="<?php echo esc_attr( YandexDeliverySettings::SOURCE_LOCATION_ID_KEY ); ?>" style="width:100%;max-width:720px;margin-top:8px;">
						<option value="0"><?php echo esc_html__( 'Не выбран', 'walls-delivery-calc' ); ?></option>
						<?php if ( null !== $selected_location ) : ?>
							<option value="<?php echo esc_attr( (string) $selected_location_id ); ?>" selected><?php echo esc_html( $this->yandex_delivery_location_label( $selected_location ) ); ?></option>
						<?php endif; ?>
					</select>
					<p class="description" data-wdc-yandex-source-status><?php echo esc_html( $selected_location_id > 0 ? $this->yandex_delivery_source_points_message( $selected_location_id, $yandex_geo_ids, $points ) : __( 'Выберите населенный пункт, чтобы загрузить ПВЗ сдачи.', 'walls-delivery-calc' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ); ?>"><?php echo esc_html__( 'ПВЗ сдачи', 'walls-delivery-calc' ); ?></label></th>
				<td>
					<label for="yandex_delivery_source_point_filter"><?php echo esc_html__( 'Фильтр ПВЗ по адресу', 'walls-delivery-calc' ); ?></label><br>
					<input id="yandex_delivery_source_point_filter" class="regular-text" type="text" placeholder="<?php echo esc_attr__( 'Введите минимум 3 символа адреса', 'walls-delivery-calc' ); ?>" style="width:100%;max-width:720px;margin-bottom:8px;">
					<p class="description" data-wdc-yandex-source-point-filter-message style="display:none;"><?php echo esc_html__( 'По фильтру ПВЗ не найдены.', 'walls-delivery-calc' ); ?></p>
					<select id="<?php echo esc_attr( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ); ?>" name="<?php echo esc_attr( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ); ?>" style="width:100%;max-width:720px;">
						<option value=""><?php echo esc_html__( 'Не выбран', 'walls-delivery-calc' ); ?></option>
						<?php foreach ( $points as $point ) : ?>
							<?php
							$station_id = $this->sanitize_yandex_platform_station_id( (string) ( $point['platform_station_id'] ?? '' ) );
							$address = trim( (string) ( $point['full_address'] ?? '' ) );
							?>
							<?php if ( '' !== $station_id ) : ?>
								<option value="<?php echo esc_attr( $station_id ); ?>" data-address="<?php echo esc_attr( $address ); ?>" <?php selected( $selected_station_id, $station_id ); ?>><?php echo esc_html( $this->yandex_delivery_source_point_label( $point ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<?php if ( $selected_location_id > 0 && array() === $yandex_geo_ids ) : ?>
						<p class="description"><?php echo esc_html__( 'Для выбранного населенного пункта нет связанных yandex_geo_id в location mapping.', 'walls-delivery-calc' ); ?></p>
					<?php elseif ( $selected_location_id > 0 && array() === $points ) : ?>
						<p class="description"><?php echo esc_html__( 'Для связанных yandex_geo_id нет активных ПВЗ, доступных для сдачи отправлений.', 'walls-delivery-calc' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
		<tr><th scope="row"><?php echo esc_html__( 'Сохраненный platform_station_id', 'walls-delivery-calc' ); ?></th><td><code><?php echo esc_html( '' !== $selected_station_id ? $selected_station_id : __( 'не выбран', 'walls-delivery-calc' ) ); ?></code></td></tr>
		<tr>
			<th scope="row"><label for="yandex_delivery_source_station_address"><?php echo esc_html__( 'Адрес выбранного ПВЗ', 'walls-delivery-calc' ); ?></label></th>
			<td><input id="yandex_delivery_source_station_address" class="large-text" type="text" readonly value="<?php echo esc_attr( '' !== $selected_address ? $selected_address : __( 'не выбран', 'walls-delivery-calc' ) ); ?>"></td>
		</tr>
		<?php if ( '' !== $selected_station_id && ! $point_found ) : ?>
			<tr><th scope="row"><?php echo esc_html__( 'Предупреждение', 'walls-delivery-calc' ); ?></th><td><div class="notice notice-warning inline"><p><?php echo esc_html__( 'Сохраненный platform_station_id не найден в локальной базе ПВЗ после последнего импорта. Настройка сохранена, но адрес сейчас недоступен.', 'walls-delivery-calc' ); ?></p></div></td></tr>
		<?php elseif ( '' !== $selected_station_id && ! $point_available ) : ?>
			<tr><th scope="row"><?php echo esc_html__( 'Предупреждение', 'walls-delivery-calc' ); ?></th><td><div class="notice notice-warning inline"><p><?php echo esc_html__( 'Сохраненный ПВЗ сдачи найден, но сейчас не активен или недоступен для сдачи отправлений. Выберите другой ПВЗ.', 'walls-delivery-calc' ); ?></p></div></td></tr>
		<?php endif; ?>
		<script>
		(function () {
			var ajaxUrl = window.ajaxurl || '';
			var nonce = '<?php echo esc_js( $ajax_nonce ); ?>';
			var search = document.getElementById('yandex_delivery_source_location_search');
			var searchButton = document.querySelector('[data-wdc-yandex-source-location-search]');
			var locationSelect = document.getElementById('<?php echo esc_js( YandexDeliverySettings::SOURCE_LOCATION_ID_KEY ); ?>');
			var pointSelect = document.getElementById('<?php echo esc_js( YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ); ?>');
			var pointFilter = document.getElementById('yandex_delivery_source_point_filter');
			var pointFilterMessage = document.querySelector('[data-wdc-yandex-source-point-filter-message]');
			var status = document.querySelector('[data-wdc-yandex-source-status]');
			var spinner = document.querySelector('[data-wdc-yandex-source-spinner]');
			var address = document.getElementById('yandex_delivery_source_station_address');
			if (!ajaxUrl || !nonce || !search || !searchButton || !locationSelect || !pointSelect) {
				return;
			}
			function setBusy(busy) {
				if (spinner) {
					spinner.classList.toggle('is-active', !!busy);
				}
			}
			function post(data) {
				data.append('action', 'wdc_yandex_delivery_source_station');
				data.append('nonce', nonce);
				setBusy(true);
				return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) { return response.json(); }).finally(function () { setBusy(false); });
			}
			function option(value, text) {
				var item = document.createElement('option');
				item.value = value;
				item.textContent = text;
				return item;
			}
			function applyPointFilter() {
				if (!pointFilter) {
					return;
				}
				var query = (pointFilter.value || '').toLocaleLowerCase();
				var useFilter = query.length >= 3;
				var found = 0;
				Array.prototype.forEach.call(pointSelect.options, function (item) {
					if (item.value === '') {
						item.hidden = false;
						return;
					}
					var addressText = (item.getAttribute('data-address') || '').toLocaleLowerCase();
					var visible = !useFilter || addressText.indexOf(query) !== -1;
					item.hidden = !visible;
					if (visible) {
						found += 1;
					}
				});
				if (pointFilterMessage) {
					pointFilterMessage.style.display = useFilter && found === 0 ? '' : 'none';
				}
			}
			function loadPoints(locationId) {
				var data = new FormData();
				data.append('mode', 'points');
				data.append('location_id', locationId);
				return post(data).then(function (resp) {
					var payload = resp && resp.success ? resp.data : {};
					pointSelect.innerHTML = '';
					pointSelect.appendChild(option('', '<?php echo esc_js( __( 'Не выбран', 'walls-delivery-calc' ) ); ?>'));
					(payload.points || []).forEach(function (point) {
						var item = option(point.platform_station_id, point.label);
						item.setAttribute('data-address', point.address || '');
						pointSelect.appendChild(item);
					});
					if (address) {
						address.value = '<?php echo esc_js( __( 'не выбран', 'walls-delivery-calc' ) ); ?>';
					}
					if (status) {
						status.textContent = payload.message || '';
					}
					applyPointFilter();
				});
			}
			searchButton.addEventListener('click', function () {
				var data = new FormData();
				data.append('mode', 'locations');
				data.append('query', search.value || '');
				post(data).then(function (resp) {
					var payload = resp && resp.success ? resp.data : {};
					locationSelect.innerHTML = '';
					locationSelect.appendChild(option('0', '<?php echo esc_js( __( 'Не выбран', 'walls-delivery-calc' ) ); ?>'));
					(payload.locations || []).forEach(function (location) {
						locationSelect.appendChild(option(String(location.id), location.label));
					});
					if (status) {
						status.textContent = payload.message || '';
					}
				});
			});
			locationSelect.addEventListener('change', function () {
				loadPoints(locationSelect.value || '0');
			});
			if (pointFilter) {
				pointFilter.addEventListener('input', applyPointFilter);
				applyPointFilter();
			}
			pointSelect.addEventListener('change', function () {
				var selected = pointSelect.options[pointSelect.selectedIndex];
				if (address) {
					address.value = selected && selected.getAttribute('data-address') ? selected.getAttribute('data-address') : '<?php echo esc_js( __( 'не выбран', 'walls-delivery-calc' ) ); ?>';
				}
			});
		}());
		</script>
		<?php
	}

	private function render_russian_post_countries_tab( DeliveryService $service ): void {
		if ( RussianPostSettings::SERVICE_KEY !== $service->service_key || ! $this->russian_post_countries instanceof RussianPostCountriesAdminPage ) {
			return;
		}

		$this->russian_post_countries->render_embedded( $this->service_tab_url( $service, 'russian_post_countries' ) );
	}

	private function render_api_credentials_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) || ! $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$domestic = $this->russian_post_domestic_values( $service );
		$postoffice_codes = $this->otpravka_settings->postoffice_codes();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_api_credentials">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3>Tariff API</h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'rp_api_endpoint', __( 'Tariff API endpoint', 'walls-delivery-calc' ), (string) ( $domestic['api_endpoint'] ?? '' ) ); ?>
				<?php $this->text_row( 'rp_api_token', __( 'Tariff API token, если выдан Почтой', 'walls-delivery-calc' ), (string) ( $domestic['api_token'] ?? '' ) ); ?>
			</table>
			<h3>Otpravka API</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">AccessToken</th><td><input class="regular-text" type="password" name="russian_post_otpravka_access_token" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_access_token() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_access_token" value="1"> <?php echo esc_html__( 'очистить сохраненный AccessToken', 'walls-delivery-calc' ); ?></label></td></tr>
				<tr><th scope="row"><label for="russian_post_otpravka_login"><?php echo esc_html__( 'Логин', 'walls-delivery-calc' ); ?></label></th><td><input class="regular-text" id="russian_post_otpravka_login" name="russian_post_otpravka_login" value="<?php echo esc_attr( (string) ( $values[ RussianPostOtpravkaApiSettings::LOGIN_KEY ] ?? '' ) ); ?>"></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Пароль', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="password" name="russian_post_otpravka_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label></td></tr>
				<?php $this->text_row( 'russian_post_otpravka_timeout', __( 'Таймаут API, сек.', 'walls-delivery-calc' ), (string) ( $values[ RussianPostOtpravkaApiSettings::TIMEOUT_KEY ] ?? 120 ) ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Индексы места приема для регистрации отправлений', 'walls-delivery-calc' ); ?></th>
					<td>
						<textarea class="large-text" rows="3" name="<?php echo esc_attr( RussianPostOtpravkaApiSettings::POSTOFFICE_CODES_KEY ); ?>"><?php echo esc_textarea( implode( "\n", $postoffice_codes ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Один индекс на строку или через запятую; используются в модалке отправления как postoffice-code и не смешиваются с индексами расчета тарифа.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( 'rp_default_from_postcode', __( 'Индекс отправления по умолчанию', 'walls-delivery-calc' ), (string) ( $domestic['default_from_postcode'] ?? '' ) ); ?>
			</table>
			<h3>Tracking API</h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row( RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY, __( 'Tracking login', 'walls-delivery-calc' ), (string) ( $values[ RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY ] ?? '' ) ); ?>
				<tr><th scope="row"><?php echo esc_html__( 'Tracking password', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="password" name="russian_post_tracking_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_tracking_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_tracking_clear_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_cdek_settings_tab( DeliveryService $service ): void {
		if ( ! $this->is_cdek_service( $service ) || ! $this->cdek_settings instanceof CdekSettings ) {
			return;
		}
		$test_credentials = $this->cdek_settings->credentials_for_environment( CdekSettings::ENV_TEST );
		$production_credentials = $this->cdek_settings->credentials_for_environment( CdekSettings::ENV_PRODUCTION );
		$token_status = $this->cdek_settings->credentials_are_complete()
			? __( 'Данные для активной среды заполнены, token cache будет создан после проверки подключения.', 'walls-delivery-calc' )
			: __( 'Данные для активной среды не заполнены.', 'walls-delivery-calc' );
		$last_check = $this->cdek_settings->last_connection_check();
		$last_status = $this->cdek_settings->last_connection_status();
		$last_message = $this->cdek_settings->last_connection_message();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Данные для входа СДЭК', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->select_assoc_row( CdekSettings::ENVIRONMENT_KEY, __( 'Среда', 'walls-delivery-calc' ), $this->cdek_settings->environment(), array( CdekSettings::ENV_TEST => 'Тестовая', CdekSettings::ENV_PRODUCTION => 'Рабочая' ) ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Тестовая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( CdekSettings::TEST_ACCOUNT_KEY, __( 'Account / client_id', 'walls-delivery-calc' ), $test_credentials->account ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Secure password / client_secret', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="cdek_test_secure_password" value="" placeholder="<?php echo esc_attr( $this->cdek_settings->has_secure_password( CdekSettings::ENV_TEST ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="cdek_clear_test_secure_password" value="1"> <?php echo esc_html__( 'очистить сохраненный Secure password тестовой среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Рабочая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( CdekSettings::PRODUCTION_ACCOUNT_KEY, __( 'Account / client_id', 'walls-delivery-calc' ), $production_credentials->account ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Secure password / client_secret', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="cdek_production_secure_password" value="" placeholder="<?php echo esc_attr( $this->cdek_settings->has_secure_password( CdekSettings::ENV_PRODUCTION ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="cdek_clear_production_secure_password" value="1"> <?php echo esc_html__( 'очистить сохраненный Secure password рабочей среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Проверка подключения', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->readonly_row( 'cdek_active_environment', __( 'Активная среда', 'walls-delivery-calc' ), $this->cdek_settings->environment_label() ); ?>
				<?php $this->readonly_row( 'cdek_token_cache_status', __( 'Token cache status', 'walls-delivery-calc' ), $token_status ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_CHECK_KEY, __( 'Последняя проверка подключения', 'walls-delivery-calc' ), '' !== $last_check ? $last_check : __( 'не выполнялась', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_STATUS_KEY, __( 'Статус последней проверки', 'walls-delivery-calc' ), '' !== $last_status ? $last_status : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_MESSAGE_KEY, __( 'Сообщение последней проверки', 'walls-delivery-calc' ), '' !== $last_message ? $last_message : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_cdek_connection">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Проверить подключение', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_yandex_delivery_settings_tab( DeliveryService $service ): void {
		if ( ! $this->is_yandex_delivery_service( $service ) || ! $this->yandex_delivery_settings instanceof YandexDeliverySettings ) {
			return;
		}
		$test_credentials = $this->yandex_delivery_settings->credentials_for_environment( YandexDeliverySettings::ENV_TEST );
		$production_credentials = $this->yandex_delivery_settings->credentials_for_environment( YandexDeliverySettings::ENV_PRODUCTION );
		$diagnostic_status = $this->yandex_delivery_settings->credentials_are_complete()
			? __( 'Данные для активной среды заполнены. Проверка выполняется только по кнопке администратора.', 'walls-delivery-calc' )
			: __( 'Данные для активной среды не заполнены.', 'walls-delivery-calc' );
		$last_check = $this->yandex_delivery_settings->last_connection_check();
		$last_status = $this->yandex_delivery_settings->last_connection_status();
		$last_message = $this->yandex_delivery_settings->last_connection_message();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_yandex_delivery_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Данные для входа Яндекс.Доставки', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->select_assoc_row( YandexDeliverySettings::ENVIRONMENT_KEY, __( 'Среда', 'walls-delivery-calc' ), $this->yandex_delivery_settings->environment(), array( YandexDeliverySettings::ENV_TEST => 'Тестовая', YandexDeliverySettings::ENV_PRODUCTION => 'Рабочая' ) ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Тестовая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Bearer token', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="yandex_delivery_test_bearer_token" value="" placeholder="<?php echo esc_attr( $this->yandex_delivery_settings->has_bearer_token( YandexDeliverySettings::ENV_TEST ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="yandex_delivery_clear_test_bearer_token" value="1"> <?php echo esc_html__( 'очистить сохраненный Bearer token тестовой среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный токен. Токен хранится только в зашифрованном виде.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY, __( 'platform_station_id точки отправления', 'walls-delivery-calc' ), $test_credentials->platform_station_id ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Рабочая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Bearer token', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="yandex_delivery_production_bearer_token" value="" placeholder="<?php echo esc_attr( $this->yandex_delivery_settings->has_bearer_token( YandexDeliverySettings::ENV_PRODUCTION ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="yandex_delivery_clear_production_bearer_token" value="1"> <?php echo esc_html__( 'очистить сохраненный Bearer token рабочей среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный токен. Токен не выводится обратно в HTML.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( YandexDeliverySettings::PRODUCTION_PLATFORM_STATION_ID_KEY, __( 'platform_station_id точки отправления', 'walls-delivery-calc' ), $production_credentials->platform_station_id ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Транспорт и диагностика', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( YandexDeliverySettings::REQUEST_TIMEOUT_KEY, __( 'Таймаут запроса, сек.', 'walls-delivery-calc' ), (string) $this->yandex_delivery_settings->request_timeout() ); ?>
				<?php $this->checkbox_row( YandexDeliverySettings::DEBUG_KEY, __( 'Отладочное логирование', 'walls-delivery-calc' ), $this->yandex_delivery_settings->debug_enabled() ); ?>
				<?php $this->readonly_row( 'yandex_delivery_active_environment', __( 'Активная среда', 'walls-delivery-calc' ), $this->yandex_delivery_settings->environment_label() ); ?>
				<?php $this->readonly_row( 'yandex_delivery_diagnostic_status', __( 'Статус диагностики', 'walls-delivery-calc' ), $diagnostic_status ); ?>
				<?php $this->readonly_row( YandexDeliverySettings::LAST_CONNECTION_CHECK_KEY, __( 'Последняя проверка подключения', 'walls-delivery-calc' ), '' !== $last_check ? $last_check : __( 'не выполнялась', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( YandexDeliverySettings::LAST_CONNECTION_STATUS_KEY, __( 'Статус последней проверки', 'walls-delivery-calc' ), '' !== $last_status ? $last_status : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( YandexDeliverySettings::LAST_CONNECTION_MESSAGE_KEY, __( 'Сообщение последней проверки', 'walls-delivery-calc' ), '' !== $last_message ? $last_message : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_yandex_delivery_connection">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Проверить подключение', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
	private function handle_yandex_geo_pipeline_v2_schedule_action(): void {
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? YandexDeliverySettings::SERVICE_KEY ) );
		if ( $this->yandex_delivery_geo_pipeline_v2_runner instanceof YandexDeliveryGeoPipelineV2Runner ) {
			$enabled = ! empty( $_POST['yandex_geo_pipeline_schedule_enabled'] );
			$days = isset( $_POST['yandex_geo_pipeline_schedule_days'] ) && is_array( $_POST['yandex_geo_pipeline_schedule_days'] ) ? array_map( static fn( mixed $value ): int => (int) $value, $_POST['yandex_geo_pipeline_schedule_days'] ) : array();
			$time = sanitize_text_field( wp_unslash( $_POST['yandex_geo_pipeline_schedule_time'] ?? '03:00' ) );
			$this->yandex_delivery_geo_pipeline_v2_runner->save_schedule_settings( $enabled, $days, $time );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'service' => $service_key, 'tab' => 'yandex_delivery_pickup' ), admin_url( 'admin.php' ) ) );
		exit;
	}
	private function handle_yandex_region_mapping_v2_action( string $action ): void {
		if ( ! $this->yandex_region_mapping_v2_repository instanceof YandexRegionMappingV2Repository ) {
			return;
		}
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? YandexDeliverySettings::SERVICE_KEY ) );
		if ( 'sync_yandex_region_mapping_v2' === $action ) {
			$report = $this->yandex_region_mapping_v2_repository->sync_from_sources();
			$this->save_yandex_region_mapping_v2_result( 'success', 'Сопоставление регионов Яндекса', 'Список регионов обновлен.', $report );
		} elseif ( 'save_yandex_region_mapping_v2' === $action ) {
			$yandex_region = sanitize_text_field( wp_unslash( $_POST['yandex_region'] ?? '' ) );
			$selected = isset( $_POST['wdc_region_names'] ) && is_array( $_POST['wdc_region_names'] ) ? array_map( static fn( mixed $value ): string => sanitize_text_field( wp_unslash( $value ) ), $_POST['wdc_region_names'] ) : array( sanitize_text_field( wp_unslash( $_POST['wdc_region_name'] ?? '' ) ) );
			$report = $this->yandex_region_mapping_v2_repository->save_mapping( $yandex_region, $selected );
			$this->save_yandex_region_mapping_v2_result( 'success', 'Сопоставление регионов Яндекса', 'Сопоставление сохранено.', array_merge( array( 'yandex_region' => $yandex_region ), $report ) );
		} elseif ( in_array( $action, array( 'save_yandex_location_manual_override_v2', 'deactivate_yandex_location_manual_override_v2' ), true ) ) {
			$this->handle_yandex_location_manual_override_v2_action( $action );
			return;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'service' => $service_key, 'tab' => 'yandex_delivery_pickup' ), admin_url( 'admin.php' ) ) );
		exit;
	}


	private function handle_yandex_location_manual_override_v2_action( string $action ): void {
		if ( ! $this->yandex_location_manual_override_v2_repository instanceof YandexLocationManualOverrideV2Repository ) {
			return;
		}
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? YandexDeliverySettings::SERVICE_KEY ) );
		if ( 'save_yandex_location_manual_override_v2' === $action ) {
			$geo_id = isset( $_POST['yandex_geo_id'] ) ? (int) $_POST['yandex_geo_id'] : 0;
			$region = sanitize_text_field( wp_unslash( $_POST['yandex_region'] ?? '' ) );
			$locality = sanitize_text_field( wp_unslash( $_POST['yandex_locality'] ?? '' ) );
			$location_id = isset( $_POST['location_id'] ) ? (int) $_POST['location_id'] : 0;
			$report = $this->yandex_location_manual_override_v2_repository->upsert_active_override( $geo_id, $region, $locality, $location_id );
			$this->save_yandex_region_mapping_v2_result( 1 === (int) ( $report['saved'] ?? 0 ) ? 'success' : 'error', 'Ручной override Яндекс mapping v2', 1 === (int) ( $report['saved'] ?? 0 ) ? 'Override сохранен.' : 'Override не сохранен.', array_merge( array( 'yandex_geo_id' => $geo_id, 'location_id' => $location_id ), $report ) );
		} elseif ( 'deactivate_yandex_location_manual_override_v2' === $action ) {
			$id = isset( $_POST['override_id'] ) ? (int) $_POST['override_id'] : 0;
			$ok = $this->yandex_location_manual_override_v2_repository->deactivate_override( $id );
			$this->save_yandex_region_mapping_v2_result( $ok ? 'success' : 'error', 'Ручной override Яндекс mapping v2', $ok ? 'Override отключен.' : 'Override не найден.', array( 'override_id' => $id ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'service' => $service_key, 'tab' => 'yandex_delivery_pickup' ), admin_url( 'admin.php' ) ) );
		exit;
	}
	private function save_yandex_region_mapping_v2_result( string $type, string $title, string $message, array $details ): void {
		if ( $this->yandex_delivery_settings instanceof YandexDeliverySettings ) {
			$this->yandex_delivery_settings->save_pickup_action_result( array( 'type' => $type, 'title' => $title, 'message' => $message, 'details' => $details ) );
		}
	}

	/** @param array<int,array<string,mixed>> $rows @param array<int,string> $wdc_regions */
	private function render_yandex_region_mapping_v2_section( DeliveryService $service, array $rows, array $wdc_regions ): void {
		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ (string) ( $row['yandex_region'] ?? '' ) ][] = $row;
		}
		?>
		<h3><?php echo esc_html__( 'Сопоставление регионов Яндекса', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="margin-bottom: 12px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="sync_yandex_region_mapping_v2" />
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>" />
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Обновить список регионов', 'walls-delivery-calc' ); ?></button>
		</form>
		<table class="widefat striped" style="max-width: 1120px;">
			<thead><tr><th><?php echo esc_html__( 'Регион Яндекса', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Регион WDC', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Требует проверки', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Действие', 'walls-delivery-calc' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $grouped as $yandex_region => $region_rows ) : ?>
					<?php $selected_regions = array_values( array_filter( array_map( static fn( array $row ): string => (string) ( $row['wdc_region_name'] ?? '' ), $region_rows ), static fn( string $value ): bool => '' !== $value ) ); ?>
					<tr>
						<td><?php echo esc_html( $yandex_region ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
								<input type="hidden" name="wdc_delivery_services_action" value="save_yandex_region_mapping_v2" />
								<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>" />
								<input type="hidden" name="yandex_region" value="<?php echo esc_attr( $yandex_region ); ?>" />
								<select name="wdc_region_names[]" multiple size="4" style="min-width: 320px;">
									<option value="" <?php selected( array() === $selected_regions ); ?>><?php echo esc_html__( 'Не выбран', 'walls-delivery-calc' ); ?></option>
									<?php foreach ( $wdc_regions as $wdc_region ) : ?>
										<option value="<?php echo esc_attr( $wdc_region ); ?>" <?php selected( in_array( $wdc_region, $selected_regions, true ) ); ?>><?php echo esc_html( $wdc_region ); ?></option>
									<?php endforeach; ?>
								</select>
						</td>
						<td><?php echo esc_html( in_array( 1, array_map( static fn( array $row ): int => (int) ( $row['needs_review'] ?? 0 ), $region_rows ), true ) ? 'Да' : 'Нет' ); ?></td>
						<td><button type="submit" class="button"><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button></form></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $grouped ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'Нет данных. Нажмите «Обновить список регионов».', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_yandex_delivery_pickup_v2_tab( DeliveryService $service ): void {
		if ( ! $this->is_yandex_delivery_service( $service ) ) {
			return;
		}
		$state = $this->yandex_delivery_pickup_v2_runner instanceof YandexDeliveryPickupPointV2RunnerService ? $this->yandex_delivery_pickup_v2_runner->current_state() : array();
		$stats = $this->yandex_delivery_pickup_v2_repository instanceof YandexDeliveryPickupPointV2Repository ? array(
			'total' => $this->yandex_delivery_pickup_v2_repository->count_all(),
			'active' => $this->yandex_delivery_pickup_v2_repository->count_active(),
			'unique_geo_ids' => $this->yandex_delivery_pickup_v2_repository->count_unique_geo_ids(),
			'by_type' => $this->yandex_delivery_pickup_v2_repository->count_by_type(),
			'latest_seen_at' => $this->yandex_delivery_pickup_v2_repository->latest_seen_at(),
		) : array();
		$geo_v2_state = $this->yandex_delivery_geo_v2_builder_runner instanceof YandexDeliveryGeoV2BuilderRunnerService ? $this->yandex_delivery_geo_v2_builder_runner->current_state() : array();
		$geo_v2_stats = $this->yandex_delivery_geo_v2_repository instanceof YandexDeliveryGeoV2Repository ? $this->yandex_delivery_geo_v2_repository->statistics() : array();
		$location_mapping_v2_state = $this->yandex_location_mapping_v2_runner instanceof YandexLocationMappingV2Runner ? $this->yandex_location_mapping_v2_runner->current_state() : array();
		$geo_v2_region_enrichment_state = $this->yandex_geo_v2_region_enrichment_runner instanceof YandexGeoV2RegionEnrichmentRunner ? $this->yandex_geo_v2_region_enrichment_runner->current_state() : array();
		$location_mapping_v2_stats = $this->yandex_location_mapping_v2_repository instanceof YandexLocationMappingV2Repository ? $this->yandex_location_mapping_v2_repository->statistics() : array();
		$location_mapping_v2_no_match = $this->yandex_location_mapping_v2_repository instanceof YandexLocationMappingV2Repository ? $this->yandex_location_mapping_v2_repository->find_recent_no_match( 20 ) : array();
		$location_mapping_v2_review_items = $this->yandex_location_mapping_v2_repository instanceof YandexLocationMappingV2Repository ? $this->yandex_location_mapping_v2_repository->find_recent_review_items( 20 ) : array();
		$location_manual_overrides_v2 = $this->yandex_location_manual_override_v2_repository instanceof YandexLocationManualOverrideV2Repository ? $this->yandex_location_manual_override_v2_repository->list_active( 20 ) : array();
		$region_mapping_v2_rows = $this->yandex_region_mapping_v2_repository instanceof YandexRegionMappingV2Repository ? $this->yandex_region_mapping_v2_repository->list_rows() : array();
		$region_mapping_v2_wdc_regions = $this->yandex_region_mapping_v2_repository instanceof YandexRegionMappingV2Repository ? $this->yandex_region_mapping_v2_repository->list_wdc_regions() : array();
		$geo_pipeline_v2_state = $this->yandex_delivery_geo_pipeline_v2_runner instanceof YandexDeliveryGeoPipelineV2Runner ? $this->yandex_delivery_geo_pipeline_v2_runner->current_state() : array();
		$geo_pipeline_v2_schedule = $this->yandex_delivery_geo_pipeline_v2_runner instanceof YandexDeliveryGeoPipelineV2Runner ? $this->yandex_delivery_geo_pipeline_v2_runner->schedule_settings() : array( 'enabled' => false, 'days' => array(), 'time' => '03:00', 'next_run' => '' );
		?>
		<h3><?php echo esc_html__( 'Яндекс ПВЗ/география', 'walls-delivery-calc' ); ?></h3>
		<p class="description" style="max-width: 960px;"><?php echo esc_html__( 'Запрос выполняется без type и geo_id, чтобы получить все точки, которые отдаёт Яндекс. Сырой JSON сохраняется во временный файл и удаляется после успешного импорта.', 'walls-delivery-calc' ); ?></p>
		<form method="post" style="max-width: 1120px; margin: 16px 0; padding: 12px; border: 1px solid #dcdcde; background: #fff;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_yandex_geo_pipeline_v2_schedule" />
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>" />
			<h3><?php echo esc_html__( 'Расписание полного обновления', 'walls-delivery-calc' ); ?></h3>
			<p><label><input type="checkbox" name="yandex_geo_pipeline_schedule_enabled" value="1" <?php checked( ! empty( $geo_pipeline_v2_schedule['enabled'] ) ); ?> /> <?php echo esc_html__( 'Включить автоматическое обновление', 'walls-delivery-calc' ); ?></label></p>
			<p><?php echo esc_html__( 'Дни запуска', 'walls-delivery-calc' ); ?>:<br>
				<?php foreach ( $this->yandex_geo_pipeline_schedule_days() as $day => $label ) : ?>
					<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="yandex_geo_pipeline_schedule_days[]" value="<?php echo esc_attr( (string) $day ); ?>" <?php checked( in_array( $day, (array) ( $geo_pipeline_v2_schedule['days'] ?? array() ), true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</p>
			<p><label><?php echo esc_html__( 'Время запуска', 'walls-delivery-calc' ); ?> <input type="time" name="yandex_geo_pipeline_schedule_time" value="<?php echo esc_attr( (string) ( $geo_pipeline_v2_schedule['time'] ?? '03:00' ) ); ?>" /></label></p>
			<p class="description"><?php echo esc_html__( 'Все время указывается по Москве (GMT+3), независимо от часового пояса сайта и сервера.', 'walls-delivery-calc' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Текущий статус расписания', 'walls-delivery-calc' ); ?>: <?php echo ! empty( $geo_pipeline_v2_schedule['enabled'] ) ? esc_html__( 'включено', 'walls-delivery-calc' ) : esc_html__( 'выключено', 'walls-delivery-calc' ); ?>; <?php echo esc_html__( 'следующий запуск', 'walls-delivery-calc' ); ?>: <code><?php echo esc_html( (string) ( $geo_pipeline_v2_schedule['next_run'] ?? '' ) ?: '—' ); ?></code></p>
			<?php submit_button( __( 'Сохранить расписание', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
		</form>		<div id="wdc-yandex-delivery-geo-pipeline-v2" data-wdc-yandex-geo-pipeline-v2 data-wdc-yandex-geo-pipeline-v2-status="<?php echo esc_attr( (string) ( $geo_pipeline_v2_state['status'] ?? 'idle' ) ); ?>">
			<h3><?php echo esc_html__( 'Полное обновление Яндекс ПВЗ/географии', 'walls-delivery-calc' ); ?></h3>
			<p>
				<button type="button" class="button button-primary" data-wdc-yandex-geo-pipeline-v2-start><?php echo esc_html__( 'Запустить полное обновление', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-geo-pipeline-v2-pause><?php echo esc_html__( 'Пауза', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-geo-pipeline-v2-resume><?php echo esc_html__( 'Продолжить', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button button-secondary" data-wdc-yandex-geo-pipeline-v2-reset><?php echo esc_html__( 'Сбросить состояние', 'walls-delivery-calc' ); ?></button>
			</p>
			<div class="notice notice-info inline" style="max-width: 1120px;">
				<p data-wdc-yandex-geo-pipeline-v2-summary><?php echo esc_html( (string) ( $geo_pipeline_v2_state['status'] ?? 'idle' ) . ': ' . (string) ( $geo_pipeline_v2_state['message'] ?? '' ) ); ?></p>
				<table class="widefat striped" style="max-width: 1120px;">
					<tbody>
						<?php foreach ( $this->yandex_delivery_geo_pipeline_v2_state_rows() as $key => $label ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-wdc-yandex-geo-pipeline-v2-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->yandex_delivery_pickup_v2_state_value( $geo_pipeline_v2_state, $key ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<h4><?php echo esc_html__( 'Сводка стадий', 'walls-delivery-calc' ); ?></h4>
				<pre data-wdc-yandex-geo-pipeline-v2-summary-json style="white-space: pre-wrap; max-width: 1080px;"><?php echo esc_html( wp_json_encode( $geo_pipeline_v2_state['summary'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ?: '{}' ); ?></pre>
			</div>
		</div>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Импорт ПВЗ', 'walls-delivery-calc' ); ?></summary>
		<div id="wdc-yandex-delivery-pickup-v2-runner" data-wdc-yandex-pickup-v2-runner data-wdc-yandex-v2-status="<?php echo esc_attr( (string) ( $state['status'] ?? 'idle' ) ); ?>">
			<p>
				<button type="button" class="button button-primary" data-wdc-yandex-v2-start><?php echo esc_html__( 'Скачать и импортировать полный список', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-v2-continue><?php echo esc_html__( 'Продолжить импорт', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-v2-pause><?php echo esc_html__( 'Пауза', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button button-secondary" data-wdc-yandex-v2-reset><?php echo esc_html__( 'Сбросить состояние', 'walls-delivery-calc' ); ?></button>
			</p>
			<div class="notice notice-info inline" style="max-width: 1120px;">
				<p data-wdc-yandex-v2-summary><?php echo esc_html( (string) ( $state['status'] ?? 'idle' ) . ': ' . (string) ( $state['message'] ?? '' ) ); ?></p>
				<table class="widefat striped" style="max-width: 1120px;">
					<tbody>
						<?php foreach ( $this->yandex_delivery_pickup_v2_state_rows() as $key => $label ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-wdc-yandex-v2-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->yandex_delivery_pickup_v2_state_value( $state, $key ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<h3><?php echo esc_html__( 'Статистика БД', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr><th scope="row"><?php echo esc_html__( 'Всего точек в БД', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $stats['total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Активных', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $stats['active'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Уникальных geoId', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $stats['unique_geo_ids'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Типы точек', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( wp_json_encode( $stats['by_type'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Последнее обновление', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $stats['latest_seen_at'] ?? '' ) ); ?></td></tr>
			</tbody>
		</table>
		</details>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Построение geo_v2', 'walls-delivery-calc' ); ?></summary>
		<h3><?php echo esc_html__( 'Агрегация geoId v2', 'walls-delivery-calc' ); ?></h3>
		<div id="wdc-yandex-delivery-geo-v2-builder" data-wdc-yandex-geo-v2-builder data-wdc-yandex-geo-v2-status="<?php echo esc_attr( (string) ( $geo_v2_state['status'] ?? 'idle' ) ); ?>">
			<p>
				<button type="button" class="button button-primary" data-wdc-yandex-geo-v2-start><?php echo esc_html__( 'Построить geoId v2', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-geo-v2-pause><?php echo esc_html__( 'Пауза geoId v2', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button button-secondary" data-wdc-yandex-geo-v2-reset><?php echo esc_html__( 'Сбросить geoId v2', 'walls-delivery-calc' ); ?></button>
			</p>
			<div class="notice notice-info inline" style="max-width: 1120px;">
				<p data-wdc-yandex-geo-v2-summary><?php echo esc_html( (string) ( $geo_v2_state['status'] ?? 'idle' ) . ': ' . (string) ( $geo_v2_state['message'] ?? '' ) ); ?></p>
				<table class="widefat striped" style="max-width: 1120px;">
					<tbody>
						<?php foreach ( $this->yandex_delivery_geo_v2_state_rows() as $key => $label ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-wdc-yandex-geo-v2-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->yandex_delivery_pickup_v2_state_value( $geo_v2_state, $key ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<h3><?php echo esc_html__( 'Статистика geo_v2', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr><th scope="row"><?php echo esc_html__( 'Всего geoId', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Активных geoId', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['active'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Всего ПВЗ в geo_v2', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['points_total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Всего dropoff', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['dropoff_total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'geoId без региона', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['no_region'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'geoId без dropoff', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['no_dropoff'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Макс. coverage radius', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( null === ( $geo_v2_stats['max_coverage_radius_km'] ?? null ) ? '' : (string) $geo_v2_stats['max_coverage_radius_km'] ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Средний coverage radius', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( null === ( $geo_v2_stats['avg_coverage_radius_km'] ?? null ) ? '' : (string) $geo_v2_stats['avg_coverage_radius_km'] ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Макс. safe radius', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( null === ( $geo_v2_stats['max_coverage_radius_safe_km'] ?? null ) ? '' : (string) $geo_v2_stats['max_coverage_radius_safe_km'] ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Средний safe radius', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( null === ( $geo_v2_stats['avg_coverage_radius_safe_km'] ?? null ) ? '' : (string) $geo_v2_stats['avg_coverage_radius_safe_km'] ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Топ регионов', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( wp_json_encode( $geo_v2_stats['top_regions'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Топ населённых пунктов', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( wp_json_encode( $geo_v2_stats['top_localities'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></td></tr>
			</tbody>
		</table>
		</details>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Обогащение регионов', 'walls-delivery-calc' ); ?></summary>
		<h3><?php echo esc_html__( 'Обогащение пустых регионов geo_v2', 'walls-delivery-calc' ); ?></h3>
		<div data-wdc-yandex-geo-v2-region-enrichment data-wdc-yandex-geo-v2-region-enrichment-status="<?php echo esc_attr( (string) ( $geo_v2_region_enrichment_state['status'] ?? 'idle' ) ); ?>">
			<p>
				<button type="button" class="button button-primary" data-wdc-yandex-geo-v2-region-enrichment-start><?php echo esc_html__( 'Запустить обогащение регионов', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-geo-v2-region-enrichment-pause><?php echo esc_html__( 'Пауза', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button button-secondary" data-wdc-yandex-geo-v2-region-enrichment-reset><?php echo esc_html__( 'Сбросить', 'walls-delivery-calc' ); ?></button>
			</p>
			<p class="description" data-wdc-yandex-geo-v2-region-enrichment-summary></p>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'Пустых активных регионов geo_v2', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $geo_v2_stats['no_region'] ?? 0 ) ); ?></td></tr>
					<?php foreach ( $this->yandex_geo_v2_region_enrichment_state_rows() as $key => $label ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><code data-wdc-yandex-geo-v2-region-enrichment-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->yandex_delivery_pickup_v2_state_value( $geo_v2_region_enrichment_state, $key ) ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		</details>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Region mapping', 'walls-delivery-calc' ); ?></summary>
		<?php $this->render_yandex_region_mapping_v2_section( $service, $region_mapping_v2_rows, $region_mapping_v2_wdc_regions ); ?>
		</details>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Location mapping', 'walls-delivery-calc' ); ?></summary>
		<h3><?php echo esc_html__( 'Маппинг geoId → населённые пункты', 'walls-delivery-calc' ); ?></h3>
		<div data-wdc-yandex-location-mapping-v2 data-wdc-yandex-location-mapping-v2-status="<?php echo esc_attr( (string) ( $location_mapping_v2_state['status'] ?? 'idle' ) ); ?>">
			<p>
				<button type="button" class="button button-primary" data-wdc-yandex-location-mapping-v2-start><?php echo esc_html__( 'Построить сопоставление', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-yandex-location-mapping-v2-pause><?php echo esc_html__( 'Пауза', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button button-secondary" data-wdc-yandex-location-mapping-v2-reset><?php echo esc_html__( 'Сбросить', 'walls-delivery-calc' ); ?></button>
			</p>
			<p class="description" data-wdc-yandex-location-mapping-v2-summary></p>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php foreach ( $this->yandex_location_mapping_v2_state_rows() as $key => $label ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><code data-wdc-yandex-location-mapping-v2-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->yandex_delivery_pickup_v2_state_value( $location_mapping_v2_state, $key ) ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<h3><?php echo esc_html__( 'Статистика маппинга geoId v2', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr><th scope="row">mapped</th><td><?php echo esc_html( (string) ( $location_mapping_v2_stats['mapped'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">needs_review</th><td><?php echo esc_html( (string) ( $location_mapping_v2_stats['needs_review'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">no_match</th><td><?php echo esc_html( (string) ( $location_mapping_v2_stats['no_match'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">avg confidence</th><td><?php echo esc_html( null === ( $location_mapping_v2_stats['avg_confidence'] ?? null ) ? '' : (string) $location_mapping_v2_stats['avg_confidence'] ); ?></td></tr>
				<tr><th scope="row">avg distance</th><td><?php echo esc_html( null === ( $location_mapping_v2_stats['avg_distance'] ?? null ) ? '' : (string) $location_mapping_v2_stats['avg_distance'] ); ?></td></tr>
			</tbody>
		</table>
		</details>
		<?php $this->render_yandex_location_manual_overrides_v2_section( $service, $location_mapping_v2_review_items, $location_mapping_v2_no_match, $location_manual_overrides_v2 ); ?>
		<?php
	}


	/** @param array<int,array<string,mixed>> $review_items @param array<int,array<string,mixed>> $no_match_items @param array<int,array<string,mixed>> $overrides */
	private function render_yandex_location_manual_overrides_v2_section( DeliveryService $service, array $review_items, array $no_match_items, array $overrides ): void {
		?>
		<details class="wdc-yandex-stage"><summary><?php echo esc_html__( 'Ручная проверка', 'walls-delivery-calc' ); ?></summary>
		<h3><?php echo esc_html__( 'Ручные override маппинга Яндекс v2', 'walls-delivery-calc' ); ?></h3>
		<p class="description" style="max-width: 960px;"><?php echo esc_html__( 'Админ может сохранить правильный WDC location для needs_review/no_match. Следующие прогоны применят override только если совпадает нормализованная пара регион + населённый пункт; при переиспользовании geoId под другой НП override не применяется.', 'walls-delivery-calc' ); ?></p>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>geo_id</th><th>Yandex</th><th>ПВЗ</th><th>Кандидат</th><th>Сохранить override</th></tr></thead>
			<tbody>
				<?php foreach ( $review_items as $item ) : ?>
					<?php $raw = is_array( $item['raw'] ?? null ) ? $item['raw'] : array(); ?>
					<tr>
						<td><?php echo esc_html( (string) ( $item['yandex_geo_id'] ?? '' ) ); ?><br><code><?php echo esc_html( (string) ( $item['status'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $item['region'] ?? '' ) ); ?><br><?php echo esc_html( (string) ( $item['locality'] ?? '' ) ); ?><br><code><?php echo esc_html( $this->yandex_location_mapping_v2_coordinates( $item['centroid_lat'] ?? null, $item['centroid_lon'] ?? null ) ); ?></code><br><small><?php echo esc_html( (string) ( $item['first_full_address'] ?? '' ) ); ?></small></td>
						<td><?php echo esc_html( 'points=' . (string) ( $item['points_count'] ?? 0 ) . ', dropoff=' . (string) ( $item['dropoff_count'] ?? 0 ) ); ?><br><?php echo esc_html( 'safe=' . (string) ( $item['coverage_radius_safe_km'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( 'location_id=' . (string) ( $item['location_id'] ?? 0 ) ); ?><br><?php echo esc_html( $this->yandex_location_mapping_v2_coordinates( $item['candidate_latitude'] ?? null, $item['candidate_longitude'] ?? null ) ); ?><br><?php echo esc_html( 'distance=' . (string) ( $item['distance_km'] ?? '' ) . ', confidence=' . (string) ( $item['confidence'] ?? '' ) ); ?><br><code><?php echo esc_html( wp_json_encode( array_intersect_key( $raw, array_flip( array( 'locality_raw', 'effective_locality', 'dominance_reason', 'reason' ) ) ), JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></code></td>
						<td>
							<form method="post" style="margin-bottom: 6px;">
								<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
								<input type="hidden" name="wdc_delivery_services_action" value="save_yandex_location_manual_override_v2" />
								<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>" />
								<input type="hidden" name="yandex_geo_id" value="<?php echo esc_attr( (string) ( $item['yandex_geo_id'] ?? '' ) ); ?>" />
								<input type="hidden" name="yandex_region" value="<?php echo esc_attr( (string) ( $item['region'] ?? '' ) ); ?>" />
								<input type="hidden" name="yandex_locality" value="<?php echo esc_attr( (string) ( $item['locality'] ?? '' ) ); ?>" />
								<input type="number" name="location_id" value="<?php echo esc_attr( (string) max( 0, (int) ( $item['location_id'] ?? 0 ) ) ); ?>" min="1" style="width: 120px;" />
								<button type="submit" class="button"><?php echo esc_html__( 'Сохранить override', 'walls-delivery-calc' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $review_items ) : ?>
					<tr><td colspan="5"><?php echo esc_html__( 'Нет needs_review/no_match строк.', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<h3><?php echo esc_html__( 'Последние no_match', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 1120px;">
			<thead><tr><th>geo_id</th><th>region</th><th>locality</th><th>coordinates</th><th>first_full_address</th><th>updated_at</th></tr></thead>
			<tbody>
				<?php foreach ( $no_match_items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $item['yandex_geo_id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['region'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['locality'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( $this->yandex_location_mapping_v2_coordinates( $item['centroid_lat'] ?? null, $item['centroid_lon'] ?? null ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $item['first_full_address'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['updated_at'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $no_match_items ) : ?>
					<tr><td colspan="6"><?php echo esc_html__( 'Нет данных.', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<h4><?php echo esc_html__( 'Активные override', 'walls-delivery-calc' ); ?></h4>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>ID</th><th>Yandex</th><th>WDC</th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $overrides as $override ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $override['id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $override['yandex_geo_id'] ?? '' ) ); ?><br><?php echo esc_html( (string) ( $override['yandex_region'] ?? '' ) ); ?><br><?php echo esc_html( (string) ( $override['yandex_locality'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $override['location_id'] ?? '' ) ); ?><br><?php echo esc_html( (string) ( $override['wdc_region_name'] ?? '' ) ); ?><br><?php echo esc_html( (string) ( $override['wdc_display_name'] ?? '' ) ); ?></td>
						<td><form method="post"><?php wp_nonce_field( 'wdc_delivery_services' ); ?><input type="hidden" name="wdc_delivery_services_action" value="deactivate_yandex_location_manual_override_v2" /><input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>" /><input type="hidden" name="override_id" value="<?php echo esc_attr( (string) ( $override['id'] ?? '' ) ); ?>" /><button type="submit" class="button button-secondary"><?php echo esc_html__( 'Отключить', 'walls-delivery-calc' ); ?></button></form></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $overrides ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'Активных override нет.', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		</details>
		<?php
	}
	private function yandex_location_mapping_v2_coordinates( mixed $lat, mixed $lon ): string {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return '—';
		}
		$format = static function ( float $value ): string {
			return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
		};
		return $format( (float) $lat ) . ', ' . $format( (float) $lon );
	}
	/** @return array<int,string> */
	private function yandex_geo_pipeline_schedule_days(): array {
		return array(
			1 => 'Пн',
			2 => 'Вт',
			3 => 'Ср',
			4 => 'Чт',
			5 => 'Пт',
			6 => 'Сб',
			7 => 'Вс',
		);
	}
	/** @return array<string,string> */
	private function yandex_delivery_geo_pipeline_v2_state_rows(): array {
		return array(
			'status' => 'status',
			'stage_label' => 'Текущая стадия',
			'stage' => 'stage',
			'processed' => 'processed',
			'total' => 'total',
			'percent' => 'percent',
			'updated_at' => 'updated_at',
			'message' => 'message',
			'errors_count' => 'errors_count',
			'errors_last' => 'errors_last',
		);
	}
	/** @return array<string,string> */
	private function yandex_delivery_pickup_v2_state_rows(): array {
		return array(
			'status' => 'Статус',
			'session_id' => 'session_id',
			'json_file_path' => 'json_file_path',
			'json_file_size_bytes' => 'json_file_size_bytes',
			'downloaded_at' => 'downloaded_at',
			'offset' => 'offset',
			'processed' => 'processed',
			'normalized' => 'normalized',
			'saved' => 'saved',
			'skipped_invalid' => 'skipped_invalid',
			'errors_count' => 'errors_count',
			'batch_size' => 'batch_size',
			'memory_peak_mb' => 'memory_peak_mb',
			'last_action' => 'last_action',
			'last_http_status' => 'last_http_status',
			'last_error_context' => 'last_error_context',
			'updated_at' => 'updated_at',
			'message' => 'message',
		);
	}

	/** @return array<string,string> */
	private function yandex_delivery_geo_v2_state_rows(): array {
		return array(
			'status' => 'status',
			'session_id' => 'session_id',
			'offset' => 'offset',
			'processed_geo_ids' => 'processed_geo_ids',
			'saved' => 'saved',
			'batch_size' => 'batch_size',
			'updated_at' => 'updated_at',
			'memory_peak_mb' => 'memory_peak_mb',
			'message' => 'message',
			'errors_last' => 'errors_last',
		);
	}
	/** @return array<string,string> */
	private function yandex_geo_v2_region_enrichment_state_rows(): array {
		return array(
			'status' => 'status',
			'session_id' => 'session_id',
			'processed' => 'processed',
			'updated' => 'updated',
			'needs_review' => 'needs_review',
			'not_found' => 'not_found',
			'skipped' => 'skipped',
			'errors' => 'errors',
			'batch_size' => 'batch_size',
			'pending_empty_regions_remaining' => 'Осталось необработанных пустых регионов',
			'empty_regions_remaining' => 'empty_regions_remaining',
			'updated_at' => 'updated_at',
			'memory_peak_mb' => 'memory_peak_mb',
			'message' => 'message',
		);
	}
	/** @return array<string,string> */
	private function yandex_location_mapping_v2_state_rows(): array {
		return array(
			'status' => 'status',
			'session_id' => 'session_id',
			'offset' => 'offset',
			'processed' => 'processed',
			'mapped' => 'mapped',
			'needs_review' => 'needs_review',
			'no_match' => 'no_match',
			'errors' => 'errors',
			'batch_size' => 'batch_size',
			'avg_confidence' => 'avg confidence',
			'avg_distance' => 'avg distance',
			'updated_at' => 'updated_at',
			'memory_peak_mb' => 'memory_peak_mb',
			'message' => 'message',
		);
	}
	/** @param array<string,mixed> $state */
	private function yandex_delivery_pickup_v2_state_value( array $state, string $key ): string {
		$value = $state[ $key ] ?? '';
		if ( is_array( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ?: '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @param array<string,mixed> $state */
	private function yandex_delivery_json_preview( string $json ): string {
		$json = trim( $json );
		if ( '' === $json ) {
			return '—';
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $json;
		}
		$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );

		return false !== $encoded ? $encoded : $json;
	}
	/** @param array<string,mixed> $row */
	/** @param array<string,mixed> $state */
	/** @return array<string,string> */
	/** @param array<string,mixed> $state */
	/**
	 * @return array{platform_station_id:string,city_name:string,type:string,rows:array<int,array<string,mixed>>}
	 */
	/** @param array<string,mixed> $report */
	/** @param array<string,mixed> $report */
	private function render_dpd_settings_tab( DeliveryService $service ): void {
		if ( ! $this->is_dpd_service( $service ) || ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$test_credentials = $this->dpd_settings->credentials_for_environment( DpdSettings::ENV_TEST );
		$production_credentials = $this->dpd_settings->credentials_for_environment( DpdSettings::ENV_PRODUCTION );
		$diagnostic_status = $this->dpd_settings->credentials_are_complete()
			? __( 'Данные для активной среды заполнены. Проверка DPD на этом этапе выполняется в dry-режиме без внешнего API-вызова.', 'walls-delivery-calc' )
			: __( 'Данные для активной среды не заполнены.', 'walls-delivery-calc' );
		$last_check = $this->dpd_settings->last_connection_check();
		$last_status = $this->dpd_settings->last_connection_status();
		$last_message = $this->dpd_settings->last_connection_message();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Данные для входа DPD', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->select_assoc_row( DpdSettings::ENVIRONMENT_KEY, __( 'Среда', 'walls-delivery-calc' ), $this->dpd_settings->environment(), array( DpdSettings::ENV_TEST => 'Тестовая', DpdSettings::ENV_PRODUCTION => 'Рабочая' ) ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Тестовая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( DpdSettings::TEST_CLIENT_NUMBER_KEY, __( 'Номер клиента DPD / clientNumber', 'walls-delivery-calc' ), $test_credentials->client_number ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Ключ клиента DPD / clientKey', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="dpd_test_client_key" value="" placeholder="<?php echo esc_attr( $this->dpd_settings->has_client_key( DpdSettings::ENV_TEST ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="dpd_clear_test_client_key" value="1"> <?php echo esc_html__( 'очистить сохраненный ключ тестовой среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет. Ключ хранится только в зашифрованном виде.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Рабочая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( DpdSettings::PRODUCTION_CLIENT_NUMBER_KEY, __( 'Номер клиента DPD / clientNumber', 'walls-delivery-calc' ), $production_credentials->client_number ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Ключ клиента DPD / clientKey', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="dpd_production_client_key" value="" placeholder="<?php echo esc_attr( $this->dpd_settings->has_client_key( DpdSettings::ENV_PRODUCTION ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="dpd_clear_production_client_key" value="1"> <?php echo esc_html__( 'очистить сохраненный ключ рабочей среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет. Не используйте тестовые данные из документации как значения по умолчанию.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Транспорт и диагностика', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( DpdSettings::REQUEST_TIMEOUT_KEY, __( 'Таймаут запроса, сек.', 'walls-delivery-calc' ), (string) $this->dpd_settings->request_timeout() ); ?>
				<?php $this->checkbox_row( DpdSettings::DEBUG_KEY, __( 'Отладочное логирование', 'walls-delivery-calc' ), $this->dpd_settings->debug_enabled() ); ?>
				<?php $this->readonly_row( 'dpd_active_environment', __( 'Активная среда', 'walls-delivery-calc' ), $this->dpd_settings->environment_label() ); ?>
				<?php $this->readonly_row( 'dpd_diagnostic_status', __( 'Статус диагностики', 'walls-delivery-calc' ), $diagnostic_status ); ?>
				<?php $this->readonly_row( DpdSettings::LAST_CONNECTION_CHECK_KEY, __( 'Последняя проверка подключения', 'walls-delivery-calc' ), '' !== $last_check ? $last_check : __( 'не выполнялась', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( DpdSettings::LAST_CONNECTION_STATUS_KEY, __( 'Статус последней проверки', 'walls-delivery-calc' ), '' !== $last_status ? $last_status : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( DpdSettings::LAST_CONNECTION_MESSAGE_KEY, __( 'Сообщение последней проверки', 'walls-delivery-calc' ), '' !== $last_message ? $last_message : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_dpd_connection">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Проверить DPD без внешнего API-вызова', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_dpd_geography_tab( DeliveryService $service ): void {
		if ( ! $this->is_dpd_service( $service ) || ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$report = $this->dpd_settings->last_geography_import_report();
		$state = $this->dpd_geography_importer instanceof DpdGeographyImportService ? $this->dpd_geography_importer->current_state() : array();
		$phase = (string) ( $state['phase'] ?? 'idle' );
		$is_import_busy = in_array( $phase, array( 'preparing', 'indexing_locations', 'downloading', 'ready', 'importing', 'finalizing' ), true );
		$percent = max( 0, min( 100, (float) ( $state['percent_complete'] ?? 0 ) ) );
		$sftp_available = $this->dpd_geography_ftp instanceof DpdGeographyFtpClient && $this->dpd_geography_ftp->is_sftp_available();
		?>
		<?php $this->render_dpd_geography_action_result(); ?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_geography_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'DPD GeographyNewDPD SFTP', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row( DpdSettings::GEOGRAPHY_FTP_HOST_KEY, __( 'Host', 'walls-delivery-calc' ), $this->dpd_settings->geography_ftp_host() ); ?>
				<?php $this->text_row( DpdSettings::GEOGRAPHY_FTP_PORT_KEY, __( 'Port', 'walls-delivery-calc' ), (string) $this->dpd_settings->geography_ftp_port() ); ?>
				<?php $this->text_row( DpdSettings::GEOGRAPHY_FTP_USERNAME_KEY, __( 'Username', 'walls-delivery-calc' ), $this->dpd_settings->geography_ftp_username() ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Password', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="dpd_geography_ftp_password" value="" placeholder="<?php echo esc_attr( $this->dpd_settings->has_geography_ftp_password() ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="dpd_clear_geography_ftp_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пароль хранится зашифрованным. Документированный пароль DPD не подставляется как значение по умолчанию.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( DpdSettings::GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY, __( 'Remote directory', 'walls-delivery-calc' ), $this->dpd_settings->geography_ftp_remote_directory() ); ?>
			</table>
			<?php submit_button( __( 'Сохранить настройки DPD Географии', 'walls-delivery-calc' ) ); ?>
		</form>
		<div style="max-width: 860px; margin-top: 12px; padding: 10px; border-left: 4px solid <?php echo esc_attr( $sftp_available ? '#00a32a' : '#dba617' ); ?>; background: #fff;">
			<strong><?php echo esc_html( $sftp_available ? '[OK]' : '[WARNING]' ); ?></strong>
			<?php echo esc_html( $sftp_available ? __( 'SFTP extension available.', 'walls-delivery-calc' ) : __( 'SFTP extension is not available. Manual CSV upload remains available.', 'walls-delivery-calc' ) ); ?>
			<form method="post" style="margin-top: 8px;" onsubmit="return window.confirm('<?php echo esc_js( __( 'Текущий запуск будет признан недействительным. Используйте это после аварийного завершения или зависшего импорта.', 'walls-delivery-calc' ) ); ?>');">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="force_cancel_dpd_geography_import">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Принудительно отменить импорт', 'walls-delivery-calc' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="run_dpd_geography_ftp_import">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Загрузить GeographyNewDPD с FTP/SFTP', 'walls-delivery-calc' ), 'secondary', 'submit', false, $is_import_busy || ! $sftp_available ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<form method="post" enctype="multipart/form-data" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="upload_dpd_geography_csv_import">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Ручная загрузка GeographyNewDPD CSV', 'walls-delivery-calc' ); ?></h3>
			<input type="file" name="dpd_geography_csv" accept=".csv,text/csv">
			<?php submit_button( __( 'Импортировать CSV', 'walls-delivery-calc' ), 'secondary', 'submit', false, $is_import_busy ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<div id="wdc-dpd-geography-import-progress" data-wdc-dpd-phase="<?php echo esc_attr( $phase ); ?>" style="max-width: 860px; margin-top: 16px; padding: 12px; border: 1px solid #ccd0d4; background: #fff;">
			<h3><?php echo esc_html__( 'Прогресс импорта DPD Географии', 'walls-delivery-calc' ); ?></h3>
			<div style="height: 18px; background: #f0f0f1; border: 1px solid #c3c4c7; max-width: 520px;">
				<div data-wdc-dpd-progress-bar style="height: 18px; width: <?php echo esc_attr( (string) $percent ); ?>%; background: #2271b1;"></div>
			</div>
			<p data-wdc-dpd-summary><?php echo esc_html( sprintf( 'Фаза: %s. Обработано %d строк. Прочитано %.1f%% файла.', $phase, (int) ( $state['rows_read'] ?? 0 ), $percent ) ); ?></p>
			<?php if ( ! empty( $state['stale_cleanup_skipped'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Очистка устаревших DPD mappings пропущена из-за ошибок отдельных строк. Ранее сохранённые mappings оставлены без изменений.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width: 860px;">
				<tbody>
				<?php foreach ( array( 'phase', 'status', 'source', 'source_file', 'rows_read', 'file_size', 'byte_offset', 'ru_rows', 'foreign_rows', 'foreign_am_rows', 'foreign_by_rows', 'foreign_kz_rows', 'foreign_kg_rows', 'foreign_locations_inserted', 'foreign_locations_updated', 'foreign_save_failed', 'foreign_mapping_conflicts', 'foreign_duplicate_identity_rows', 'skipped_non_ru', 'skipped_invalid', 'matched_by_fias', 'matched_by_own_fias', 'matched_by_city_fias', 'resolved_after_fias_disambiguation', 'true_fias_ambiguity', 'matched_by_kladr', 'matched_by_name', 'match_batches', 'max_match_batch_rows', 'lookup_query_groups', 'match_context_candidates_peak', 'saved_candidates', 'finalized_mappings', 'finalized_changes', 'stale_cleared', 'stale_cleanup_skipped', 'unchanged_mappings', 'conflicts', 'ambiguous', 'unmatched', 'errors_total', 'errors', 'percent_complete', 'last_message', 'started_at', 'updated_at', 'finished_at' ) as $key ) : ?>
					<tr>
						<th><?php echo esc_html( $key ); ?></th>
						<td data-wdc-dpd-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( is_array( $state[ $key ] ?? null ) ? implode( '; ', array_map( 'strval', $state[ $key ] ) ) : (string) ( $state[ $key ] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" style="margin-top: 12px;">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="reset_dpd_geography_import">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Сбросить импорт', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'DPD cityId diagnostics', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'WDC location ID', 'walls-delivery-calc' ); ?></th>
					<td><input class="regular-text" type="number" min="1" name="dpd_geography_location_id" value=""></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'DPD cityId для ручного mapping', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="text" name="dpd_geography_city_id" value="">
						<p class="description"><?php echo esc_html__( 'Ручное сохранение пишет DPD cityId в wdc_location_delivery_codes. DaData fallback запускается только по кнопке для одного location_id.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button class="button" type="submit" name="wdc_delivery_services_action" value="check_dpd_geography"><?php echo esc_html__( 'Проверить mapping', 'walls-delivery-calc' ); ?></button>
				<button class="button button-secondary" type="submit" name="wdc_delivery_services_action" value="save_dpd_city_mapping"><?php echo esc_html__( 'Сохранить mapping вручную', 'walls-delivery-calc' ); ?></button>
				<button class="button button-secondary" type="submit" name="wdc_delivery_services_action" value="test_dpd_dadata_fallback"><?php echo esc_html__( 'DaData fallback для location_id', 'walls-delivery-calc' ); ?></button>
			</p>
		</form>
		<?php if ( array() !== $report ) : ?>
			<?php if ( ! empty( $report['stale_cleanup_skipped'] ) ) : ?>
				<div class="notice notice-warning inline" style="max-width: 860px;"><p><?php echo esc_html__( 'Очистка устаревших DPD mappings пропущена из-за ошибок отдельных строк. Ранее сохранённые mappings оставлены без изменений.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<h3><?php echo esc_html__( 'Последний отчет импорта DPD Географии', 'walls-delivery-calc' ); ?></h3>
			<table class="widefat striped" style="max-width: 860px;">
				<tbody>
				<?php foreach ( $report as $key => $value ) : ?>
					<tr>
						<th><?php echo esc_html( (string) $key ); ?></th>
						<td><?php echo esc_html( is_array( $value ) ? implode( '; ', array_map( 'strval', $value ) ) : (string) $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_dpd_pickup_tab( DeliveryService $service ): void {
		if ( ! $this->is_dpd_service( $service ) || ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$counts = $this->dpd_pickup_points instanceof DpdPickupPointRepository ? $this->dpd_pickup_points->count_by_source() : array();
		$total = $this->dpd_pickup_points instanceof DpdPickupPointRepository ? $this->dpd_pickup_points->count_all() : 0;
		$last_report = $this->dpd_settings->last_pickup_import_report();
		$search = $this->dpd_pickup_search_results();
		?>
		<h3><?php echo esc_html__( 'DPD ПВЗ и терминалы', 'walls-delivery-calc' ); ?></h3>
		<?php $this->render_dpd_pickup_action_result(); ?>
		<table class="widefat striped" style="max-width: 940px;">
			<tbody>
				<tr><th scope="row"><?php echo esc_html__( 'Всего активных точек', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) $total ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'parcel shops', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $counts['getParcelShops'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'self-delivery terminals', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $counts['getTerminalsSelfDelivery2'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Последний импорт', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $last_report['finished_at'] ?? 'не выполнялся' ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Источник', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( $this->dpd_pickup_report_source_label( $last_report ) ); ?></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Результат', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( $this->dpd_pickup_report_summary( $last_report ) ); ?></td></tr>
			</tbody>
		</table>
		<p>
			<?php $this->dpd_pickup_import_button( $service, 'run_dpd_pickup_parcel_shops_import', __( 'Обновить ПВЗ / parcel shops', 'walls-delivery-calc' ) ); ?>
			<?php $this->dpd_pickup_import_button( $service, 'run_dpd_pickup_terminals_import', __( 'Обновить терминалы self-delivery', 'walls-delivery-calc' ) ); ?>
			<?php $this->dpd_pickup_import_button( $service, 'run_dpd_pickup_all_import', __( 'Обновить все', 'walls-delivery-calc' ), 'button button-primary' ); ?>
			<?php $this->dpd_pickup_import_button( $service, 'reset_dpd_pickup_result', __( 'Сбросить last result', 'walls-delivery-calc' ), 'button button-secondary' ); ?>
		</p>
		<hr>
		<h3><?php echo esc_html__( 'Автоматическое обновление списка ПВЗ', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_pickup_autosync">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Автоматическое обновление списка ПВЗ', 'walls-delivery-calc' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( DpdSettings::PICKUP_AUTOSYNC_ENABLED_KEY ); ?>" value="1" <?php checked( $this->dpd_settings->pickup_autosync_enabled() ); ?>> <?php echo esc_html__( 'Включить ежедневный импорт ПВЗ DPD', 'walls-delivery-calc' ); ?></label>
					</td>
				</tr>
				<?php $this->dpd_pickup_autosync_time_row( DpdSettings::PICKUP_AUTOSYNC_TIME_1_KEY, __( 'Время обновления 1', 'walls-delivery-calc' ), $this->dpd_settings->pickup_autosync_time_1() ); ?>
				<?php $this->dpd_pickup_autosync_time_row( DpdSettings::PICKUP_AUTOSYNC_TIME_2_KEY, __( 'Время обновления 2', 'walls-delivery-calc' ), $this->dpd_settings->pickup_autosync_time_2() ); ?>
				<?php $this->dpd_pickup_autosync_time_row( DpdSettings::PICKUP_AUTOSYNC_TIME_3_KEY, __( 'Время обновления 3', 'walls-delivery-calc' ), $this->dpd_settings->pickup_autosync_time_3() ); ?>
			</table>
			<p class="description"><?php echo esc_html__( 'Время указывается по Москве (GMT+3). Можно выбрать от 0 до 3 запусков в день. Если время не выбрано, запуск не выполняется.', 'walls-delivery-calc' ); ?></p>
			<p class="submit"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить autosync ПВЗ', 'walls-delivery-calc' ); ?></button></p>
		</form>
		<hr>
		<h3><?php echo esc_html__( 'Диагностика', 'walls-delivery-calc' ); ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<input type="hidden" name="service" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="tab" value="dpd_pickup">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php echo esc_html__( 'terminalCode', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="text" name="dpd_pickup_terminal_code" value="<?php echo esc_attr( $search['terminal_code'] ); ?>"></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'cityId', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="number" min="1" name="dpd_pickup_city_id" value="<?php echo esc_attr( $search['city_id'] ); ?>"></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'cityName', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="text" name="dpd_pickup_city_name" value="<?php echo esc_attr( $search['city_name'] ); ?>"></td></tr>
			</table>
			<p><button class="button"><?php echo esc_html__( 'Найти', 'walls-delivery-calc' ); ?></button></p>
		</form>
		<?php if ( array() !== $search['rows'] ) : ?>
			<table class="widefat striped" style="max-width: 1180px;">
				<thead><tr><th>terminalCode</th><th>type</th><th>cityId</th><th>cityName</th><th>address</th><th>coordinates</th><th>source</th></tr></thead>
				<tbody>
					<?php foreach ( $search['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['terminal_code'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['type'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['city_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['city_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['address'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( trim( (string) ( $row['latitude'] ?? '' ) . ', ' . (string) ( $row['longitude'] ?? '' ), ', ' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['source'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function dpd_pickup_import_button( DeliveryService $service, string $action, string $label, string $class = 'button' ): void {
		?>
		<form method="post" style="display:inline-block; margin: 0 6px 6px 0;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="wdc_delivery_services_action" value="<?php echo esc_attr( $action ); ?>">
			<button class="<?php echo esc_attr( $class ); ?>" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function dpd_pickup_autosync_time_row( string $name, string $label, string $selected ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( DpdSettings::pickup_autosync_time_options() as $value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $option_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * @return array{terminal_code:string,city_id:string,city_name:string,rows:array<int,array<string,mixed>>}
	 */
	private function dpd_pickup_search_results(): array {
		$terminal_code = isset( $_GET['dpd_pickup_terminal_code'] ) ? sanitize_text_field( wp_unslash( $_GET['dpd_pickup_terminal_code'] ) ) : '';
		$city_id = isset( $_GET['dpd_pickup_city_id'] ) ? (string) max( 0, (int) $_GET['dpd_pickup_city_id'] ) : '';
		$city_name = isset( $_GET['dpd_pickup_city_name'] ) ? sanitize_text_field( wp_unslash( $_GET['dpd_pickup_city_name'] ) ) : '';
		$filters = array( 'limit' => 20 );
		if ( '' !== $terminal_code ) {
			$filters['terminal_code'] = $terminal_code;
		}
		if ( '' !== $city_id && '0' !== $city_id ) {
			$filters['city_id'] = (int) $city_id;
		}
		if ( '' !== $city_name ) {
			$filters['city_name'] = $city_name;
		}
		$rows = count( $filters ) > 1 && $this->dpd_pickup_points instanceof DpdPickupPointRepository
			? $this->dpd_pickup_points->search( $filters )
			: array();

		return array(
			'terminal_code' => $terminal_code,
			'city_id' => '0' === $city_id ? '' : $city_id,
			'city_name' => $city_name,
			'rows' => $rows,
		);
	}

	/**
	 * @param array<string,mixed> $report
	 */
	private function dpd_pickup_report_source_label( array $report ): string {
		if ( array() === $report ) {
			return 'нет данных';
		}

		$context = (string) ( $report['context'] ?? 'manual' );
		$label = 'auto_cron' === $context ? 'автоматический' : 'ручной';
		$source = (string) ( $report['source'] ?? '' );

		return '' !== $source ? sprintf( '%s (%s)', $label, $source ) : $label;
	}

	/**
	 * @param array<string,mixed> $report
	 */
	private function dpd_pickup_report_summary( array $report ): string {
		if ( array() === $report ) {
			return 'нет данных';
		}

		return sprintf(
			'%s: fetched=%d normalized=%d saved=%d skipped=%d inactive=%d errors=%d',
			(string) ( $report['status'] ?? ( $report['source'] ?? '' ) ),
			(int) ( $report['fetched_count'] ?? 0 ),
			(int) ( $report['normalized_count'] ?? 0 ),
			(int) ( $report['saved_count'] ?? 0 ),
			(int) ( $report['skipped_invalid'] ?? 0 ),
			(int) ( $report['marked_inactive'] ?? 0 ),
			count( is_array( $report['errors'] ?? null ) ? $report['errors'] : array() )
		);
	}

	private function render_dpd_pickup_action_result(): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$result = $this->dpd_settings->get_pickup_action_result();
		if ( array() === $result ) {
			return;
		}
		$type = in_array( (string) ( $result['type'] ?? '' ), array( 'success', 'warning', 'error', 'info' ), true ) ? (string) $result['type'] : 'info';
		$class = 'notice notice-' . ( 'error' === $type ? 'error' : ( 'warning' === $type ? 'warning' : ( 'success' === $type ? 'success' : 'info' ) ) );
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><strong><?php echo esc_html( (string) ( $result['title'] ?? 'DPD ПВЗ' ) ); ?></strong></p>
			<p><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></p>
			<?php if ( ! empty( $result['details'] ) && is_array( $result['details'] ) ) : ?>
				<ul>
					<?php foreach ( $result['details'] as $key => $value ) : ?>
						<li><code><?php echo esc_html( (string) $key ); ?></code>: <?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		$this->dpd_settings->clear_pickup_action_result();
	}

	private function save_dpd_pickup_action_result( DpdPickupPointImportReport $report ): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$this->dpd_settings->save_pickup_action_result(
			array(
				'type' => array() === $report->errors ? 'success' : 'error',
				'title' => 'DPD ПВЗ import',
				'message' => $report->message,
				'details' => array(
					'source' => $report->source,
					'fetched' => $report->fetched_count,
					'normalized' => $report->normalized_count,
					'saved' => $report->saved_count,
					'skipped_invalid' => $report->skipped_invalid,
					'marked_inactive' => $report->marked_inactive,
					'errors' => $report->errors,
				),
			)
		);
	}

	private function render_dpd_tariff_tab( DeliveryService $service ): void {
		if ( ! $this->is_dpd_service( $service ) || ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_tariff_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Настройки расчета DPD', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row_with_description( DpdSettings::TARIFF_SENDER_LOCATION_ID_KEY, __( 'location_id отправителя', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_sender_location_id(), __( 'Если override DPD cityId пустой, отправитель будет резолвиться через DpdCityResolver.', 'walls-delivery-calc' ) ); ?>
				<?php $this->text_row_with_description( DpdSettings::TARIFF_SENDER_DPD_CITY_ID_KEY, __( 'DPD cityId отправителя override', 'walls-delivery-calc' ), $this->dpd_settings->tariff_sender_dpd_city_id(), __( 'Имеет приоритет над location_id отправителя. Для Новосибирска ожидается 49455627.', 'walls-delivery-calc' ) ); ?>
				<?php $this->dpd_sender_location_info_row(); ?>
				<?php $this->text_row_with_description( DpdSettings::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY, __( 'ПВЗ отправителя по умолчанию', 'walls-delivery-calc' ), $this->dpd_settings->tariff_default_sender_terminal_code(), __( 'DPD terminalCode активного parcel_shop отправителя. Не используйте terminal_self_delivery.', 'walls-delivery-calc' ) ); ?>
				<?php $this->text_row_with_description( DpdSettings::TARIFF_CARGO_CATEGORY_KEY, __( 'Категория груза DPD', 'walls-delivery-calc' ), $this->dpd_settings->tariff_cargo_category(), __( 'Передается в order.cargoCategory. Если поле пустое, используется Товары.', 'walls-delivery-calc' ) ); ?>
				<?php $this->text_row_with_description( DpdSettings::TARIFF_SENDER_NAME_KEY, __( 'Название отправителя DPD', 'walls-delivery-calc' ), $this->dpd_settings->tariff_sender_name(), __( 'Передается в header.senderAddress.name. Если пусто, используется название сайта.', 'walls-delivery-calc' ) ); ?>
				<?php $this->text_row_with_description( DpdSettings::TARIFF_SENDER_PHONE_KEY, __( 'Телефон отправителя DPD', 'walls-delivery-calc' ), $this->dpd_settings->tariff_sender_phone(), __( 'Передается в header.senderAddress.contactPhone и обязателен для live create.', 'walls-delivery-calc' ) ); ?>
				<?php $this->dpd_default_sender_terminal_row(); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Посылка по умолчанию', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( DpdSettings::TARIFF_DEFAULT_WEIGHT_G_KEY, __( 'Вес, г', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_default_weight_g() ); ?>
				<?php $this->text_row( DpdSettings::TARIFF_DEFAULT_LENGTH_CM_KEY, __( 'Длина, см', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_default_length_cm() ); ?>
				<?php $this->text_row( DpdSettings::TARIFF_DEFAULT_WIDTH_CM_KEY, __( 'Ширина, см', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_default_width_cm() ); ?>
				<?php $this->text_row( DpdSettings::TARIFF_DEFAULT_HEIGHT_CM_KEY, __( 'Высота, см', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_default_height_cm() ); ?>
				<?php $this->text_row( DpdSettings::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY, __( 'Объявленная ценность, руб.', 'walls-delivery-calc' ), (string) $this->dpd_settings->tariff_default_declared_value_rub() ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'События DPD', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row_with_description( DpdSettings::EVENTS_LOOKBACK_DAYS_KEY, __( 'Количество дней для получения событий DPD', 'walls-delivery-calc' ), null === $this->dpd_settings->events_lookback_days() ? '' : (string) $this->dpd_settings->events_lookback_days(), __( 'Пусто: dateFrom/dateTo не передаются. 1: сегодня с 00:00; 2: вчера с 00:00. Диапазон 1..365.', 'walls-delivery-calc' ) ); ?>
				<?php $this->checkbox_row( DpdSettings::EVENTS_CONFIRM_ENABLED_KEY, __( 'Подтверждать обработанные события DPD', 'walls-delivery-calc' ), $this->dpd_settings->events_confirm_enabled() ); ?>
				<?php $this->checkbox_row( DpdSettings::AUTOSYNC_ENABLED_KEY, __( 'Автоматическое обновление статусов DPD', 'walls-delivery-calc' ), $this->dpd_settings->autosync_enabled() ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Последний autosync DPD', 'walls-delivery-calc' ); ?></th>
					<td>
						<p style="margin:0;"><?php echo esc_html( '' !== $this->dpd_settings->autosync_last_run() ? $this->dpd_settings->autosync_last_run() : '-' ); ?></p>
						<p class="description" style="margin:4px 0 0;"><?php echo esc_html__( 'Последний результат:', 'walls-delivery-calc' ); ?> <?php echo esc_html( $this->dpd_autosync_result_label( $this->dpd_settings->autosync_last_result() ) ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Сохранить настройки расчета DPD', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function dpd_autosync_result_label( string $result ): string {
		return match ( $result ) {
			'success' => __( 'успешно', 'walls-delivery-calc' ),
			'error' => __( 'ошибка', 'walls-delivery-calc' ),
			'disabled' => __( 'отключено', 'walls-delivery-calc' ),
			default => __( 'не запускался', 'walls-delivery-calc' ),
		};
	}

	private function dpd_sender_location_info_row(): void {
		$summary = $this->dpd_sender_location_summary();
		if ( '' === trim( $summary ) ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Отправитель DPD', 'walls-delivery-calc' ); ?></th>
			<td><p class="description" style="margin:0; white-space: pre-line;"><?php echo esc_html( $summary ); ?></p></td>
		</tr>
		<?php
	}

	private function dpd_sender_location_summary(): string {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return '';
		}
		$location_id = $this->dpd_settings->tariff_sender_location_id();
		$location = $location_id > 0 && $this->locations instanceof LocationRepository ? $this->locations->find_by_id( $location_id ) : null;
		$display_name = null !== $location ? $location->resolved_display_name() : '';
		$city_id = preg_replace( '/\D+/', '', $this->dpd_settings->tariff_sender_dpd_city_id() ) ?? '';
		if ( '' === $city_id && null !== $location && $this->dpd_city_resolver instanceof DpdCityResolver ) {
			$resolution = $this->dpd_city_resolver->resolve( $location );
			$city_id = is_array( $resolution ) ? ( preg_replace( '/\D+/', '', (string) ( $resolution['city_id'] ?? '' ) ) ?? '' ) : '';
		}
		$lines = array();
		if ( '' !== trim( $display_name ) ) {
			$lines[] = trim( $display_name );
		}
		if ( '' !== $city_id ) {
			$lines[] = 'DPD cityId: ' . $city_id;
		}

		return implode( "\n", $lines );
	}

	private function dpd_default_sender_terminal_row(): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$code = $this->dpd_settings->tariff_default_sender_terminal_code();
		if ( '' === $code ) {
			?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'ПВЗ отправителя', 'walls-delivery-calc' ); ?></th>
				<td><p class="description" style="margin:0;"><?php echo esc_html__( 'ПВЗ отправителя по умолчанию не задан.', 'walls-delivery-calc' ); ?></p></td>
			</tr>
			<?php
			return;
		}
		$summary = $this->dpd_default_sender_terminal_summary( $code );
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'ПВЗ отправителя', 'walls-delivery-calc' ); ?></th>
			<td><p class="description" style="margin:0; white-space: pre-line;"><?php echo esc_html( $summary ); ?></p></td>
		</tr>
		<?php
	}

	private function dpd_default_sender_terminal_summary( string $terminal_code ): string {
		if ( ! $this->dpd_pickup_points instanceof DpdPickupPointRepository ) {
			return 'warning: справочник DPD ПВЗ недоступен.';
		}
		$sender_city_id = $this->dpd_sender_city_id();
		$rows = $this->dpd_pickup_points->search( array( 'terminal_code' => $terminal_code, 'limit' => 20 ) );
		$point = null;
		foreach ( $rows as $row ) {
			if ( 'parcel_shop' === (string) ( $row['type'] ?? '' ) ) {
				$point = $row;
				break;
			}
		}
		if ( null === $point ) {
			return 'warning: terminalCode не найден среди активных DPD parcel_shop.';
		}
		$lines = array(
			'terminalCode: ' . (string) ( $point['terminal_code'] ?? $terminal_code ),
			'name: ' . (string) ( $point['name'] ?? '' ),
			'address: ' . (string) ( $point['address'] ?? '' ),
			'city_name: ' . (string) ( $point['city_name'] ?? '' ),
			'source: ' . (string) ( $point['source'] ?? '' ),
		);
		if ( '' !== $sender_city_id && $sender_city_id !== (string) ( $point['city_id'] ?? '' ) ) {
			$lines[] = 'warning: cityId ПВЗ не совпадает с cityId отправителя (' . $sender_city_id . ').';
		}

		return implode( "\n", $lines );
	}

	private function dpd_sender_city_id(): string {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return '';
		}
		$city_id = preg_replace( '/\D+/', '', $this->dpd_settings->tariff_sender_dpd_city_id() ) ?? '';
		if ( '' !== $city_id ) {
			return $city_id;
		}
		$location_id = $this->dpd_settings->tariff_sender_location_id();
		$location = $location_id > 0 && $this->locations instanceof LocationRepository ? $this->locations->find_by_id( $location_id ) : null;
		if ( null !== $location && $this->dpd_city_resolver instanceof DpdCityResolver ) {
			$resolution = $this->dpd_city_resolver->resolve( $location );
			return is_array( $resolution ) ? ( preg_replace( '/\D+/', '', (string) ( $resolution['city_id'] ?? '' ) ) ?? '' ) : '';
		}

		return '';
	}

	private function render_dpd_geography_action_result(): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$result = $this->dpd_settings->get_geography_action_result();
		if ( array() === $result ) {
			return;
		}
		$type = (string) ( $result['type'] ?? 'info' );
		$notice_class = match ( $type ) {
			'success' => 'notice-success',
			'warning' => 'notice-warning',
			'error' => 'notice-error',
			default => 'notice-info',
		};
		$details = is_array( $result['details'] ?? null ) ? $result['details'] : array();
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?>" style="max-width: 860px; padding-top: 8px; padding-bottom: 8px;">
			<p><strong><?php echo esc_html( (string) ( $result['title'] ?? 'DPD География' ) ); ?></strong></p>
			<?php if ( '' !== (string) ( $result['message'] ?? '' ) ) : ?>
				<p><?php echo esc_html( (string) $result['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( array() !== $details ) : ?>
				<ul style="margin: 0 0 0 18px; list-style: disc;">
					<?php foreach ( $details as $key => $value ) : ?>
						<li><code><?php echo esc_html( (string) $key ); ?></code>=<?php echo esc_html( is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( '' !== (string) ( $result['created_at'] ?? '' ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $result['created_at'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$this->dpd_settings->clear_geography_action_result();
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save_dpd_import_action_result( string $title, array $state ): void {
		$phase = (string) ( $state['phase'] ?? '' );
		$status = (string) ( $state['status'] ?? '' );
		$control = (string) ( $state['operation_control']['outcome'] ?? ( $state['step_control']['outcome'] ?? '' ) );
		$type = 'failed' === $phase || 'error' === $status ? 'error' : ( in_array( $control, array( 'busy', 'reset_required' ), true ) || 'warning' === $status ? 'warning' : 'success' );
		$message = (string) ( $state['last_message'] ?? ( $state['message'] ?? 'Import job created.' ) );
		$this->save_dpd_geography_action_result(
			$type,
			$title,
			'' !== $message ? $message : 'Import job created.',
			array(
				'source' => (string) ( $state['source'] ?? '' ),
				'source_file' => (string) ( $state['source_file'] ?? '' ),
				'file_size' => (int) ( $state['file_size'] ?? 0 ),
				'phase' => $phase,
				'status' => $status,
				'operation_control' => $control,
				'stale_cleanup_skipped' => ! empty( $state['stale_cleanup_skipped'] ) ? 'yes' : 'no',
				'stale_cleared' => (int) ( $state['stale_cleared'] ?? 0 ),
				'foreign_duplicate_identity_rows' => (int) ( $state['foreign_duplicate_identity_rows'] ?? 0 ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function dpd_import_action_succeeded( array $state ): bool {
		$control = (string) ( $state['operation_control']['outcome'] ?? ( $state['step_control']['outcome'] ?? '' ) );
		if ( in_array( $control, array( 'busy', 'reset_required' ), true ) ) {
			return false;
		}

		return 'failed' !== (string) ( $state['phase'] ?? '' ) && 'error' !== (string) ( $state['status'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function save_dpd_geography_action_result( string $type, string $title, string $message, array $details ): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			return;
		}
		$this->dpd_settings->save_geography_action_result(
			array(
				'type' => $type,
				'title' => $title,
				'message' => $message,
				'details' => $details,
			)
		);
	}

	private function render_cdek_tariff_calculation_rows(): void {
		if ( ! $this->cdek_settings instanceof CdekSettings ) {
			return;
		}
		$dimensions = $this->cdek_settings->default_package_dimensions_cm();
		?>
		<tr><th colspan="2"><h3><?php echo esc_html__( 'Расчет тарифов', 'walls-delivery-calc' ); ?></h3></th></tr>
		<?php $this->text_row_with_description( CdekSettings::SENDER_CITY_NAME_KEY, __( 'Город отправителя СДЭК для тарифов от двери', 'walls-delivery-calc' ), $this->cdek_settings->sender_city_name(), __( 'Используется в from_location.city при регистрации отправлений СДЭК с забором от двери.', 'walls-delivery-calc' ) ); ?>
		<?php $this->text_row( CdekSettings::SENDER_CITY_CODE_KEY, __( 'Код города отправителя СДЭК', 'walls-delivery-calc' ), (string) $this->cdek_settings->sender_city_code() ); ?>
		<?php $this->text_row( CdekSettings::SHIPMENT_POINT_KEY, __( 'Код ПВЗ отправления СДЭК', 'walls-delivery-calc' ), $this->cdek_settings->shipment_point() ); ?>
		<?php $this->text_row( CdekSettings::SHIPMENT_POINT_ADDRESS_KEY, __( 'Адрес ПВЗ отправления СДЭК', 'walls-delivery-calc' ), $this->cdek_settings->shipment_point_address() ); ?>
		<?php $this->text_row( CdekSettings::SENDER_POSTAL_CODE_KEY, __( 'Индекс отправителя', 'walls-delivery-calc' ), $this->cdek_settings->sender_postal_code() ); ?>
		<?php $this->text_row( CdekSettings::SENDER_ADDRESS_KEY, __( 'Адрес отправителя СДЭК для тарифов от двери', 'walls-delivery-calc' ), $this->cdek_settings->sender_address() ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY, __( 'Длина упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['length'] ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY, __( 'Ширина упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['width'] ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY, __( 'Высота упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['height'] ); ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>"><?php echo esc_html__( 'Цена страховки', 'walls-delivery-calc' ); ?></label></th>
			<td>
				<input class="regular-text" id="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>" name="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>" value="<?php echo esc_attr( (string) $this->cdek_settings->insurance_percent() ); ?>">
				<p class="description"><?php echo esc_html__( 'Процент от стоимости товаров с учетом скидок, который будет автоматически добавлен к стоимости доставки СДЭК перед применением правил расчета. Например, 0,75 означает 0,75%.', 'walls-delivery-calc' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function render_shipments_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$shipment = ( new ShipmentServiceSettings( $this->settings ) )->for_service( $service );
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_shipments">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT, __( 'Срок хранения по умолчанию, дней', 'walls-delivery-calc' ), (string) ( $shipment[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ); ?>
				<?php $this->checkbox_row( ShipmentServiceSettings::SEND_GOODS_ITEMS, __( 'Передавать состав вложения goods.items', 'walls-delivery-calc' ), ! empty( $shipment[ ShipmentServiceSettings::SEND_GOODS_ITEMS ] ) ); ?>
				<?php $this->checkbox_row( ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT, __( 'По умолчанию объединять товары в одну строку', 'walls-delivery-calc' ), ! empty( $shipment[ ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT ] ) ); ?>
				<?php $this->text_row( ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE, __( 'Шаблон названия объединенной строки', 'walls-delivery-calc' ), (string) ( $shipment[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? 'Товары по заказу {order_number}' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить отправления', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_status_mapping_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$settings = null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ? $this->settings->all_settings( (int) $service->id ) : array();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_status_mapping">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->textarea_row( 'status_mapping_json', __( 'Status mapping JSON', 'walls-delivery-calc' ), (string) ( $settings['status_mapping_json'] ?? '{}' ) ); ?>
				<?php $this->text_row( 'status_polling_frequency_minutes', __( 'Polling frequency, minutes', 'walls-delivery-calc' ), (string) ( $settings['status_polling_frequency_minutes'] ?? 60 ) ); ?>
				<?php $this->text_row( 'status_auto_sync_wc_statuses', __( 'WC statuses eligible for auto-sync', 'walls-delivery-calc' ), (string) ( $settings['status_auto_sync_wc_statuses'] ?? 'processing,completed' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить mapping статусов', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_cdek_statuses_tab( DeliveryService $service ): void {
		if ( ! $this->is_cdek_service( $service ) || ! $this->cdek_status_mapping instanceof CdekStatusMappingService ) {
			return;
		}
		$mapping = $this->cdek_status_mapping->mapping();
		?>
		<form method="post" style="max-width: 960px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_statuses">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<p class="description"><?php echo esc_html__( 'Сопоставление не меняет raw-статус СДЭК и правила кнопок; оно сохраняет универсальный статус отправления для общей логики WDC.', 'walls-delivery-calc' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Статус СДЭК', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Универсальный статус отправления', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( CdekStatusMappingService::status_labels() as $code => $label ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $label ); ?></strong>
								<br><code><?php echo esc_html( $code ); ?></code>
							</td>
							<td><?php $this->render_delivery_status_select( CdekStatusMappingService::MAPPING_KEY, $code, (string) ( $mapping[ $code ] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить статусы СДЭК', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_dpd_statuses_tab( DeliveryService $service ): void {
		if ( ! $this->is_dpd_service( $service ) || ! $this->dpd_status_mapping instanceof DpdStatusMapping ) {
			return;
		}
		$mapping = $this->dpd_status_mapping->mapping();
		$defaults = DpdStatusMapping::default_mapping();
		?>
		<form method="post" style="max-width: 1180px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_statuses">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<p class="description"><?php echo esc_html__( 'Справочник взят из DPD WS Integration Guide, раздел 5.5.4: EventCode, EventName и marker/code name. Сопоставление сохраняет универсальный статус отправления WDC и не запускает API статусов DPD.', 'walls-delivery-calc' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'EventCode', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'EventName', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'DPD marker/code name', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Универсальный статус отправления', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Дефолтное значение', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( DpdStatusMapping::statuses() as $event_code => $status ) : ?>
						<?php $event_code = (string) $event_code; ?>
						<tr>
							<td><code><?php echo esc_html( $event_code ); ?></code></td>
							<td>
								<strong><?php echo esc_html( $status['event_name'] ); ?></strong>
								<?php if ( '' !== $status['comment'] ) : ?>
									<br><small><?php echo esc_html( $status['comment'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== $status['status_code'] ? '<code>' . esc_html( $status['status_code'] ) . '</code>' : '<span class="description">-</span>'; ?></td>
							<td><?php $this->render_delivery_status_select( DpdStatusMapping::MAPPING_KEY, $event_code, (string) ( $mapping[ $event_code ] ?? '' ) ); ?></td>
							<td><?php echo esc_html( DeliveryStatus::label( (string) ( $defaults[ $event_code ] ?? DeliveryStatus::UNKNOWN ) ) . ' (' . (string) ( $defaults[ $event_code ] ?? DeliveryStatus::UNKNOWN ) . ')' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить статусы DPD', 'walls-delivery-calc' ) ); ?>
			<button class="button" type="submit" name="dpd_statuses_reset" value="1"><?php echo esc_html__( 'Сбросить к дефолтным значениям', 'walls-delivery-calc' ); ?></button>
		</form>
		<?php
	}

	private function render_yandex_delivery_statuses_tab( DeliveryService $service ): void {
		if ( ! $this->is_yandex_delivery_service( $service ) || ! $this->yandex_status_mapping instanceof YandexStatusMapping ) {
			return;
		}
		$mapping = $this->yandex_status_mapping->mapping();
		$defaults = YandexStatusMapping::default_mapping();
		?>
		<form method="post" style="max-width: 1180px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_yandex_delivery_statuses">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<p class="description"><?php echo esc_html__( 'Единый справочник raw-статусов Яндекс.Доставки. Сопоставление сохраняет универсальный статус отправления WDC; raw-статус остаётся диагностическим значением.', 'walls-delivery-calc' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Код Яндекс', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Описание', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Применимость', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Универсальный статус отправления', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Дефолтное значение', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( YandexStatusMapping::statuses() as $code => $status ) : ?>
						<?php $code = (string) $code; ?>
						<tr>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td><strong><?php echo esc_html( $status['description'] ); ?></strong></td>
							<td><?php echo esc_html( $this->yandex_status_mapping->mode_label( $status['mode'] ) ); ?></td>
							<td><?php $this->render_delivery_status_select( YandexStatusMapping::MAPPING_KEY, $code, (string) ( $mapping[ $code ] ?? '' ) ); ?></td>
							<td><?php echo esc_html( DeliveryStatus::label( (string) ( $defaults[ $code ] ?? DeliveryStatus::UNKNOWN ) ) . ' (' . (string) ( $defaults[ $code ] ?? DeliveryStatus::UNKNOWN ) . ')' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить статусы Яндекс.Доставки', 'walls-delivery-calc' ) ); ?>
			<button class="button" type="submit" name="yandex_delivery_statuses_reset" value="1"><?php echo esc_html__( 'Сбросить к дефолтным значениям', 'walls-delivery-calc' ); ?></button>
		</form>
		<?php
	}

	private function render_jet_geography_tab( DeliveryService $service ): void {
		if ( JetLogisticSettings::SERVICE_KEY !== $service->service_key || ! $this->jet_logistic_geography instanceof JetLogisticGeographyAdminPage ) {
			return;
		}

		$this->jet_logistic_geography->render_embedded( $service, $this->consume_jet_admin_notice() );
	}

	private function render_jet_statuses_tab( DeliveryService $service ): void {
		if ( JetLogisticSettings::SERVICE_KEY !== $service->service_key || ! $this->jet_logistic_statuses instanceof JetLogisticStatusAdminPage ) {
			return;
		}

		$this->jet_logistic_statuses->render_embedded( $service, $this->consume_jet_admin_notice() );
	}

	private function render_pek_settings_tab( DeliveryService $service ): void {
		if ( PekSettings::SERVICE_KEY !== $service->service_key || ! $this->pek_admin instanceof PekAdminPage ) {
			return;
		}

		$this->pek_admin->render_embedded( $service );
	}

	private function render_pek_statuses_tab( DeliveryService $service ): void {
		if ( PekSettings::SERVICE_KEY !== $service->service_key || ! $this->pek_statuses instanceof PekStatusAdminPage ) {
			return;
		}

		$this->pek_statuses->render_embedded( $service );
	}

	private function render_diagnostics_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$settings_count = null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ? count( $this->settings->all_settings( (int) $service->id ) ) : 0;
		$points = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_active() : 0;
		?>
		<table class="widefat striped" style="max-width: 760px; margin-top:16px;">
			<tbody>
				<tr><th scope="row">Service key</th><td><code><?php echo esc_html( $service->service_key ); ?></code></td></tr>
				<tr><th scope="row">Carrier key</th><td><code><?php echo esc_html( $service->carrier_key ); ?></code></td></tr>
				<tr><th scope="row">Settings rows</th><td><?php echo esc_html( (string) $settings_count ); ?></td></tr>
				<tr><th scope="row">Активные ПВЗ / ОПС</th><td><?php echo esc_html( (string) $points ); ?></td></tr>
				<tr><th scope="row">Checkout groups</th><td><code><?php echo esc_html( RussianPostDomesticSettings::checkout_group_id( DeliveryType::PICKUP ) ); ?></code>, <code><?php echo esc_html( RussianPostDomesticSettings::checkout_group_id( DeliveryType::COURIER ) ); ?></code></td></tr>
			</tbody>
		</table>
		<?php
	}

	private function render_delivery_status_select( string $name, string $code, string $selected_status ): void {
		echo '<select name="' . esc_attr( $name ) . '[' . esc_attr( $code ) . ']">';
		foreach ( DeliveryStatus::all() as $status ) {
			echo '<option value="' . esc_attr( $status ) . '" ' . selected( $selected_status, $status, false ) . '>' . esc_html( DeliveryStatus::label( $status ) . ' (' . $status . ')' ) . '</option>';
		}
		echo '</select>';
	}

	private function render_tariffs_tab( DeliveryService $service ): void {
		if ( $this->is_cdek_service( $service ) ) {
			$this->render_cdek_tariffs_tab( $service );
			return;
		}
		if ( $this->is_dpd_service( $service ) ) {
			$this->render_dpd_runtime_tariffs_tab( $service );
			return;
		}
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$variants = $this->domestic_tariff_variants( $service );
		?>
		<form method="post" style="margin-top:16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_tariffs">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
		<table class="widefat striped">
			<thead><tr><th>Включен</th><th>Сортировка</th><th>Код object</th><th>Название в checkout</th><th>Тип доставки</th><th>Мин. вес, г</th><th>Макс. вес, г</th><th>Объявленная ценность</th><th>ЕКОМ</th><th>Комментарий администратора</th></tr></thead>
			<tbody>
				<?php foreach ( $variants as $variant ) : ?>
					<tr>
						<td><input type="checkbox" name="tariff_enabled[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="1" <?php checked( $variant->enabled ); ?>></td>
						<td><input class="small-text" name="tariff_sort[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( (string) $variant->sort_order ); ?>"></td>
						<td><?php echo esc_html( (string) $variant->object_code ); ?></td>
						<td><input class="regular-text" name="tariff_title[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( $variant->title ); ?>"></td>
						<td><?php echo esc_html( DeliveryType::COURIER === $variant->delivery_type ? 'Курьер' : 'До отделения' ); ?></td>
						<td><input class="small-text" type="number" min="0" name="tariff_min_weight_g[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( null === $variant->min_weight_g ? '' : (string) $variant->min_weight_g ); ?>"></td>
						<td><input class="small-text" type="number" min="0" name="tariff_max_weight_g[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( null === $variant->max_weight_g ? '' : (string) $variant->max_weight_g ); ?>"></td>
						<td><?php echo esc_html( $variant->requires_declared_value ? ( $variant->always_available ? 'ОЦ, всегда доступен' : 'ОЦ' ) : 'Нет' ); ?></td>
						<td><label><input type="checkbox" name="tariff_is_ecom[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="1" <?php checked( $variant->is_ecom ); ?>> ЕКОМ</label></td>
						<td><input class="regular-text" name="tariff_admin_comment[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( $variant->admin_comment ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button( __( 'Сохранить тарифы', 'walls-delivery-calc' ) ); ?>
		</form>
		<p><?php echo esc_html__( 'Лимиты веса можно оставить пустыми. Расчет использует вес товаров с учетом настроек упаковки службы; pack для API всегда равен 99.', 'walls-delivery-calc' ); ?></p>
		<?php
	}

	private function render_dpd_runtime_tariffs_tab( DeliveryService $service ): void {
		if ( ! $this->dpd_settings instanceof DpdSettings ) {
			echo '<p>' . esc_html__( 'Настройки DPD недоступны.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		$enabled = array_fill_keys( $this->dpd_settings->runtime_enabled_service_codes(), true );
		$titles = $this->dpd_settings->runtime_tariff_titles();
		?>
		<form method="post" style="margin-top:16px;max-width:1120px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_dpd_runtime_tariffs">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Тарифы DPD для checkout', 'walls-delivery-calc' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Включен', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'serviceCode', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Название DPD', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Название тарифа в checkout', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( DpdSettings::known_service_codes() as $code => $default_title ) : ?>
						<tr>
							<td><label><input type="checkbox" name="dpd_runtime_service_enabled[<?php echo esc_attr( $code ); ?>]" value="1" <?php checked( ! empty( $enabled[ $code ] ) ); ?>> <?php echo esc_html__( 'Да', 'walls-delivery-calc' ); ?></label></td>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td><?php echo esc_html( $default_title ); ?></td>
							<td><input class="regular-text" name="dpd_runtime_tariff_title[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( (string) ( $titles[ $code ] ?? $default_title ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h3><?php echo esc_html__( 'Режим расчета DPD для checkout', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->checkbox_row( DpdSettings::RUNTIME_ENABLE_COURIER_RATES_KEY, __( 'Использовать курьерские тарифы', 'walls-delivery-calc' ), $this->dpd_settings->runtime_courier_rates_enabled() ); ?>
			</table>
			<p class="description"><?php echo esc_html__( 'DPD checkout всегда считает отправку от терминала. Доставка до пункта выдачи считается всегда; доставка до двери считается отдельным запросом только при включенной галке. Выбор конкретного пункта DPD и карта будут добавлены позже.', 'walls-delivery-calc' ); ?></p>
			<?php submit_button( __( 'Сохранить тарифы DPD', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_cdek_tariffs_tab( DeliveryService $service ): void {
		if ( ! $this->cdek_tariffs instanceof CdekTariffRepository ) {
			echo '<p>' . esc_html__( 'Хранилище тарифов СДЭК недоступно.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		$rows = $this->cdek_tariffs->all();
		$preview = get_transient( $this->cdek_tariff_preview_transient_key() );
		?>
		<div style="margin-top:16px;max-width:1180px;">
			<?php $this->render_cdek_tariffs_bulk_notice(); ?>
			<form method="post" style="margin-bottom:16px;">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="preview_cdek_tariffs_sync">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Загрузить тарифы из СДЭК', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php echo esc_html__( 'Используется метод GET /v2/calculator/alltariffs. Перед записью показывается дифф с текущей таблицей.', 'walls-delivery-calc' ); ?></p>
			</form>
			<?php $this->render_cdek_tariffs_sync_preview( $service, is_array( $preview ) ? $preview : array() ); ?>
			<?php $this->render_cdek_tariffs_bulk_actions( $service ); ?>
			<form method="post">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_tariffs">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<table class="widefat striped">
					<thead><tr><th>Код</th><th>Название СДЭК</th><th>Ограничения</th><th>Название на сайте</th><th>Тип доставки</th><th>Режим тарифа</th><th>Комментарий</th><th>Активен</th><th>Последний sync</th></tr></thead>
					<tbody>
						<?php if ( array() === $rows ) : ?>
							<tr><td colspan="9"><?php echo esc_html__( 'Тарифы еще не загружены.', 'walls-delivery-calc' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $code = (string) $row['tariff_code']; ?>
							<tr>
								<td><code><?php echo esc_html( $code ); ?></code><input type="hidden" name="cdek_tariff_code[]" value="<?php echo esc_attr( $code ); ?>"></td>
								<td><?php echo esc_html( (string) $row['tariff_name_from_cdek'] ); ?></td>
								<td><?php echo wp_kses_post( $this->cdek_tariff_limits_html( $row ) ); ?></td>
								<td><input class="regular-text" name="cdek_tariff_custom_title[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( (string) $row['custom_title'] ); ?>"></td>
								<td><?php $this->cdek_delivery_type_select( $code, (string) $row['delivery_type'] ); ?></td>
								<td><?php $this->cdek_delivery_mode_select( $code, (int) ( $row['delivery_mode'] ?? 0 ) ); ?></td>
								<td><textarea name="cdek_tariff_admin_comment[<?php echo esc_attr( $code ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( (string) $row['admin_comment'] ); ?></textarea></td>
								<td><label><input type="checkbox" name="cdek_tariff_is_active[<?php echo esc_attr( $code ); ?>]" value="1" <?php checked( ! empty( $row['is_active'] ) ); ?>> <?php echo esc_html__( 'Да', 'walls-delivery-calc' ); ?></label></td>
								<td><?php echo esc_html( (string) ( $row['last_sync_at'] ?? '-' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Сохранить тарифы', 'walls-delivery-calc' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function handle_cdek_tariffs_bulk_action(): int {
		if ( ! $this->cdek_tariffs instanceof CdekTariffRepository ) {
			return 0;
		}
		$bulk_action = sanitize_key( wp_unslash( $_POST['cdek_tariffs_bulk_action'] ?? '' ) );
		$mode = max( 0, (int) ( $_POST['cdek_tariffs_delivery_mode'] ?? 0 ) );

		return match ( $bulk_action ) {
			'delete_all' => $this->cdek_tariffs->delete_all(),
			'enable_all' => $this->cdek_tariffs->set_all_active( true ),
			'disable_all' => $this->cdek_tariffs->set_all_active( false ),
			'enable_mode' => $this->cdek_tariffs->set_active_by_delivery_mode( $mode, true ),
			'disable_mode' => $this->cdek_tariffs->set_active_by_delivery_mode( $mode, false ),
			default => 0,
		};
	}

	private function render_cdek_tariffs_bulk_notice(): void {
		$notice = sanitize_key( wp_unslash( $_GET['wdc_cdek_tariffs_notice'] ?? '' ) );
		if ( '' === $notice ) {
			return;
		}
		$count = max( 0, (int) ( $_GET['wdc_cdek_tariffs_count'] ?? 0 ) );
		$message = match ( $notice ) {
			'deleted' => sprintf( __( 'Удалено тарифов СДЭК: %d.', 'walls-delivery-calc' ), $count ),
			'updated' => sprintf( __( 'Обновлено тарифов СДЭК: %d.', 'walls-delivery-calc' ), $count ),
			default => __( 'Тарифы СДЭК не изменены.', 'walls-delivery-calc' ),
		};
		$class = 'unchanged' === $notice ? 'notice notice-warning inline' : 'notice notice-success inline';

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_cdek_tariffs_bulk_actions( DeliveryService $service ): void {
		?>
		<div class="notice notice-info inline" style="padding:12px;margin:12px 0 16px;">
			<p><strong><?php echo esc_html__( 'Массовые действия', 'walls-delivery-calc' ); ?></strong></p>
			<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'enable_all', 0, __( 'Включить все', 'walls-delivery-calc' ) ); ?>
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'disable_all', 0, __( 'Выключить все', 'walls-delivery-calc' ) ); ?>
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'delete_all', 0, __( 'Удалить все тарифы', 'walls-delivery-calc' ), 'button button-link-delete', __( 'Удалить все тарифы СДЭК? Это действие нельзя отменить.', 'walls-delivery-calc' ) ); ?>
			</p>
			<?php foreach ( $this->cdek_delivery_mode_bulk_labels() as $mode => $label ) : ?>
				<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:6px 0;">
					<span style="min-width:120px;"><?php echo esc_html( $label ); ?>:</span>
					<?php $this->render_cdek_tariffs_bulk_button( $service, 'enable_mode', $mode, __( 'Включить', 'walls-delivery-calc' ) ); ?>
					<?php $this->render_cdek_tariffs_bulk_button( $service, 'disable_mode', $mode, __( 'Выключить', 'walls-delivery-calc' ) ); ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_cdek_tariffs_bulk_button( DeliveryService $service, string $bulk_action, int $mode, string $label, string $class = 'button button-secondary', string $confirm = '' ): void {
		?>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="bulk_cdek_tariffs">
			<input type="hidden" name="cdek_tariffs_bulk_action" value="<?php echo esc_attr( $bulk_action ); ?>">
			<input type="hidden" name="cdek_tariffs_delivery_mode" value="<?php echo esc_attr( (string) $mode ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"<?php echo '' !== $confirm ? ' onclick="return confirm(' . esc_js( wp_json_encode( $confirm ) ?: "''" ) . ');"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function cdek_delivery_mode_bulk_labels(): array {
		return array(
			4 => __( 'Склад-склад', 'walls-delivery-calc' ),
			3 => __( 'Склад-дверь', 'walls-delivery-calc' ),
			2 => __( 'Дверь-склад', 'walls-delivery-calc' ),
			1 => __( 'Дверь-дверь', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @param array<string,mixed> $preview
	 */
	private function render_cdek_tariffs_sync_preview( DeliveryService $service, array $preview ): void {
		if ( array() === $preview ) {
			return;
		}
		if ( '' !== trim( (string) ( $preview['error'] ?? '' ) ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $preview['error'] ) . '</p></div>';
			return;
		}
		$diff = is_array( $preview['diff'] ?? null ) ? $preview['diff'] : array();
		$new = is_array( $diff['new'] ?? null ) ? $diff['new'] : array();
		$changed = is_array( $diff['changed'] ?? null ) ? $diff['changed'] : array();
		$missing = is_array( $diff['missing'] ?? null ) ? $diff['missing'] : array();
		?>
		<div class="notice notice-info inline" style="padding:12px;margin:12px 0;">
			<p><strong><?php echo esc_html__( 'Предпросмотр синхронизации тарифов СДЭК', 'walls-delivery-calc' ); ?></strong></p>
			<p><?php echo esc_html( sprintf( 'Новые тарифы: %d; изменившиеся: %d; отсутствуют в API: %d.', count( $new ), count( $changed ), count( $missing ) ) ); ?></p>
			<?php $this->render_cdek_tariff_diff_list( 'Новые тарифы будут добавлены', $new ); ?>
			<?php $this->render_cdek_tariff_diff_list( 'Изменившиеся тарифы будут обновлены', $changed ); ?>
			<?php $this->render_cdek_tariff_diff_list( 'Удаленные тарифы отсутствуют в API', $missing ); ?>
			<form method="post">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="confirm_cdek_tariffs_sync">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Подтвердить sync тарифов', 'walls-delivery-calc' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function render_cdek_tariff_diff_list( string $title, array $rows ): void {
		if ( array() === $rows ) {
			return;
		}
		echo '<p><strong>' . esc_html( $title ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			echo '<li><code>' . esc_html( (string) ( $row['tariff_code'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $row['tariff_name_from_cdek'] ?? $row['tariff_name'] ?? '' ) ) . ' (' . esc_html( (string) ( $row['delivery_type'] ?? '' ) ) . ')</li>';
		}
		if ( count( $rows ) > 20 ) {
			echo '<li>' . esc_html( sprintf( '...и еще %d', count( $rows ) - 20 ) ) . '</li>';
		}
		echo '</ul>';
	}

	private function cdek_delivery_type_select( string $code, string $value ): void {
		$value = DeliveryType::COURIER === $value ? DeliveryType::COURIER : DeliveryType::PICKUP;
		?>
		<select name="cdek_tariff_delivery_type[<?php echo esc_attr( $code ); ?>]">
			<option value="<?php echo esc_attr( DeliveryType::PICKUP ); ?>" <?php selected( DeliveryType::PICKUP, $value ); ?>><?php echo esc_html__( 'до ПВЗ', 'walls-delivery-calc' ); ?></option>
			<option value="<?php echo esc_attr( DeliveryType::COURIER ); ?>" <?php selected( DeliveryType::COURIER, $value ); ?>><?php echo esc_html__( 'до двери', 'walls-delivery-calc' ); ?></option>
		</select>
		<?php
	}

	private function cdek_delivery_mode_select( string $code, int $value ): void {
		$value = in_array( $value, array( 1, 2, 3, 4 ), true ) ? $value : 0;
		?>
		<select name="cdek_tariff_delivery_mode[<?php echo esc_attr( $code ); ?>]">
			<?php foreach ( $this->cdek_delivery_mode_options() as $mode => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $mode ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function cdek_delivery_mode_options(): array {
		return array(
			0 => __( 'Не определен', 'walls-delivery-calc' ),
			1 => __( 'Дверь-дверь', 'walls-delivery-calc' ),
			2 => __( 'Дверь-склад', 'walls-delivery-calc' ),
			3 => __( 'Склад-дверь', 'walls-delivery-calc' ),
			4 => __( 'Склад-склад', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function cdek_tariff_limits_html( array $row ): string {
		$weight = $this->cdek_limit_range( $row['weight_min'] ?? null, $row['weight_max'] ?? null );
		$length = $this->cdek_limit_range( $row['length_min'] ?? null, $row['length_max'] ?? null );
		$width = $this->cdek_limit_range( $row['width_min'] ?? null, $row['width_max'] ?? null );
		$height = $this->cdek_limit_range( $row['height_min'] ?? null, $row['height_max'] ?? null );
		$lines = array();
		if ( '' !== $weight ) {
			$lines[] = 'Вес: ' . $weight . ' кг';
		}
		$dimensions = array();
		if ( '' !== $length ) {
			$dimensions[] = 'Д ' . $length;
		}
		if ( '' !== $width ) {
			$dimensions[] = 'Ш ' . $width;
		}
		if ( '' !== $height ) {
			$dimensions[] = 'В ' . $height;
		}
		if ( array() !== $dimensions ) {
			$lines[] = 'Габариты: ' . implode( ', ', $dimensions ) . ' см';
		}
		if ( array() === $lines ) {
			return '&mdash;';
		}

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	private function cdek_limit_range( mixed $min, mixed $max ): string {
		$min = $this->cdek_limit_number( $min );
		$max = $this->cdek_limit_number( $max );
		if ( null === $min && null === $max ) {
			return '';
		}
		if ( null === $min ) {
			return 'до ' . $max;
		}
		if ( null === $max ) {
			return 'от ' . $min;
		}

		return $min . '–' . $max;
	}

	private function cdek_limit_number( mixed $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$number = rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );

		return '' !== $number ? $number : '0';
	}

	private function render_russian_post_pickup_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) || ! $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$result = $this->otpravka_settings->last_import_result();
		$state = $this->pickup_importer instanceof RussianPostPickupImporter
			? $this->pickup_importer->refresh_state_for_status()
			: ( $this->pickup_import_state instanceof RussianPostPickupImportStateService ? $this->pickup_import_state->current() : array() );
		$is_busy = in_array( (string) ( $state['status'] ?? 'idle' ), array( 'queued', 'running' ), true );
		$counts = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_by_type() : array();
		$total = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_active() : 0;
		$point_types = $this->pickup_point_type_settings instanceof RussianPostPickupPointTypeSettings ? $this->pickup_point_type_settings->all() : RussianPostPickupPointTypeSettings::defaults();
		$locked = $this->pickup_importer instanceof RussianPostPickupImporter && $this->pickup_importer->is_locked();
		$schedule_enabled = ! empty( $values[ RussianPostOtpravkaApiSettings::PICKUP_SCHEDULE_ENABLED_KEY ] );
		$next_schedule = $schedule_enabled && function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK ) : false;
		?>
		<form method="post" enctype="multipart/form-data" style="max-width: 960px; margin-top:16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_russian_post_pickup">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3>Типы пунктов выдачи</h3>
			<p class="description">Отключенные типы не попадают в REST-ответы карты. Если выключить все типы, OPS будет включен автоматически.</p>
			<table class="widefat striped" style="max-width: 960px;">
				<thead><tr><th>Тип</th><th>Использовать</th><th>Название в карточке/баллоне/списке</th></tr></thead>
				<tbody>
					<?php foreach ( RussianPostPickupPointTypeSettings::TYPES as $type ) : ?>
						<?php $key = strtolower( $type ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $type ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( "russian_post_domestic_point_type_{$key}_enabled" ); ?>" value="1" <?php checked( ! empty( $point_types[ $type ]['enabled'] ) ); ?>> Использовать</label></td>
							<td><input class="regular-text" type="text" name="<?php echo esc_attr( "russian_post_domestic_point_type_{$key}_label" ); ?>" value="<?php echo esc_attr( (string) ( $point_types[ $type ]['label'] ?? '' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h3>Импорт ПВЗ / ОПС</h3>
			<?php if ( 'failed' === (string) ( $state['status'] ?? '' ) && '' !== $this->pickup_import_state_value( $state, 'errors' ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $this->pickup_import_state_value( $state, 'errors' ) ); ?></p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Статус импорта</th><td><div class="wdc-rp-pickup-import-status" data-wdc-rp-pickup-import-status data-wdc-rp-status="<?php echo esc_attr( (string) ( $state['status'] ?? 'idle' ) ); ?>"><details><summary data-wdc-rp-status-summary><?php echo esc_html( $this->pickup_import_status_summary( $state ) ); ?> <span class="spinner <?php echo $is_busy ? 'is-active' : ''; ?>" data-wdc-rp-spinner></span></summary><table class="widefat striped" style="max-width: 760px; margin-top: 8px;"><tbody><?php foreach ( $this->pickup_import_state_rows() as $key => $label ) : ?><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-wdc-rp-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->pickup_import_state_value( $state, $key ) ); ?></td></tr><?php endforeach; ?></tbody></table><p><button type="button" class="button" data-wdc-rp-refresh-status>Обновить статус</button></p></details></div></td></tr>
				<?php $this->select_row( 'russian_post_pickup_unload_type', 'Тип выгрузки', (string) ( $values[ RussianPostOtpravkaApiSettings::PICKUP_UNLOAD_TYPE_KEY ] ?? 'ALL' ), array( 'ALL', 'OPS', 'PVZ', 'APS' ) ); ?>
				<?php $this->checkbox_row( 'russian_post_pickup_schedule_enabled', 'Обновлять еженедельно', ! empty( $values[ RussianPostOtpravkaApiSettings::PICKUP_SCHEDULE_ENABLED_KEY ] ) ); ?>
				<?php if ( $schedule_enabled ) : ?>
					<tr><th scope="row">Следующий запуск</th><td>
						<?php if ( false !== $next_schedule ) : ?>
							<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) $next_schedule ) ); ?>
						<?php else : ?>
							<span class="notice notice-warning inline" style="display:inline-block;margin:0;padding:4px 8px;"><?php echo esc_html__( 'Расписание включено, но следующий запуск пока не запланирован.', 'walls-delivery-calc' ); ?></span>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<tr><th scope="row">Блокировка</th><td><?php echo esc_html( $locked ? 'активна' : 'свободна' ); ?></td></tr>
				<tr><th scope="row">Активные точки</th><td><?php echo esc_html( (string) $total ); ?>; OPS: <?php echo esc_html( (string) ( $counts['OPS'] ?? 0 ) ); ?>, PVZ: <?php echo esc_html( (string) ( $counts['PVZ'] ?? 0 ) ); ?>, APS: <?php echo esc_html( (string) ( $counts['APS'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">Последний успешный импорт</th><td><?php echo esc_html( $this->otpravka_settings->last_success_at() ?: '-' ); ?></td></tr>
				<tr><th scope="row">Последний статус</th><td><?php echo esc_html( ! empty( $result['success'] ) ? 'успешно' : ( array() === $result ? '-' : 'ошибка' ) ); ?></td></tr>
				<tr><th scope="row">Статистика</th><td>начат: <?php echo esc_html( (string) ( $result['started_at'] ?? '-' ) ); ?>; завершен: <?php echo esc_html( (string) ( $result['finished_at'] ?? '-' ) ); ?>; добавлено: <?php echo esc_html( (string) ( $result['inserted'] ?? 0 ) ); ?>; обновлено: <?php echo esc_html( (string) ( $result['updated'] ?? 0 ) ); ?>; деактивировано: <?php echo esc_html( (string) ( $result['deactivated'] ?? 0 ) ); ?>; пропущено: <?php echo esc_html( (string) ( $result['skipped'] ?? 0 ) ); ?>; ошибки: <?php echo esc_html( $this->translate_import_message( implode( '; ', array_map( 'strval', is_array( $result['errors'] ?? null ) ? $result['errors'] : array() ) ) ) ); ?></td></tr>
			</table>
			<?php submit_button( 'Сохранить настройки импорта', 'secondary', 'submit', false ); ?>
			<button class="button button-primary" type="submit" name="wdc_delivery_services_action" value="run_russian_post_pickup_import" <?php disabled( $is_busy ); ?>>Запустить импорт сейчас</button>
			<?php if ( $is_busy ) : ?>
				<button class="button" type="submit" name="wdc_delivery_services_action" value="reset_russian_post_pickup_import">Отменить / сбросить зависший импорт</button>
			<?php endif; ?>
			<h3 style="margin-top:24px;">Импорт из ZIP/TXT-файла</h3>
			<p class="description">Можно загрузить ZIP, скачанный из API Отправка: <code>https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL</code>. Если ZIP extract не работает в локальной среде, распакуйте ZIP вручную и загрузите .txt/.json файл с <code>passportElements</code>. Файл будет обработан тем же background batch pipeline.</p>
			<p><input type="file" name="russian_post_pickup_file" accept=".zip,.txt,.json"> <button class="button button-primary" type="submit" name="wdc_delivery_services_action" value="upload_russian_post_pickup_file_import" <?php disabled( $is_busy ); ?>>Загрузить ZIP/TXT и начать импорт</button></p>
			<details style="max-width: 760px; margin-top: 8px;">
				<summary>PowerShell: скачать ZIP вручную</summary>
				<pre style="white-space: pre-wrap; background:#f6f7f7; padding:12px; border:1px solid #dcdcde;"><code># === НАСТРОЙКИ ===
$AccessToken = "ВАШ_ACCESS_TOKEN"
$Login       = "ВАШ_LOGIN"
$Password    = "ВАШ_PASSWORD"

# === АВТОРИЗАЦИЯ ДЛЯ X-USER-AUTHORIZATION ===
$BasicAuth = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes("$Login`:$Password")
)

# === КУДА СОХРАНИТЬ ===
$OutFile = "D:\russian-post-passport-all.zip"

# === СКАЧИВАНИЕ ===
Invoke-WebRequest `
  -Uri "https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL" `
  -Headers @{
      "Authorization"        = "AccessToken $AccessToken"
      "X-User-Authorization" = "Basic $BasicAuth"
      "Accept"               = "application/octet-stream"
  } `
  -OutFile $OutFile `
  -TimeoutSec 300

# === РАСПАКОВКА, ЕСЛИ НУЖЕН TXT/JSON FALLBACK ===
Expand-Archive -Path "D:\russian-post-passport-all.zip" -DestinationPath "D:\russian-post-passport-all"
Get-ChildItem "D:\russian-post-passport-all"</code></pre>
			</details>
		</form>
		<?php
	}

	private function render_russian_post_pickup_diagnostics_tab(): void {
		$this->russian_post_pickup_diagnostics->render();
	}

	/**
	 * @return array<string,string>
	 */
	private function pickup_import_state_rows(): array {
		return array(
			'status' => 'Статус',
			'stage' => 'Этап',
			'started_at' => 'Начат',
			'finished_at' => 'Завершен',
			'last_activity_at' => 'Последняя активность',
			'type' => 'Тип выгрузки',
			'source' => 'Источник',
			'original_upload_name' => 'Имя загруженного файла',
			'uploaded_file_size' => 'Размер загруженного файла',
			'import_id' => 'ID импорта',
			'download_url' => 'URL загрузки',
			'download_started_at' => 'Загрузка начата',
			'download_duration_ms' => 'Длительность загрузки, мс',
			'download_http_code' => 'HTTP-код загрузки',
			'download_response_message' => 'Ответ загрузки',
			'download_error' => 'Ошибка загрузки',
			'download_backend' => 'Способ загрузки',
			'fallback_used' => 'Использован fallback загрузки',
			'first_backend_error' => 'Ошибка первого способа загрузки',
			'curl_errno' => 'cURL errno',
			'curl_error' => 'Ошибка cURL',
			'temp_file_size' => 'Размер временного ZIP',
			'extract_started_at' => 'Распаковка начата',
			'extract_duration_ms' => 'Длительность распаковки, мс',
			'extract_backend' => 'Способ распаковки',
			'ziparchive_available' => 'ZipArchive доступен',
			'extract_zip_file' => 'ZIP-файл',
			'extract_zip_size' => 'Размер ZIP',
			'extract_success' => 'Распаковка успешна',
			'extracted_payload_entry_name' => 'Файл payload в архиве',
			'extracted_payload_entry_index' => 'Индекс payload в архиве',
			'extracted_payload_file' => 'Payload-файл',
			'extracted_payload_size' => 'Размер payload',
			'extract_error' => 'Ошибка распаковки',
			'payload_file' => 'Payload-файл',
			'payload_size' => 'Размер payload',
			'downloaded' => 'Загружено байт',
			'parsed' => 'Обработано',
			'inserted' => 'Добавлено',
			'updated' => 'Обновлено',
			'deactivated' => 'Деактивировано',
			'skipped' => 'Пропущено',
			'rows_inserted_to_staging' => 'Записано в staging',
			'objects_processed' => 'Объектов обработано',
			'batches_processed' => 'Batch-задач обработано',
			'current_batch_size' => 'Размер текущего batch',
			'last_batch_duration_ms' => 'Последний batch, мс',
			'max_batch_duration_ms' => 'Максимальный batch, мс',
			'payload_offset' => 'Смещение payload',
			'staging_table' => 'Таблица staging',
			'main_table' => 'Основная таблица',
			'backup_table' => 'Backup-таблица',
			'swap_started_at' => 'Swap начат',
			'swap_finished_at' => 'Swap завершен',
			'errors' => 'Ошибки',
			'memory_peak' => 'Пик памяти',
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function pickup_import_status_summary( array $state ): string {
		return sprintf(
			'Статус: %s; этап: %s; обработано: %d; записано: %d',
			$this->translate_import_status( (string) ( $state['status'] ?? 'idle' ) ),
			$this->translate_import_stage( (string) ( $state['stage'] ?? '' ) ),
			(int) ( $state['parsed'] ?? 0 ),
			(int) ( $state['inserted'] ?? 0 )
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function pickup_import_state_value( array $state, string $key ): string {
		if ( 'errors' === $key ) {
			return $this->translate_import_message( implode( '; ', array_map( 'strval', is_array( $state['errors'] ?? null ) ? $state['errors'] : array() ) ) );
		}
		if ( 'status' === $key ) {
			return $this->translate_import_status( (string) ( $state[ $key ] ?? 'idle' ) );
		}
		if ( 'stage' === $key ) {
			return $this->translate_import_stage( (string) ( $state[ $key ] ?? '' ) );
		}
		if ( 'source' === $key ) {
			return $this->translate_import_source( (string) ( $state[ $key ] ?? '' ) );
		}
		if ( 'memory_peak' === $key ) {
			return size_format( max( 0, (int) ( $state[ $key ] ?? 0 ) ) );
		}
		if ( in_array( $key, array( 'fallback_used', 'ziparchive_available', 'extract_success', 'parser_completed' ), true ) ) {
			return ! empty( $state[ $key ] ) ? 'да' : 'нет';
		}

		return $this->translate_import_message( (string) ( $state[ $key ] ?? '' ) );
	}

	private function translate_import_status( string $status ): string {
		$labels = array(
			'' => '-',
			'idle' => 'Ожидание',
			'queued' => 'В очереди',
			'running' => 'Выполняется',
			'success' => 'Успешно',
			'failed' => 'Ошибка',
		);

		return $labels[ $status ] ?? $status;
	}

	private function translate_import_stage( string $stage ): string {
		$labels = array(
			'' => '-',
			'queued' => 'В очереди',
			'download' => 'Загрузка',
			'extract' => 'Распаковка',
			'parse' => 'Обработка',
			'upsert' => 'Запись в staging',
			'deactivate' => 'Финализация',
			'finalize' => 'Финализация',
			'finished' => 'Завершено',
			'failed' => 'Ошибка',
		);

		return $labels[ $stage ] ?? $stage;
	}

	private function translate_import_source( string $source ): string {
		$labels = array(
			'' => '-',
			'api_download' => 'Автоматическая загрузка из API',
			'uploaded_zip' => 'Загруженный ZIP',
			'uploaded_payload' => 'Загруженный TXT/JSON',
			'uploaded_file' => 'Загруженный файл',
		);

		return $labels[ $source ] ?? $source;
	}

	private function translate_import_message( string $message ): string {
		if ( '' === $message ) {
			return '';
		}

		$replacements = array(
			'Unable to queue pickup import. Another import may be running.' => 'Не удалось поставить импорт в очередь. Возможно, уже выполняется другой импорт.',
			'Unable to queue ZIP import. Another import may be running.' => 'Не удалось поставить импорт ZIP в очередь. Возможно, уже выполняется другой импорт.',
			'Unable to schedule background import job.' => 'Не удалось запланировать фоновую задачу импорта.',
			'Unable to schedule background import batch job.' => 'Не удалось запланировать фоновую batch-задачу импорта.',
			'Uploaded TXT/JSON payload file is missing or empty.' => 'Загруженный TXT/JSON-файл отсутствует или пуст.',
			'Uploaded TXT/JSON payload file is missing, empty, or has an invalid extension.' => 'Загруженный TXT/JSON-файл отсутствует, пуст или имеет недопустимое расширение.',
			'Uploaded ZIP file is missing or empty.' => 'Загруженный ZIP-файл отсутствует или пуст.',
			'ZIP extract failed. Try uploading extracted TXT/JSON payload.' => 'Не удалось распаковать ZIP. Попробуйте загрузить распакованный TXT/JSON-файл.',
			'PHP ZipArchive extension is not available.' => 'На сервере недоступно расширение PHP ZipArchive. Проверьте PHP extension zip.',
			'ZIP does not contain JSON/TXT passport payload.' => 'ZIP не содержит JSON/TXT-файл с passportElements.',
			'Unable to open ZIP archive.' => 'Не удалось открыть ZIP-архив.',
			'ZipArchive code:' => 'Код ZipArchive:',
			'Download stage timed out/stale.' => 'Этап загрузки завис или превысил лимит ожидания.',
			'API download is unstable in this environment. Use manual ZIP upload import.' => 'Автоматическая загрузка через API нестабильна в этом окружении. Используйте ручную загрузку ZIP.',
			'Extract stage timed out/stale. Check PHP ZipArchive extension or use extracted JSON/TXT import.' => 'Этап распаковки завис или превысил лимит ожидания. Проверьте PHP ZipArchive или загрузите распакованный TXT/JSON.',
			'Batch stage timed out/stale.' => 'Batch-обработка зависла или превысила лимит ожидания.',
			'Import was manually cancelled/reset by admin.' => 'Импорт был вручную отменен/сброшен администратором.',
			'Не удалось загрузить файл импорта ПВЗ или файл не выбран.' => 'Не удалось загрузить файл импорта ПВЗ или файл не выбран.',
			'Only ZIP, TXT, or JSON files are allowed for Russian Post pickup import.' => 'Для импорта ПВЗ Почты России разрешены только ZIP, TXT или JSON-файлы.',
			'Uploaded file failed ZIP/TXT/JSON type validation.' => 'Загруженный файл не прошел проверку типа ZIP/TXT/JSON.',
			'Unable to store uploaded pickup import file.' => 'Не удалось сохранить загруженный файл импорта ПВЗ.',
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $message );
	}

	private function render_rules_tab( DeliveryService $service ): void {
		$service_rules = $this->rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, $service->service_key );
		if ( array() === $service_rules ) {
			?>
			<div class="notice notice-info inline"><p><?php echo esc_html__( 'Для этой службы не настроены собственные правила. При расчете будут применяться дефолтные правила, если включена соответствующая настройка службы.', 'walls-delivery-calc' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wdc-rules' ) ); ?>"><?php echo esc_html__( 'Открыть дефолтные правила', 'walls-delivery-calc' ); ?></a></p>
			<?php
		}
		?>
		<form method="post" style="margin: 12px 0;" onsubmit="<?php echo array() !== $service_rules ? esc_attr( "return confirm('У службы уже есть собственные правила. Скопировать дефолтные правила дополнительно?');" ) : ''; ?>">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="copy_default_rules">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<button class="button"><?php echo esc_html__( 'Скопировать дефолтные правила', 'walls-delivery-calc' ); ?></button>
		</form>
		<?php
		$is_cdek = $this->is_cdek_service( $service );
		if ( $is_cdek ) {
			$this->render_cdek_rules_calculator( $service, $service_rules );
			$this->rules_admin->set_service_simulation_runner( null );
		} else {
			$this->rules_admin->set_service_simulation_runner( fn( array $input, array $rules ): array => $this->simulate_service_rules( $service, $input, $rules ) );
		}
		$this->rules_admin->render_embedded_for_context(
			new RuleAdminContext(
				RuleRepository::TARGET_SERVICE,
				$service->service_key,
				self::MENU_SLUG,
				$this->service_rules_url( $service ),
				'Правила службы: ' . $service->title,
				'Правило службы',
				'Для этой службы не настроены собственные правила. При расчете будут применяться дефолтные правила, если включена соответствующая настройка службы.',
				! $is_cdek
			)
		);
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	private function render_cdek_rules_calculator( DeliveryService $service, array $rules ): void {
		$input = $this->cdek_rules_calculator_input();
		$result = null;
		if ( isset( $_POST['wdc_cdek_rules_calculator'] ) ) {
			$result = $this->handle_cdek_rules_calculator( $service, $input, $rules );
		}
		?>
		<section class="wdc-rules-card" id="wdc-cdek-test-calculator">
			<h2><?php echo esc_html__( 'Тестовый калькулятор СДЭК', 'walls-delivery-calc' ); ?></h2>
			<form method="post" class="wdc-simulation-form">
				<?php wp_nonce_field( 'wdc_cdek_rules_calculator', 'wdc_cdek_rules_calculator_nonce' ); ?>
				<input type="hidden" name="wdc_cdek_rules_calculator" value="1">
				<div class="wdc-rule-grid">
					<label><span><?php echo esc_html__( 'Область / регион', 'walls-delivery-calc' ); ?></span><input type="text" name="cdek_test[region]" value="<?php echo esc_attr( $input['region'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Город', 'walls-delivery-calc' ); ?></span><input type="text" name="cdek_test[city]" value="<?php echo esc_attr( $input['city'] ); ?>" required></label>
					<label><span><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?></span><input type="number" min="1" step="1" name="cdek_test[weight_g]" value="<?php echo esc_attr( (string) $input['weight_g'] ); ?>" required></label>
					<label><span><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?></span><input type="number" min="1" step="1" name="cdek_test[length_cm]" value="<?php echo esc_attr( (string) $input['length_cm'] ); ?>" required></label>
					<label><span><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?></span><input type="number" min="1" step="1" name="cdek_test[width_cm]" value="<?php echo esc_attr( (string) $input['width_cm'] ); ?>" required></label>
					<label><span><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?></span><input type="number" min="1" step="1" name="cdek_test[height_cm]" value="<?php echo esc_attr( (string) $input['height_cm'] ); ?>" required></label>
					<label><span><?php echo esc_html__( 'Ценность посылки, руб.', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="cdek_test[declared_value]" value="<?php echo esc_attr( $input['declared_value'] ); ?>"></label>
				</div>
				<p class="submit"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Рассчитать СДЭК', 'walls-delivery-calc' ); ?></button></p>
			</form>
		</section>
		<?php if ( is_array( $result ) ) : ?>
			<?php $this->render_cdek_rules_calculator_result( $result ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @return array{region:string,city:string,weight_g:int,length_cm:int,width_cm:int,height_cm:int,declared_value:string}
	 */
	private function cdek_rules_calculator_input(): array {
		$raw = is_array( $_POST['cdek_test'] ?? null ) ? wp_unslash( $_POST['cdek_test'] ) : array();

		return array(
			'region' => sanitize_text_field( (string) ( $raw['region'] ?? '' ) ),
			'city' => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
			'weight_g' => max( 1, (int) ( $raw['weight_g'] ?? 1000 ) ),
			'length_cm' => max( 1, (int) ( $raw['length_cm'] ?? 20 ) ),
			'width_cm' => max( 1, (int) ( $raw['width_cm'] ?? 20 ) ),
			'height_cm' => max( 1, (int) ( $raw['height_cm'] ?? 10 ) ),
			'declared_value' => sanitize_text_field( (string) ( $raw['declared_value'] ?? '0' ) ),
		);
	}

	/**
	 * @param array{region:string,city:string,weight_g:int,length_cm:int,width_cm:int,height_cm:int,declared_value:string} $input
	 * @param array<int,Rule> $rules
	 * @return array<string,mixed>
	 */
	private function handle_cdek_rules_calculator( DeliveryService $service, array $input, array $rules ): array {
		if ( ! isset( $_POST['wdc_cdek_rules_calculator_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wdc_cdek_rules_calculator_nonce'] ) ), 'wdc_cdek_rules_calculator' ) ) {
			return array( 'error' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) );
		}
		if ( ! $this->cdek_carrier instanceof CdekCarrier || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'error' => __( 'Тестовый калькулятор СДЭК недоступен: runtime службы не инициализирован.', 'walls-delivery-calc' ) );
		}
		if ( '' === trim( $input['city'] ) ) {
			return array( 'error' => __( 'Укажите город для расчета СДЭК.', 'walls-delivery-calc' ) );
		}

		$order_total = Money::from_rubles( (float) str_replace( ',', '.', $input['declared_value'] ) );
		$item = new PackageItem( 'CDEK-TEST', 'Тестовая посылка', 1, $order_total, $order_total, $input['weight_g'], $input['length_cm'], $input['width_cm'], $input['height_cm'] );
		$package = Package::from_items( array( $item ), 0, $order_total, $order_total );
		$destination = new Address(
			country_code: 'RU',
			region_name: $input['region'],
			city: $input['city'],
			settlement: $input['city'],
			raw_address: trim( implode( ', ', array_filter( array( $input['region'], $input['city'] ) ) ) )
		);
		$rows = array();
		$audit = array();
		$errors = array();
		$location = array();

		foreach ( array( DeliveryType::PICKUP, DeliveryType::COURIER ) as $delivery_type ) {
			$request = new QuoteRequest(
				'RU',
				$destination,
				$package,
				'',
				$order_total,
				gmdate( 'Y-m-d' ),
				array(
					'service_key' => CdekSettings::SERVICE_KEY,
					'delivery_type' => $delivery_type,
					'city' => $input['city'],
					'region' => $input['region'],
				)
			);
			$quote = $this->cdek_carrier->quote( $request );
			if ( is_array( $quote->raw_reference['location'] ?? null ) ) {
				$location = $quote->raw_reference['location'];
			}
			if ( ! $quote->success && 'destination_city_not_resolved' === $quote->error_code ) {
				$errors[] = __( 'Не удалось определить код города СДЭК для указанного города.', 'walls-delivery-calc' );
				continue;
			}
			if ( ! $quote->success && '' !== $quote->error_code ) {
				$errors[] = $quote->error_message ?: $quote->error_code;
			}
			foreach ( $quote->rates as $rate ) {
				if ( ! $rate instanceof DeliveryRate ) {
					continue;
				}
				$context = new RuleEvaluationContext( $order_total, $rate->price, $package, $destination, $rate->delivery_type, '', gmdate( 'Y-m-d' ), array(), array_merge( $rate->meta, array( 'original_delivery_days' => $rate->delivery_days->min_days ?? $rate->delivery_days->max_days, 'original_delivery_min_days' => $rate->delivery_days->min_days, 'original_delivery_max_days' => $rate->delivery_days->max_days ) ) );
				$applied = $this->rule_builder->apply( $rate, $context, $rules );
				$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $applied['rate'], $service ) : $applied['rate'];
				$mode = (int) ( $rate->meta['delivery_mode'] ?? 0 );
				$rows[] = array(
					'title' => $rate->tariff_name ?: $rate->title,
					'tariff_code' => $rate->tariff_key,
					'delivery_mode' => $this->cdek_delivery_mode_label( $mode ),
					'api_price' => $this->money_label( $rate->price ),
					'api_term' => $this->range_label( $rate->delivery_days ),
					'final_price' => $this->money_label( $processed->price ),
					'final_term' => $this->range_label( $processed->delivery_days ),
				);
				$audit[ $rate->tariff_key ] = $applied['audit'];
			}
		}

		return array(
			'rows' => $rows,
			'errors' => array_values( array_unique( array_filter( $errors ) ) ),
			'location' => $location,
			'audit' => $audit,
		);
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function render_cdek_rules_calculator_result( array $result ): void {
		$errors = is_array( $result['errors'] ?? null ) ? $result['errors'] : array();
		if ( ! empty( $result['error'] ) ) {
			$errors[] = (string) $result['error'];
		}
		foreach ( $errors as $error ) {
			?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $error ); ?></p></div>
			<?php
		}
		$rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		if ( array() === $rows ) {
			return;
		}
		?>
		<section class="wdc-rules-result" data-wdc-cdek-test-calculator-result>
			<h2><?php echo esc_html__( 'Результат тестового расчета СДЭК', 'walls-delivery-calc' ); ?></h2>
			<?php if ( is_array( $result['location'] ?? null ) && ! empty( $result['location']['cdek_to_city_code'] ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Код города СДЭК: %s', 'walls-delivery-calc' ), (string) $result['location']['cdek_to_city_code'] ) ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Тариф', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'tariff_code', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Режим тарифа', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Цена API до правил', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Срок API до правил', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Цена после правил', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Срок после правил', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php if ( ! is_array( $row ) ) { continue; } ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row['tariff_code'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $row['delivery_mode'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['api_price'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['api_term'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['final_price'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['final_term'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Новая служба', 'walls-delivery-calc' ) . '</h2>';
		$this->render_service_form( null );
	}

	private function render_service_form( ?DeliveryService $service ): void {
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $service->id ?? 0 ) ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ?? '' ); ?>
				<?php $this->text_row( 'title', __( 'Название', 'walls-delivery-calc' ), $service->title ?? '' ); ?>
				<?php $this->text_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ?? '' ); ?>
				<?php $this->select_row( 'service_type', __( 'Тип', 'walls-delivery-calc' ), $service->service_type ?? DeliveryService::TYPE_FIXED, array( DeliveryService::TYPE_API, DeliveryService::TYPE_FIXED, DeliveryService::TYPE_WEIGHT_BASED ) ); ?>
				<?php $this->select_assoc_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, $this->availability_mode_options() ); ?>
				<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), $service instanceof DeliveryService ? implode( ',', $this->countries->countries( (int) $service->id ) ) : '' ); ?>
				<?php $this->text_row( 'minimum_price_rub', __( 'Минимальная цена, руб.', 'walls-delivery-calc' ), (string) ( $service->minimum_price_rub ?? 1 ) ); ?>
				<?php $this->text_row( 'sort_order', __( 'Sort order', 'walls-delivery-calc' ), (string) ( $service->sort_order ?? 100 ) ); ?>
				<?php $this->checkbox_row( 'enabled', __( 'Включена', 'walls-delivery-calc' ), $service->enabled ?? true ); ?>
				<?php $this->checkbox_row( 'use_default_rules_when_no_service_rules', __( 'Fallback на default rules', 'walls-delivery-calc' ), $service->use_default_rules_when_no_service_rules ?? true ); ?>
				<?php $this->checkbox_row( 'round_up_to_ruble', __( 'Округлять вверх до рубля', 'walls-delivery-calc' ), $service->round_up_to_ruble ?? true ); ?>
			</table>
			<?php submit_button( __( 'Сохранить службу', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function text_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"></td>
		</tr>
		<?php
	}

	private function text_row_with_description( string $name, string $label, string $value, string $description ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input class="regular-text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function readonly_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><code><?php echo esc_html( $value ); ?></code><p class="description"><?php echo esc_html__( 'Техническое поле системной службы, не редактируется.', 'walls-delivery-calc' ); ?></p></td>
		</tr>
		<?php
	}

	private function textarea_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><textarea class="large-text" rows="3" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( $value ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * @param array<int,string> $options
	 */
	private function select_row( string $name, string $label, string $value, array $options ): void {
		$this->select_assoc_row( $name, $label, $value, array_combine( $options, $options ) ?: array() );
	}

	/**
	 * @param array<string,string> $options
	 */
	private function select_assoc_row( string $name, string $label, string $value, array $options ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $option => $label_text ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( $label_text ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr>
		<?php
	}

	private function checkbox_row( string $name, string $label, bool $checked ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>> <?php echo esc_html__( 'да', 'walls-delivery-calc' ); ?></label></td>
		</tr>
		<?php
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_service_data(): array {
		return array(
			'service_key' => sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ),
			'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'carrier_key' => sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) ),
			'service_type' => sanitize_key( wp_unslash( $_POST['service_type'] ?? DeliveryService::TYPE_FIXED ) ),
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
			'minimum_price_rub' => max( 0, (float) str_replace( ',', '.', (string) wp_unslash( $_POST['minimum_price_rub'] ?? '1' ) ) ),
			'sort_order' => (int) ( $_POST['sort_order'] ?? 100 ),
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'use_default_rules_when_no_service_rules' => isset( $_POST['use_default_rules_when_no_service_rules'] ) ? 1 : 0,
			'round_up_to_ruble' => isset( $_POST['round_up_to_ruble'] ) ? 1 : 0,
			'deleted' => 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_main_data(): array {
		return array(
			'service_key' => sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ),
			'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'carrier_key' => sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) ),
			'service_type' => sanitize_key( wp_unslash( $_POST['service_type'] ?? DeliveryService::TYPE_FIXED ) ),
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
			'sort_order' => (int) ( $_POST['sort_order'] ?? 100 ),
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'use_default_rules_when_no_service_rules' => isset( $_POST['use_default_rules_when_no_service_rules'] ) ? 1 : 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_availability_data(): array {
		return array(
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_calculation_data(): array {
		return array(
			'round_up_to_ruble' => isset( $_POST['round_up_to_ruble'] ) ? 1 : 0,
			'minimum_price_rub' => max( 0, (float) str_replace( ',', '.', (string) wp_unslash( $_POST['minimum_price_rub'] ?? '1' ) ) ),
			'include_packaging_weight' => isset( $_POST['include_packaging_weight'] ) ? 1 : 0,
			'packaging_weight_mode' => DeliveryService::normalize_packaging_weight_mode( sanitize_key( wp_unslash( $_POST['packaging_weight_mode'] ?? DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) ) ),
			'pickup_customer_comment' => $this->sanitize_textarea( $_POST['pickup_customer_comment'] ?? '' ),
			'courier_customer_comment' => $this->sanitize_textarea( $_POST['courier_customer_comment'] ?? '' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function packaging_weight_mode_options(): array {
		return array(
			DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT => __( 'Прибавлять к общему весу посылки', 'walls-delivery-calc' ),
			DeliveryService::PACKAGING_WEIGHT_PACKAGE_ITEM => __( 'Добавлять отдельной строкой «Упаковка»', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function availability_mode_options(): array {
		return array(
			DeliveryService::AVAILABILITY_CARRIER_DIRECTORY => __( 'Справочник перевозчика', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_SELECTED_COUNTRIES => __( 'Только выбранные страны', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_ALL_COUNTRIES => __( 'Все страны', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED => __( 'Все страны, кроме выбранных', 'walls-delivery-calc' ),
		);
	}

	private function availability_mode_label( string $mode ): string {
		return $this->availability_mode_options()[ $mode ] ?? $mode;
	}

	private function sanitize_textarea( mixed $value ): string {
		$value = wp_unslash( $value );

		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : trim( strip_tags( (string) $value ) );
	}

	private function sanitize_yandex_platform_station_id( string $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '', trim( $value ) ) ?? '', 0, 80 );
	}

	private function save_russian_post_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_global_delivery_settings(): void {
		if ( ! $this->global_settings instanceof SettingsRepository ) {
			return;
		}

		$value = max( 0, min( 365, (int) ( $_POST[ SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY ] ?? 2 ) ) );
		$this->global_settings->set( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, $value );
	}

	private function save_russian_post_domestic_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_domestic_main_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_main_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_cdek_main_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_cdek_main_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_yandex_delivery_main_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_yandex_delivery_main_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_yandex_delivery_calculation_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_yandex_delivery_calculation_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_domestic_api_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_api_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_pickup_type_settings( int $service_id ): void {
		if ( $service_id <= 0 || ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		$type_settings = $this->pickup_point_type_settings ?? new RussianPostPickupPointTypeSettings();
		foreach ( $type_settings->sanitize_admin_values( $_POST ) as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_shipment_service_settings( int $service_id, string $service_key ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		foreach ( ShipmentServiceSettings::sanitize_from_post( $_POST, $service_key ) as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_status_mapping_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		$json = trim( (string) wp_unslash( $_POST['status_mapping_json'] ?? '{}' ) );
		$this->settings->set_setting( $service_id, 'status_mapping_json', '' !== $json ? $json : '{}', 'string' );
		$this->settings->set_setting( $service_id, 'status_polling_frequency_minutes', max( 5, min( 1440, (int) ( $_POST['status_polling_frequency_minutes'] ?? 60 ) ) ), 'number' );
		$this->settings->set_setting( $service_id, 'status_auto_sync_wc_statuses', sanitize_text_field( wp_unslash( $_POST['status_auto_sync_wc_statuses'] ?? 'processing,completed' ) ), 'string' );
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_settings_from_post(): array {
		$number = static fn ( string $key, float $default = 0 ): float => (float) str_replace( ',', '.', (string) wp_unslash( $_POST[ $key ] ?? (string) $default ) );
		$int = static fn ( string $key, int $default = 0 ): int => max( 0, (int) ( $_POST[ $key ] ?? $default ) );
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$url = static fn ( string $key, string $default = '' ): string => function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) wp_unslash( $_POST[ $key ] ?? $default ) ) : filter_var( (string) wp_unslash( $_POST[ $key ] ?? $default ), FILTER_SANITIZE_URL );
		return array(
			'api_endpoint' => array( 'value' => $url( 'rp_api_endpoint', 'https://tariff.pochta.ru/v2/calculate/tariff' ), 'format' => 'string' ),
			'country_endpoint' => array( 'value' => $url( 'rp_country_endpoint', 'https://tariff.pochta.ru/v2/dictionary/country' ), 'format' => 'string' ),
			'origin_postcode' => array( 'value' => $string( 'rp_origin_postcode', '630005' ), 'format' => 'string' ),
			'object_code' => array( 'value' => max( 1, $int( 'rp_object_code', 4031 ) ), 'format' => 'number' ),
			'isavia' => array( 'value' => ! empty( $_POST['rp_isavia'] ) ? 1 : 0, 'format' => 'number' ),
			'timeout' => array( 'value' => max( 1, min( 60, $int( 'rp_timeout', 20 ) ) ), 'format' => 'number' ),
			'vat_rate' => array( 'value' => max( 0, $number( 'rp_vat_rate', 0.2 ) ), 'format' => 'number' ),
			'max_package_weight_g' => array( 'value' => max( 1, min( 100000, $int( 'rp_max_package_weight_g', 19990 ) ) ), 'format' => 'number' ),
			'fallback_enabled' => array( 'value' => isset( $_POST['rp_fallback_enabled'] ), 'format' => 'bool' ),
			'fallback_text' => array( 'value' => $string( 'rp_fallback_text', 'Стоимость доставки рассчитает менеджер' ), 'format' => 'string' ),
			'cache_until_end_of_day' => array( 'value' => isset( $_POST['rp_cache_until_end_of_day'] ), 'format' => 'bool' ),
			'auto_refresh_countries_if_empty' => array( 'value' => isset( $_POST['rp_auto_refresh_countries_if_empty'] ), 'format' => 'bool' ),
			'debug' => array( 'value' => isset( $_POST['rp_debug'] ), 'format' => 'bool' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_settings_from_post(): array {
		$number = static fn ( string $key, float $default = 0 ): float => (float) str_replace( ',', '.', (string) wp_unslash( $_POST[ $key ] ?? (string) $default ) );
		$int = static fn ( string $key, int $default = 0 ): int => max( 0, (int) ( $_POST[ $key ] ?? $default ) );
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$postcodes_raw = preg_split( '/[\s,;]+/', (string) wp_unslash( $_POST['rp_from_postcodes'] ?? '' ) ) ?: array();
		$postcodes = array_values(
			array_unique(
				array_filter(
					array_map( static fn ( mixed $value ): string => preg_replace( '/\D+/', '', (string) $value ) ?? '', $postcodes_raw ),
					static fn ( string $value ): bool => 1 === preg_match( '/^\d{6}$/', $value )
				)
			)
		);
		if ( array() === $postcodes ) {
			$postcodes = array( '630005' );
		}

		return array(
			'from_postcodes' => array( 'value' => $postcodes, 'format' => 'json' ),
			'return_postcode' => array( 'value' => $string( 'rp_return_postcode', $postcodes[0] ), 'format' => 'string' ),
			'insurance_enabled' => array( 'value' => isset( $_POST['rp_insurance_enabled'] ), 'format' => 'bool' ),
			'timeout' => array( 'value' => max( 1, min( 60, $int( 'rp_timeout', 20 ) ) ), 'format' => 'number' ),
			'vat_rate' => array( 'value' => max( 0, $number( 'rp_vat_rate', 0.2 ) ), 'format' => 'number' ),
			'fallback_enabled' => array( 'value' => isset( $_POST['rp_fallback_enabled'] ), 'format' => 'bool' ),
			'fallback_text' => array( 'value' => $string( 'rp_fallback_text', 'Стоимость доставки рассчитает менеджер' ), 'format' => 'string' ),
			'cache_until_end_of_day' => array( 'value' => isset( $_POST['rp_cache_until_end_of_day'] ), 'format' => 'bool' ),
			'debug' => array( 'value' => isset( $_POST['rp_debug'] ), 'format' => 'bool' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_main_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$pickup_title = trim( $string( 'pickup_method_title', RussianPostDomesticSettings::PICKUP_SERVICE_TITLE ) );
		$courier_title = trim( $string( 'courier_method_title', RussianPostDomesticSettings::COURIER_SERVICE_TITLE ) );

		return array(
			'pickup_method_title' => array( 'value' => '' !== $pickup_title ? $pickup_title : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE, 'format' => 'string' ),
			'courier_method_title' => array( 'value' => '' !== $courier_title ? $courier_title : RussianPostDomesticSettings::COURIER_SERVICE_TITLE, 'format' => 'string' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_cdek_main_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$pickup_title = trim( $string( 'pickup_method_title', CdekSettings::DEFAULT_PICKUP_METHOD_TITLE ) );
		$courier_title = trim( $string( 'courier_method_title', CdekSettings::DEFAULT_COURIER_METHOD_TITLE ) );

		return array(
			'pickup_method_title' => array( 'value' => '' !== $pickup_title ? $pickup_title : CdekSettings::DEFAULT_PICKUP_METHOD_TITLE, 'format' => 'string' ),
			'courier_method_title' => array( 'value' => '' !== $courier_title ? $courier_title : CdekSettings::DEFAULT_COURIER_METHOD_TITLE, 'format' => 'string' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_yandex_delivery_main_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$pickup_title = trim( $string( YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY, YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE ) );
		$courier_title = trim( $string( YandexDeliverySettings::COURIER_METHOD_TITLE_KEY, YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE ) );

		return array(
			YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY => array( 'value' => '' !== $pickup_title ? $pickup_title : YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE, 'format' => 'string' ),
			YandexDeliverySettings::COURIER_METHOD_TITLE_KEY => array( 'value' => '' !== $courier_title ? $courier_title : YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE, 'format' => 'string' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_yandex_delivery_calculation_settings_from_post(): array {
		return array(
			YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY => array( 'value' => $this->sanitize_yandex_platform_station_id( (string) wp_unslash( $_POST[ YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY ] ?? '' ) ), 'format' => 'string' ),
			YandexDeliverySettings::SOURCE_LOCATION_ID_KEY => array( 'value' => max( 0, (int) ( $_POST[ YandexDeliverySettings::SOURCE_LOCATION_ID_KEY ] ?? 0 ) ), 'format' => 'number' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_api_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$url = static fn ( string $key, string $default = '' ): string => function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) wp_unslash( $_POST[ $key ] ?? $default ) ) : filter_var( (string) wp_unslash( $_POST[ $key ] ?? $default ), FILTER_SANITIZE_URL );

		return array(
			'api_endpoint' => array( 'value' => $url( 'rp_api_endpoint', 'https://tariff.pochta.ru/v2/calculate/tariff/delivery' ), 'format' => 'string' ),
			'api_token' => array( 'value' => $string( 'rp_api_token', '' ), 'format' => 'string' ),
			'default_from_postcode' => array( 'value' => $string( 'rp_default_from_postcode', '630005' ), 'format' => 'string' ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function countries_from_post(): array {
		$raw = wp_unslash( $_POST['countries'] ?? '' );
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $raw ) ) ) );
		}

		return array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
	}

	private function render_cdek_country_checkboxes( DeliveryService $service ): void {
		$selected = null === $service->id ? array() : $this->countries->countries( (int) $service->id );
		$labels = array(
			'RU' => __( 'Россия', 'walls-delivery-calc' ),
			'AM' => __( 'Армения', 'walls-delivery-calc' ),
			'BY' => __( 'Беларусь', 'walls-delivery-calc' ),
			'KZ' => __( 'Казахстан', 'walls-delivery-calc' ),
			'KG' => __( 'Киргизия', 'walls-delivery-calc' ),
		);
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Страны СДЭК', 'walls-delivery-calc' ); ?></th>
			<td>
				<?php foreach ( CdekSettings::SUPPORTED_COUNTRIES as $country ) : ?>
					<label style="display:block;margin:0 0 6px;">
						<input type="checkbox" name="countries[]" value="<?php echo esc_attr( $country ); ?>" <?php checked( in_array( $country, $selected, true ) ); ?>>
						<?php echo esc_html( $country . ' — ' . ( $labels[ $country ] ?? $country ) ); ?>
					</label>
				<?php endforeach; ?>
				<p class="description"><?php echo esc_html__( 'Снятая страна полностью отключает службу СДЭК для этой страны. Можно снять все страны; глобальный статус службы сохраняется отдельно.', 'walls-delivery-calc' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function countries_summary( DeliveryService $service ): string {
		if ( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $service->availability_mode ) {
			return __( 'Справочник перевозчика', 'walls-delivery-calc' );
		}
		if ( DeliveryService::AVAILABILITY_ALL_COUNTRIES === $service->availability_mode ) {
			return __( 'Все страны', 'walls-delivery-calc' );
		}
		if ( DeliveryService::AVAILABILITY_SELECTED_COUNTRIES === $service->availability_mode ) {
			$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

			return array() === $countries ? __( 'Только выбранные страны', 'walls-delivery-calc' ) : implode( ', ', $countries );
		}
		if ( DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED === $service->availability_mode ) {
			$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

			return array() === $countries ? __( 'Все страны, кроме выбранных', 'walls-delivery-calc' ) : __( 'Все, кроме: ', 'walls-delivery-calc' ) . implode( ', ', $countries );
		}
		$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

		return array() === $countries ? '-' : implode( ', ', $countries );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function russian_post_values( DeliveryService $service ): array {
		$defaults = $this->russian_post_settings instanceof RussianPostSettings ? $this->russian_post_settings->defaults() : array();
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function russian_post_domestic_values( DeliveryService $service ): array {
		$defaults = array(
			'api_endpoint' => 'https://tariff.pochta.ru/v2/calculate/tariff/delivery',
			'api_token' => '',
			'pickup_method_title' => RussianPostDomesticSettings::PICKUP_SERVICE_TITLE,
			'courier_method_title' => RussianPostDomesticSettings::COURIER_SERVICE_TITLE,
			'from_postcodes' => array( '630005' ),
			'default_from_postcode' => '630005',
			'return_postcode' => '630005',
			'insurance_enabled' => false,
			'timeout' => 20,
			'vat_rate' => 0.2,
			'fallback_enabled' => false,
			'fallback_text' => 'Стоимость доставки рассчитает менеджер',
			'cache_until_end_of_day' => true,
			'debug' => false,
		);
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cdek_main_values( DeliveryService $service ): array {
		$defaults = array(
			'pickup_method_title' => CdekSettings::DEFAULT_PICKUP_METHOD_TITLE,
			'courier_method_title' => CdekSettings::DEFAULT_COURIER_METHOD_TITLE,
		);
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function yandex_delivery_calculation_values( DeliveryService $service ): array {
		$defaults = array(
			YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY => '',
			YandexDeliverySettings::SOURCE_LOCATION_ID_KEY => 0,
		);
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function yandex_delivery_main_values( DeliveryService $service ): array {
		$defaults = array(
			YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY => YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE,
			YandexDeliverySettings::COURIER_METHOD_TITLE_KEY => YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE,
		);
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<int,\WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant>
	 */
	private function domestic_tariff_variants( DeliveryService $service ): array {
		$defaults = ( new RussianPostDomesticTariffVariantResolver() )->defaults();
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->get_setting( (int) $service->id, 'tariff_variants', array() ) : array();
		if ( ! is_array( $saved ) || array() === $saved ) {
			return $defaults;
		}
		$by_code = array();
		foreach ( $saved as $row ) {
			if ( is_array( $row ) && isset( $row['object_code'] ) ) {
				$by_code[ (int) $row['object_code'] ] = $row;
			}
		}

		return array_map(
			static fn ( $variant ) => isset( $by_code[ $variant->object_code ] )
				? \WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant::from_array( array_merge( $variant->to_array(), $by_code[ $variant->object_code ] ) )
				: $variant,
			$defaults
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_domestic_tariff_variants_from_post(): array {
		$enabled = is_array( $_POST['tariff_enabled'] ?? null ) ? wp_unslash( $_POST['tariff_enabled'] ) : array();
		$sort = is_array( $_POST['tariff_sort'] ?? null ) ? wp_unslash( $_POST['tariff_sort'] ) : array();
		$title = is_array( $_POST['tariff_title'] ?? null ) ? wp_unslash( $_POST['tariff_title'] ) : array();
		$min_weight = is_array( $_POST['tariff_min_weight_g'] ?? null ) ? wp_unslash( $_POST['tariff_min_weight_g'] ) : array();
		$max_weight = is_array( $_POST['tariff_max_weight_g'] ?? null ) ? wp_unslash( $_POST['tariff_max_weight_g'] ) : array();
		$is_ecom = is_array( $_POST['tariff_is_ecom'] ?? null ) ? wp_unslash( $_POST['tariff_is_ecom'] ) : array();
		$admin_comment = is_array( $_POST['tariff_admin_comment'] ?? null ) ? wp_unslash( $_POST['tariff_admin_comment'] ) : array();
		return array_map(
			static function ( $variant ) use ( $enabled, $sort, $title, $min_weight, $max_weight, $is_ecom, $admin_comment ): array {
				$data = $variant->to_array();
				$code = (string) $variant->object_code;
				$data['enabled'] = isset( $enabled[ $code ] );
				$data['sort_order'] = max( 0, (int) ( $sort[ $code ] ?? $variant->sort_order ) );
				$custom_title = isset( $title[ $code ] ) ? sanitize_text_field( (string) $title[ $code ] ) : $variant->title;
				$data['title'] = '' !== trim( $custom_title ) ? $custom_title : $variant->title;
				$data['min_weight_g'] = isset( $min_weight[ $code ] ) && '' !== trim( (string) $min_weight[ $code ] ) ? max( 0, (int) $min_weight[ $code ] ) : null;
				$data['max_weight_g'] = isset( $max_weight[ $code ] ) && '' !== trim( (string) $max_weight[ $code ] ) ? max( 0, (int) $max_weight[ $code ] ) : null;
				$data['is_ecom'] = isset( $is_ecom[ $code ] );
				$data['admin_comment'] = isset( $admin_comment[ $code ] ) ? sanitize_text_field( (string) $admin_comment[ $code ] ) : '';

				return $data;
			},
			( new RussianPostDomesticTariffVariantResolver() )->defaults()
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_cdek_tariffs_from_post(): array {
		$codes = is_array( $_POST['cdek_tariff_code'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_code'] ) : array();
		$custom_titles = is_array( $_POST['cdek_tariff_custom_title'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_custom_title'] ) : array();
		$delivery_types = is_array( $_POST['cdek_tariff_delivery_type'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_delivery_type'] ) : array();
		$delivery_modes = is_array( $_POST['cdek_tariff_delivery_mode'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_delivery_mode'] ) : array();
		$comments = is_array( $_POST['cdek_tariff_admin_comment'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_admin_comment'] ) : array();
		$active = is_array( $_POST['cdek_tariff_is_active'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_is_active'] ) : array();
		$rows = array();
		foreach ( $codes as $code ) {
			$code = preg_replace( '/[^0-9A-Za-z_-]+/', '', trim( (string) $code ) ) ?? '';
			if ( '' === $code ) {
				continue;
			}
			$rows[] = array(
				'tariff_code' => $code,
				'custom_title' => sanitize_text_field( (string) ( $custom_titles[ $code ] ?? '' ) ),
				'delivery_type' => DeliveryType::COURIER === (string) ( $delivery_types[ $code ] ?? '' ) ? DeliveryType::COURIER : DeliveryType::PICKUP,
				'delivery_mode' => in_array( (int) ( $delivery_modes[ $code ] ?? 0 ), array( 1, 2, 3, 4 ), true ) ? (int) $delivery_modes[ $code ] : 0,
				'admin_comment' => sanitize_textarea_field( (string) ( $comments[ $code ] ?? '' ) ),
				'is_active' => isset( $active[ $code ] ),
			);
		}

		return $rows;
	}

	private function cdek_tariff_preview_transient_key(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return 'wdc_cdek_tariff_sync_preview_' . $user_id;
	}

	private function clear_delivery_quote_cache(): void {
		if ( $this->delivery_quote_cache_manager instanceof DeliveryQuoteCacheManager ) {
			$this->delivery_quote_cache_manager->clear_all_delivery_cache();
		}
	}

	private function is_domestic_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && RussianPostDomesticSettings::SERVICE_KEY === $service->service_key && RussianPostDomesticSettings::CARRIER_KEY === $service->carrier_key;
	}

	private function is_cdek_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && CdekSettings::SERVICE_KEY === $service->service_key && CdekSettings::CARRIER_KEY === $service->carrier_key;
	}

	private function is_dpd_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && DpdSettings::SERVICE_KEY === $service->service_key && DpdSettings::CARRIER_KEY === $service->carrier_key;
	}

	private function is_yandex_delivery_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && YandexDeliverySettings::SERVICE_KEY === $service->service_key && YandexDeliverySettings::CARRIER_KEY === $service->carrier_key;
	}

	private function service_tab_url( DeliveryService $service, string $tab ): string {
		return $this->service_tab_url_by_key( $service->service_key, $tab );
	}

	private function service_tab_url_by_key( string $service_key, string $tab ): string {
		return admin_url( 'admin.php?' . http_build_query( array( 'page' => self::MENU_SLUG, 'service' => $service_key, 'tab' => $tab ) ) );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function service_rules_url( DeliveryService $service, array $args = array() ): string {
		return admin_url( 'admin.php?' . http_build_query( array_merge( array( 'page' => self::MENU_SLUG, 'service' => $service->service_key, 'tab' => 'rules' ), $args ) ) );
	}

	private function copy_default_rules_to_service( DeliveryService $service ): void {
		$priority = $this->next_service_rule_priority( $service );
		foreach ( $this->rules->get_all_rules_for_target( RuleRepository::TARGET_DEFAULT, '' ) as $rule ) {
			$data = $rule->to_array();
			$data['id'] = null;
			$data['target_type'] = RuleRepository::TARGET_SERVICE;
			$data['target_value'] = $service->service_key;
			$data['priority'] = $priority;
			$data['conditions'] = array_map(
				static function ( RuleCondition $condition ): array {
					$item = $condition->to_array();
					$item['id'] = null;
					$item['rule_id'] = null;

					return $item;
				},
				$rule->conditions
			);
			$this->rules->save_rule( Rule::from_array( $data ) );
			$priority += 10;
		}
	}

	private function next_service_rule_priority( DeliveryService $service ): int {
		$last = 0;
		foreach ( $this->rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, $service->service_key ) as $rule ) {
			$last = max( $last, $rule->priority );
		}

		return $last + 10;
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,Rule> $rules
	 * @return array<string,mixed>
	 */
	private function simulate_service_rules( DeliveryService $service, array $input, array $rules ): array {
		if ( $this->is_domestic_service( $service ) ) {
			return $this->simulate_domestic_service_rules( $service, $input, $rules );
		}

		if ( DpdSettings::SERVICE_KEY === $service->service_key || DpdSettings::CARRIER_KEY === $service->carrier_key ) {
			return $this->simulate_runtime_carrier_service_rules(
				$service,
				$input,
				$rules,
				$this->dpd_carrier,
				__( 'Не удалось выполнить тестовый расчёт DPD: ', 'walls-delivery-calc' )
			);
		}

		if ( YandexDeliverySettings::SERVICE_KEY === $service->service_key || YandexDeliverySettings::CARRIER_KEY === $service->carrier_key ) {
			return $this->simulate_runtime_carrier_service_rules(
				$service,
				$input,
				$rules,
				$this->yandex_delivery_carrier,
				__( 'Не удалось выполнить тестовый расчёт Яндекс Доставки: ', 'walls-delivery-calc' )
			);
		}

		if ( RussianPostSettings::SERVICE_KEY !== $service->service_key || ! $this->russian_post_carrier instanceof RussianPostInternationalCarrier || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'notice' => __( 'Симуляция для этой службы пока не поддерживается.', 'walls-delivery-calc' ) );
		}

		$country = strtoupper( sanitize_text_field( (string) ( $input['country'] ?? 'US' ) ) );
		$weight = max( 0, (int) ( $input['weight'] ?? 1000 ) );
		$order_total = (float) str_replace( ',', '.', (string) ( $input['order_total'] ?? 1000 ) );
		$date = sanitize_text_field( (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) ) );
		$item = new PackageItem( 'SIM', 'Simulation', 1, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ), $weight );
		$package = Package::from_items( array( $item ), 0, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ) );
		$packaging = $this->packaging_calculator instanceof PackagingWeightCalculator
			? $this->packaging_calculator->apply_to_package( $package, $service )
			: new PackagingApplicationResult( $package->weight_g, 0, $package->get_total_weight_g(), $service->include_packaging_weight, $service->packaging_weight_mode, $package );
		$package = $packaging->package;
		$request = new QuoteRequest( $country, new Address( country_code: $country ), $package, '', Money::from_rubles( $order_total ), $date );
		$quote = $this->russian_post_carrier->quote( $request );
		$rate = $quote->rates[0] ?? null;
		if ( ! $rate instanceof DeliveryRate ) {
			return array(
				'base_price' => '-',
				'final_price' => '-',
				'source' => $quote->source . ' / ' . $quote->error_code,
				'notice' => $quote->error_message ?: __( 'Служба не вернула тариф для выбранной страны.', 'walls-delivery-calc' ),
			);
		}

		$context = new RuleEvaluationContext( Money::from_rubles( $order_total ), $rate->price, $package, $request->destination, $rate->delivery_type, '', $date, array(), array( 'original_delivery_days' => $rate->delivery_days?->min_days ?? 0, 'original_delivery_min_days' => $rate->delivery_days?->min_days, 'original_delivery_max_days' => $rate->delivery_days?->max_days ) );
		$applied = $this->rule_builder->apply( $rate, $context, $rules );
		$final = $applied['rate'];
		$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $final, $service ) : $final;

		return array(
			'base_price' => $rate->price->get_rubles() . ' ' . $rate->price->get_currency(),
			'final_price' => $processed->price->get_rubles() . ' ' . $processed->price->get_currency(),
			'delivery_days' => null !== $processed->delivery_days ? trim( (string) ( $processed->delivery_days->min_days ?? '-' ) . '-' . (string) ( $processed->delivery_days->max_days ?? '-' ), '-' ) : '-',
			'products_weight_g' => $packaging->original_products_weight_g,
			'packaging_weight_g' => $packaging->packaging_weight_g,
			'package_weight_with_packaging_g' => $packaging->final_package_weight_g,
			'packaging_weight_mode' => $packaging->packaging_weight_mode,
			'source' => implode( ' / ', array_filter( array( $quote->source, ! empty( $rate->meta['fallback_reason'] ) ? 'fallback: ' . $rate->meta['fallback_reason'] : '', ! empty( $rate->meta['cache_hit'] ) ? 'cache hit' : 'cache miss' ) ) ),
			'audit' => $applied['audit'],
			'notice' => array() === $rules ? __( 'Для службы не настроены собственные правила.', 'walls-delivery-calc' ) : '',
		);
	}

	private function simulate_domestic_service_rules( DeliveryService $service, array $input, array $rules ): array {
		if ( ! $this->russian_post_domestic_carrier instanceof RussianPostDomesticCarrier || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'notice' => __( 'Симуляция domestic-службы пока не поддерживается.', 'walls-delivery-calc' ) );
		}

		$weight = max( 0, (int) ( $input['weight'] ?? 1000 ) );
		$order_total = (float) str_replace( ',', '.', (string) ( $input['order_total'] ?? 1000 ) );
		$date = sanitize_text_field( (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) ) );
		$postcode = preg_replace( '/\D+/', '', (string) ( $input['postal_code'] ?? '' ) ) ?? '';
		$city = sanitize_text_field( (string) ( $input['city'] ?? '' ) );
		$fias_id = sanitize_text_field( (string) ( $input['location_fias_id'] ?? '' ) );
		$item = new PackageItem( 'SIM', 'Simulation', 1, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ), $weight );
		$package = Package::from_items( array( $item ), 0, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ) );
		$packaging = $this->packaging_calculator instanceof PackagingWeightCalculator
			? $this->packaging_calculator->apply_to_package( $package, $service )
			: new PackagingApplicationResult( $package->weight_g, 0, $package->get_total_weight_g(), $service->include_packaging_weight, $service->packaging_weight_mode, $package );
		$package = $packaging->package;
		$request = new QuoteRequest(
			'RU',
			new Address( country_code: 'RU', city: $city, settlement: $city, postcode: $postcode, raw_address: $city, fias_id: $fias_id ),
			$package,
			'',
			Money::from_rubles( $order_total ),
			$date,
			array(
				'service_key' => $service->service_key,
				'postcode' => $postcode,
				'fias_id' => $fias_id,
				'city' => $city,
			)
		);
		$quote = $this->russian_post_domestic_carrier->quote( $request );
		$rows = array();
		$audit = array();
		foreach ( $quote->rates as $rate ) {
			if ( ! $rate instanceof DeliveryRate ) {
				continue;
			}
			$context = new RuleEvaluationContext( Money::from_rubles( $order_total ), $rate->price, $package, $request->destination, $rate->delivery_type, '', $date, array(), array_merge( $rate->meta, array( 'original_delivery_days' => $rate->delivery_days->min_days ?? $rate->delivery_days->max_days, 'original_delivery_min_days' => $rate->delivery_days->min_days, 'original_delivery_max_days' => $rate->delivery_days->max_days ) ) );
			$applied = $this->rule_builder->apply( $rate, $context, $rules );
			$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $applied['rate'], $service ) : $applied['rate'];
			$rows[] = array(
				'object_code' => $rate->tariff_key,
				'title' => $rate->tariff_name,
				'api_price' => $rate->price->get_rubles() . ' ' . $rate->price->get_currency(),
				'api_delivery_days' => $this->range_label( $rate->delivery_days ),
				'final_price' => $processed->price->get_rubles() . ' ' . $processed->price->get_currency(),
				'final_delivery_days' => $this->range_label( $processed->delivery_days ),
			);
			$audit[ $rate->tariff_key ] = $applied['audit'];
		}

		return array(
			'tariffs' => $rows,
			'products_weight_g' => $packaging->original_products_weight_g,
			'packaging_weight_g' => $packaging->packaging_weight_g,
			'package_weight_with_packaging_g' => $packaging->final_package_weight_g,
			'packaging_weight_mode' => $packaging->packaging_weight_mode,
			'source' => $quote->source . ( '' !== $quote->error_code ? ' / ' . $quote->error_code : '' ),
			'skipped_tariffs' => is_array( $quote->raw_reference['skipped_tariffs'] ?? null ) ? $quote->raw_reference['skipped_tariffs'] : array(),
			'audit' => $audit,
			'notice' => array() === $rows ? ( $quote->error_message ?: $quote->error_code ) : '',
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,Rule> $rules
	 * @return array<string,mixed>
	 */
	private function simulate_runtime_carrier_service_rules( DeliveryService $service, array $input, array $rules, ?CarrierAdapterInterface $carrier, string $error_prefix ): array {
		if ( ! $carrier instanceof CarrierAdapterInterface || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'notice' => $error_prefix . __( 'runtime-компонент службы не подключён.', 'walls-delivery-calc' ) );
		}

		try {
			$request = $this->simulation_quote_request( $service, $input );
			$quote = $carrier->quote( $request );
		} catch ( \Throwable $exception ) {
			return array( 'notice' => $error_prefix . $exception->getMessage() );
		}

		$rows = array();
		$audit = array();
		foreach ( $quote->rates as $rate ) {
			if ( ! $rate instanceof DeliveryRate ) {
				continue;
			}

			$context = new RuleEvaluationContext(
				$request->order_total,
				$rate->price,
				$request->package,
				$request->destination,
				$rate->delivery_type,
				$request->payment_method,
				$request->calculation_date,
				array(),
				array_merge(
					$rate->meta,
					array(
						'original_delivery_days' => $rate->delivery_days->min_days ?? $rate->delivery_days->max_days,
						'original_delivery_min_days' => $rate->delivery_days->min_days,
						'original_delivery_max_days' => $rate->delivery_days->max_days,
					)
				)
			);
			$applied = $this->rule_builder->apply( $rate, $context, $rules );
			$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $applied['rate'], $service ) : $applied['rate'];
			$rows[] = array(
				'object_code' => $rate->tariff_key,
				'title' => $rate->tariff_name ?: $rate->title,
				'delivery_type' => $rate->delivery_type,
				'api_price' => $rate->price->get_rubles() . ' ' . $rate->price->get_currency(),
				'api_delivery_days' => $this->range_label( $rate->delivery_days ),
				'final_price' => $processed->price->get_rubles() . ' ' . $processed->price->get_currency(),
				'final_delivery_days' => $this->range_label( $processed->delivery_days ),
				'disabled' => $processed->disabled ? 'yes' : 'no',
				'disabled_reason' => $processed->disabled_reason,
				'comments' => implode( '; ', array_map( 'strval', $processed->comments ) ),
				'formula_visualization' => is_array( $processed->meta['formula_visualization'] ?? null ) ? implode( "\n", array_map( 'strval', $processed->meta['formula_visualization'] ) ) : '',
				'lead_time_audit' => is_array( $processed->meta['lead_time_audit'] ?? null ) ? implode( "\n", array_map( 'strval', $processed->meta['lead_time_audit'] ) ) : '',
			);
			$audit[ $rate->tariff_key ?: $rate->rate_id ] = $applied['audit'];
		}

		return array(
			'tariffs' => $rows,
			'products_weight_g' => $request->package->weight_g,
			'packaging_weight_g' => (int) ( $request->package->packaging_weight_g ?? 0 ),
			'package_weight_with_packaging_g' => $request->package->get_total_weight_g(),
			'packaging_weight_mode' => $request->package->packaging_weight_mode,
			'dimensions_cm' => trim( (string) $request->package->length_cm . 'x' . (string) $request->package->width_cm . 'x' . (string) $request->package->height_cm, 'x' ),
			'source' => implode( ' / ', array_filter( array( $quote->source, $quote->error_code, $quote->cache_hit ? 'cache hit' : '' ) ) ),
			'skipped_tariffs' => is_array( $quote->raw_reference['skipped_tariffs'] ?? null ) ? $quote->raw_reference['skipped_tariffs'] : array(),
			'audit' => $audit,
			'notice' => array() === $rows ? ( $quote->error_message ?: $quote->error_code ?: $error_prefix . __( 'служба не вернула тарифы.', 'walls-delivery-calc' ) ) : ( array() === $rules ? __( 'Для службы не настроены собственные правила.', 'walls-delivery-calc' ) : '' ),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function simulation_quote_request( DeliveryService $service, array $input ): QuoteRequest {
		$country = strtoupper( sanitize_text_field( (string) ( $input['country'] ?? 'RU' ) ) );
		$weight = max( 0, (int) ( $input['weight'] ?? 1000 ) );
		$order_total_value = (float) str_replace( ',', '.', (string) ( $input['order_total'] ?? 1000 ) );
		$date = sanitize_text_field( (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) ) );
		$city = sanitize_text_field( (string) ( $input['city'] ?? '' ) );
		$postcode = sanitize_text_field( (string) ( $input['postal_code'] ?? '' ) );
		$fias_id = sanitize_text_field( (string) ( $input['location_fias_id'] ?? '' ) );
		$delivery_type = DeliveryType::COURIER === (string) ( $input['delivery_type'] ?? '' ) ? DeliveryType::COURIER : DeliveryType::PICKUP;
		$order_total = Money::from_rubles( $order_total_value );
		$item = new PackageItem(
			'SIM',
			'Simulation',
			1,
			$order_total,
			$order_total,
			$weight,
			max( 0, (int) round( (float) ( $input['length_cm'] ?? 0 ) ) ),
			max( 0, (int) round( (float) ( $input['width_cm'] ?? 0 ) ) ),
			max( 0, (int) round( (float) ( $input['height_cm'] ?? 0 ) ) )
		);
		$package = Package::from_items( array( $item ), 0, $order_total, $order_total );
		$packaging = $this->packaging_calculator instanceof PackagingWeightCalculator
			? $this->packaging_calculator->apply_to_package( $package, $service )
			: new PackagingApplicationResult( $package->weight_g, 0, $package->get_total_weight_g(), $service->include_packaging_weight, $service->packaging_weight_mode, $package );

		$location_id = max( 0, (int) ( $input['selected_location_id'] ?? $input['location_id'] ?? 0 ) );

		return new QuoteRequest(
			$country,
			new Address( country_code: $country, city: $city, settlement: $city, postcode: $postcode, raw_address: $city, fias_id: $fias_id ),
			$packaging->package,
			sanitize_text_field( (string) ( $input['payment_method'] ?? '' ) ),
			$order_total,
			$date,
			array(
				'service_key' => $service->service_key,
				'delivery_type' => $delivery_type,
				'postcode' => $postcode,
				'city' => $city,
				'fias_id' => $fias_id,
				'selected_location_fias_id' => $fias_id,
				'location_id' => $location_id,
				'selected_location_id' => $location_id,
				'selected_delivery_terminal_code' => sanitize_text_field( (string) ( $input['selected_delivery_terminal_code'] ?? '' ) ),
			)
		);
	}

	private function range_label( DateRange $range ): string {
		$label = DeliveryDaysFormatter::format( $range );

		return '' !== $label ? $label : '-';
	}

	/**
	 * @param array<string,mixed> $report
	 */
	private function dpd_import_report_message( array $report ): string {
		return sprintf(
			'DPD geography import: phase=%s status=%s source=%s file=%s total=%d ru=%d candidates=%d finalized=%d changes=%d stale_cleared=%d stale_cleanup_skipped=%s duplicate_identity_rows=%d unchanged=%d conflicts=%d ambiguous=%d unmatched=%d errors=%d',
			(string) ( $report['phase'] ?? '' ),
			(string) ( $report['status'] ?? '' ),
			(string) ( $report['source'] ?? '' ),
			(string) ( $report['source_file'] ?? '' ),
			(int) ( $report['total_rows'] ?? 0 ),
			(int) ( $report['ru_rows'] ?? 0 ),
			(int) ( $report['saved_candidates'] ?? 0 ),
			(int) ( $report['finalized_mappings'] ?? 0 ),
			(int) ( $report['finalized_changes'] ?? 0 ),
			(int) ( $report['stale_cleared'] ?? 0 ),
			! empty( $report['stale_cleanup_skipped'] ) ? 'yes' : 'no',
			(int) ( $report['foreign_duplicate_identity_rows'] ?? 0 ),
			(int) ( $report['unchanged_mappings'] ?? 0 ),
			(int) ( $report['conflicts'] ?? 0 ),
			(int) ( $report['ambiguous'] ?? 0 ),
			(int) ( $report['unmatched'] ?? 0 ),
			(int) ( $report['errors_total'] ?? ( is_array( $report['errors'] ?? null ) ? count( $report['errors'] ) : 0 ) )
		);
	}

	private function money_label( Money $money ): string {
		return number_format( $money->get_rubles(), 2, '.', ' ' ) . ' ' . $money->get_currency();
	}

	private function cdek_delivery_mode_label( int $mode ): string {
		return match ( $mode ) {
			1 => __( 'Дверь-дверь', 'walls-delivery-calc' ),
			2 => __( 'Дверь-склад', 'walls-delivery-calc' ),
			3 => __( 'Склад-дверь', 'walls-delivery-calc' ),
			4 => __( 'Склад-склад', 'walls-delivery-calc' ),
			default => __( 'Не определен', 'walls-delivery-calc' ),
		};
	}
}
