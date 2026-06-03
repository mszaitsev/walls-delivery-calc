# Карта текущего кода Walls Delivery Calc

Актуальный baseline: версия `0.33.8`, runtime находится в `src/`. Старый runtime `includes/...` больше не является текущей структурой проекта. Исторический аудит legacy-структуры сохранен в `docs/wdc-architecture-audit.md`.

Единый статус готовности проекта ведется в `docs/project-status.md`.

## Сводная таблица

| Зона | Назначение | Ключевые файлы |
| --- | --- | --- |
| `walls-delivery-calc.php` | WordPress plugin entrypoint, version constants, запуск core platform. | `walls-delivery-calc.php`, `src/Core/bootstrap.php` |
| `src/Core` | Autoloading, DI container, plugin wiring, feature flags, environment, requirements. | `Plugin.php`, `Container.php`, `Autoloader.php`, `FeatureFlags.php` |
| `src/Infrastructure` | Database migrations, logging, queue abstraction, settings, encryption. | `MigrationManager.php`, `Logger.php`, `ActionScheduler.php`, `SettingsRepository.php`, `EncryptionService.php` |
| `src/WooCommerce` | HPOS compatibility declaration. | `HPOSCompatibility.php` |
| `src/Domain` | Framework-independent delivery domain model. | `Address`, `Calendar`, `Carrier`, `Common`, `Package`, `Pickup`, `Quote`, `Shipment`, `Status` |
| `src/Calendar` | Calendar storage, generation, admin UI, planned delivery date calculation. | `CalendarService.php`, `DeliveryDateCalculator.php`, `CalendarAdminPage.php` |
| `src/Locations` | FIAS/GAR locations, imports, aliases, search, snapshots, enrichment helpers. | `LocationRepository.php`, `GarSyncManager.php`, `GarPlacesCsvImporter.php`, `LocationsAdminPage.php` |
| `src/Checkout` | Checkout runtime, WooCommerce shipping method integration, city/address/pickup UX, cache, sorting, validation. | `CheckoutOrchestrator.php`, `NewShippingMethod.php`, `ShippingMethodRegistrar.php`, `OrderShippingMetaPersister.php` |
| `src/Rules` | Rule Engine, rule storage, conditions/actions, admin builder and simulator. | `RuleEngine.php`, `RuleEvaluator.php`, `RuleRepository.php`, `RulesAdminPage.php` |
| `src/Carriers` | Carrier adapter interface, registry, Russian Post runtime and API clients. | `CarrierAdapterInterface.php`, `CarrierRegistry.php`, `RussianPostDomesticCarrier.php`, `RussianPostInternationalCarrier.php` |
| `src/DeliveryServices` | Delivery service definitions, service settings/countries, admin management. | `DeliveryServiceManager.php`, `DeliveryServiceRegistry.php`, `DeliveryServicesAdminPage.php` |
| `src/Pickup` | Pickup domain runtime, Russian Post pickup import/diagnostics, REST endpoints. | `RussianPostPickupImporter.php`, `RussianPostPickupPointRepository.php`, `PickupPointsRestController.php` |
| `src/Orders` | Admin order delivery metabox. | `OrderDeliveryMetabox.php` |
| `src/Packaging` | Packaging weight calculation. | `PackagingWeightCalculator.php` |
| `database/migrations` | Versioned database schema changes. | `0001...0025` |
| `assets` | Admin and checkout CSS/JS, pickup map, vendor Leaflet. | `assets/admin`, `assets/frontend`, `assets/vendor/leaflet` |
| `tests` | Standalone smoke tests for domain/runtime/admin flows. | `tests/*/run-*.php` |

## Root

### `walls-delivery-calc.php`

- Defines plugin metadata and `WDC_VERSION`.
- Current version: `0.33.8`.
- Requires `src/Core/bootstrap.php`.
- Calls `wdc_bootstrap_core_platform()`.

### `uninstall.php`

- Cleanup entrypoint for plugin uninstall.
- Current cleanup behavior is documented in `docs/wdc-legacy-uninstall-cleanup.md`.

## Core Platform

