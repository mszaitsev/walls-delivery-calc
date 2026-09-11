# Dependency Injection

Version: 1.0.0

`Plugin.php` is the only composition root. Runtime services receive required collaborators through constructors; carrier-specific settings, clients, mappers, adapters, document providers, and schedulers remain owned by their carrier modules. Checkout and Rule Engine domain services do not locate WooCommerce globals outside the documented boundary adapters.

The shared `ActionScheduler` adapter owns request-scoped readiness coordination. Scheduler owners identify deferred callbacks by class, preventing duplicate registration within one bootstrap request. `TimezoneService` is injected into clock-based scheduler owners, while `ScheduledTaskCatalog` is read-only and never executes or repairs jobs.

The 1.0 composition root contains no prepared-dataset, automatic GAR/SPAS, or location-alias services. `postal_code` is enrichment-owned; canonical location search uses `searchable_text` and hierarchy fields.

## GAR Update Composition

The composition root injects `LocationIncrementalCandidateEnricher` and `DeliveryQuoteCacheManager` into `LocationIncrementalUpdateService`. The enricher reuses the registered `DaDataPostcodeClient`, `LocationCoordinatesDadataBatchUpdater` single-location resolver and `RussianPostCourierCalcPostcodeFillStateService` scoped step. It returns patches only; no repository table switching or duplicate HTTP client is introduced. The small option-backed `LocationMaintenanceJobGuard` centralizes the existing maintenance-state contract inside the shared write lock and backup guard.

The composition root registers `LocationWriteLock`, `LocationDatabaseBackupService` and `LocationDatabaseBackupAdmin`. The backup service receives the shared lock, `LocationCountryIndexService`, `DeliveryQuoteCacheManager` and `Logger`; the admin owner receives it plus `PluginEnvironment` and `Logger`. `LocationsAdminPage` receives the admin owner and lock; `DpdGeographyImportService` receives the same lock for its locations-writing requests. No service lookup occurs inside these services. Optional trailing constructor dependencies retain existing diagnostic fixtures; production composition always supplies the lock.

Manual delivery support is wired only through `Plugin.php`: `ManualDeliverySettings` uses the existing delivery-service settings repository, `ManualDeliveryCarrier` is registered once in `CarrierRegistry`, and `DeliveryServicesAdminPage` receives the settings collaborator for CRUD persistence. Generic checkout, rules, and shipment services do not depend on manual implementation classes, and required collaborators are still constructor-injected through the composition root.

Delivery-service country synchronization remains owned by the injected `DeliveryServiceCountryRepository`. The `service_country (service_id, country_code)` unique key is the integrity boundary; `replace_countries()` is write-idempotent, removes only stale rows, and inserts missing rows with duplicate-safe UPSERT. Generic pickup validation uses the injected/session `requires_pickup_point` metadata rather than assuming that every pickup delivery type needs a selectable point. Customer comments are snapshotted and rendered by the injected generic comment services. Jet Logistic order administration and shipment actions use the shared draft factory, adapter registry, and returned server capabilities without a Jet-specific service locator or cron.

Ozon Delivery pickup catalog, quote, diagnostic, runtime services, shipment services, and `OzonDeliveryPackagingBuilderFactory` are carrier-owned and composed only in `src/Core/Plugin.php`. The factory supplies the Ozon-only 50x50x30 cm parcel policy to the generic builder without an Ozon branch in packaging code. The pickup importer owns the two-phase generation workflow: discovery freezes `/v1/delivery-point/list` IDs in `wdc_ozon_delivery_pickup_ids`, enrichment resolves frozen IDs through `/v1/delivery-point/info`, and the existing activation path publishes the new generation only after completion. The pickup provider is registered through `CarrierPickupPointProviderRegistry`, reads only the active local snapshot, and has no API-client dependency or staging-table dependency. The runtime `OzonDeliveryCarrier` is registered through `CarrierRegistry` and can emit both `ozon_delivery:pickup` and `ozon_delivery:courier` rates; its buyer-facing tracking/refusal comments live on the final rate and are persisted by the generic customer-comment snapshot builder. `OzonDeliveryQuoteRequestBuilder` keeps separate pickup and courier delivery-object builders; `OzonDeliveryCourierLocationResolver` resolves courier checkout pricing coordinates from trusted DaData exact-address geo data first, then from the nearest active local Ozon pickup point within 1 km of the selected `LocationRepository` row, and `OzonDeliveryCourierAddressMapper` owns the official courier coordinates payload/fingerprint. Ozon shipment creation/status/cancel/label/modal/persistence classes register through the existing Shipment Framework registries and mapper list; manual attach is an Ozon adapter/service method over the typed API client and normal repository shape, not a second admin metabox or repository. Approve remains inside the Ozon create orchestration rather than becoming a generic framework action.

