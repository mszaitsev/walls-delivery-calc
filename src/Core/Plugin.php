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
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportLockService;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyMatcher;
use WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyStageRepository;
use WallsShop\WDC\Carriers\Dpd\Geography\WpDpdDaDataDeliveryClient;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointAutoSync;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointNormalizer;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointScheduleFormatter;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentDateResolver;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdPackagingBuilderFactory;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffCalculationService;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffOptionNormalizer;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTariffRequestBuilder;
use WallsShop\WDC\Carriers\Dpd\Tariff\DpdTerminalCodeTariffRequestBuilder;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticGeographyAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticStatusAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiDiagnosticService;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticHttpClientInterface;
use WallsShop\WDC\Carriers\JetLogistic\Api\WpJetLogisticHttpClient;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvClient;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvParser;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCityNameNormalizer;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCountrySyncService;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticRegionNameNormalizer;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteRequestBuilder;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteResponseParser;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusEventResolver;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMapper;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusService;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminNoticeStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminPage;
use WallsShop\WDC\Carriers\Pek\Admin\PekStatusAdminPage;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekQuoteDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Admin\PekQuoteDiagnosticStore;
use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekConnectionDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\Api\WpPekHttpClient;
use WallsShop\WDC\Carriers\Pek\Checkout\PekCheckoutQuoteContextResolver;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\Installation\PekSchemaIntegrityService;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCargoConstraintsConverter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalSearchCache;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekLightCargoSurchargePolicy;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteMessageSanitizer;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuotePlannedDateTimeResolver;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResponseParser;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\Carriers\OzonDelivery\Admin\OzonDeliveryAdminPage;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryAccessTokenService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Api\WpOzonDeliveryHttpClient;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
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
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
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
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostPickupDiagnosticsTab;
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
use WallsShop\WDC\Carriers\Runtime\JetLogisticCarrier;
use WallsShop\WDC\Carriers\Runtime\PekCarrier;
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
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Locations\LocationCoordinateEnricher;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
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
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
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
use WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Pickup\Presentation\PickupPointPresentationResolver;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;
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
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentActualCostAjaxController;
use WallsShop\WDC\Shipments\Admin\ShipmentCostAnalyticsAdminSection;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Admin\ShipmentStatusesAdminPage;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostExtractor;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostLookupService;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentModalRequestMapper;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Analytics\CreatedShipmentIdentityResolver;
use WallsShop\WDC\Shipments\Analytics\OrderAnalyticsShipmentSelector;
use WallsShop\WDC\Shipments\Analytics\OrderSelectedDeliveryIdentityResolver;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsIndexer;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsQuery;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsRecordBuilder;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsService;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRepository;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsTable;
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
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentAdapter;
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentService;
use WallsShop\WDC\Shipments\Pek\PekPrivateAccessTokenService;
use WallsShop\WDC\Shipments\Pek\PekManualAttachContextResolver;
use WallsShop\WDC\Shipments\Pek\PekSenderCounterpartService;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekShipmentCargoBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentCorrelationResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentCourierAddressResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentCreateResponseParser;
use WallsShop\WDC\Shipments\Pek\PekShipmentDeclaredValueResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentDestinationResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentDocumentProvider;
use WallsShop\WDC\Shipments\Pek\PekShipmentDocumentService;
use WallsShop\WDC\Shipments\Pek\PekShipmentModalExtension;
use WallsShop\WDC\Shipments\Pek\PekShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Pek\PekShipmentProductWeightResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentRecipientBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentRequestBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentSenderWarehouseResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentService;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusResponseNormalizer;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusService;
use WallsShop\WDC\Shipments\Pek\PekSmsReleaseAvailabilityService;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;
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

	private string $migration_failure_message = '';

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
		$this->container->register( Logger::class, fn(): Logger => new Logger() );
		$this->container->register( SettingsRepository::class, fn(): SettingsRepository => new SettingsRepository() );
		$this->container->register( CheckoutFeatureGate::class, fn(): CheckoutFeatureGate => new CheckoutFeatureGate( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( EncryptionService::class, fn(): EncryptionService => new EncryptionService() );
		$this->container->register( MigrationManager::class, fn(): MigrationManager => new MigrationManager( $this->environment->version(), $this->environment->plugin_dir() . 'database/migrations' ) );
		$this->container->register( ActionScheduler::class, fn(): ActionScheduler => new ActionScheduler( $this->container->get( Logger::class ) ) );
		$this->container->register( CalendarRepository::class, fn(): CalendarRepository => new CalendarRepository() );
		$this->container->register( LocationRepository::class, fn(): LocationRepository => new LocationRepository() );
		$this->container->register( LocationDeliveryCodeRepository::class, fn(): LocationDeliveryCodeRepository => new LocationDeliveryCodeRepository() );
		$this->container->register( RegionRepository::class, fn(): RegionRepository => new RegionRepository() );
		$this->container->register( PickupPointRepository::class, fn(): PickupPointRepository => new PickupPointRepository() );
		$this->container->register( RussianPostPickupPointRepository::class, fn(): RussianPostPickupPointRepository => new RussianPostPickupPointRepository() );
		$this->container->register( DpdPickupPointScheduleFormatter::class, fn(): DpdPickupPointScheduleFormatter => new DpdPickupPointScheduleFormatter() );
		$this->container->register( YandexDeliveryCheckoutPickupPointFormatter::class, fn(): YandexDeliveryCheckoutPickupPointFormatter => new YandexDeliveryCheckoutPickupPointFormatter() );
		$this->container->register( RussianPostPickupLocationResolver::class, fn(): RussianPostPickupLocationResolver => new RussianPostPickupLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( RussianPostPickupDiagnosticsService::class, fn(): RussianPostPickupDiagnosticsService => new RussianPostPickupDiagnosticsService( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( LocationRepository::class ), location_resolver: $this->container->get( RussianPostPickupLocationResolver::class ) ) );
		$this->container->register( RussianPostPickupPointTypeSettings::class, fn(): RussianPostPickupPointTypeSettings => new RussianPostPickupPointTypeSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( PickupPointLocationResolver::class, fn(): PickupPointLocationResolver => new PickupPointLocationResolver( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( CheckoutPickupPointRestController::class, fn(): CheckoutPickupPointRestController => new CheckoutPickupPointRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( CheckoutSessionManager::class ), $this->container->get( PickupPointLocationResolver::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexDeliveryCheckoutPickupPointFormatter::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( CheckoutPickupPointProviderQueryResolver::class ), $this->container->get( WooCommerceSessionBootstrapper::class ) ) );
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
		$this->container->register( CdekLocationResolver::class, fn(): CdekLocationResolver => new CdekLocationResolver( $this->container->get( CdekApiClient::class ), $this->container->get( CdekSettings::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( CdekDeliveryPointService::class, fn(): CdekDeliveryPointService => new CdekDeliveryPointService( $this->container->get( CdekApiClient::class ), $this->container->get( CdekSettings::class ), $this->container->get( CdekLocationResolver::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( CdekTariffRepository::class, fn(): CdekTariffRepository => new CdekTariffRepository() );
		$this->container->register( CdekTariffSyncService::class, fn(): CdekTariffSyncService => new CdekTariffSyncService( $this->container->get( CdekApiClient::class ), $this->container->get( CdekTariffRepository::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdSettings::class, fn(): DpdSettings => new DpdSettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( YandexDeliverySettings::class, fn(): YandexDeliverySettings => new YandexDeliverySettings( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( JetLogisticSettings::class, fn(): JetLogisticSettings => new JetLogisticSettings( $this->container->get( SettingsRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( JetLogisticCredentials::class, fn(): JetLogisticCredentials => new JetLogisticCredentials( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( JetLogisticHttpClientInterface::class, fn(): JetLogisticHttpClientInterface => new WpJetLogisticHttpClient() );
		$this->container->register( JetLogisticApiClient::class, fn(): JetLogisticApiClient => new JetLogisticApiClient( $this->container->get( JetLogisticHttpClientInterface::class ), $this->container->get( JetLogisticSettings::class ), $this->container->get( JetLogisticCredentials::class ) ) );
		$this->container->register( PekRuPhoneNormalizer::class, fn(): PekRuPhoneNormalizer => new PekRuPhoneNormalizer() );
		$this->container->register( PekSettings::class, fn(): PekSettings => new PekSettings( $this->container->get( SettingsRepository::class ), $this->container->get( PekRuPhoneNormalizer::class ) ) );
		$this->container->register( PekCountryPolicy::class, fn(): PekCountryPolicy => new PekCountryPolicy() );
		$this->container->register( PekCredentials::class, fn(): PekCredentials => new PekCredentials( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( OzonDeliverySettings::class, fn(): OzonDeliverySettings => new OzonDeliverySettings( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( OzonDeliveryCredentials::class, fn(): OzonDeliveryCredentials => new OzonDeliveryCredentials( $this->container->get( SettingsRepository::class ), $this->container->get( EncryptionService::class ) ) );
		$this->container->register( OzonDeliveryMessageSanitizer::class, fn(): OzonDeliveryMessageSanitizer => new OzonDeliveryMessageSanitizer() );
		$this->container->register( OzonDeliveryHttpClientInterface::class, fn(): OzonDeliveryHttpClientInterface => new WpOzonDeliveryHttpClient( $this->container->get( OzonDeliverySettings::class )->request_timeout(), $this->container->get( OzonDeliveryMessageSanitizer::class ) ) );
		$this->container->register( OzonDeliveryAccessTokenService::class, fn(): OzonDeliveryAccessTokenService => new OzonDeliveryAccessTokenService( $this->container->get( OzonDeliveryCredentials::class ), $this->container->get( OzonDeliveryHttpClientInterface::class ), $this->container->get( OzonDeliveryMessageSanitizer::class ) ) );
		$this->container->register( OzonDeliveryApiClient::class, fn(): OzonDeliveryApiClient => new OzonDeliveryApiClient( $this->container->get( OzonDeliveryHttpClientInterface::class ), $this->container->get( OzonDeliveryAccessTokenService::class ) ) );
		$this->container->register( OzonDeliveryConnectionDiagnosticService::class, fn(): OzonDeliveryConnectionDiagnosticService => new OzonDeliveryConnectionDiagnosticService( $this->container->get( OzonDeliveryCredentials::class ), $this->container->get( OzonDeliveryAccessTokenService::class ), $this->container->get( OzonDeliverySettings::class ) ) );
		$this->container->register( PekHttpClientInterface::class, fn(): PekHttpClientInterface => new WpPekHttpClient() );
		$this->container->register( PekRequestBudget::class, fn(): PekRequestBudget => new PekRequestBudget( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekApiClient::class, fn(): PekApiClient => new PekApiClient( $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ), $this->container->get( PekHttpClientInterface::class ), $this->container->get( PekRequestBudget::class ) ) );
		$this->container->register( PekConnectionDiagnosticService::class, fn(): PekConnectionDiagnosticService => new PekConnectionDiagnosticService( $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ), $this->container->get( PekApiClient::class ) ) );
		$this->container->register( PekSenderWarehouseSearchCache::class, fn(): PekSenderWarehouseSearchCache => new PekSenderWarehouseSearchCache() );
		$this->container->register( PekSenderWarehouseService::class, fn(): PekSenderWarehouseService => new PekSenderWarehouseService( $this->container->get( PekApiClient::class ), $this->container->get( PekSettings::class ), $this->container->get( PekSenderWarehouseSearchCache::class ) ) );
		$this->container->register( PekAdminNoticeStore::class, fn(): PekAdminNoticeStore => new PekAdminNoticeStore() );
		$this->container->register( PekAddressBuilder::class, fn(): PekAddressBuilder => new PekAddressBuilder() );
		$this->container->register( PekLocationMappingRepository::class, fn(): PekLocationMappingRepository => new PekLocationMappingRepository() );
		$this->container->register( PekSchemaIntegrityService::class, fn(): PekSchemaIntegrityService => new PekSchemaIntegrityService( null, $this->container->get( PekLocationMappingRepository::class ), $this->container->get( PekTerminalRepository::class ) ) );
		$this->container->register( PekLocationResolver::class, fn(): PekLocationResolver => new PekLocationResolver( $this->container->get( LocationRepository::class ), $this->container->get( PekAddressBuilder::class ), $this->container->get( PekLocationMappingRepository::class ), $this->container->get( PekApiClient::class ), $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekDestinationTerminalSearchCache::class, fn(): PekDestinationTerminalSearchCache => new PekDestinationTerminalSearchCache() );
		$this->container->register( PekTerminalRepository::class, fn(): PekTerminalRepository => new PekTerminalRepository() );
		$this->container->register( PekCargoConstraintsConverter::class, fn(): PekCargoConstraintsConverter => new PekCargoConstraintsConverter() );
		$this->container->register( PekTerminalService::class, fn(): PekTerminalService => new PekTerminalService( $this->container->get( PekLocationResolver::class ), $this->container->get( PekApiClient::class ), $this->container->get( PekCargoConstraintsConverter::class ), $this->container->get( PekDestinationTerminalSearchCache::class ), $this->container->get( PekTerminalRepository::class ), $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekPickupPointProvider::class, fn(): PekPickupPointProvider => new PekPickupPointProvider( $this->container->get( PekTerminalService::class ) ) );
		$this->container->register( CarrierPickupPointProviderRegistry::class, fn(): CarrierPickupPointProviderRegistry => new CarrierPickupPointProviderRegistry( array( $this->container->get( PekPickupPointProvider::class ) ) ) );
		$this->container->register( PekDestinationPickupDiagnosticStore::class, fn(): PekDestinationPickupDiagnosticStore => new PekDestinationPickupDiagnosticStore() );
		$this->container->register( PekDestinationPickupDiagnosticService::class, fn(): PekDestinationPickupDiagnosticService => new PekDestinationPickupDiagnosticService( $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( LocationRepository::class ), $this->container->get( PekTerminalService::class ), $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( PekQuoteCargoBuilder::class, fn(): PekQuoteCargoBuilder => new PekQuoteCargoBuilder() );
		$this->container->register( PekQuoteRequestBuilder::class, fn(): PekQuoteRequestBuilder => new PekQuoteRequestBuilder( $this->container->get( PekSettings::class ), $this->container->get( PekQuoteCargoBuilder::class ), $this->container->get( PekCountryPolicy::class ) ) );
		$this->container->register( PekQuoteResponseParser::class, fn(): PekQuoteResponseParser => new PekQuoteResponseParser() );
		$this->container->register( PekQuoteMessageSanitizer::class, fn(): PekQuoteMessageSanitizer => new PekQuoteMessageSanitizer( $this->container->get( PekCredentials::class ), $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekLightCargoSurchargePolicy::class, fn(): PekLightCargoSurchargePolicy => new PekLightCargoSurchargePolicy( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekQuotePlannedDateTimeResolver::class, fn(): PekQuotePlannedDateTimeResolver => new PekQuotePlannedDateTimeResolver( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekQuoteService::class, fn(): PekQuoteService => new PekQuoteService( $this->container->get( PekCredentials::class ), $this->container->get( PekApiClient::class ), $this->container->get( PekQuoteRequestBuilder::class ), $this->container->get( PekQuoteResponseParser::class ), $this->container->get( PekQuoteMessageSanitizer::class ), $this->container->get( PekLightCargoSurchargePolicy::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( PekCheckoutPickupPointFormatter::class, fn(): PekCheckoutPickupPointFormatter => new PekCheckoutPickupPointFormatter() );
		$this->container->register( PekCheckoutQuoteContextResolver::class, fn(): PekCheckoutQuoteContextResolver => new PekCheckoutQuoteContextResolver( $this->container->get( PekSettings::class ), $this->container->get( LocationRepository::class ), $this->container->get( PekLocationResolver::class ), $this->container->get( PekAddressBuilder::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( PekQuotePlannedDateTimeResolver::class ), $this->container->get( PekCheckoutPickupPointFormatter::class ), $this->container->get( PekCountryPolicy::class ) ) );
		$this->container->register( PekCarrier::class, fn(): PekCarrier => new PekCarrier( $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ), $this->container->get( PekCheckoutQuoteContextResolver::class ), $this->container->get( PekQuoteService::class ), $this->container->get( PekQuotePlannedDateTimeResolver::class ), $this->container->get( Logger::class ), $this->container->get( PekCountryPolicy::class ) ) );
		$this->container->register( PekQuoteDiagnosticStore::class, fn(): PekQuoteDiagnosticStore => new PekQuoteDiagnosticStore() );
		$this->container->register( PekQuoteDiagnosticService::class, fn(): PekQuoteDiagnosticService => new PekQuoteDiagnosticService( $this->container->get( LocationRepository::class ), $this->container->get( PekLocationResolver::class ), $this->container->get( PekAddressBuilder::class ), $this->container->get( PekSettings::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( PekQuoteService::class ), $this->container->get( PekQuotePlannedDateTimeResolver::class ) ) );
		$this->container->register( PekPrivateAccessTokenService::class, fn(): PekPrivateAccessTokenService => new PekPrivateAccessTokenService( $this->container->get( PekApiClient::class ) ) );
		$this->container->register( PekSenderCounterpartService::class, fn(): PekSenderCounterpartService => new PekSenderCounterpartService( $this->container->get( PekApiClient::class ), $this->container->get( PekPrivateAccessTokenService::class ), $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ) ) );
		$this->container->register( PekSmsReleaseAvailabilityService::class, fn(): PekSmsReleaseAvailabilityService => new PekSmsReleaseAvailabilityService( $this->container->get( PekApiClient::class ), $this->container->get( PekPrivateAccessTokenService::class ), $this->container->get( PekSettings::class ), $this->container->get( PekQuoteMessageSanitizer::class ) ) );
		$this->container->register( PekShipmentDeclaredValueResolver::class, fn(): PekShipmentDeclaredValueResolver => new PekShipmentDeclaredValueResolver() );
		$this->container->register( PekShipmentDestinationResolver::class, fn(): PekShipmentDestinationResolver => new PekShipmentDestinationResolver( $this->container->get( PekPickupPointProvider::class ), $this->container->get( PekLocationResolver::class ), $this->container->get( LocationRepository::class ) ) );
		$this->container->register( PekShipmentProductWeightResolver::class, fn(): PekShipmentProductWeightResolver => new PekShipmentProductWeightResolver( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekShipmentSenderWarehouseResolver::class, fn(): PekShipmentSenderWarehouseResolver => new PekShipmentSenderWarehouseResolver( $this->container->get( PekSettings::class ), $this->container->get( PekSenderWarehouseService::class ) ) );
		$this->container->register( PekShipmentCargoBuilder::class, fn(): PekShipmentCargoBuilder => new PekShipmentCargoBuilder( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekShipmentCourierAddressResolver::class, fn(): PekShipmentCourierAddressResolver => new PekShipmentCourierAddressResolver() );
		$this->container->register( PekShipmentRecipientBuilder::class, fn(): PekShipmentRecipientBuilder => new PekShipmentRecipientBuilder( $this->container->get( PekShipmentCourierAddressResolver::class ), $this->container->get( PekRuPhoneNormalizer::class ) ) );
		$this->container->register( PekShipmentCorrelationResolver::class, fn(): PekShipmentCorrelationResolver => new PekShipmentCorrelationResolver() );
		$this->container->register( PekShipmentRequestBuilder::class, fn(): PekShipmentRequestBuilder => new PekShipmentRequestBuilder( $this->container->get( PekSettings::class ), $this->container->get( PekShipmentDeclaredValueResolver::class ), $this->container->get( PekShipmentSenderWarehouseResolver::class ), $this->container->get( PekShipmentCargoBuilder::class ), $this->container->get( PekShipmentRecipientBuilder::class ), $this->container->get( PekShipmentCorrelationResolver::class ), $this->container->get( PekSmsReleaseAvailabilityService::class ), $this->container->get( PekShipmentDestinationResolver::class ), $this->container->get( PekShipmentProductWeightResolver::class ), $this->container->get( PekCredentials::class ), $this->container->get( PekRuPhoneNormalizer::class ) ) );
		$this->container->register( PekStatusMapping::class, fn(): PekStatusMapping => new PekStatusMapping( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( PekShipmentStatusResponseNormalizer::class, fn(): PekShipmentStatusResponseNormalizer => new PekShipmentStatusResponseNormalizer() );
		$this->container->register( PekShipmentStatusService::class, fn(): PekShipmentStatusService => new PekShipmentStatusService( $this->container->get( PekApiClient::class ), $this->container->get( PekStatusMapping::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentActualCostService::class ), $this->container->get( PekShipmentStatusResponseNormalizer::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( PekShipmentButtonPolicy::class, fn(): PekShipmentButtonPolicy => new PekShipmentButtonPolicy( $this->container->get( PekStatusMapping::class ), $this->container->get( PekCountryPolicy::class ) ) );
		$this->container->register( PekManualAttachContextResolver::class, fn(): PekManualAttachContextResolver => new PekManualAttachContextResolver( $this->container->get( OrderShipmentDraftFactory::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( PekCountryPolicy::class ) ) );
		$this->container->register( PekShipmentService::class, fn(): PekShipmentService => new PekShipmentService( $this->container->get( PekApiClient::class ), $this->container->get( PekShipmentStatusService::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( PekShipmentButtonPolicy::class ), $this->container->get( ShipmentActualCostService::class ), $this->container->get( PekStatusMapping::class ), $this->container->get( PekManualAttachContextResolver::class ), $this->container->get( PekCountryPolicy::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( PekShipmentCreateResponseParser::class, fn(): PekShipmentCreateResponseParser => new PekShipmentCreateResponseParser() );
		$this->container->register( PekShipmentAdapter::class, fn(): PekShipmentAdapter => new PekShipmentAdapter( $this->container->get( PekApiClient::class ), $this->container->get( PekShipmentRequestBuilder::class ), $this->container->get( PekShipmentStatusService::class ), $this->container->get( PekShipmentService::class ), $this->container->get( PekShipmentButtonPolicy::class ), $this->container->get( PekShipmentCreateResponseParser::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( PekCountryPolicy::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( PekShipmentPersistenceMapper::class, fn(): PekShipmentPersistenceMapper => new PekShipmentPersistenceMapper() );
		$this->container->register( PekShipmentModalExtension::class, fn(): PekShipmentModalExtension => new PekShipmentModalExtension( $this->container->get( PekSettings::class ) ) );
		$this->container->register( PekShipmentDocumentService::class, fn(): PekShipmentDocumentService => new PekShipmentDocumentService( $this->container->get( PekApiClient::class ) ) );
		$this->container->register( PekShipmentDocumentProvider::class, fn(): PekShipmentDocumentProvider => new PekShipmentDocumentProvider( $this->container->get( PekShipmentDocumentService::class ) ) );
		$this->container->register( JetLogisticCityNameNormalizer::class, fn(): JetLogisticCityNameNormalizer => new JetLogisticCityNameNormalizer() );
		$this->container->register( JetLogisticRegionNameNormalizer::class, fn(): JetLogisticRegionNameNormalizer => new JetLogisticRegionNameNormalizer() );
		$this->container->register( JetLogisticCitiesCsvClient::class, fn(): JetLogisticCitiesCsvClient => new JetLogisticCitiesCsvClient() );
		$this->container->register( JetLogisticCitiesCsvParser::class, fn(): JetLogisticCitiesCsvParser => new JetLogisticCitiesCsvParser( $this->container->get( JetLogisticCityNameNormalizer::class ), $this->container->get( JetLogisticRegionNameNormalizer::class ) ) );
		$this->container->register( JetLogisticGeographyRepository::class, fn(): JetLogisticGeographyRepository => new JetLogisticGeographyRepository() );
		$this->container->register( JetLogisticGeographyOverrideRepository::class, fn(): JetLogisticGeographyOverrideRepository => new JetLogisticGeographyOverrideRepository() );
		$this->container->register( JetLogisticGeographyMatcher::class, fn(): JetLogisticGeographyMatcher => new JetLogisticGeographyMatcher( $this->container->get( LocationRepository::class ), $this->container->get( JetLogisticGeographyOverrideRepository::class ), $this->container->get( JetLogisticRegionNameNormalizer::class ) ) );
		$this->container->register( JetLogisticCountrySyncService::class, fn(): JetLogisticCountrySyncService => new JetLogisticCountrySyncService( $this->container->get( JetLogisticGeographyRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceCountryRepository::class ), $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( JetLogisticGeographyImportService::class, fn(): JetLogisticGeographyImportService => new JetLogisticGeographyImportService( $this->container->get( JetLogisticCitiesCsvParser::class ), $this->container->get( JetLogisticGeographyMatcher::class ), $this->container->get( JetLogisticGeographyRepository::class ), $this->container->get( JetLogisticCountrySyncService::class ) ) );
		$this->container->register( JetLogisticStatusMappingRepository::class, fn(): JetLogisticStatusMappingRepository => new JetLogisticStatusMappingRepository() );
		$this->container->register( JetLogisticStatusMapper::class, fn(): JetLogisticStatusMapper => new JetLogisticStatusMapper( $this->container->get( JetLogisticStatusMappingRepository::class ) ) );
		$this->container->register( JetLogisticStatusEventResolver::class, fn(): JetLogisticStatusEventResolver => new JetLogisticStatusEventResolver( $this->container->get( JetLogisticStatusMapper::class ) ) );
		$this->container->register( JetLogisticStatusService::class, fn(): JetLogisticStatusService => new JetLogisticStatusService( $this->container->get( JetLogisticApiClient::class ), $this->container->get( JetLogisticStatusMapper::class ), $this->container->get( JetLogisticStatusEventResolver::class ) ) );
		$this->container->register( JetLogisticApiDiagnosticService::class, fn(): JetLogisticApiDiagnosticService => new JetLogisticApiDiagnosticService( $this->container->get( JetLogisticCredentials::class ), $this->container->get( JetLogisticSettings::class ), $this->container->get( JetLogisticApiClient::class ), $this->container->get( JetLogisticGeographyRepository::class ), $this->container->get( JetLogisticStatusMappingRepository::class ), $this->container->get( JetLogisticQuoteResponseParser::class ), $this->container->get( JetLogisticStatusEventResolver::class ) ) );
		$this->container->register( JetLogisticQuoteRequestBuilder::class, fn(): JetLogisticQuoteRequestBuilder => new JetLogisticQuoteRequestBuilder( $this->container->get( JetLogisticCredentials::class ) ) );
		$this->container->register( JetLogisticQuoteResponseParser::class, fn(): JetLogisticQuoteResponseParser => new JetLogisticQuoteResponseParser() );
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
		$this->container->register( DpdGeographyMatcher::class, fn(): DpdGeographyMatcher => new DpdGeographyMatcher() );
		$this->container->register( DpdGeographyImportStateService::class, fn(): DpdGeographyImportStateService => new DpdGeographyImportStateService() );
		$this->container->register( DpdGeographyImportLockService::class, fn(): DpdGeographyImportLockService => new DpdGeographyImportLockService() );
		$this->container->register( DpdGeographyStageRepository::class, fn(): DpdGeographyStageRepository => new DpdGeographyStageRepository() );
		$this->container->register( DpdGeographyImportService::class, fn(): DpdGeographyImportService => new DpdGeographyImportService( $this->container->get( DpdGeographyCsvParser::class ), $this->container->get( DpdGeographyMatcher::class ), $this->container->get( DpdGeographyImportStateService::class ), $this->container->get( DpdGeographyStageRepository::class ), $this->container->get( LocationRepository::class ), $this->container->get( LocationDeliveryCodeRepository::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdGeographyImportLockService::class ) ) );
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
		$this->container->register( ShipmentCreationAttemptService::class, fn(): ShipmentCreationAttemptService => new ShipmentCreationAttemptService( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( ShipmentServiceSettings::class, fn(): ShipmentServiceSettings => new ShipmentServiceSettings( $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( RussianPostShipmentProductMapper::class, fn(): RussianPostShipmentProductMapper => new RussianPostShipmentProductMapper() );
		$this->container->register( RussianPostCreateRequestBuilder::class, fn(): RussianPostCreateRequestBuilder => new RussianPostCreateRequestBuilder( $this->container->get( RussianPostShipmentProductMapper::class ) ) );
		$this->container->register( RussianPostAddressNormalizer::class, fn(): RussianPostAddressNormalizer => new RussianPostAddressNormalizer( $this->container->get( RussianPostOtpravkaApiClient::class ) ) );
		$this->container->register( RussianPostShipmentAdapter::class, fn(): RussianPostShipmentAdapter => new RussianPostShipmentAdapter( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostCreateRequestBuilder::class ), $this->container->get( Logger::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentBacklogService::class ) ) );
		$this->container->register( CdekCreateRequestBuilder::class, fn(): CdekCreateRequestBuilder => new CdekCreateRequestBuilder( $this->container->get( CdekSettings::class ) ) );
		$this->container->register( CdekShipmentAdapter::class, fn(): CdekShipmentAdapter => new CdekShipmentAdapter( $this->container->get( CdekApiClient::class ), $this->container->get( CdekCreateRequestBuilder::class ), $this->container->get( Logger::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekOrderStatusService::class ) ) );
		$this->container->register( ShipmentActualCostComparisonService::class, fn(): ShipmentActualCostComparisonService => new ShipmentActualCostComparisonService() );
		$this->container->register( ShipmentBaseApiCostResolver::class, fn(): ShipmentBaseApiCostResolver => new ShipmentBaseApiCostResolver() );
		$this->container->register( ShipmentActualCostResolver::class, fn(): ShipmentActualCostResolver => new ShipmentActualCostResolver( $this->container->get( ShipmentActualCostComparisonService::class ), $this->container->get( ShipmentBaseApiCostResolver::class ) ) );
		$this->container->register( ShipmentActualCostService::class, fn(): ShipmentActualCostService => new ShipmentActualCostService( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( DpdShipmentDateResolver::class, fn(): DpdShipmentDateResolver => new DpdShipmentDateResolver( $this->container->get( CalendarService::class ), $this->container->get( TimezoneService::class ) ) );
		$this->container->register( DpdShipmentPayloadBuilder::class, fn(): DpdShipmentPayloadBuilder => new DpdShipmentPayloadBuilder( $this->container->get( DpdSettings::class ) ) );
		$this->container->register( DpdShipmentRepository::class, fn(): DpdShipmentRepository => new DpdShipmentRepository( $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( DpdEventNormalizer::class, fn(): DpdEventNormalizer => new DpdEventNormalizer() );
		$this->container->register( DpdShipmentButtonPolicy::class, fn(): DpdShipmentButtonPolicy => new DpdShipmentButtonPolicy() );
		$this->container->register( DpdStatusMapping::class, fn(): DpdStatusMapping => new DpdStatusMapping( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( DpdEventSyncService::class, fn(): DpdEventSyncService => new DpdEventSyncService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdShipmentRepository::class ), $this->container->get( DpdEventNormalizer::class ), $this->container->get( DpdStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ), $this->container->get( Logger::class ), $this->container->get( DpdShipmentEnrichmentService::class ) ) );
		$this->container->register( DpdShipmentEnrichmentService::class, fn(): DpdShipmentEnrichmentService => new DpdShipmentEnrichmentService( $this->container->get( DpdApiClient::class ), $this->container->get( DpdShipmentRepository::class ), $this->container->get( ShipmentActualCostService::class ) ) );
		$this->container->register( DpdShipmentDocumentService::class, fn(): DpdShipmentDocumentService => new DpdShipmentDocumentService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( DpdApiClient::class ) ) );
		$this->container->register( DpdShipmentDocumentProvider::class, fn(): DpdShipmentDocumentProvider => new DpdShipmentDocumentProvider( $this->container->get( DpdShipmentDocumentService::class ) ) );
		$this->container->register( DpdOrderRegistrationService::class, fn(): DpdOrderRegistrationService => new DpdOrderRegistrationService( $this->container->get( DpdShipmentPayloadBuilder::class ), $this->container->get( DpdApiClient::class ), $this->container->get( DpdShipmentRepository::class ), $this->container->get( DpdEventSyncService::class ), $this->container->get( DpdShipmentEnrichmentService::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdShipmentAdapter::class, fn(): DpdShipmentAdapter => new DpdShipmentAdapter( $this->container->get( DpdShipmentPayloadBuilder::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( DpdApiClient::class ), $this->container->get( DpdOrderRegistrationService::class ), $this->container->get( DpdShipmentButtonPolicy::class ), $this->container->get( DpdShipmentEnrichmentService::class ) ) );
		$this->container->register( YandexShipmentRepository::class, fn(): YandexShipmentRepository => new YandexShipmentRepository( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( YandexStatusMapping::class, fn(): YandexStatusMapping => new YandexStatusMapping( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( YandexShipmentButtonPolicy::class, fn(): YandexShipmentButtonPolicy => new YandexShipmentButtonPolicy( $this->container->get( YandexStatusMapping::class ) ) );
		$this->container->register( YandexShipmentLabelPolicy::class, fn(): YandexShipmentLabelPolicy => new YandexShipmentLabelPolicy( $this->container->get( YandexStatusMapping::class ) ) );
		$this->container->register( YandexShipmentDocumentService::class, fn(): YandexShipmentDocumentService => new YandexShipmentDocumentService( $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexDeliveryShipmentClient::class ), $this->container->get( YandexShipmentLabelPolicy::class ) ) );
		$this->container->register( YandexShipmentDocumentProvider::class, fn(): YandexShipmentDocumentProvider => new YandexShipmentDocumentProvider( $this->container->get( YandexShipmentDocumentService::class ), $this->container->get( YandexShipmentLabelPolicy::class ) ) );
		$this->container->register( YandexShipmentModalExtension::class, fn(): YandexShipmentModalExtension => new YandexShipmentModalExtension( $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( YandexShipmentRegistrationService::class, fn(): YandexShipmentRegistrationService => new YandexShipmentRegistrationService( $this->container->get( CoreYandexDeliveryShipmentRegistrationService::class ), $this->container->get( YandexDeliveryShipmentPayloadBuilder::class ), $this->container->get( YandexDeliveryShipmentClient::class ), $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexShipmentPersistenceMapper::class ), $this->container->get( YandexShipmentButtonPolicy::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( YandexShipmentAdapter::class, fn(): YandexShipmentAdapter => new YandexShipmentAdapter( $this->container->get( YandexShipmentRegistrationService::class ), $this->container->get( YandexShipmentButtonPolicy::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( YandexShipmentLabelPolicy::class ) ) );
		$this->container->register( JetLogisticShipmentService::class, fn(): JetLogisticShipmentService => new JetLogisticShipmentService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( JetLogisticStatusService::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( JetLogisticShipmentAdapter::class, fn(): JetLogisticShipmentAdapter => new JetLogisticShipmentAdapter( $this->container->get( JetLogisticShipmentService::class ), $this->container->get( ShipmentActualCostResolver::class ) ) );
		$this->container->register( YandexShipmentPersistenceMapper::class, fn(): YandexShipmentPersistenceMapper => new YandexShipmentPersistenceMapper( $this->container->get( YandexShipmentRepository::class ), $this->container->get( YandexStatusMapping::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( ShipmentMetaboxButtonPolicy::class, fn(): ShipmentMetaboxButtonPolicy => new ShipmentMetaboxButtonPolicy() );
		$this->container->register( CdekStatusMappingService::class, fn(): CdekStatusMappingService => new CdekStatusMappingService( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( CdekOrderStatusService::class, fn(): CdekOrderStatusService => new CdekOrderStatusService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekApiClient::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( ShipmentActualCostService::class ), $this->container->get( Logger::class ), $this->container->get( CdekStatusMappingService::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( CdekBarcodePrintService::class, fn(): CdekBarcodePrintService => new CdekBarcodePrintService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( CdekApiClient::class ) ) );
		$this->container->register( CdekShipmentDocumentProvider::class, fn(): CdekShipmentDocumentProvider => new CdekShipmentDocumentProvider( $this->container->get( CdekBarcodePrintService::class ) ) );
		$this->container->register( CdekShipmentModalExtension::class, fn(): CdekShipmentModalExtension => new CdekShipmentModalExtension() );
		$this->container->register( CdekShipmentPersistenceMapper::class, fn(): CdekShipmentPersistenceMapper => new CdekShipmentPersistenceMapper() );
		$this->container->register( DpdShipmentPersistenceMapper::class, fn(): DpdShipmentPersistenceMapper => new DpdShipmentPersistenceMapper() );
		$this->container->register( ShipmentModalRequestMapper::class, fn(): ShipmentModalRequestMapper => new ShipmentModalRequestMapper() );
		$this->container->register( OrderShipmentDraftFactory::class, fn(): OrderShipmentDraftFactory => new OrderShipmentDraftFactory( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentServiceSettings::class ), $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( RussianPostOtpravkaApiSettings::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( CdekSettings::class ), $this->container->get( CdekTariffRepository::class ), $this->container->get( DpdSettings::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( DpdShipmentDateResolver::class ), $this->container->get( YandexDeliverySettings::class ), $this->container->get( ShipmentModalRequestMapper::class ), $this->container->get( PekSettings::class ), $this->container->get( PekShipmentCourierAddressResolver::class ) ) );
		$this->container->register( RussianPostShipmentActualCostExtractor::class, fn(): RussianPostShipmentActualCostExtractor => new RussianPostShipmentActualCostExtractor() );
		$this->container->register( RussianPostShipmentActualCostLookupService::class, fn(): RussianPostShipmentActualCostLookupService => new RussianPostShipmentActualCostLookupService( $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostShipmentActualCostExtractor::class ) ) );
		$this->container->register( RussianPostShipmentDocumentService::class, fn(): RussianPostShipmentDocumentService => new RussianPostShipmentDocumentService( $this->container->get( RussianPostOtpravkaApiClient::class ) ) );
		$this->container->register( RussianPostShipmentDocumentProvider::class, fn(): RussianPostShipmentDocumentProvider => new RussianPostShipmentDocumentProvider( $this->container->get( RussianPostShipmentDocumentService::class ) ) );
		$this->container->register( RussianPostShipmentModalExtension::class, fn(): RussianPostShipmentModalExtension => new RussianPostShipmentModalExtension() );
		$this->container->register( RussianPostShipmentPersistenceMapper::class, fn(): RussianPostShipmentPersistenceMapper => new RussianPostShipmentPersistenceMapper( $this->container->get( RussianPostShipmentActualCostLookupService::class ) ) );
		$this->container->register( ShipmentDocumentProviderRegistry::class, fn(): ShipmentDocumentProviderRegistry => new ShipmentDocumentProviderRegistry( array( $this->container->get( CdekShipmentDocumentProvider::class ), $this->container->get( DpdShipmentDocumentProvider::class ), $this->container->get( YandexShipmentDocumentProvider::class ), $this->container->get( RussianPostShipmentDocumentProvider::class ), $this->container->get( PekShipmentDocumentProvider::class ) ) ) );
		$this->container->register( ShipmentDocumentDownloadService::class, fn(): ShipmentDocumentDownloadService => new ShipmentDocumentDownloadService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentDocumentProviderRegistry::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( DpdShipmentModalExtension::class, fn(): DpdShipmentModalExtension => new DpdShipmentModalExtension( fn(): array => $this->container->get( SettingsRepository::class )->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() ) ) );
		$this->container->register( ShipmentModalExtensionRegistry::class, fn(): ShipmentModalExtensionRegistry => new ShipmentModalExtensionRegistry( array( $this->container->get( CdekShipmentModalExtension::class ), $this->container->get( DpdShipmentModalExtension::class ), $this->container->get( RussianPostShipmentModalExtension::class ), $this->container->get( YandexShipmentModalExtension::class ), $this->container->get( PekShipmentModalExtension::class ) ) ) );
		$this->container->register( CarrierShipmentAdapterRegistry::class, fn(): CarrierShipmentAdapterRegistry => new CarrierShipmentAdapterRegistry( array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ), $this->container->get( JetLogisticShipmentAdapter::class ), $this->container->get( PekShipmentAdapter::class ) ) ) );
		$this->container->register( ShipmentCreationService::class, fn(): ShipmentCreationService => new ShipmentCreationService( $this->container->get( OrderShipmentRepository::class ), array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ), $this->container->get( PekShipmentAdapter::class ) ), $this->container->get( ShipmentActualCostService::class ), $this->container->get( Logger::class ), $this->container->get( CarrierShipmentAdapterRegistry::class ), array( $this->container->get( RussianPostShipmentPersistenceMapper::class ), $this->container->get( CdekShipmentPersistenceMapper::class ), $this->container->get( DpdShipmentPersistenceMapper::class ), $this->container->get( YandexShipmentPersistenceMapper::class ), $this->container->get( PekShipmentPersistenceMapper::class ) ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( ShipmentOrderStatusMappingService::class, fn(): ShipmentOrderStatusMappingService => new ShipmentOrderStatusMappingService( $this->container->get( SettingsRepository::class ) ) );
		$this->container->register( ShipmentStatusUpdateService::class, fn(): ShipmentStatusUpdateService => new ShipmentStatusUpdateService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( RussianPostTrackingApiClient::class ), $this->container->get( RussianPostTrackingStatusMapper::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( ShipmentOrderStatusMappingService::class ) ) );
		$this->container->register( ShipmentStatusAutoSyncService::class, fn(): ShipmentStatusAutoSyncService => new ShipmentStatusAutoSyncService( $this->container->get( SettingsRepository::class ), $this->container->get( OrderShipmentRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentOrderStatusMappingService::class ), null, $this->container->get( CdekOrderStatusService::class ), null, $this->container->get( CarrierShipmentAdapterRegistry::class ), $this->container->get( DpdEventSyncService::class ), $this->container->get( DpdSettings::class ) ) );
		$this->container->register( ShipmentStatusAutoSyncCron::class, fn(): ShipmentStatusAutoSyncCron => new ShipmentStatusAutoSyncCron( $this->container->get( ShipmentStatusAutoSyncService::class ) ) );
		$this->container->register( ShipmentBacklogService::class, fn(): ShipmentBacklogService => new ShipmentBacklogService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentActualCostService::class ), $this->container->get( RussianPostShipmentActualCostExtractor::class ), $this->container->get( ShipmentCreationAttemptService::class ) ) );
		$this->container->register( RussianPostPassportPointNormalizer::class, fn(): RussianPostPassportPointNormalizer => new RussianPostPassportPointNormalizer() );
		$this->container->register( RussianPostPickupImportStateService::class, fn(): RussianPostPickupImportStateService => new RussianPostPickupImportStateService() );
		$this->container->register( RussianPostPickupImporter::class, fn(): RussianPostPickupImporter => new RussianPostPickupImporter( $this->container->get( RussianPostOtpravkaApiSettings::class ), $this->container->get( RussianPostOtpravkaApiClient::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( RussianPostPassportPointNormalizer::class ), $this->container->get( RussianPostPickupImportStateService::class ), $this->container->get( ActionScheduler::class ), $this->container->get( RussianPostPickupLocationResolver::class ) ) );
		$this->container->register( RussianPostCountryMappingRepository::class, fn(): RussianPostCountryMappingRepository => new RussianPostCountryMappingRepository() );
		$this->container->register( RussianPostCountryMappingService::class, fn(): RussianPostCountryMappingService => new RussianPostCountryMappingService( $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostApiClient::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostCountryDirectory::class, fn(): RussianPostCountryDirectory => new RussianPostCountryDirectory( $this->container->get( RussianPostApiClient::class ), $this->container->get( Logger::class ), $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostCountryMappingService::class ), $this->container->get( RussianPostSettings::class ) ) );
		$this->container->register( RussianPostInternationalCarrier::class, fn(): RussianPostInternationalCarrier => new RussianPostInternationalCarrier( $this->container->get( RussianPostSettings::class ), $this->container->get( RussianPostApiClient::class ), $this->container->get( RussianPostCountryDirectory::class ), $this->container->get( Logger::class ) ) );
		$this->container->register( RussianPostDomesticCarrier::class, fn(): RussianPostDomesticCarrier => new RussianPostDomesticCarrier( $this->container->get( RussianPostDomesticSettings::class ), $this->container->get( RussianPostDomesticApiClient::class ), $this->container->get( RussianPostDomesticTariffVariantResolver::class ), $this->container->get( Logger::class ), $this->container->get( DaDataPostcodeClient::class ), $this->container->get( LocationRepository::class ) ) );
		$this->container->register( CdekCarrier::class, fn(): CdekCarrier => new CdekCarrier( $this->container->get( CdekSettings::class ), $this->container->get( CdekApiClient::class ), $this->container->get( CdekLocationResolver::class ), $this->container->get( Logger::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( CdekTariffRepository::class ) ) );
		$this->container->register( DpdQuoteCarrier::class, fn(): DpdQuoteCarrier => new DpdQuoteCarrier( $this->container->get( DpdSettings::class ), $this->container->get( DpdTariffCalculationService::class ), $this->container->get( DpdPackagingBuilderFactory::class )->create(), $this->container->get( Logger::class ), $this->container->get( CheckoutSessionManager::class ) ) );
		$this->container->register( YandexDeliveryCarrier::class, fn(): YandexDeliveryCarrier => new YandexDeliveryCarrier( $this->container->get( YandexDeliverySettings::class ), $this->container->get( YandexDeliveryApiClient::class ), $this->container->get( YandexLocationMappingV2Repository::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( Logger::class ), $this->container->get( YandexDeliveryPricingRequestBuilder::class ), $this->container->get( YandexDeliveryPricingResponseParser::class ) ) );
		$this->container->register( JetLogisticCarrier::class, fn(): JetLogisticCarrier => new JetLogisticCarrier( $this->container->get( JetLogisticSettings::class ), $this->container->get( JetLogisticApiClient::class ), $this->container->get( JetLogisticQuoteRequestBuilder::class ), $this->container->get( JetLogisticQuoteResponseParser::class ), $this->container->get( JetLogisticGeographyRepository::class ), $this->container->get( JetLogisticCityNameNormalizer::class ), $this->container->get( Logger::class ) ) );
		$this->container->register(
			CarrierRegistry::class,
			function (): CarrierRegistry {
				$registry = new CarrierRegistry();
				$registry->register( $this->container->get( RussianPostInternationalCarrier::class ) );
				$registry->register( $this->container->get( RussianPostDomesticCarrier::class ) );
				$registry->register( $this->container->get( CdekCarrier::class ) );
				$registry->register( $this->container->get( DpdQuoteCarrier::class ) );
				$registry->register( $this->container->get( YandexDeliveryCarrier::class ) );
				$registry->register( $this->container->get( JetLogisticCarrier::class ) );
				$registry->register( $this->container->get( PekCarrier::class ) );

				return $registry;
			}
		);
		$this->container->register( DeliveryServiceRegistry::class, fn(): DeliveryServiceRegistry => new DeliveryServiceRegistry( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( CarrierRegistry::class ) ) );
		$this->container->register( DeliveryServiceManager::class, fn(): DeliveryServiceManager => new DeliveryServiceManager( $this->container->get( DeliveryServiceRepository::class ), $this->container->get( DeliveryServiceCountryRepository::class ), $this->container->get( RuleRepository::class ), $this->container->get( RussianPostCountryDirectory::class ), $this->container->get( DeliveryServiceSettingsRepository::class ) ) );
		$this->container->register( QuoteCache::class, fn(): QuoteCache => new QuoteCache() );
		$this->container->register( DeliveryQuoteCacheManager::class, fn(): DeliveryQuoteCacheManager => new DeliveryQuoteCacheManager( $this->container->get( QuoteCache::class ) ) );
		$this->container->register( RateSorter::class, fn(): RateSorter => new RateSorter() );
		$this->container->register( FallbackRateFactory::class, fn(): FallbackRateFactory => new FallbackRateFactory() );
		$this->container->register( RuleAppliedRateBuilder::class, fn(): RuleAppliedRateBuilder => new RuleAppliedRateBuilder( $this->container->get( RuleEngine::class ) ) );
		$this->container->register( CheckoutLogger::class, fn(): CheckoutLogger => new CheckoutLogger( $this->container->get( Logger::class ) ) );
		$this->container->register( CarrierExecutionGuard::class, fn(): CarrierExecutionGuard => new CarrierExecutionGuard( $this->container->get( CheckoutLogger::class ) ) );
		$this->container->register(
			DeliveryLeadTimeNormalizer::class,
			fn(): DeliveryLeadTimeNormalizer => new DeliveryLeadTimeNormalizer(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( DeliveryServiceSettingsRepository::class ),
				$this->container->get( DeliveryDateCalculator::class ),
				$this->container->get( DeliveryDateFormatter::class )
			)
		);
		$this->container->register(
			CheckoutOrchestrator::class,
			fn(): CheckoutOrchestrator => new CheckoutOrchestrator(
				$this->container->get( CarrierRegistry::class ),
				$this->container->get( RuleAppliedRateBuilder::class ),
				$this->container->get( RateSorter::class ),
				$this->container->get( FallbackRateFactory::class ),
				$this->container->get( CarrierExecutionGuard::class ),
				$this->container->get( CheckoutLogger::class ),
				$this->container->get( DeliveryLeadTimeNormalizer::class ),
				$this->container->get( QuoteCache::class ),
				$this->container->get( DeliveryServiceRegistry::class ),
				$this->container->get( DeliveryServiceManager::class ),
				$this->container->get( PackagingWeightCalculator::class ),
				$this->container->get( DpdSettings::class )
			)
		);
		$this->container->register( CheckoutSessionManager::class, fn(): CheckoutSessionManager => new CheckoutSessionManager() );
		$this->container->register( WooCommerceSessionBootstrapper::class, fn(): WooCommerceSessionBootstrapper => new WooCommerceSessionBootstrapper() );
		$this->container->register( CheckoutPickupPointProviderQueryResolver::class, fn(): CheckoutPickupPointProviderQueryResolver => new CheckoutPickupPointProviderQueryResolver(
			$this->container->get( CheckoutSessionManager::class ),
			array(
				PekSettings::CARRIER_KEY => array( $this->container->get( PekCheckoutQuoteContextResolver::class ), 'query_from_snapshot' ),
			)
		) );
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
		$this->container->register( PickupPointsRestController::class, fn(): PickupPointsRestController => new PickupPointsRestController( $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( PickupAddressSearchService::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexLocationMappingV2Repository::class ), $this->container->get( YandexDeliveryCheckoutPickupPointFormatter::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( CheckoutPickupPointProviderQueryResolver::class ), $this->container->get( PekCheckoutPickupPointFormatter::class ), $this->container->get( WooCommerceSessionBootstrapper::class ) ) );
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
		$this->container->register( RuleFormulaFormatter::class, fn(): RuleFormulaFormatter => new RuleFormulaFormatter() );
		$this->container->register( DeliveryCalculationDataBuilder::class, fn(): DeliveryCalculationDataBuilder => new DeliveryCalculationDataBuilder( $this->container->get( RuleFormulaFormatter::class ) ) );
		$this->container->register( OrderShippingMetaPersister::class, fn(): OrderShippingMetaPersister => new OrderShippingMetaPersister( $this->container->get( CheckoutSessionManager::class ), $this->container->get( DeliveryDateFormatter::class ), $this->container->get( DeliveryCalculationDataBuilder::class ), $this->container->get( LocationRepository::class ) ) );
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
		$this->container->register( ShipmentCostThresholdPolicy::class, fn(): ShipmentCostThresholdPolicy => new ShipmentCostThresholdPolicy() );
		$this->container->register( ShipmentCostAnalyticsTable::class, fn(): ShipmentCostAnalyticsTable => new ShipmentCostAnalyticsTable() );
		$this->container->register( ShipmentCostAnalyticsRepository::class, fn(): ShipmentCostAnalyticsRepository => new ShipmentCostAnalyticsRepository( $this->container->get( ShipmentCostAnalyticsTable::class ) ) );
		$this->container->register( ShipmentCostAnalyticsQuery::class, fn(): ShipmentCostAnalyticsQuery => new ShipmentCostAnalyticsQuery( $this->container->get( ShipmentCostAnalyticsRepository::class ) ) );
		$this->container->register( CreatedShipmentIdentityResolver::class, fn(): CreatedShipmentIdentityResolver => new CreatedShipmentIdentityResolver() );
		$this->container->register( OrderSelectedDeliveryIdentityResolver::class, fn(): OrderSelectedDeliveryIdentityResolver => new OrderSelectedDeliveryIdentityResolver() );
		$this->container->register( OrderAnalyticsShipmentSelector::class, fn(): OrderAnalyticsShipmentSelector => new OrderAnalyticsShipmentSelector( $this->container->get( OrderSelectedDeliveryIdentityResolver::class ), $this->container->get( CreatedShipmentIdentityResolver::class ) ) );
		$this->container->register( ShipmentCostAnalyticsRecordBuilder::class, fn(): ShipmentCostAnalyticsRecordBuilder => new ShipmentCostAnalyticsRecordBuilder( $this->container->get( OrderShipmentRepository::class ), $this->container->get( OrderAnalyticsShipmentSelector::class ), $this->container->get( ShipmentBaseApiCostResolver::class ), $this->container->get( ShipmentCostThresholdPolicy::class ) ) );
		$this->container->register( ShipmentCostAnalyticsIndexer::class, fn(): ShipmentCostAnalyticsIndexer => new ShipmentCostAnalyticsIndexer( $this->container->get( ShipmentCostAnalyticsRecordBuilder::class ), $this->container->get( ShipmentCostAnalyticsRepository::class ), $this->container->get( Logger::class ) ) );
		$this->container->register(
			ShipmentCostAnalyticsService::class,
			fn(): ShipmentCostAnalyticsService => new ShipmentCostAnalyticsService(
				$this->container->get( ShipmentCostAnalyticsQuery::class ),
				$this->container->get( CarrierRegistry::class )
			)
		);
		$this->container->register(
			ShipmentCostAnalyticsAdminSection::class,
			fn(): ShipmentCostAnalyticsAdminSection => new ShipmentCostAnalyticsAdminSection(
				$this->container->get( ShipmentCostAnalyticsService::class ),
				$this->container->get( ShipmentCostThresholdPolicy::class ),
				$this->environment->plugin_url(),
				$this->environment->version()
			)
		);
		$this->container->register(
			AdminMenu::class,
			fn(): AdminMenu => new AdminMenu(
				$this->environment,
				$this->container->get( DeliveryQuoteCacheManager::class ),
				$this->container->get( ShipmentCostAnalyticsAdminSection::class )
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
			RussianPostPickupDiagnosticsTab::class,
			fn(): RussianPostPickupDiagnosticsTab => new RussianPostPickupDiagnosticsTab(
				$this->container->get( RussianPostPickupDiagnosticsService::class )
			)
		);
		$this->container->register( SettingsAdminPage::class, fn(): SettingsAdminPage => new SettingsAdminPage( $this->container->get( SettingsRepository::class ), $this->container->get( FiasCredentials::class ), $this->container->get( AddressSuggestionSettings::class ), $this->container->get( DaDataTokenPool::class ), $this->container->get( RussianPostSettings::class ) ) );
		$this->container->register( RussianPostCountriesAdminPage::class, fn(): RussianPostCountriesAdminPage => new RussianPostCountriesAdminPage( $this->container->get( RussianPostCountryMappingRepository::class ), $this->container->get( RussianPostCountryMappingService::class ) ) );
		$this->container->register( JetLogisticGeographyAdminPage::class, fn(): JetLogisticGeographyAdminPage => new JetLogisticGeographyAdminPage( $this->container->get( JetLogisticGeographyImportService::class ), $this->container->get( JetLogisticCitiesCsvClient::class ), $this->container->get( JetLogisticGeographyOverrideRepository::class ), $this->container->get( JetLogisticGeographyRepository::class ), $this->container->get( JetLogisticCountrySyncService::class ), $this->container->get( LocationRepository::class ), $this->container->get( JetLogisticSettings::class ), $this->container->get( JetLogisticCredentials::class ), $this->container->get( JetLogisticApiDiagnosticService::class ) ) );
		$this->container->register( JetLogisticStatusAdminPage::class, fn(): JetLogisticStatusAdminPage => new JetLogisticStatusAdminPage( $this->container->get( JetLogisticStatusMappingRepository::class ), $this->container->get( JetLogisticApiDiagnosticService::class ) ) );
		$this->container->register( PekAdminPage::class, fn(): PekAdminPage => new PekAdminPage( $this->container->get( PekSettings::class ), $this->container->get( PekCredentials::class ), $this->container->get( PekConnectionDiagnosticService::class ), $this->container->get( PekSenderWarehouseService::class ), $this->container->get( PekAdminNoticeStore::class ), $this->container->get( PekDestinationPickupDiagnosticService::class ), $this->container->get( PekDestinationPickupDiagnosticStore::class ), $this->container->get( PekQuoteDiagnosticService::class ), $this->container->get( PekQuoteDiagnosticStore::class ), $this->container->get( DeliveryQuoteCacheManager::class ), $this->container->get( PekSenderCounterpartService::class ) ) );
		$this->container->register( PekStatusAdminPage::class, fn(): PekStatusAdminPage => new PekStatusAdminPage( $this->container->get( PekStatusMapping::class ) ) );
		$this->container->register( OzonDeliveryAdminPage::class, fn(): OzonDeliveryAdminPage => new OzonDeliveryAdminPage( $this->container->get( OzonDeliveryCredentials::class ), $this->container->get( OzonDeliverySettings::class ), $this->container->get( OzonDeliveryConnectionDiagnosticService::class ) ) );
		$this->container->register(
			DeliveryServicesAdminPage::class,
			fn(): DeliveryServicesAdminPage => new DeliveryServicesAdminPage(
				$this->container->get( DeliveryServiceRepository::class ),
				$this->container->get( DeliveryServiceCountryRepository::class ),
				$this->container->get( RulesAdminPage::class ),
				$this->container->get( RuleRepository::class ),
				$this->container->get( RussianPostPickupDiagnosticsTab::class ),
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
				$this->container->get( DpdQuoteCarrier::class ),
				$this->container->get( YandexDeliveryCarrier::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( JetLogisticGeographyAdminPage::class ),
				$this->container->get( JetLogisticStatusAdminPage::class ),
				$this->container->get( PekAdminPage::class ),
				$this->container->get( PekStatusAdminPage::class ),
				$this->container->get( OzonDeliveryAdminPage::class ),
			)
		);
		$this->container->register( OrderQuoteRequestMapper::class, fn(): OrderQuoteRequestMapper => new OrderQuoteRequestMapper( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( OrderDeliveryRecalculationService::class, fn(): OrderDeliveryRecalculationService => new OrderDeliveryRecalculationService( $this->container->get( OrderQuoteRequestMapper::class ), $this->container->get( CheckoutOrchestrator::class ), $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( OrderDeliveryAddressNormalizationService::class, fn(): OrderDeliveryAddressNormalizationService => new OrderDeliveryAddressNormalizationService( $this->container->get( CheckoutAddressRuntime::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( AddressSuggestionService::class ) ) );
		$this->container->register( OrderDeliveryReplacementService::class, fn(): OrderDeliveryReplacementService => new OrderDeliveryReplacementService( $this->container->get( OrderShipmentRepository::class ), $this->container->get( DeliveryDateFormatter::class ), $this->container->get( DeliveryCalculationDataBuilder::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( PekCheckoutQuoteContextResolver::class ), $this->container->get( PekCheckoutPickupPointFormatter::class ) ) );
		$this->container->register( OrderDeliveryRateRenderer::class, fn(): OrderDeliveryRateRenderer => new OrderDeliveryRateRenderer() );
		$this->container->register( OrderDeliveryRecalculationAdminController::class, fn(): OrderDeliveryRecalculationAdminController => new OrderDeliveryRecalculationAdminController( $this->container->get( OrderDeliveryRecalculationService::class ), $this->container->get( OrderDeliveryRateRenderer::class ), $this->container->get( CheckoutLocationAjax::class ), $this->container->get( RussianPostPickupPointRepository::class ), $this->container->get( OrderDeliveryAddressNormalizationService::class ), $this->container->get( OrderDeliveryReplacementService::class ), $this->container->get( YandexDeliveryCheckoutPickupPointFormatter::class ), $this->container->get( SettingsRepository::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( DpdPickupPointScheduleFormatter::class ), $this->container->get( CarrierPickupPointProviderRegistry::class ), $this->container->get( PekCheckoutQuoteContextResolver::class ), $this->container->get( PekCheckoutPickupPointFormatter::class ), $this->container->get( PekCountryPolicy::class ), $this->environment->plugin_url(), $this->environment->version(), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( YandexDeliveryPickupPointV2Repository::class ), $this->container->get( YandexLocationMappingV2Repository::class ) ) );
		$this->container->register( OrderDeliveryMetabox::class, fn(): OrderDeliveryMetabox => new OrderDeliveryMetabox( $this->container->get( OrderShipmentRepository::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class, fn(): \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService => new \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService( $this->container->get( AddressSuggestionSettings::class ), $this->container->get( AddressSuggestionClientInterface::class ), $this->container->get( CdekLocationResolver::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminAjaxService::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminAjaxService => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminAjaxService() );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder( $this->container->get( OrderShipmentRepository::class ), $this->container->get( DeliveryServiceRepository::class ), $this->container->get( ShipmentStatusUpdateService::class ), $this->container->get( ShipmentActualCostResolver::class ), $this->container->get( CdekOrderStatusService::class ), $this->container->get( ShipmentBacklogService::class ), $this->container->get( CarrierShipmentAdapterRegistry::class ), $this->container->get( ShipmentMetaboxButtonPolicy::class ), $this->container->get( ShipmentDocumentProviderRegistry::class ), $this->container->get( ShipmentDocumentDownloadService::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController( $this->container->get( OrderShipmentRepository::class ), $this->container->get( OrderShipmentDraftFactory::class ), $this->container->get( ShipmentCreationService::class ), $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ), $this->container->get( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController( $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController( $this->container->get( OrderShipmentDraftFactory::class ), $this->container->get( ShipmentCreationService::class ), $this->container->get( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController( $this->container->get( OrderShipmentRepository::class ), $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController( $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController( $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController( $this->container->get( RussianPostAddressNormalizer::class ), $this->container->get( CdekDeliveryPointService::class ), $this->container->get( DpdPickupPointService::class ), $this->container->get( \WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService::class ), $this->container->get( AddressSuggestionService::class ), $this->container->get( RussianPostPickupPointTypeSettings::class ), $this->container->get( PekSenderWarehouseService::class ), $this->container->get( ShipmentModalRequestMapper::class ), $this->container->get( OrderShipmentDraftFactory::class ) ) );
		$this->container->register( ShipmentActualCostAjaxController::class, fn(): ShipmentActualCostAjaxController => new ShipmentActualCostAjaxController( $this->container->get( ShipmentActualCostService::class ), $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController( $this->container->get( OrderShipmentRepository::class ), $this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder::class ), $this->container->get( CdekBarcodePrintService::class ) ) );
		$this->container->register( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController::class, fn(): \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController => new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController() );
		$this->container->register(
			OrderShipmentsMetabox::class,
			fn(): OrderShipmentsMetabox => new OrderShipmentsMetabox(
				$this->container->get( OrderShipmentRepository::class ),
				$this->container->get( OrderShipmentDraftFactory::class ),
				$this->container->get( DeliveryServiceRepository::class ),
				$this->container->get( ShipmentStatusUpdateService::class ),
				$this->container->get( ShipmentActualCostResolver::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController::class ),
				$this->container->get( ShipmentActualCostAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController::class ),
				$this->container->get( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController::class ),
				$this->container->get( CdekOrderStatusService::class ),
				$this->container->get( ShipmentBacklogService::class ),
				$this->container->get( RussianPostPickupPointTypeSettings::class ),
				$this->environment->plugin_url(),
				$this->environment->version(),
				$this->container->get( CarrierShipmentAdapterRegistry::class ),
				$this->container->get( ShipmentMetaboxButtonPolicy::class ),
				$this->container->get( ShipmentDocumentProviderRegistry::class ),
				$this->container->get( ShipmentDocumentDownloadService::class ),
				$this->container->get( ShipmentModalExtensionRegistry::class )
			)
		);
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
		add_action( ShipmentCostAnalyticsIndexer::SHIPMENT_CHANGED_HOOK, array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_shipment_changed' ), 10, 3 );
		add_action( ShipmentCostAnalyticsIndexer::SHIPMENT_DELETED_HOOK, array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_shipment_changed' ), 10, 2 );
		add_action( 'wdc_delivery_calculation_changed', array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_shipment_changed' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_trash_order', array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_order_deleted' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( $this->container->get( ShipmentCostAnalyticsIndexer::class ), 'handle_order_restored' ), 10, 1 );
		add_action( YandexDeliveryGeoPipelineV2Runner::CRON_HOOK, array( $this->container->get( YandexDeliveryGeoPipelineV2Runner::class ), 'run_scheduled_step' ) );
		add_action( YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK, array( $this->container->get( YandexDeliveryGeoPipelineV2Runner::class ), 'run_scheduled_start' ) );
		$this->container->get( YandexDeliveryGeoPipelineV2Runner::class )->ensure_schedule();
		add_action( 'rest_api_init', array( $this->container->get( PickupPointsRestController::class ), 'register' ) );
		add_action( 'rest_api_init', array( $this->container->get( CheckoutPickupPointRestController::class ), 'register' ) );
	}

	public function boot_modules(): void {
		if ( ! $this->run_migrations_safely() ) {
			return;
		}
		$this->container->get( DeliveryServiceManager::class )->ensure_builtin_services();
		$this->container->get( CalendarService::class )->ensure_initial_years();
		$this->container->get( ActionScheduler::class );
		$this->container->get( GarChangesService::class );
		$this->container->get( CalendarScheduler::class )->register();
		$this->container->get( GarSyncManager::class )->register();
		$this->container->get( FiasImportManager::class )->register();
	}

	public function activate(): void {
		if ( ! $this->run_migrations_safely() ) {
			return;
		}
		$this->container->get( DeliveryServiceManager::class )->ensure_builtin_services();
		$this->container->get( CalendarService::class )->ensure_initial_years();
		$this->container->get( DpdPickupPointAutoSync::class )->activate();
	}

	public function deactivate(): void {
		$this->container->get( DpdPickupPointAutoSync::class )->deactivate();
	}

	private function run_migrations_safely(): bool {
		try {
			$this->container->get( MigrationManager::class )->run();
			$this->migration_failure_message = '';
			return true;
		} catch ( \Throwable $exception ) {
			$this->migration_failure_message = $this->safe_migration_failure_message( $exception );
			$this->container->get( Logger::class )->error(
				'Database migration failed.',
				array(
					'error' => $this->migration_failure_message,
					'exception_class' => get_class( $exception ),
				)
			);
			add_action( 'admin_notices', array( $this, 'render_migration_failure_notice' ) );
			return false;
		}
	}

	public function render_migration_failure_notice(): void {
		if ( '' === $this->migration_failure_message || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Калькулятор доставок: миграция базы данных не завершена.', 'walls-delivery-calc' ); ?></strong>
				<?php echo esc_html( $this->migration_failure_message ); ?>
			</p>
		</div>
		<?php
	}

	private function safe_migration_failure_message( \Throwable $exception ): string {
		$message = trim( $exception->getMessage() );
		if ( '' === $message ) {
			return 'Database migration failed.';
		}
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;

		return trim( substr( $message, 0, 240 ) );
	}
}