### `src/Core/bootstrap.php`

- Registers `WallsShop\WDC\` autoloading from `src/`.
- Creates `PluginEnvironment`.
- Instantiates and registers `Plugin`.

### `src/Core/Plugin.php`

- Main runtime composition root.
- Registers services in the container.
- Wires admin pages, checkout integration, locations, calendar, rules, carriers, delivery services, pickup controllers, migrations, and background hooks.
- Registers Russian Post domestic and international carriers in `CarrierRegistry`.

### `src/Core/Container.php`

- Lightweight service container used by the plugin runtime.

### `src/Core/FeatureFlags.php`

- Holds runtime feature gates used by checkout and platform modules.

### `src/Core/RequirementsChecker.php`

- Checks runtime requirements such as WooCommerce availability/version.

## Infrastructure, Settings, Repositories, Migrations

### `src/Infrastructure/Settings/SettingsRepository.php`

- Central option/settings access layer.
- Used by Russian Post settings, delivery services, checkout feature gates, locations, DaData, and packaging.

### `src/Infrastructure/Security/EncryptionService.php`

- Handles encryption/decryption for secret settings such as API credentials.

### `src/Infrastructure/Logging`

- `Logger.php` provides logging abstraction.
- `LogRedactor.php` redacts sensitive data before logging.

### `src/Infrastructure/Queue/ActionScheduler.php`

- Wraps Action Scheduler / WP Cron style scheduling for background jobs.

### `src/Infrastructure/Database/MigrationManager.php`

- Runs migration files from `database/migrations`.
- Current migrations cover calendars, locations, aliases, GAR changes/imports, rules, Russian Post country mappings, delivery services, Russian Post pickup points, and location indexes/fields.

### Repository zones

- `src/Calendar/Storage/CalendarRepository.php`
- `src/Locations/Storage/LocationRepository.php`
- `src/Locations/Storage/RegionRepository.php`
- `src/Pickup/Storage/PickupPointRepository.php`
- `src/Pickup/RussianPost/RussianPostPickupPointRepository.php`
- `src/Rules/Storage/RuleRepository.php`
- `src/DeliveryServices/*Repository.php`
- `src/Carriers/RussianPost/RussianPostCountryMappingRepository.php`

## Domain

`src/Domain` is the framework-independent model layer.

- `Address`: address data and normalization result.
- `Calendar`: calendar day and planned delivery date.
- `Carrier`: carrier identity and capabilities.
- `Common`: money, date ranges, delivery days formatter.
- `Package`: package, package items, shipment places.
- `Pickup`: pickup point and pickup selection.
- `Quote`: quote request, delivery quote/rate/type.
- `Shipment`: shipment and shipment creation request/result baseline.
- `Status`: delivery status, status event, status mapping baseline.

Shipment and status domain classes exist, but runtime creation/tracking/status synchronization is not implemented yet.

## Calendar

Key files:

- `src/Calendar/CalendarTypes.php`
- `src/Calendar/Services/CalendarService.php`
- `src/Calendar/Services/DeliveryDateCalculator.php`
- `src/Calendar/Services/DeliveryDateFormatter.php`
- `src/Calendar/Services/TimezoneService.php`
- `src/Calendar/Services/YearGenerator.php`
- `src/Calendar/Services/CalendarScheduler.php`
- `src/Calendar/Admin/CalendarAdminPage.php`

Responsibilities:

- Generate and edit calendar days.
- Separate shop processing calendar from carrier/RU calendar.
- Calculate handoff date and planned delivery date/range.
- Schedule next-year generation.

## Locations, FIAS/GAR, DaData

### Locations

Key files:

- `src/Locations/Admin/LocationsAdminPage.php`
- `src/Locations/Storage/LocationRepository.php`
- `src/Locations/Storage/RegionRepository.php`
- `src/Locations/Services/LocationSearchService.php`
- `src/Locations/Services/LocationDisplayNameFormatter.php`
- `src/Locations/Services/LocationCountryIndexService.php`
- `src/Locations/Services/KeyboardLayoutTransformer.php`

Responsibilities:

- Local settlement/city storage.
- Search and checkout lookup.
- Admin import/cleanup/snapshot tooling.
- Display-name and alias management.

### FIAS/GAR

Key files:

- `src/Locations/Fias/*`
- `src/Locations/Gar/GarChangesClient.php`
- `src/Locations/Gar/GarSyncManager.php`
- `src/Locations/Import/FiasImportManager.php`
- `src/Locations/Import/GarPlacesCsvImporter.php`
- `src/Locations/Import/LocationImportService.php`
- `src/Locations/Import/LocationIncrementalUpdateService.php`
- `src/Locations/Import/LocationsSnapshotExporter.php`
- `src/Locations/Import/LocationsSnapshotImporter.php`

Responsibilities:

- Prepared FIAS/GAR imports.
- GAR change checks.
- CSV imports.
- Snapshot export/import.
- Incremental updates.

### DaData

Key files:

- `src/Checkout/AddressSuggestions/*`
- `src/Locations/Postcodes/DaDataPostcodeClient.php`
- `src/Locations/Coordinates/LocationCoordinatesDadataBatchUpdater.php`

Responsibilities:

- Checkout address suggestions.
- Token pool and daily usage limits.
- Postcode and coordinate enrichment.
- Pickup address search support.

## Checkout Runtime

### Runtime pipeline

Key files:

- `src/Checkout/Runtime/CheckoutOrchestrator.php`
- `src/Checkout/Runtime/CarrierExecutionGuard.php`
- `src/Checkout/Runtime/FallbackRateFactory.php`
- `src/Checkout/Runtime/RuleAppliedRateBuilder.php`
- `src/Checkout/Runtime/CheckoutCalculationResult.php`
- `src/Checkout/Runtime/CheckoutLogger.php`
- `src/Checkout/Cache/QuoteCache.php`
- `src/Checkout/Cache/DeliveryQuoteCacheManager.php`
- `src/Checkout/Sorting/RateSorter.php`

Responsibilities:

- Map WooCommerce package/destination into a quote request.
- Execute registered carriers.
- Apply delivery services and Rule Engine.
- Cache quotes.
- Sort rates.
- Build fallback rates.

### WooCommerce checkout integration

Key files:

- `src/Checkout/WooCommerce/ShippingMethodRegistrar.php`
- `src/Checkout/WooCommerce/NewShippingMethod.php`
- `src/Checkout/WooCommerce/WooCommercePackageMapper.php`
- `src/Checkout/WooCommerce/WooCommerceRateMapper.php`
- `src/Checkout/WooCommerce/OrderShippingMetaPersister.php`
- `src/Checkout/WooCommerce/CheckoutSessionManager.php`
- `src/Checkout/WooCommerce/CheckoutValidation.php`
- `src/Checkout/WooCommerce/CheckoutSortSelector.php`
- `src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php`
- `src/Checkout/WooCommerce/CheckoutRateRenderer.php`
- `src/Checkout/WooCommerce/CheckoutAddressRenderer.php`
- `src/Checkout/WooCommerce/PickupMapCheckout.php`
- `src/Checkout/WooCommerce/PickupPointRenderer.php`
- `src/Checkout/WooCommerce/PickupPointOrderDisplay.php`
- `src/Checkout/WooCommerce/CourierRateSupport.php`

Responsibilities:

- Register WooCommerce shipping method.
- Enqueue checkout assets.
- Render WDC rates, sorting, delivery type controls, courier address summary, pickup UI.
- Persist shipping calculation snapshot to order/order item meta using WooCommerce APIs.
- Validate pickup selection and courier address requirements.

### Checkout locations and address runtime

Key files:

- `src/Checkout/Locations/*`
- `src/Checkout/Address/*`
- `src/Checkout/AddressSuggestions/*`
- `src/Checkout/Admin/CheckoutSimulationPage.php`

Responsibilities:

- Local city picker/search/resolve.
- Address normalization runtime.
- DaData suggestions.
- Admin simulation page.

## Rules

Key files:

- `src/Rules/Domain/*`
- `src/Rules/Services/ConditionEvaluator.php`
- `src/Rules/Services/RuleEvaluator.php`
- `src/Rules/Services/RuleEngine.php`
- `src/Rules/Services/RuleSimulator.php`
- `src/Rules/Services/RuleFormulaFormatter.php`
- `src/Rules/Storage/RuleRepository.php`
- `src/Rules/Admin/RulesAdminPage.php`

Responsibilities:

- Store and evaluate rules.
- Support condition groups and expressions.
- Modify delivery price and delivery days.
- Disable rates and stop processing.
- Produce audit trail.
- Provide admin CRUD/simulation UI.

## Carriers

### Carrier contract and registry

Key files:

- `src/Carriers/Contracts/CarrierAdapterInterface.php`
- `src/Carriers/Registry/CarrierRegistry.php`
- `src/Carriers/Runtime/CarrierRuntimeContext.php`

Current registered carriers:

- `RussianPostDomesticCarrier`
- `RussianPostInternationalCarrier`

No CDEK, DPD, Yandex Delivery, PEK, Energia, Aerogruz, or Jet adapters are present in the current code.

### Russian Post

Key files:

- `src/Carriers/Runtime/RussianPostDomesticCarrier.php`
- `src/Carriers/Runtime/RussianPostInternationalCarrier.php`
- `src/Carriers/RussianPost/RussianPostApiClient.php`
- `src/Carriers/RussianPost/RussianPostDomesticApiClient.php`
- `src/Carriers/RussianPost/RussianPostSettings.php`
- `src/Carriers/RussianPost/RussianPostDomesticSettings.php`
- `src/Carriers/RussianPost/RussianPostCountryDirectory.php`
- `src/Carriers/RussianPost/RussianPostCountryMappingService.php`
- `src/Carriers/RussianPost/RussianPostCountryMappingRepository.php`
- `src/Carriers/RussianPost/RussianPostDomesticTariffVariantResolver.php`
- `src/Carriers/RussianPost/RussianPostCourierTariffProbeService.php`
- `src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiSettings.php`
- `src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiClient.php`
- `src/Carriers/RussianPost/Admin/RussianPostCountriesAdminPage.php`

Responsibilities:

- Domestic and international rate calculation.
- Country mapping and country availability.
- Domestic tariff variants.
- Courier tariff probing.
- Shared Otpravka credentials/client foundation for pickup import and future shipment operations.

## Delivery Services

Key files:

- `src/DeliveryServices/DeliveryService.php`
- `src/DeliveryServices/DeliveryServiceManager.php`
- `src/DeliveryServices/DeliveryServiceRegistry.php`
- `src/DeliveryServices/DeliveryServiceRepository.php`
- `src/DeliveryServices/DeliveryServiceSettingsRepository.php`
- `src/DeliveryServices/DeliveryServiceCountryRepository.php`
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php`

Responsibilities:

- Store service definitions and service-specific settings.
- Configure enabled services, countries, comments, packaging behavior, and service rules.
- Provide admin UI for delivery services and Russian Post pickup import controls.

The layer supports current Russian Post services. A full manual/fixed pseudo-carrier lifecycle is not complete yet.

## Pickup

### Generic pickup baseline

Key files:

- `src/Domain/Pickup/*`
- `src/Pickup/Storage/PickupPointRepository.php`
- `src/Pickup/Services/PickupPointLocationResolver.php`
- `src/Pickup/Presentation/PickupPointCardRenderer.php`
- `src/Pickup/Admin/PickupAdminPage.php`

### Russian Post pickup

Key files:

- `src/Pickup/RussianPost/RussianPostPickupImporter.php`
- `src/Pickup/RussianPost/RussianPostPickupImportStateService.php`
- `src/Pickup/RussianPost/RussianPostPickupPointRepository.php`
- `src/Pickup/RussianPost/RussianPostPassportPointNormalizer.php`
- `src/Pickup/RussianPost/RussianPostPickupDiagnosticsService.php`
- `src/Pickup/RussianPost/RussianPostPickupLocationResolver.php`
- `src/Pickup/RussianPost/RussianPostPickupPointTypeSettings.php`
- `src/Pickup/RussianPost/RussianPostWorkTimeFormatter.php`
- `src/Pickup/Search/PickupAddressSearchService.php`

Responsibilities:

- Import Russian Post passport pickup data.
- Maintain import state and diagnostics.
- Store compact Russian Post pickup rows in a carrier-specific table.
- Resolve pickup points to local checkout locations.
- Support address/postcode search for pickup map.

### Pickup REST

Key files:

- `src/Pickup/Rest/PickupPointsRestController.php`
- `src/Pickup/Rest/CheckoutPickupPointRestController.php`

Endpoints:

- Public read-only pickup directory/search/detail endpoints.
- Nonce-protected checkout pickup selection/state endpoints.

## Admin

Admin pages are split across modules:

- `src/Admin/AdminMenu.php`
- `src/Admin/AdminNotices.php`
- `src/Admin/SettingsAdminPage.php`
- `src/Calendar/Admin/CalendarAdminPage.php`
- `src/Locations/Admin/LocationsAdminPage.php`
- `src/Rules/Admin/RulesAdminPage.php`
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php`
- `src/Pickup/Admin/PickupAdminPage.php`
- `src/Checkout/Admin/CheckoutSimulationPage.php`
- `src/Orders/Admin/OrderDeliveryMetabox.php`

Responsibilities:

- WDC menu and overview/settings.
- Calendar editing.
- Locations import/search/cleanup/snapshots.
- Rules CRUD and simulation.
- Delivery services and Russian Post pickup import controls.
- Pickup summary.
- Checkout simulation.
- Order delivery metabox.

Order admin recalculation is not fully implemented yet.

## Assets

### Admin assets

- `assets/admin/calendar-admin.css`
- `assets/admin/calendar-admin.js`
- `assets/admin/checkout-simulation.css`
- `assets/admin/locations-admin.css`
- `assets/admin/rules-admin.css`
- `assets/admin/rules-admin.js`
- `assets/admin/russian-post-pickup-import.js`

### Frontend checkout assets

- `assets/frontend/checkout-address-suggestions.css`
- `assets/frontend/checkout-address-suggestions.js`
- `assets/frontend/checkout-city-selector.css`
- `assets/frontend/checkout-city-selector.js`
- `assets/frontend/checkout-rates.css`
- `assets/frontend/checkout-sort.js`
- `assets/frontend/courier-address-summary.js`
- `assets/frontend/domestic-tariff-selector.css`
- `assets/frontend/domestic-tariff-selector.js`
- `assets/frontend/pickup-foundation.css`

### Pickup map assets

- `assets/frontend/pickup-map/wdc-pickup-api.js`
- `assets/frontend/pickup-map/wdc-pickup-modal.js`
- `assets/frontend/pickup-map/wdc-pickup-map.js`
- `assets/frontend/pickup-map/wdc-pickup-checkout.js`
- `assets/frontend/pickup-map/wdc-pickup-map.css`
- `assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js`
- `assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js`
- `assets/vendor/leaflet/*`

## Tests

Standalone smoke tests are stored under `tests/`.

Main coverage areas:

- `tests/domain`
- `tests/calendar`
- `tests/fias`
- `tests/locations`
- `tests/address`
- `tests/checkout`
- `tests/rules`
- `tests/carriers`
- `tests/delivery-services`
- `tests/pickup`
- `tests/orders`
- `tests/packaging`
- `tests/runtime`

Representative commands:

```powershell
php tests/domain/run-domain-smoke.php
php tests/calendar/run-calendar-smoke.php
php tests/checkout/run-checkout-smoke.php
php tests/pickup/run-russian-post-pickup-import-smoke.php
php tests/runtime/run-no-legacy-smoke.php
```

## Не реализовано в текущем runtime

- CDEK, DPD, Yandex Delivery, PEK, Energia, Aerogruz, Jet adapters.
- Shipment runtime for creating carrier shipments.
- Carrier labels, acts, documents, and tracking status polling.
- Automatic WooCommerce status changes based on delivery status.
- Full order admin recalculation workflow.
- Production monitoring/status dashboard.