`WooCommercePackageMapper` receives the canonical `LocationRepository` through DI so checkout can resolve generic destination coordinates by `selected_location_id` when session city context has no latitude/longitude. It also receives the shared `RussianPhoneNormalizer`, maps the standard WooCommerce `billing_phone` into canonical `customer_context['recipient_phone']`, and forwards DaData exact-address coordinates only from server-confirmed checkout-session evidence created by the WDC DaData suggestion endpoint. Ozon courier checkout intentionally does not use generic destination coordinate aliases or hidden browser DaData geo fields as authority: `OzonDeliveryCourierLocationResolver` either uses trusted session DaData exact-address coordinates or treats the selected `LocationRepository` row only as a center for the 1 km active-Ozon-pickup proxy search. Ozon-specific fallback phone ownership stays inside `OzonDeliverySettings` and `OzonDeliveryQuoteRequestBuilder`.

Jet Logistic DI registers `JetLogisticApiClient` with `JetLogisticCredentials`, so both calculator and tracking calls send the same support-issued access token through the API payload. `JetLogisticApiDiagnosticService` is carrier-owned and is injected only into the embedded Jet admin tabs for read-only connection and tracking diagnostics.

Ozon Delivery wiring is carrier-owned in `Plugin.php`: settings, encrypted credentials, encrypted transient token cache, message sanitizer, WordPress HTTP transport, access-token service, API boundary, explicit OAuth diagnostic, quote request builder/parser/service, safe quote diagnostic, pickup provider, and live-gated runtime carrier. `DeliveryServicesAdminPage` supplies standard service tabs and routes only carrier-specific actions/rendering. Ozon API calls still go only through `OzonDeliveryApiClient` and the existing transport; generic checkout, pickup REST, and Shipment Framework do not branch on Ozon.

`OzonDeliveryQuoteService` owns the Ozon pickup provider query snapshot placed on the rate metadata. It computes the generic checkout destination fingerprint from trusted `QuoteRequest` context and query location, stores it in `pickup_provider_query.destination_fingerprint` for `CheckoutPickupPointProviderQueryResolver`, and uses the canonical 60 km Ozon destination pickup radius. The Ozon pickup provider reads only the active local snapshot, limits SQL by generation, active flag, and coordinate rectangle before exact radius/cargo filtering, returns the full eligible buyer-map set without arbitrary first-N truncation, and exposes selected-point repricing through generic `requires_rate_refresh` metadata. The resolver and REST controllers are not relaxed and do not recompute trusted context from browser data.

`src/Core/Plugin.php` is the composition root. `src/Core/Container.php` is a small lazy singleton container with `register()`, `get()`, and `has()`.

## Rules

- Register global services only in `Plugin::register_services()`.
- Concrete carriers are wired only in `Plugin.php`.
- Do not instantiate registered services inside production services as a fallback.
- Do not make required collaborators nullable just to hide missing wiring.
- Avoid duplicate registrations; the last registration replaces the previous factory.
- Do not move wiring into repositories, metabox renderers, or adapters.

## Carrier Registration Checklist

For a carrier with shipment support, register:

