# Dependency Injection

Version: 0.133.4

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

PEK geography/pickup/runtime additionally registers the generic `CarrierPickupPointProviderRegistry`, `CheckoutPickupPointProviderQueryResolver`, shared `WooCommerceSessionBootstrapper`, `PekAddressBuilder`, `PekLocationMappingRepository`, `PekLocationResolver`, `PekSchemaIntegrityService`, `PekDestinationTerminalSearchCache`, `PekTerminalRepository`, `PekCargoConstraintsConverter`, `PekTerminalService`, `PekPickupPointProvider`, `PekCheckoutPickupPointFormatter`, `PekQuotePlannedDateTimeResolver`, `PekCheckoutQuoteContextResolver`, `PekCarrier`, `PekDestinationPickupDiagnosticStore`, and `PekDestinationPickupDiagnosticService` in `Plugin.php`. The registry is constructed with the PEK provider and is used by admin diagnostics plus provider-backed checkout pickup REST through a trusted rate-context resolver that reads the production `rate_meta` payload from stored WooCommerce session rates after the current REST request has loaded the existing WooCommerce customer session. `PekQuotePlannedDateTimeResolver` memoizes only inside its request-scoped service instance so cache context and payload share one plannedDateTime without persisting time globally. `PekCarrier` is registered in `CarrierRegistry` only; no PEK Shipment Framework registry receives a PEK adapter/provider/extension. The PEK admin page receives diagnostics and `DeliveryQuoteCacheManager`; saving PEK price-affecting settings clears the shared delivery quote cache and does not create a PEK-specific cache manager.

Version 0.133.4 registers `PekQuoteCargoBuilder`, `PekQuoteRequestBuilder`, `PekQuoteResponseParser`, `PekQuoteMessageSanitizer`, `PekLightCargoSurchargePolicy`, `PekQuotePlannedDateTimeResolver`, `PekQuoteService`, `PekQuoteDiagnosticStore`, and `PekQuoteDiagnosticService` in `Plugin.php`. `PekQuoteMessageSanitizer` receives `PekCredentials` and `PekSettings` so actual PEK login/API key/client card/INN/KPP values can be removed from calculator API messages, field-error messages, and arbitrary field names before diagnostic persistence or UI output. `PekLightCargoSurchargePolicy` receives `PekSettings` and applies store-owned configurable bag/plombing surcharges after the carrier result is parsed; `PekQuoteCargoBuilder` still owns only the outgoing PEK cargo payload and always sends `isHP=false` and `sealingPositionsCount=0`. `PekQuoteService` is injected into `PekCarrier`, which converts final adjusted quote results into checkout rates without service locator fallbacks.

- settings and credentials;
- API client and HTTP/SOAP client;
- quote runtime carrier in `CarrierRegistry`;
- shipment adapter in `CarrierShipmentAdapterRegistry`;
- persistence mapper in `ShipmentCreationService`, if API shipment creation exists;
- document provider in `ShipmentDocumentProviderRegistry`, if documents exist;
- modal extension in `ShipmentModalExtensionRegistry`, if UI fields are needed;
- lifecycle continuation implementation through the adapter, if the carrier requires continuation steps.

## Current Notes

`CheckoutFeatureGate` is wired in `Plugin.php` with `SettingsRepository` only. Runtime checkout components depend on the gate rather than reading `enable_new_checkout_shipping` directly.

`DeliveryLeadTimeNormalizer` is wired in `Plugin.php` and is the checkout/order-admin runtime component that reads delivery lead-time settings. Calendar arithmetic stays in `DeliveryDateCalculator`; carriers, order metaboxes, and WooCommerce renderers consume normalized `DeliveryRate` values instead of constructing planned dates themselves.

`ShipmentCreationService` receives both a registry and an adapter array. The registry is the canonical path. The adapter array remains a temporary test-construction fallback for direct construction tests; it is documented technical debt, not a production compatibility contract and not a pattern for new code.
