<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Admin\AdminNotices;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Calendar\Admin\CalendarAdminPage;
use WallsShop\WDC\Calendar\Services\CalendarScheduler;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\Api\WpCdekHttpClient;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffSyncService;
use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdDuplicateCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdGeographyDiagnosticService;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClient;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdDaDataDeliveryClientInterface;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdDaDataDeliveryFallbackService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyCsvParser;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyFtpClient;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportStateService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyStageRepository;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdLocationIndex;
use WallsShop\WDC\Carriers\Dpd\Geography\WpDpdDaDataDeliveryClient;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointAutoSync;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointNormalizer;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentDateResolver;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdPackagingBuilderFactory;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequestBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Api\WpYandexDeliveryHttpClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentService;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMapperV2Service;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationManualOverrideV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2ImportService;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2JsonStreamReader;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2ScheduleFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingRequestBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Pricing\YandexDeliveryPricingResponseParser;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryEarliestOfferSelector;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationService as CoreYandexDeliveryShipmentRegistrationService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingService;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCourierTariffProbeService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Carriers\Runtime\DpdQuoteCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Carriers\Runtime\YandexDeliveryCarrier;
use WallsShop\WDC\Checkout\Address\CheckoutAddressNormalizer;
use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Address\FiasAddressNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionAjax;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionNormalizer;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\Admin\CheckoutSimulationPage;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Locations\LocationCoordinateEnricher;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutAddressRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDebugPanel;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDeliveryTypeSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutFeatureGate;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSortSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupMapCheckout;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointOrderDisplay;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointRenderer;
use WallsShop\WDC\Checkout\WooCommerce\ShippingMethodRegistrar;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater;
use WallsShop\WDC\Locations\Fias\FiasCredentials;
use WallsShop\WDC\Locations\Fias\FiasEndpoints;
use WallsShop\WDC\Locations\Fias\FiasHttpClient;
use WallsShop\WDC\Locations\Fias\FiasLogger;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Gar\GarChangesClient;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\FiasImportManager;
use WallsShop\WDC\Locations\Import\GarPlacesCsvImporter;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Import\LocationIncrementalUpdateService;
use WallsShop\WDC\Locations\Import\LocationsSnapshotExporter;
use WallsShop\WDC\Locations\Import\LocationsSnapshotImporter;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService;
use WallsShop\WDC\Locations\Services\GarChangesService;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\RegionRepository;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRateRenderer;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRecalculationAdminController;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Pickup\Admin\PickupAdminPage;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Pickup\Presentation\PickupPointPresentationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupDiagnosticsService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupLocationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPassportPointNormalizer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\Search\PickupAddressSearchService;
use WallsShop\WDC\Pickup\Services\PickupPointLocationResolver;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Admin\ShipmentStatusesAdminPage;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostExtractor;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostLookupService;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentModalRequestMapper;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekCreateRequestBuilder;
use WallsShop\WDC\Shipments\Cdek\CdekBarcodePrintService;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAdapter;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentDocumentProvider;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentModalExtension;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Cdek\CdekStatusMappingService;
use WallsShop\WDC\Shipments\Dpd\DpdEventNormalizer;
use WallsShop\WDC\Shipments\Dpd\DpdEventSyncService;
use WallsShop\WDC\Shipments\Dpd\DpdOrderRegistrationService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentDocumentService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentDocumentProvider;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentModalExtension;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentEnrichmentService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentRepository;
use WallsShop\WDC\Shipments\Dpd\DpdStatusMapping;
use WallsShop\WDC\Shipments\RussianPost\RussianPostCreateRequestBuilder;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentDocumentProvider;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentDocumentService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentModalExtension;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentAdapter;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentProductMapper;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentButtonPolicy;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentDocumentService;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentDocumentProvider;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentModalExtension;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentLabelPolicy;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRegistrationService;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexStatusMapping;
use WallsShop\WDC\WooCommerce\HPOSCompatibility;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private PluginEnvironment $environment;

	private Container $container;

	public function __construct( PluginEnvironment $environment, ?Container $container = null ) {
		$this->environment = $environment;
		$this->container   = $container ?? new Container();
	}

	public function register(): void {
		$this->register_services();
		$this->register_hooks();
	}

	public function container(): Container {
		return $this->container;
	}

	private function register_services(): void {
		$this->container->register( PluginEnvironment::class, fn(): PluginEnvironment => $this->environment );
		$this->container->register( PluginConstants::class, fn(): PluginConstants => new PluginConstants( $this->environment ) );
		$this->container->register( FeatureFlags::class, fn(): FeatureFlags => new FeatureFlags() );
		$this->container->register( Logger::class, fn(): Logger => new Logger() );
		$this->container->register( SettingsRepository::class, fn(): SettingsRepository => new SettingsRepository() );
		$this->container->register( CheckoutFeatureGate::class, fn(): CheckoutFeatureGate => new CheckoutFeatureGate( $this->container->get( FeatureFlags::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( EncryptionService::class, fn(): EncryptionService => new EncryptionService() );
		$this->container->register( MigrationManager::class, fn(): MigrationManager => new MigrationManager( $this->environment->version(), $this->environment->plugin_dir() . 'database/migrations' ) );
		$this->container->register( ActionScheduler::class, fn(): ActionScheduler => new ActionScheduler( $this->container->get( Logger::class ) ) );
		$this->container->register( CalendarRepository::class, fn(): CalendarRepository => new CalendarRepository() );
		$this->container->register( LocationRepository::class, fn(): LocationRepository => new LocationRepository() );
		$this->container->register( LocationDeliveryCodeRepository::class, fn(): LocationDeliveryCodeRepository => new LocationDeliveryCodeRepository() );
		$this->container->register( RegionRepository::class, fn(): RegionRepository => new RegionRepository() );
		$this->container->register( PickupPointRepository::class, fn(): PickupPointRepository => new PickupPointRepository() );
		$this->container->register( RussianPostPickupPointRepository::class, fn(): RussianPostPickupPointRepository => new RussianPostPickupPointRepository() );
		$this->container->register( RussianPostPickupLocationResolver::class, fn(): RussianPostPickupLocationResolver => new RussianPostPickupLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( RussianPostPickupDiagnosticsService::class, fn(): RussianPostPickupDiagnosticsService => new RussianPostPickupDiagnosticsService( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( LocationRepository::class ), location_resolver: $this->container->get( RussianPostPickupLocationResolver::class ) ) );
		$this->container->register( RussianPostPickupPointTypeSettings::class, fn(): RussianPostPickupPointTypeSettings => new RussianPostPickupPointTypeSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( PickupPointLocationResolver::class, fn(): PickupPointLocationResolver => new PickupPointLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( CheckoutPickupPointRestController::class, fn(): CheckoutPickupPointRestController => new CheckoutPickupPointRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( CheckoutSessionManager::class ), $this->container->get( PickupPointLocationResolver::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ) ) );
		$this->container->register( RuleRepository::class, fn(): RuleRepository => new RuleRepository() );
		$this->container->register( DeliveryServiceRepository::class, fn(): DeliveryServiceRepository => new DeliveryServiceRepository() );
		$this->container->register( DeliveryServiceSettingsRepository::class, fn(): DeliveryServiceSettingsRepository => new DeliveryServiceSettingsRepository() );
		$this->container->register( DeliveryServiceCountryRepository::class, fn(): DeliveryServiceCountryRepository => new DeliveryServiceCountryRepository() );
		$this->container->register( PackagingWeightCalculator::class, fn(): PackagingWeightCalculator => new PackagingWeightCalculator( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( ConditionEvaluator::class, fn(): ConditionEvaluator => new ConditionEvaluator() );
		$this->container->register( RuleEvaluator::class, fn(): RuleEvaluator => new RuleEvaluator( $this->container->get( ConditionEvaluator::class ) ) );
		$this->container->register( RuleEngine::class, fn(): RuleEngine => new RuleEngine( $this->container->get( RuleEvaluator::class ) ) );
		$this->container->register( RuleSimulator::class, fn(): RuleSimulator => new RuleSimulator( $this->container->get( RuleEngine::class ) ) );
		$this->container->register( RussianPostSettings::class, fn(): RussianPostSettings => new RussianPostSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( RussianPostApiClient::class, fn(): RussianPostApiClient => new RussianPostApiClient( $this->container->get( RussianPostSettings::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostDomesticSettings::class, fn(): RussianPostDomesticSettings => new RussianPostDomesticSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( RussianPostDomesticTariffVariantResolver::class, fn(): RussianPostDomesticTariffVariantResolver => new RussianPostDomesticTariffVariantResolver() );
		$this->container->register( RussianPostDomesticApiClient::class, fn(): RussianPostDomesticApiClient => new RussianPostDomesticApiClient( $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( CdekSettings::class, fn(): CdekSettings => new CdekSettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( WpCdekHttpClient::class, fn(): WpCdekHttpClient => new WpCdekHttpClient( 20 ) );
		$this->container->register( CdekOAuthTokenService::class, fn(): CdekOAuthTokenService => new CdekOAuthTokenService( $this->container->get( CdekSettings::class ), $this->container->get( WpCdekHttpClient::class ) ) );
		$this->container->register( CdekApiClient::class, fn(): CdekApiClient => new CdekApiClient( $this->container->get( CdekOAuthTokenService::class ), $this->container->get( CdekSettings::class ), $this->container->get( WpCdekHttpClient::class ) ) );
		$this->container->register( CdekLocationResolver::class, fn(): CdekLocationResolver => new CdekLocationResolver( $this->container->get( CdekApiClient::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( CdekDeliveryPointService::class, fn(): CdekDeliveryPointService => new CdekDeliveryPointService( $this->container->get( CdekApiClient::class ), $this->container->get( CdekSettings::class ), $this->container->get( CdekLocationResolver::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( CdekTariffRepository::class, fn(): CdekTariffRepository => new CdekTariffRepository() );
		$this->container->register( CdekTariffSyncService::class, fn(): CdekTariffSyncService => new CdekTariffSyncService( $this->container->get( CdekApiClient::class ), $this->container->get( CdekTariffRepository::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdSettings::class, fn(): DpdSettings => new DpdSettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( YandexDeliverySettings::class, fn(): YandexDeliverySettings => new YandexDeliverySettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( YandexDeliveryHttpClientInterface::class, fn(): YandexDeliveryHttpClientInterface => new WpYandexDeliveryHttpClient( $this->container->get( YandexDeliverySettings::class )->request_timeout() ) );
		$this->container->register( YandexDeliveryApiClient::class, fn(): YandexDeliveryApiClient => new YandexDeliveryApiClient( $this->container->get( YandexDeliverySettings::class ), $this->container->get( YandexDeliveryHttpClientInterface::class ) ) );
		$this->container->register( YandexDeliveryShipmentPayloadBuilder::class, fn(): YandexDeliveryShipmentPayloadBuilder => new YandexDeliveryShipmentPayloadBuilder() );
		$this->container->register( YandexDeliveryShipmentClient::class, fn(): YandexDeliveryShipmentClient => new YandexDeliveryShipmentClient( $this->container->get( YandexDeliveryApiClient::class ) ) );
		$this->container->register( YandexDeliveryEarliestOfferSelector::class, fn(): YandexDeliveryEarliestOfferSelector => new YandexDeliveryEarliestOfferSelector() );
		$this->container->register( CoreYandexDeliveryShipmentRegistrationService::class, fn(): CoreYandexDeliveryShipmentRegistrationService => new CoreYandexDeliveryShipmentRegistrationService( $this->container->get( YandexDeliveryShipmentPayloadBuilder::class ), $this->container->get( YandexDeliveryShipmentClient::class ), $this->container->get( YandexDeliveryEarliestOfferSelector::class ) ) );
		$this->container->register( YandexDeliveryConnectionDiagnosticService::class, fn(): YandexDeliveryConnectionDiagnosticService => new YandexDeliveryConnectionDiagnosticService( $this->container->get( YandexDeliverySettings::class ), $this->container->get( YandexDeliveryApiClient::class ) ) );
		$this->container->register( YandexDeliveryPricingRequestBuilder::class, fn(): YandexDeliveryPricingRequestBuilder => new YandexDeliveryPricingRequestBuilder( $this->container->get( PackagingBuilder::class ) ) );
		$this->container->register( YandexDeliveryPricingResponseParser::class, fn(): YandexDeliveryPricingResponseParser => new YandexDeliveryPricingResponseParser() );
		$this->container->register( YandexDeliveryPickupPointV2Repository::class, fn(): YandexDeliveryPickupPointV2Repository => new YandexDeliveryPickupPointV2Repository() );
		$this->container->register( YandexDeliveryPickupPointV2JsonStreamReader::class, fn(): YandexDeliveryPickupPointV2JsonStreamReader => new YandexDeliveryPickupPointV2JsonStreamReader() );
		$this->container->register( YandexDeliveryPickupPointV2ScheduleFormatter::class, fn(): YandexDeliveryPickupPointV2ScheduleFormatter => new YandexDeliveryPickupPointV2ScheduleFormatter() );
		$this->container->register( YandexDeliveryPickupPointV2ImportService::class, fn(): YandexDeliveryPickupPointV2ImportService => new YandexDeliveryPickupPointV2ImportService( $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexDeliveryPickupPointV2ScheduleFormatter::class ), $this->container->get( YandexDeliveryPickupPointV2JsonStreamReader::class ) ) );
		$this->container->register( YandexDeliveryPickupPointV2RunnerService::class, fn(): YandexDeliveryPickupPointV2RunnerService => new YandexDeliveryPickupPointV2RunnerService( $this->container->get( YandexDeliveryApiClient::class ), $this->container->get( YandexDeliveryPickupPointV2ImportService::class ) ) );
		$this->container->register( YandexDeliveryGeoV2Repository::class, fn(): YandexDeliveryGeoV2Repository => new YandexDeliveryGeoV2Repository() );
		$this->container->register( YandexDeliveryGeoV2BuilderService::class, fn(): YandexDeliveryGeoV2BuilderService => new YandexDeliveryGeoV2BuilderService( $this->container->get( YandexDeliveryGeoV2Repository::class ) ) );
		$this->container->register( YandexDeliveryGeoV2BuilderRunnerService::class, fn(): YandexDeliveryGeoV2BuilderRunnerService => new YandexDeliveryGeoV2BuilderRunnerService( $this->container->get( YandexDeliveryGeoV2BuilderService::class ) ) );
		$this->container->register( YandexLocationMappingV2Repository::class, fn(): YandexLocationMappingV2Repository => new YandexLocationMappingV2Repository() );
		$this->container->register( YandexLocationManualOverrideV2Repository::class, fn(): YandexLocationManualOverrideV2Repository => new YandexLocationManualOverrideV2Repository() );
		$this->container->register( YandexRegionMappingV2Repository::class, fn(): YandexRegionMappingV2Repository => new YandexRegionMappingV2Repository() );
		$this->container->register( YandexGeoV2RegionEnrichmentService::class, fn(): YandexGeoV2RegionEnrichmentService => new YandexGeoV2RegionEnrichmentService( $this->container->get( YandexDeliveryGeoV2Repository::class ), null, null, $this->container->get( YandexRegionMappingV2Repository::class ) ) );
		$this->container->register( YandexGeoV2RegionEnrichmentRunner::class, fn(): YandexGeoV2RegionEnrichmentRunner => new YandexGeoV2RegionEnrichmentRunner( $this->container->get( YandexGeoV2RegionEnrichmentService::class ), $this->container->get( YandexDeliveryGeoV2Repository::class ) ) );
		$this->container->register( YandexLocationMapperV2Service::class, fn(): YandexLocationMapperV2Service => new YandexLocationMapperV2Service( $this->container->get( YandexLocationMappingV2Repository::class ), null, null, $this->container->get( YandexRegionMappingV2Repository::class ), $this->container->get( YandexLocationManualOverrideV2Repository::class ) ) );
		$this->container->register( YandexLocationMappingV2Runner::class, fn(): YandexLocationMappingV2Runner => new YandexLocationMappingV2Runner( $this->container->get( YandexLocationMapperV2Service::class ), $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( YandexDeliveryGeoPipelineV2Runner::class, fn(): YandexDeliveryGeoPipelineV2Runner => new YandexDeliveryGeoPipelineV2Runner( $this->container->get( YandexDeliveryPickupPointV2RunnerService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexDeliveryGeoV2BuilderRunnerService::class ), $this->container->get( YandexDeliveryGeoV2Repository::class ), $this->container->get( YandexGeoV2RegionEnrichmentRunner::class ), $this->container->get( YandexRegionMappingV2Repository::class ), $this->container->get( YandexLocationMappingV2Runner::class ), $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( DpdSoapClientInterface::class, fn(): DpdSoapClientInterface => new DpdSoapClient( $this->container->get( DpdSettings::class )->request_timeout() ) );
		$this->container->register( DpdApiClient::class, fn(): DpdApiClient => new DpdApiClient( $this->container->get( DpdSettings::class ), $this->container->get( DpdSoapClientInterface::class ) ) );
		$this->container->register( DpdDuplicateCityResolver::class, fn(): DpdDuplicateCityResolver => new DpdDuplicateCityResolver() );
		$this->container->register( DpdCityResolver::class, fn(): DpdCityResolver => new DpdCityResolver( $this->container->get( LocationDeliveryCodeRepository::class ) ) );
		$this->container->register( DpdGeographyDiagnosticService::class, fn(): DpdGeographyDiagnosticService => new DpdGeographyDiagnosticService( $this->container->get( DpdCityResolver::class ), $this->container->get( LocationDeliveryCodeRepository::class ), $this->container->get( LocationRepository::class ) ) );
		$this->container->register( DpdGeographyCsvParser::class, fn(): DpdGeographyCsvParser => new DpdGeographyCsvParser() );
		$this->container->register( DpdLocationIndex::class, fn(): DpdLocationIndex => new DpdLocationIndex( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( DpdGeographyMatcher::class, fn(): DpdGeographyMatcher => new DpdGeographyMatcher( $this->container->get( DpdLocationIndex::class ) ) );
		$this->container->register( DpdGeographyImportStateService::class, fn(): DpdGeographyImportStateService => new DpdGeographyImportStateService() );
		$this->container->register( DpdGeographyStageRepository::class, fn(): DpdGeographyStageRepository => new DpdGeographyStageRepository() );
		$this->container->register( DpdGeographyImportService::class, fn(): DpdGeographyImportService => new DpdGeographyImportService( $this->container->get( DpdGeographyCsvParser::class ), $this->container->get( DpdGeographyMatcher::class ), $this->container->get( DpdLocationIndex::class ), $this->container->get( DpdGeographyImportStateService::class ), $this->container->get( DpdGeographyStageRepository::class ), $this->container->get( DpdSettings::class ) ) );
		$this->container->register( DpdGeographyFtpClient::class, fn(): DpdGeographyFtpClient => new DpdGeographyFtpClient( $this->container->get( DpdSettings::class ) ) );
		$this->container->register( DpdDaDataDeliveryClientInterface::class, fn(): DpdDaDataDeliveryClientInterface => new WpDpdDaDataDeliveryClient( $this->container->get( AddressSuggestionSettings::class ), $this->container->get( DaDataTokenPool::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdDaDataDeliveryFallbackService::class, fn(): DpdDaDataDeliveryFallbackService => new DpdDaDataDeliveryFallbackService( $this->container->get( LocationRepository::class ), $this->container->get( LocationDeliveryCodeRepository::class ), $this->container->get( DpdDaDataDeliveryClientInterface::class ) ) );
		$this->container->register( DpdPickupPointRepository::class, fn(): DpdPickupPointRepository => new DpdPickupPointRepository() );
		$this->container->register( DpdPickupPointNormalizer::class, fn(): DpdPickupPointNormalizer => new DpdPickupPointNormalizer() );
		$this->container->register( DpdPickupPointImportService::class, fn(): DpdPickupPointImportService => new DpdPickupPointImportService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdPickupPointNormalizer::class ), $this->container->get( DpdPickupPointRepository::class ), $this->container->get( DpdSettings::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdPickupPointAutoSync::class, fn(): DpdPickupPointAutoSync => new DpdPickupPointAutoSync( $this->container->get( DpdSettings::class ), $this->container->get( DpdPickupPointImportService::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdPickupPointService::class, fn(): DpdPickupPointService => new DpdPickupPointService( $this->container->get( DpdPickupPointRepository::class ), $this->container->get( LocationDeliveryCodeRepository::class ) ) );
		$this->container->register( PackagingBuilderConfig::class, fn(): PackagingBuilderConfig => PackagingBuilderConfig::defaults() );
		$this->container->register( PackagingBuilder::class, fn(): PackagingBuilder => new PackagingBuilder( $this->container->get( PackagingBuilderConfig::class ), $this->container->get( PackagingWeightCalculator::class ) ) );
		$this->container->register( DpdPackagingBuilderFactory::class, fn(): DpdPackagingBuilderFactory => new DpdPackagingBuilderFactory( $this->container->get( PackagingWeightCalculator::class ) ) );
		$this->container->register( DpdTariffRequestBuilder::class, fn(): DpdTariffRequestBuilder => new DpdTariffRequestBuilder() );
		$this->container->register( DpdTariffOptionNormalizer::class, fn(): DpdTariffOptionNormalizer => new DpdTariffOptionNormalizer() );
		$this->container->register( DpdTerminalCodeTariffRequestBuilder::class, fn(): DpdTerminalCodeTariffRequestBuilder => new DpdTerminalCodeTariffRequestBuilder() );
		$this->container->register( DpdTariffCalculationService::class, fn(): DpdTariffCalculationService => new DpdTariffCalculationService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdCityResolver::class ), $this->container->get( LocationRepository::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdTariffRequestBuilder::class ), $this->container->get( DpdTariffOptionNormalizer::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( DpdTerminalCodeTariffRequestBuilder::class ) ) );
		$this->container->register( RussianPostCourierTariffProbeService::class, fn(): RussianPostCourierTariffProbeService => new RussianPostCourierTariffProbeService( $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostOtpravkaApiSettings::class, fn(): RussianPostOtpravkaApiSettings => new RussianPostOtpravkaApiSettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( RussianPostOtpravkaApiClient::class, fn(): RussianPostOtpravkaApiClient => new RussianPostOtpravkaApiClient( $this->container->get( RussianPostOtpravkaApiSettings::class ) ) );
		$this->container->register( RussianPostTrackingApiClient::class, fn(): RussianPostTrackingApiClient => new RussianPostTrackingApiClient( $this->container->get( RussianPostOtpravkaApiSettings::class ) ) );
		$this->container->register( RussianPostTrackingStatusMapper::class, fn(): RussianPostTrackingStatusMapper => new RussianPostTrackingStatusMapper() );
		$this->container->register( OrderShipmentRepository::class, fn(): OrderShipmentRepository => new OrderShipmentRepository() );
		$this->container->register( ShipmentServiceSettings::class, fn(): ShipmentServiceSettings => new ShipmentServiceSettings( $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( RussianPostShipmentProductMapper::class, fn(): RussianPostShipmentProductMapper => new RussianPostShipmentProductMapper() );
		$this->container->register( RussianPostCreateRequestBuilder::class, fn(): RussianPostCreateRequestBuilder => new RussianPostCreateRequestBuilder( $this->container->get( RussianPostShipmentProductMapper::class ) ) );
		$this->container->register( RussianPostAddressNormalizer::class, fn(): RussianPostAddressNormalizer => new RussianPostAddressNormalizer( $this->container->get( RussianPostOtpravkaApiClient::class ) ) );
		$this->container->register( RussianPostShipmentAdapter::class, fn(): RussianPostShipmentAdapter => new RussianPostShipmentAdapter( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostCreateRequestBuilder::class ), $this->container->get( Logger::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentBacklogService::class ) ) );
		$this->container->register( CdekCreateRequestBuilder::class, fn(): CdekCreateRequestBuilder => new CdekCreateRequestBuilder( $this->container->get( CdekSettings::class ) ) );
		$this->container->register( CdekShipmentAdapter::class, fn(): CdekShipmentAdapter => new CdekShipmentAdapter( $this->container->get( CdekApiClient::class ), $this->container->get( CdekCreateRequestBuilder::class ), $this->container->get( Logger::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekOrderStatusService::class ), $this->container->get( CdekBarcodePrintService::class ) ) );
		$this->container->register( ShipmentActualCostComparisonService::class, fn(): ShipmentActualCostComparisonService => new ShipmentActualCostComparisonService() );
		$this->container->register( ShipmentBaseApiCostResolver::class, fn(): ShipmentBaseApiCostResolver => new ShipmentBaseApiCostResolver() );
		$this->container->register( DpdShipmentDateResolver::class, fn(): DpdShipmentDateResolver => new DpdShipmentDateResolver( $this->container->get( CalendarService::class ), $this->container->get( TimezoneService::class ) ) );
		$this->container->register( DpdShipmentPayloadBuilder::class, fn(): DpdShipmentPayloadBuilder => new DpdShipmentPayloadBuilder( $this->container->get( DpdSettings::class ) ) );
		$this->container->register( DpdShipmentRepository::class, fn(): DpdShipmentRepository => new DpdShipmentRepository( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( DpdEventNormalizer::class, fn(): DpdEventNormalizer => new DpdEventNormalizer() );
		$this->container->register( DpdShipmentButtonPolicy::class, fn(): DpdShipmentButtonPolicy => new DpdShipmentButtonPolicy() );
		$this->container->register( DpdStatusMapping::class, fn(): DpdStatusMapping => new DpdStatusMapping( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( DpdEventSyncService::class, fn(): DpdEventSyncService => new DpdEventSyncService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdShipmentRepository::class ), $this->container->get( DpdEventNormalizer::class ), $this->container->get( DpdStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ), $this->container->get( Logger::class ), $this->container->get( DpdShipmentEnrichmentService::class ) ) );
		$this->container->register( DpdShipmentEnrichmentService::class, fn(): DpdShipmentEnrichmentService => new DpdShipmentEnrichmentService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdShipmentRepository::class ) ) );
		$this->container->register( DpdShipmentDocumentService::class, fn(): DpdShipmentDocumentService => new DpdShipmentDocumentService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( DpdApiClient::class ) ) );
		$this->container->register( DpdShipmentDocumentProvider::class, fn(): DpdShipmentDocumentProvider => new DpdShipmentDocumentProvider( $this->container->get( DpdShipmentDocumentService::class ) ) );
		$this->container->register( DpdOrderRegistrationService::class, fn(): DpdOrderRegistrationService => new DpdOrderRegistrationService( $this->container->get( DpdShipmentPayloadBuilder::class ), $this->container->get( DpdApiClient::class ), $this->container->get( DpdShipmentRepository::class ), $this->container->get( DpdEventSyncService::class ), $this->container->get( DpdShipmentEnrichmentService::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdShipmentAdapter::class, fn(): DpdShipmentAdapter => new DpdShipmentAdapter( $this->container->get( DpdShipmentPayloadBuilder::class ), $this->container->get( DpdApiClient::class ), $this->container->get( DpdOrderRegistrationService::class ), $this->container->get( DpdShipmentButtonPolicy::class ), $this->container->get( DpdShipmentEnrichmentService::class ), $this->container->get( ShipmentActualCostComparisonService::class ), $this->container->get( ShipmentBaseApiCostResolver::class ) ) );
		$this->container->register( YandexShipmentRepository::class, fn(): YandexShipmentRepository => new YandexShipmentRepository( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( YandexStatusMapping::class, fn(): YandexStatusMapping => new YandexStatusMapping( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( YandexShipmentButtonPolicy::class, fn(): YandexShipmentButtonPolicy => new YandexShipmentButtonPolicy( $this->container->get( YandexStatusMapping::class ) ) );
		$this->container->register( YandexShipmentLabelPolicy::class, fn(): YandexShipmentLabelPolicy => new YandexShipmentLabelPolicy( $this->container->get( YandexStatusMapping::class ) ) );
		$this->container->register( YandexShipmentDocumentService::class, fn(): YandexShipmentDocumentService => new YandexShipmentDocumentService( $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexDeliveryShipmentClient::class ), $this->container->get( YandexShipmentLabelPolicy::class ) ) );
		$this->container->register( YandexShipmentDocumentProvider::class, fn(): YandexShipmentDocumentProvider => new YandexShipmentDocumentProvider( $this->container->get( YandexShipmentDocumentService::class ), $this->container->get( YandexShipmentLabelPolicy::class ) ) );
		$this->container->register( YandexShipmentModalExtension::class, fn(): YandexShipmentModalExtension => new YandexShipmentModalExtension( $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( YandexShipmentRegistrationService::class, fn(): YandexShipmentRegistrationService => new YandexShipmentRegistrationService( $this->container->get( CoreYandexDeliveryShipmentRegistrationService::class ), $this->container->get( YandexDeliveryShipmentPayloadBuilder::class ), $this->container->get( YandexDeliveryShipmentClient::class ), $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexShipmentPersistenceMapper::class ), $this->container->get( YandexShipmentButtonPolicy::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( YandexShipmentAdapter::class, fn(): YandexShipmentAdapter => new YandexShipmentAdapter( $this->container->get( YandexShipmentRegistrationService::class ), $this->container->get( YandexShipmentButtonPolicy::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( YandexShipmentLabelPolicy::class ), $this->container->get( ShipmentActualCostComparisonService::class ), $this->container->get( ShipmentBaseApiCostResolver::class ) ) );
		$this->container->register( YandexShipmentPersistenceMapper::class, fn(): YandexShipmentPersistenceMapper => new YandexShipmentPersistenceMapper( $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( ShipmentMetaboxButtonPolicy::class, fn(): ShipmentMetaboxButtonPolicy => new ShipmentMetaboxButtonPolicy() );
		$this->container->register( CdekStatusMappingService::class, fn(): CdekStatusMappingService => new CdekStatusMappingService( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( CdekOrderStatusService::class, fn(): CdekOrderStatusService => new CdekOrderStatusService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekApiClient::class ), $this->container->get( Logger::class ), $this->container->get( CdekStatusMappingService::class ), $this->container->get( ShipmentActualCostComparisonService::class ), $this->container->get( ShipmentBaseApiCostResolver::class ) ) );
		$this->container->register( CdekBarcodePrintService::class, fn(): CdekBarcodePrintService => new CdekBarcodePrintService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekApiClient::class ) ) );
		$this->container->register( CdekShipmentDocumentProvider::class, fn(): CdekShipmentDocumentProvider => new CdekShipmentDocumentProvider( $this->container->get( CdekBarcodePrintService::class ) ) );
		$this->container->register( CdekShipmentModalExtension::class, fn(): CdekShipmentModalExtension => new CdekShipmentModalExtension() );
		$this->container->register( CdekShipmentPersistenceMapper::class, fn(): CdekShipmentPersistenceMapper => new CdekShipmentPersistenceMapper() );
		$this->container->register( DpdShipmentPersistenceMapper::class, fn(): DpdShipmentPersistenceMapper => new DpdShipmentPersistenceMapper() );
		$this->container->register( ShipmentModalRequestMapper::class, fn(): ShipmentModalRequestMapper => new ShipmentModalRequestMapper() );
		$this->container->register( OrderShipmentDraftFactory::class, fn(): OrderShipmentDraftFactory => new OrderShipmentDraftFactory( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentServiceSettings::class ), $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( RussianPostOtpravkaApiSettings::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( CdekSettings::class ), $this->container->get( CdekTariffRepository::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( DpdShipmentDateResolver::class ), $this->container->get( YandexDeliverySettings::class ), $this->container->get( ShipmentModalRequestMapper::class ) ) );
		$this->container->register( RussianPostShipmentActualCostExtractor::class, fn(): RussianPostShipmentActualCostExtractor => new RussianPostShipmentActualCostExtractor() );
		$this->container->register( RussianPostShipmentActualCostLookupService::class, fn(): RussianPostShipmentActualCostLookupService => new RussianPostShipmentActualCostLookupService( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostShipmentActualCostExtractor::class ) ) );
		$this->container->register( RussianPostShipmentDocumentService::class, fn(): RussianPostShipmentDocumentService => new RussianPostShipmentDocumentService( $this->container->get( RussianPostOtpravkaApiClient::class ) ) );
		$this->container->register( RussianPostShipmentDocumentProvider::class, fn(): RussianPostShipmentDocumentProvider => new RussianPostShipmentDocumentProvider( $this->container->get( RussianPostShipmentDocumentService::class ) ) );
		$this->container->register( RussianPostShipmentModalExtension::class, fn(): RussianPostShipmentModalExtension => new RussianPostShipmentModalExtension() );
		$this->container->register( RussianPostShipmentPersistenceMapper::class, fn(): RussianPostShipmentPersistenceMapper => new RussianPostShipmentPersistenceMapper( $this->container->get( RussianPostShipmentActualCostLookupService::class ) ) );
		$this->container->register( ShipmentDocumentProviderRegistry::class, fn(): ShipmentDocumentProviderRegistry => new ShipmentDocumentProviderRegistry( array( $this->container->get( CdekShipmentDocumentProvider::class ), $this->container->get( DpdShipmentDocumentProvider::class ), $this->container->get( YandexShipmentDocumentProvider::class ), $this->container->get( RussianPostShipmentDocumentProvider::class ) ) ) );
		$this->container->register( ShipmentDocumentDownloadService::class, fn(): ShipmentDocumentDownloadService => new ShipmentDocumentDownloadService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentDocumentProviderRegistry::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdShipmentModalExtension::class, fn(): DpdShipmentModalExtension => new DpdShipmentModalExtension( fn(): array => $this->container->get( SettingsRepository::class )->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() ) ) );
		$this->container->register( ShipmentModalExtensionRegistry::class, fn(): ShipmentModalExtensionRegistry => new ShipmentModalExtensionRegistry( array( $this->container->get( CdekShipmentModalExtension::class ), $this->container->get( DpdShipmentModalExtension::class ), $this->container->get( RussianPostShipmentModalExtension::class ), $this->container->get( YandexShipmentModalExtension::class ) ) ) );
		$this->container->register( CarrierShipmentAdapterRegistry::class, fn(): CarrierShipmentAdapterRegistry => new CarrierShipmentAdapterRegistry( array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ) ) ) );
		$this->container->register( ShipmentCreationService::class, fn(): ShipmentCreationService => new ShipmentCreationService( $this->container->get( OrderShipmentRepository::class ), array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ) ), $this->container->get( Logger::class ), $this->container->get( CarrierShipmentAdapterRegistry::class ), array( $this->container->get( RussianPostShipmentPersistenceMapper::class ), $this->container->get( CdekShipmentPersistenceMapper::class ), $this->container->get( DpdShipmentPersistenceMapper::class ), $this->container->get( YandexShipmentPersistenceMapper::class ) ) ) );
		$this->container->register( ShipmentOrderStatusMappingService::class, fn(): ShipmentOrderStatusMappingService => new ShipmentOrderStatusMappingService( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( ShipmentStatusUpdateService::class, fn(): ShipmentStatusUpdateService => new ShipmentStatusUpdateService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( RussianPostTrackingApiClient::class ), $this->container->get( RussianPostTrackingStatusMapper::class ), $this->container->get( ShipmentOrderStatusMappingService::class ), $this->container->get( ShipmentActualCostComparisonService::class ), $this->container->get( ShipmentBaseApiCostResolver::class ) ) );
		$this->container->register( ShipmentStatusAutoSyncService::class, fn(): ShipmentStatusAutoSyncService => new ShipmentStatusAutoSyncService( $this->container->get( SettingsRepository::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentOrderStatusMappingService::class ), null, $this->container->get( CdekOrderStatusService::class ), null, $this->container->get( CarrierShipmentAdapterRegistry::class ), $this->container->get( DpdEventSyncService::class ), $this->container->get( DpdSettings::class ) ) );
		$this->container->register( ShipmentStatusAutoSyncCron::class, fn(): ShipmentStatusAutoSyncCron => new ShipmentStatusAutoSyncCron( $this->container->get( ShipmentStatusAutoSyncService::class ) ) );
		$this->container->register( ShipmentBacklogService::class, fn(): ShipmentBacklogService => new ShipmentBacklogService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( RussianPostShipmentActualCostExtractor::class ) ) );
		$this->container->register( RussianPostPassportPointNormalizer::class, fn(): RussianPostPassportPointNormalizer => new RussianPostPassportPointNormalizer() );
		$this->container->register( RussianPostPickupImportStateService::class, fn(): RussianPostPickupImportStateService => new RussianPostPickupImportStateService() );
		$this->container->register( RussianPostPickupImporter::class, fn(): RussianPostPickupImporter => new RussianPostPickupImporter( $this->container->get( RussianPostOtpravkaApiSettings::class ), $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( RussianPostPassportPointNormalizer::class ), $this->container->get( RussianPostPickupImportStateService::class ), $this->container->get( ActionScheduler::class ), $this->container->get( RussianPostPickupLocationResolver::class ) ) );
		$this->container->register( RussianPostCountryMappingRepository::class, fn(): RussianPostCountryMappingRepository => new RussianPostCountryMappingRepository() );
		$this->container->register( RussianPostCountryMappingService::class, fn(): RussianPostCountryMappingService => new RussianPostCountryMappingService( $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostApiClient::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostCountryDirectory::class, fn(): RussianPostCountryDirectory => new RussianPostCountryDirectory( $this->container->get( RussianPostApiClient::class ), $this->container->get( Logger::class ), $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostCountryMappingService::class ), $this->container->get( RussianPostSettings::class ) ) );
		$this->container->register( RussianPostInternationalCarrier::class, fn(): RussianPostInternationalCarrier => new RussianPostInternationalCarrier( $this->container->get( RussianPostSettings::class ), $this->container->get( RussianPostApiClient::class ), $this->container->get( RussianPostCountryDirectory::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostDomesticCarrier::class, fn(): RussianPostDomesticCarrier => new RussianPostDomesticCarrier( $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( RussianPostDomesticApiClient::class ), $this->container->get( RussianPostDomesticTariffVariantResolver::class ), $this->container->get( Logger::class ), $this->container->get( DaDataPostcodeClient::class ), $this->container->get( LocationRepository::class ) ) );
		$this->container->register( CdekCarrier::class, fn(): CdekCarrier => new CdekCarrier( $this->container->get( CdekSettings::class ), $this->container->get( CdekApiClient::class ), $this->container->get( CdekLocationResolver::class ), $this->container->get( Logger::class ), $this->container->get( CdekTariffRepository::class ) ) );
		$this->container->register( DpdQuoteCarrier::class, fn(): DpdQuoteCarrier => new DpdQuoteCarrier( $this->container->get( DpdSettings::class ), $this->container->get( DpdTariffCalculationService::class ), $this->container->get( DpdPackagingBuilderFactory::class )->create(), $this->container->get( Logger::class ), $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register( YandexDeliveryCarrier::class, fn(): YandexDeliveryCarrier => new YandexDeliveryCarrier( $this->container->get( YandexDeliverySettings::class ), $this->container->get( YandexDeliveryApiClient::class ), $this->container->get( YandexLocationMappingV2Repository::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( Logger::class ), $this->container->get( YandexDeliveryPricingRequestBuilder::class ), $this->container->get( YandexDeliveryPricingResponseParser::class ) ) );
		$this->container->register(
			CarrierRegistry::class,
			function (): CarrierRegistry {
				$registry = new CarrierRegistry();
				$registry->register( $this->container->get( RussianPostInternationalCarrier::class ) );
				$registry->register( $this->container->get( RussianPostDomesticCarrier::class ) );
				$registry->register( $this->container->get( CdekCarrier::class ) );
				$registry->register( $this->container->get( DpdQuoteCarrier::class ) );
				$registry->register( $this->container->get( YandexDeliveryCarrier::class ) );

				return $registry;
			}
		);
		$this->container->register( DeliveryServiceRegistry::class, fn(): DeliveryServiceRegistry => new DeliveryServiceRegistry( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( CarrierRegistry::class ) ) );
		$this->container->register( DeliveryServiceManager::class, fn(): DeliveryServiceManager => new DeliveryServiceManager( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceCountryRepository::class ), $this->container->get( RuleRepository::class ), $this->container->get( RussianPostCountryDirectory::class ) ) );
		$this->container->register( QuoteCache::class, fn(): QuoteCache => new QuoteCache() );
		$this->container->register( DeliveryQuoteCacheManager::class, fn(): DeliveryQuoteCacheManager => new DeliveryQuoteCacheManager( $this->container->get( QuoteCache::class ) ) );
		$this->container->register( RateSorter::class, fn(): RateSorter => new RateSorter() );
		$this->container->register( FallbackRateFactory::class, fn(): FallbackRateFactory => new FallbackRateFactory() );
		$this->container->register( RuleAppliedRateBuilder::class, fn(): RuleAppliedRateBuilder => new RuleAppliedRateBuilder( $this->container->get( RuleEngine::class ) ) );
		$this->container->register( CheckoutLogger::class, fn(): CheckoutLogger => new CheckoutLogger( $this->container->get( Logger::class ) ) );
		$this->container->register( CarrierExecutionGuard::class, fn(): CarrierExecutionGuard => new CarrierExecutionGuard( $this->container->get( CheckoutLogger::class ) ) );
		$this->container->register(
			CheckoutOrchestrator::class,
			fn(): CheckoutOrchestrator => new CheckoutOrchestrator(
				$this->container->get( CarrierRegistry::class ),
				$this->container->get( RuleAppliedRateBuilder::class ),
				$this->container->get( RateSorter::class ),
				$this->container->get( FallbackRateFactory::class ),
				$this->container->get( CarrierExecutionGuard::class ),
				$this->container->get( CheckoutLogger::class ),
				$this->container->get( QuoteCache::class ),
				$this->container->get( DeliveryServiceRegistry::class ),
				$this->container->get( DeliveryServiceManager::class ),
				$this->container->get( PackagingWeightCalculator::class ),
				$this->container->get( DpdSettings::class )
			)
		);
		$this->container->register( CheckoutSessionManager::class, fn(): CheckoutSessionManager => new CheckoutSessionManager() );
		$this->container->register( CheckoutLocationSearch::class, fn(): CheckoutLocationSearch => new CheckoutLocationSearch( $this->container->get( LocationSearchService::class ) ) );
		$this->container->register( CheckoutLocationAjax::class, fn(): CheckoutLocationAjax => new CheckoutLocationAjax( $this->container->get( CheckoutLocationSearch::class ), $this->container->get( SettingsRepository::class ), $this->container->get( LocationCountryIndexService::class ) ) );
		$this->container->register( CheckoutCityResolver::class, fn(): CheckoutCityResolver => new CheckoutCityResolver( $this->container->get( LocationRepository::class ), $this->container->get( CheckoutLocationSearch::class ) ) );
		$this->container->register( FiasEndpoints::class, fn(): FiasEndpoints => new FiasEndpoints() );
		$this->container->register( FiasLogger::class, fn(): FiasLogger => new FiasLogger( $this->container->get( Logger::class ) ) );
		$this->container->register( FiasCredentials::class, fn(): FiasCredentials => new FiasCredentials( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( FiasRateLimiter::class, fn(): FiasRateLimiter => new FiasRateLimiter( $this->container->get( SettingsRepository::class ), $this->container->get( FiasLogger::class ) ) );
		$this->container->register( FiasHttpClient::class, fn(): FiasHttpClient => new FiasHttpClient( $this->container->get( SettingsRepository::class )->get_int( 'fias_api_timeout', 3 ), $this->container->get( FiasLogger::class ) ) );
		$this->container->register( FiasAddressNormalizer::class, fn(): FiasAddressNormalizer => new FiasAddressNormalizer( $this->container->get( CheckoutCityResolver::class ), $this->container->get( SettingsRepository::class ), $this->container->get( FiasEndpoints::class ), $this->container->get( FiasHttpClient::class ), $this->container->get( FiasRateLimiter::class ), $this->container->get( FiasLogger::class ), $this->container->get( FiasCredentials::class ) ) );
		$this->container->register( DaDataTokenPool::class, fn(): DaDataTokenPool => new DaDataTokenPool( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( AddressSuggestionSettings::class, fn(): AddressSuggestionSettings => new AddressSuggestionSettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ), $this->container->get( DaDataTokenPool::class ) ) );
		$this->container->register( AddressSuggestionNormalizer::class, fn(): AddressSuggestionNormalizer => new AddressSuggestionNormalizer() );
		$this->container->register( DaDataSuggestionClient::class, fn(): DaDataSuggestionClient => new DaDataSuggestionClient( $this->container->get( AddressSuggestionSettings::class ), $this->container->get( DaDataTokenPool::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DaDataPostcodeClient::class, fn(): DaDataPostcodeClient => new DaDataPostcodeClient( $this->container->get( DaDataTokenPool::class ), $this->container->get( Logger::class ), $this->container->get( AddressSuggestionSettings::class )->timeout() ) );
		$this->container->register( RussianPostCourierCalcPostcodeFillStateService::class, fn(): RussianPostCourierCalcPostcodeFillStateService => new RussianPostCourierCalcPostcodeFillStateService( $this->container->get( LocationRepository::class ), $this->container->get( RussianPostCourierTariffProbeService::class ), null, null, null, $this->container->get( Logger::class ) ) );
		$this->container->register( AddressSuggestionClientInterface::class, fn(): AddressSuggestionClientInterface => $this->container->get( DaDataSuggestionClient::class ) );
		$this->container->register( AddressSuggestionService::class, fn(): AddressSuggestionService => new AddressSuggestionService( $this->container->get( AddressSuggestionSettings::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( AddressSuggestionNormalizer::class ) ) );
		$this->container->register( AddressSuggestionAjax::class, fn(): AddressSuggestionAjax => new AddressSuggestionAjax( $this->container->get( AddressSuggestionService::class ), $this->container->get( DaDataTokenPool::class ) ) );
		$this->container->register( PickupAddressSearchService::class, fn(): PickupAddressSearchService => new PickupAddressSearchService( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( DaDataTokenPool::class ), $this->container->get( AddressSuggestionSettings::class ), $this->container->get( LocationRepository::class ) ) );
		$this->container->register( PickupPointsRestController::class, fn(): PickupPointsRestController => new PickupPointsRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( PickupAddressSearchService::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( LocationCoordinateEnricher::class, fn(): LocationCoordinateEnricher => new LocationCoordinateEnricher( $this->container->get( LocationRepository::class ), $this->container->get( AddressSuggestionClientInterface::class ) ) );
		$this->container->register( LocationCoordinatesDadataBatchUpdater::class, fn(): LocationCoordinatesDadataBatchUpdater => new LocationCoordinatesDadataBatchUpdater( $this->container->get( LocationRepository::class ), $this->container->get( AddressSuggestionClientInterface::class ) ) );
		$this->container->register( FallbackAddressNormalizer::class, fn(): FallbackAddressNormalizer => new FallbackAddressNormalizer() );
		$this->container->register(
			CheckoutAddressNormalizer::class,
			fn(): CheckoutAddressNormalizer => new CheckoutAddressNormalizer(
				$this->container->get( FiasAddressNormalizer::class ),
				$this->container->get( FallbackAddressNormalizer::class )
			)
		);
		$this->container->register(
			CheckoutAddressRuntime::class,
			fn(): CheckoutAddressRuntime => new CheckoutAddressRuntime(
				$this->container->get( CheckoutAddressNormalizer::class ),
				$this->container->get( CheckoutCityResolver::class ),
				$this->container->get( CheckoutSessionManager::class ),
				$this->container->get( LocationCoordinateEnricher::class )
			)
		);
		$this->container->register( CheckoutAddressValidation::class, fn(): CheckoutAddressValidation => new CheckoutAddressValidation( $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register( WooCommerceRateMapper::class, fn(): WooCommerceRateMapper => new WooCommerceRateMapper() );
		$this->container->register( WooCommercePackageMapper::class, fn(): WooCommercePackageMapper => new WooCommercePackageMapper( $this->container->get( CheckoutAddressRuntime::class ), $this->container->get( CheckoutSessionManager::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register(
			ShippingMethodRegistrar::class,
			fn(): ShippingMethodRegistrar => new ShippingMethodRegistrar(
				$this->container->get( CheckoutFeatureGate::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( CheckoutOrchestrator::class ),
				$this->container->get( WooCommercePackageMapper::class ),
				$this->container->get( WooCommerceRateMapper::class ),
				$this->container->get( CheckoutSessionManager::class ),
				$this->container->get( RuleRepository::class ),
				$this->environment,
				$this->container->get( Logger::class ),
				$this->container->get( AddressSuggestionSettings::class ),
				$this->container->get( DaDataTokenPool::class ),
				$this->container->get( DeliveryServiceManager::class ),
				$this->container->get( LocationCountryIndexService::class )
			)
		);
		$this->container->register( NewShippingMethod::class, fn(): NewShippingMethod => new NewShippingMethod() );
		$this->container->register( PickupPointRenderer::class, fn(): PickupPointRenderer => new PickupPointRenderer() );
		$this->container->register( PickupPointPresentationResolver::class, fn(): PickupPointPresentationResolver => new PickupPointPresentationResolver() );
		$this->container->register( PickupPointCardRenderer::class, fn(): PickupPointCardRenderer => new PickupPointCardRenderer( $this->container->get( PickupPointPresentationResolver::class ) ) );
		$this->container->register( CheckoutRateRenderer::class, fn(): CheckoutRateRenderer => new CheckoutRateRenderer( $this->container->get( CheckoutSessionManager::class ), $this->container->get( PickupPointCardRenderer::class ) ) );
		$this->container->register(
			CheckoutDeliveryTypeSelector::class,
			fn(): CheckoutDeliveryTypeSelector => new CheckoutDeliveryTypeSelector(
				$this->container->get( CheckoutSessionManager::class ),
				$this->container->get( PickupPointRepository::class ),
				$this->container->get( PickupPointRenderer::class ),
				$this->container->get( PickupPointCardRenderer::class )
			)
		);
		$this->container->register( CheckoutValidation::class, fn(): CheckoutValidation => new CheckoutValidation( $this->container->get( CheckoutSessionManager::class ), $this->container->get( CheckoutAddressValidation::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ) ) );
		$this->container->register( CheckoutSortSelector::class, fn(): CheckoutSortSelector => new CheckoutSortSelector( $this->container->get( CheckoutSessionManager::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( OrderShippingMetaPersister::class, fn(): OrderShippingMetaPersister => new OrderShippingMetaPersister( $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register( PickupMapCheckout::class, fn(): PickupMapCheckout => new PickupMapCheckout( $this->container->get( CheckoutSessionManager::class ), $this->environment, $this->container->get( SettingsRepository::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ) ) );
		$this->container->register( PickupPointOrderDisplay::class, fn(): PickupPointOrderDisplay => new PickupPointOrderDisplay( $this->container->get( PickupPointCardRenderer::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( CheckoutDebugPanel::class, fn(): CheckoutDebugPanel => new CheckoutDebugPanel( $this->container->get( CheckoutSessionManager::class ), $this->container->get( CheckoutFeatureGate::class ) ) );
		$this->container->register( CheckoutAddressRenderer::class, fn(): CheckoutAddressRenderer => new CheckoutAddressRenderer( $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register( LocationSearchService::class, fn(): LocationSearchService => new LocationSearchService( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( LocationCountryIndexService::class, fn(): LocationCountryIndexService => new LocationCountryIndexService( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( LocationImportService::class, fn(): LocationImportService => new LocationImportService( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( LocationAliasGenerator::class, fn(): LocationAliasGenerator => new LocationAliasGenerator() );
		$this->container->register( GarPlacesCsvImporter::class, fn(): GarPlacesCsvImporter => new GarPlacesCsvImporter( $this->container->get( LocationRepository::class ), $this->container->get( RegionRepository::class ), $this->container->get( LocationAliasGenerator::class ) ) );
		$this->container->register( LocationIncrementalUpdateService::class, fn(): LocationIncrementalUpdateService => new LocationIncrementalUpdateService( $this->container->get( LocationAliasGenerator::class ) ) );
		$this->container->register( LocationsSnapshotExporter::class, fn(): LocationsSnapshotExporter => new LocationsSnapshotExporter() );
		$this->container->register( LocationsSnapshotImporter::class, fn(): LocationsSnapshotImporter => new LocationsSnapshotImporter() );
		$this->container->register( FiasImportManager::class, fn(): FiasImportManager => new FiasImportManager( $this->environment, $this->container->get( LocationRepository::class ), $this->container->get( LocationAliasGenerator::class ), $this->container->get( ActionScheduler::class ) ) );
		$this->container->register( GarChangesClient::class, fn(): GarChangesClient => new GarChangesClient( $this->container->get( FiasHttpClient::class ) ) );
		$this->container->register( GarSyncManager::class, fn(): GarSyncManager => new GarSyncManager( $this->container->get( ActionScheduler::class ), $this->container->get( GarChangesClient::class ), $this->container->get( Logger::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( GarChangesService::class, fn(): GarChangesService => new GarChangesService() );
		$this->container->register( YearGenerator::class, fn(): YearGenerator => new YearGenerator() );
		$this->container->register( TimezoneService::class, fn(): TimezoneService => new TimezoneService() );
		$this->container->register( DeliveryDateFormatter::class, fn(): DeliveryDateFormatter => new DeliveryDateFormatter() );
		$this->container->register(
			CalendarService::class,
			fn(): CalendarService => new CalendarService(
				$this->container->get( CalendarRepository::class ),
				$this->container->get( YearGenerator::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( TimezoneService::class )
			)
		);
		$this->container->register(
			DeliveryDateCalculator::class,
			fn(): DeliveryDateCalculator => new DeliveryDateCalculator(
				$this->container->get( CalendarService::class ),
				$this->container->get( TimezoneService::class ),
				$this->container->get( DeliveryDateFormatter::class )
			)
		);
		$this->container->register(
			CalendarScheduler::class,
			fn(): CalendarScheduler => new CalendarScheduler(
				$this->container->get( ActionScheduler::class ),
				$this->container->get( CalendarService::class ),
				$this->container->get( TimezoneService::class )
			)
		);
		$this->container->register( RequirementsChecker::class, fn(): RequirementsChecker => new RequirementsChecker( $this->environment ) );
		$this->container->register( HPOSCompatibility::class, fn(): HPOSCompatibility => new HPOSCompatibility( $this->environment ) );
		$this->container->register(
			AdminNotices::class,
			fn(): AdminNotices => new AdminNotices(
				$this->container->get( RequirementsChecker::class ),
				$this->container->get( CalendarService::class )
			)
		);
		$this->container->register(
			AdminMenu::class,
			fn(): AdminMenu => new AdminMenu(
				$this->environment,
				$this->container->get( FeatureFlags::class ),
				$this->container->get( RequirementsChecker::class ),
				$this->container->get( DeliveryQuoteCacheManager::class )
			)
		);
		$this->container->register(
			CalendarAdminPage::class,
			fn(): CalendarAdminPage => new CalendarAdminPage(
				$this->environment,
				$this->container->get( CalendarService::class ),
				$this->container->get( CalendarRepository::class ),
				$this->container->get( YearGenerator::class )
			)
		);
		$this->container->register(
			LocationsAdminPage::class,
			fn(): LocationsAdminPage => new LocationsAdminPage(
				$this->environment,
				$this->container->get( LocationRepository::class ),
				$this->container->get( LocationSearchService::class ),
				$this->container->get( LocationImportService::class ),
				$this->container->get( FiasRateLimiter::class ),
				$this->container->get( GarSyncManager::class ),
				$this->container->get( FiasImportManager::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( FiasCredentials::class ),
				$this->container->get( GarPlacesCsvImporter::class ),
				$this->container->get( LocationsSnapshotExporter::class ),
				$this->container->get( LocationsSnapshotImporter::class ),
				$this->container->get( DaDataPostcodeClient::class ),
				$this->container->get( LocationCoordinatesDadataBatchUpdater::class ),
				$this->container->get( LocationCountryIndexService::class ),
				$this->container->get( LocationIncrementalUpdateService::class ),
				$this->container->get( RussianPostCourierCalcPostcodeFillStateService::class )
			)
		);
		$this->container->register(
			RulesAdminPage::class,
			fn(): RulesAdminPage => new RulesAdminPage(
				$this->environment,
				$this->container->get( RuleRepository::class ),
				$this->container->get( RuleSimulator::class ),
				$this->container->get( SettingsRepository::class )
			)
		);
		$this->container->register(
			CheckoutSimulationPage::class,
			fn(): CheckoutSimulationPage => new CheckoutSimulationPage(
				$this->environment,
				$this->container->get( CheckoutOrchestrator::class )
			)
		);
		$this->container->register(
			PickupAdminPage::class,
			fn(): PickupAdminPage => new PickupAdminPage(
				$this->container->get( RussianPostPickupPointRepository::class ),
				$this->container->get( RussianPostOtpravkaApiSettings::class ),
				$this->container->get( RussianPostPickupDiagnosticsService::class )
			)
		);
		$this->container->register( SettingsAdminPage::class, fn(): SettingsAdminPage => new SettingsAdminPage( $this->container->get( SettingsRepository::class ), $this->container->get( FiasCredentials::class ), $this->container->get( AddressSuggestionSettings::class ), $this->container->get( DaDataTokenPool::class ), $this->container->get( RussianPostSettings::class ) ) );
		$this->container->register( RussianPostCountriesAdminPage::class, fn(): RussianPostCountriesAdminPage => new RussianPostCountriesAdminPage( $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostCountryMappingService::class ) ) );
		$this->container->register(
			DeliveryServicesAdminPage::class,
			fn(): DeliveryServicesAdminPage => new DeliveryServicesAdminPage(
				$this->container->get( DeliveryServiceRepository::class ),
				$this->container->get( DeliveryServiceCountryRepository::class ),
				$this->container->get( RulesAdminPage::class ),
				$this->container->get( RuleRepository::class ),
				$this->container->get( DeliveryServiceSettingsRepository::class ),
				$this->container->get( RussianPostSettings::class ),
				$this->container->get( RussianPostCountriesAdminPage::class ),
				$this->container->get( RussianPostInternationalCarrier::class ),
				$this->container->get( RuleAppliedRateBuilder::class ),
				$this->container->get( DeliveryServiceManager::class ),
				$this->container->get( PackagingWeightCalculator::class ),
				$this->container->get( RussianPostDomesticCarrier::class ),
				$this->container->get( RussianPostOtpravkaApiSettings::class ),
				$this->container->get( RussianPostPickupImporter::class ),
				$this->container->get( PickupPointRepository::class ),
				$this->container->get( RussianPostPickupPointRepository::class ),
				$this->container->get( RussianPostPickupImportStateService::class ),
				$this->environment,
				$this->container->get( RussianPostPickupPointTypeSettings::class ),
				$this->container->get( CdekSettings::class ),
				$this->container->get( CdekApiClient::class ),
				$this->container->get( CdekTariffRepository::class ),
				$this->container->get( CdekTariffSyncService::class ),
				$this->container->get( DeliveryQuoteCacheManager::class ),
				$this->container->get( CdekStatusMappingService::class ),
				$this->container->get( CdekCarrier::class ),
				$this->container->get( DpdSettings::class ),
				$this->container->get( DpdApiClient::class ),
				$this->container->get( DpdGeographyDiagnosticService::class ),
				$this->container->get( DpdGeographyImportService::class ),
				$this->container->get( DpdGeographyFtpClient::class ),
				$this->container->get( DpdDaDataDeliveryFallbackService::class ),
				$this->container->get( DpdPickupPointRepository::class ),
				$this->container->get( DpdPickupPointImportService::class ),
				$this->container->get( DpdCityResolver::class ),
				$this->container->get( LocationRepository::class ),
				$this->container->get( DpdStatusMapping::class ),
				$this->container->get( DpdPickupPointAutoSync::class ),
				$this->container->get( YandexDeliverySettings::class ),
				$this->container->get( YandexDeliveryConnectionDiagnosticService::class ),
				$this->container->get( YandexDeliveryPickupPointV2Repository::class ),
				$this->container->get( YandexDeliveryPickupPointV2RunnerService::class ),
				$this->container->get( YandexDeliveryGeoV2Repository::class ),
				$this->container->get( YandexDeliveryGeoV2BuilderRunnerService::class ),
				$this->container->get( YandexLocationMappingV2Repository::class ),
				$this->container->get( YandexLocationMappingV2Runner::class ),
				$this->container->get( YandexLocationManualOverrideV2Repository::class ),
				$this->container->get( YandexRegionMappingV2Repository::class ),
				$this->container->get( YandexGeoV2RegionEnrichmentRunner::class ),
				$this->container->get( YandexDeliveryGeoPipelineV2Runner::class ),
				$this->container->get( YandexStatusMapping::class ),
			)
		);
		$this->container->register( OrderQuoteRequestMapper::class, fn(): OrderQuoteRequestMapper => new OrderQuoteRequestMapper( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( OrderDeliveryRecalculationService::class, fn(): OrderDeliveryRecalculationService => new OrderDeliveryRecalculationService( $this->container->get( OrderQuoteRequestMapper::class ), $this->container->get( CheckoutOrchestrator::class ), $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderDeliveryAddressNormalizationService::class, fn(): OrderDeliveryAddressNormalizationService => new OrderDeliveryAddressNormalizationService( $this->container->get( CheckoutAddressRuntime::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( AddressSuggestionService::class ) ) );
		$this->container->register( OrderDeliveryReplacementService::class, fn(): OrderDeliveryReplacementService => new OrderDeliveryReplacementService( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderDeliveryRateRenderer::class, fn(): OrderDeliveryRateRenderer => new OrderDeliveryRateRenderer() );
		$this->container->register( OrderDeliveryRecalculationAdminController::class, fn(): OrderDeliveryRecalculationAdminController => new OrderDeliveryRecalculationAdminController( $this->container->get( OrderDeliveryRecalculationService::class ), $this->container->get( OrderDeliveryRateRenderer::class ), $this->container->get( CheckoutLocationAjax::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->environment->plugin_url(), $this->environment->version(), $this->container->get( OrderDeliveryAddressNormalizationService::class ), $this->container->get( OrderDeliveryReplacementService::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( OrderDeliveryMetabox::class, fn(): OrderDeliveryMetabox => new OrderDeliveryMetabox( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class, fn(): \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService => new \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService( $this->container->get( AddressSuggestionSettings::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( CdekLocationResolver::class ) ) );
		$this->container->register( OrderShipmentsMetabox::class, fn(): OrderShipmentsMetabox => new OrderShipmentsMetabox( $this->container->get( OrderShipmentRepository::class ), $this->container->get( OrderShipmentDraftFactory::class ), $this->container->get( ShipmentCreationService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( CdekOrderStatusService::class ), $this->container->get( ShipmentBacklogService::class ), $this->container->get( RussianPostAddressNormalizer::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class ), $this->container->get( AddressSuggestionService::class ), $this->environment->plugin_url(), $this->environment->version(), $this->container->get( CdekBarcodePrintService::class ), $this->container->get( CarrierShipmentAdapterRegistry::class ), $this->container->get( ShipmentMetaboxButtonPolicy::class ), $this->container->get( ShipmentDocumentProviderRegistry::class ), $this->container->get( ShipmentDocumentDownloadService::class ), $this->container->get( ShipmentModalExtensionRegistry::class ) ) );
		$this->container->register( ShipmentStatusesAdminPage::class, fn(): ShipmentStatusesAdminPage => new ShipmentStatusesAdminPage( $this->container->get( SettingsRepository::class ), $this->container->get( ShipmentStatusAutoSyncService::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
	}

	private function register_hooks(): void {
		$this->container->get( HPOSCompatibility::class )->register();

		add_action( 'plugins_loaded', array( $this, 'boot_modules' ), 20 );
		register_activation_hook( $this->environment->plugin_file(), array( $this, 'activate' ) );
		register_deactivation_hook( $this->environment->plugin_file(), array( $this, 'deactivate' ) );
		$this->container->get( DeliveryQuoteCacheManager::class )->register();
		$this->container->get( ShippingMethodRegistrar::class )->register();
		$this->container->get( CheckoutLocationAjax::class )->register();
		$this->container->get( AddressSuggestionAjax::class )->register();
		if ( $this->container->get( CheckoutFeatureGate::class )->enabled() ) {
			$this->container->get( CheckoutRateRenderer::class )->register();
			$this->container->get( CheckoutDeliveryTypeSelector::class )->register();
			$this->container->get( CheckoutSortSelector::class )->register();
			$this->container->get( CheckoutAddressRuntime::class )->register();
			$this->container->get( CheckoutValidation::class )->register();
			$this->container->get( OrderShippingMetaPersister::class )->register();
			$this->container->get( PickupMapCheckout::class )->register();
			$this->container->get( PickupPointOrderDisplay::class )->register();
			$this->container->get( CheckoutDebugPanel::class )->register();
		}

		if ( is_admin() ) {
			$this->container->get( AdminNotices::class )->register();
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( SettingsAdminPage::class )->register();
			$this->container->get( CalendarAdminPage::class )->register();
			$this->container->get( LocationsAdminPage::class )->register();
			$this->container->get( RulesAdminPage::class )->register();
			$this->container->get( CheckoutSimulationPage::class )->register();
			$this->container->get( PickupAdminPage::class )->register();
			$this->container->get( OrderDeliveryRecalculationAdminController::class )->register();
			$this->container->get( DeliveryServicesAdminPage::class )->register();
			$this->container->get( ShipmentStatusesAdminPage::class )->register();
			$this->container->get( OrderDeliveryMetabox::class )->register();
			$this->container->get( OrderShipmentsMetabox::class )->register();
			$this->container->get( ShipmentDocumentDownloadService::class )->register();
		}
		$this->container->get( RussianPostPickupImporter::class )->register();
		$this->container->get( ShipmentStatusAutoSyncCron::class )->register();
		$this->container->get( DpdPickupPointAutoSync::class )->register();
		add_action( YandexDeliveryGeoPipelineV2Runner::CRON_HOOK, array( $this->container->get( YandexDeliveryGeoPipelineV2Runner::class ), 'run_scheduled_step' ) );
		add_action( YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK, array( $this->container->get( YandexDeliveryGeoPipelineV2Runner::class ), 'run_scheduled_start' ) );
		$this->container->get( YandexDeliveryGeoPipelineV2Runner::class )->ensure_schedule();
		add_action( 'rest_api_init', array( $this->container->get( PickupPointsRestController::class ), 'register' ) );
		add_action( 'rest_api_init', array( $this->container->get( CheckoutPickupPointRestController::class ), 'register' ) );
	}

	public function boot_modules(): void {
		$this->container->get( MigrationManager::class )->run();
		$this->container->get( DeliveryServiceManager::class )->ensure_builtin_services();
		$this->container->get( CalendarService::class )->ensure_initial_years();
		$this->container->get( ActionScheduler::class );
		$this->container->get( GarChangesService::class );
		$this->container->get( CalendarScheduler::class )->register();
		$this->container->get( GarSyncManager::class )->register();
		$this->container->get( FiasImportManager::class )->register();
	}

	public function activate(): void {
		$this->container->get( MigrationManager::class )->run();
		$this->container->get( DeliveryServiceManager::class )->ensure_builtin_services();
		$this->container->get( CalendarService::class )->ensure_initial_years();
		$this->container->get( DpdPickupPointAutoSync::class )->activate();
	}

	public function deactivate(): void {
		$this->container->get( DpdPickupPointAutoSync::class )->deactivate();
	}
}