Jet Logistic registers one quote carrier (`JetLogisticCarrier`) and one shipment adapter (`JetLogisticShipmentAdapter`) in `Plugin.php`. Its API, credentials, geography import, status mapping, and manual shipment service are carrier-owned services. Do not add Jet to `ShipmentDocumentProviderRegistry`, `ShipmentModalExtensionRegistry`, lifecycle continuation wiring, or `ShipmentCreationService` mapper arrays.

PEK foundation registers only settings, credentials, HTTP transport, request budget, typed API client, connection diagnostic service, user-scoped sender warehouse search cache, user-scoped admin notice store, sender warehouse service, and the PEK admin tab in `Plugin.php`. The diagnostic service owns independent read-only checks for products, countries, legal forms, and `/branches/all/` API availability; saved warehouse ID matching is a separate informational result using the carrier-owned official-shape matcher in the sender warehouse service. It exposes compact check metadata instead of raw responses. Warehouse availability date parsing and source-specific timezone normalization for `/branches/all/` `timezone` and `/branches/nearestdepartments/` `timeZone` are carrier-owned inside the sender warehouse service; there is no generic date/time framework or Shipment Framework dependency for PEK foundation. The delivery-service admin page receives the PEK admin component only for routing/rendering and must not construct PEK services, parse PEK API payloads, store PEK notices, or handle PEK credentials itself.

PEK geography/pickup/runtime additionally registers the generic `CarrierPickupPointProviderRegistry`, `CheckoutPickupPointProviderQueryResolver`, shared `WooCommerceSessionBootstrapper`, `PekAddressBuilder`, `PekLocationMappingRepository`, `PekLocationResolver`, `PekSchemaIntegrityService`, `PekDestinationTerminalSearchCache`, `PekTerminalRepository`, `PekCargoConstraintsConverter`, `PekTerminalService`, `PekPickupPointProvider`, `PekCheckoutPickupPointFormatter`, `PekQuotePlannedDateTimeResolver`, `PekCheckoutQuoteContextResolver`, `PekCarrier`, `PekDestinationPickupDiagnosticStore`, and `PekDestinationPickupDiagnosticService` in `Plugin.php`. The registry is constructed with the PEK and Ozon providers and is used by admin diagnostics plus provider-backed checkout pickup REST through a trusted rate-context resolver that reads the production `rate_meta` payload from stored WooCommerce session rates after the current REST request has loaded the existing WooCommerce customer session. `PekQuotePlannedDateTimeResolver` memoizes only inside its request-scoped service instance so cache context and payload share one plannedDateTime without persisting time globally. `PekCarrier` is registered in `CarrierRegistry` only; no PEK Shipment Framework registry receives a PEK adapter/provider/extension. The PEK admin page receives diagnostics and `DeliveryQuoteCacheManager`; saving PEK price-affecting settings clears the shared delivery quote cache and does not create a PEK-specific cache manager.

- settings and credentials;
- API client and HTTP/SOAP client;
- quote runtime carrier in `CarrierRegistry`;
- shipment adapter in `CarrierShipmentAdapterRegistry`;
- persistence mapper in `ShipmentCreationService`, if API shipment creation exists;
- document provider in `ShipmentDocumentProviderRegistry`, if documents exist;
- modal extension in `ShipmentModalExtensionRegistry`, if UI fields are needed;
- lifecycle continuation implementation through the adapter, if the carrier requires continuation steps.

## Current Notes

Checkout runtime registration is owned by `Plugin.php` and depends only on `PlatformRuntimeSettings`. Runtime checkout components do not read a separate checkout rollout flag.

`DeliveryLeadTimeNormalizer` is wired in `Plugin.php` and is the checkout/order-admin runtime component that reads delivery lead-time settings. Calendar arithmetic stays in `DeliveryDateCalculator`; carriers, order metaboxes, and WooCommerce renderers consume normalized `DeliveryRate` values instead of constructing planned dates themselves.

`ShipmentCreationService` receives both a registry and an adapter array. The registry is the canonical path. The adapter array remains a temporary test-construction fallback for direct construction tests; it is documented technical debt, not a production compatibility contract and not a pattern for new code.
