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
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
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
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\RegionRepository;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRateRenderer;
use WallsShop\WDC\Orders\Admin\OrderDeliveryRecalculationAdminController;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Pickup\Admin\PickupAdminPage;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
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
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostExtractor;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostLookupService;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostCreateRequestBuilder;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentAdapter;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentProductMapper;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
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
		$this->container->register( RegionRepository::class, fn(): RegionRepository => new RegionRepository() );
		$this->container->register( PickupPointRepository::class, fn(): PickupPointRepository => new PickupPointRepository() );
		$this->container->register( RussianPostPickupPointRepository::class, fn(): RussianPostPickupPointRepository => new RussianPostPickupPointRepository() );
		$this->container->register( RussianPostPickupLocationResolver::class, fn(): RussianPostPickupLocationResolver => new RussianPostPickupLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( RussianPostPickupDiagnosticsService::class, fn(): RussianPostPickupDiagnosticsService => new RussianPostPickupDiagnosticsService( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( LocationRepository::class ), location_resolver: $this->container->get( RussianPostPickupLocationResolver::class ) ) );
		$this->container->register( RussianPostPickupPointTypeSettings::class, fn(): RussianPostPickupPointTypeSettings => new RussianPostPickupPointTypeSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( PickupPointLocationResolver::class, fn(): PickupPointLocationResolver => new PickupPointLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( CheckoutPickupPointRestController::class, fn(): CheckoutPickupPointRestController => new CheckoutPickupPointRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( CheckoutSessionManager::class ), $this->container->get( PickupPointLocationResolver::class ), $this->container->get( CdekDeliveryPointService::class ) ) );
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
		$this->container->register( RussianPostShipmentAdapter::class, fn(): RussianPostShipmentAdapter => new RussianPostShipmentAdapter( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostCreateRequestBuilder::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( OrderShipmentDraftFactory::class, fn(): OrderShipmentDraftFactory => new OrderShipmentDraftFactory( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentServiceSettings::class ), $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( RussianPostOtpravkaApiSettings::class ), $this->container->get( RussianPostPickupPointRepository::class ) ) );
		$this->container->register( RussianPostShipmentActualCostExtractor::class, fn(): RussianPostShipmentActualCostExtractor => new RussianPostShipmentActualCostExtractor() );
		$this->container->register( RussianPostShipmentActualCostLookupService::class, fn(): RussianPostShipmentActualCostLookupService => new RussianPostShipmentActualCostLookupService( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostShipmentActualCostExtractor::class ) ) );
		$this->container->register( ShipmentCreationService::class, fn(): ShipmentCreationService => new ShipmentCreationService( $this->container->get( OrderShipmentRepository::class ), array( $this->container->get( RussianPostShipmentAdapter::class ) ), $this->container->get( Logger::class ), $this->container->get( RussianPostShipmentActualCostLookupService::class ) ) );
		$this->container->register( ShipmentOrderStatusMappingService::class, fn(): ShipmentOrderStatusMappingService => new ShipmentOrderStatusMappingService( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( ShipmentStatusUpdateService::class, fn(): ShipmentStatusUpdateService => new ShipmentStatusUpdateService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( RussianPostTrackingApiClient::class ), $this->container->get( RussianPostTrackingStatusMapper::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( ShipmentStatusAutoSyncService::class, fn(): ShipmentStatusAutoSyncService => new ShipmentStatusAutoSyncService( $this->container->get( SettingsRepository::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
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
		$this->container->register( CdekCarrier::class, fn(): CdekCarrier => new CdekCarrier( $this->container->get( CdekSettings::class ), $this->container->get( CdekApiClient::class ), $this->container->get( CdekLocationResolver::class ), $this->container->get( Logger::class ) ) );
		$this->container->register(
			CarrierRegistry::class,
			function (): CarrierRegistry {
				$registry = new CarrierRegistry();
				$registry->register( $this->container->get( RussianPostInternationalCarrier::class ) );
				$registry->register( $this->container->get( RussianPostDomesticCarrier::class ) );
				$registry->register( $this->container->get( CdekCarrier::class ) );

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
				$this->container->get( PackagingWeightCalculator::class )
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
		$this->container->register( PickupPointsRestController::class, fn(): PickupPointsRestController => new PickupPointsRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( PickupAddressSearchService::class ), $this->container->get( CdekDeliveryPointService::class ) ) );
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
		$this->container->register( PickupPointCardRenderer::class, fn(): PickupPointCardRenderer => new PickupPointCardRenderer() );
		$this->container->register( CheckoutRateRenderer::class, fn(): CheckoutRateRenderer => new CheckoutRateRenderer( $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register(
			CheckoutDeliveryTypeSelector::class,
			fn(): CheckoutDeliveryTypeSelector => new CheckoutDeliveryTypeSelector(
				$this->container->get( CheckoutSessionManager::class ),
				$this->container->get( PickupPointRepository::class ),
				$this->container->get( PickupPointRenderer::class ),
				$this->container->get( PickupPointCardRenderer::class )
			)
		);
		$this->container->register( CheckoutValidation::class, fn(): CheckoutValidation => new CheckoutValidation( $this->container->get( CheckoutSessionManager::class ), $this->container->get( CheckoutAddressValidation::class ), $this->container->get( RussianPostPickupPointRepository::class ) ) );
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
				$this->container->get( CdekApiClient::class )
			)
		);
		$this->container->register( OrderQuoteRequestMapper::class, fn(): OrderQuoteRequestMapper => new OrderQuoteRequestMapper() );
		$this->container->register( OrderDeliveryRecalculationService::class, fn(): OrderDeliveryRecalculationService => new OrderDeliveryRecalculationService( $this->container->get( OrderQuoteRequestMapper::class ), $this->container->get( CheckoutOrchestrator::class ), $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderDeliveryAddressNormalizationService::class, fn(): OrderDeliveryAddressNormalizationService => new OrderDeliveryAddressNormalizationService( $this->container->get( CheckoutAddressRuntime::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( AddressSuggestionService::class ) ) );
		$this->container->register( OrderDeliveryReplacementService::class, fn(): OrderDeliveryReplacementService => new OrderDeliveryReplacementService( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderDeliveryRateRenderer::class, fn(): OrderDeliveryRateRenderer => new OrderDeliveryRateRenderer() );
		$this->container->register( OrderDeliveryRecalculationAdminController::class, fn(): OrderDeliveryRecalculationAdminController => new OrderDeliveryRecalculationAdminController( $this->container->get( OrderDeliveryRecalculationService::class ), $this->container->get( OrderDeliveryRateRenderer::class ), $this->container->get( CheckoutLocationAjax::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->environment->plugin_url(), $this->environment->version(), $this->container->get( OrderDeliveryAddressNormalizationService::class ), $this->container->get( OrderDeliveryReplacementService::class ), $this->container->get( CdekDeliveryPointService::class ) ) );
		$this->container->register( OrderDeliveryMetabox::class, fn(): OrderDeliveryMetabox => new OrderDeliveryMetabox( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderShipmentsMetabox::class, fn(): OrderShipmentsMetabox => new OrderShipmentsMetabox( $this->container->get( OrderShipmentRepository::class ), $this->container->get( OrderShipmentDraftFactory::class ), $this->container->get( ShipmentCreationService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentBacklogService::class ), $this->container->get( RussianPostAddressNormalizer::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->environment->plugin_url(), $this->environment->version() ) );
		$this->container->register( ShipmentStatusesAdminPage::class, fn(): ShipmentStatusesAdminPage => new ShipmentStatusesAdminPage( $this->container->get( SettingsRepository::class ), $this->container->get( ShipmentStatusAutoSyncService::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
	}

	private function register_hooks(): void {
		$this->container->get( HPOSCompatibility::class )->register();

		add_action( 'plugins_loaded', array( $this, 'boot_modules' ), 20 );
		register_activation_hook( $this->environment->plugin_file(), array( $this, 'activate' ) );
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
		}
		$this->container->get( RussianPostPickupImporter::class )->register();
		$this->container->get( ShipmentStatusAutoSyncCron::class )->register();
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
	}
}
