# Plugin Architecture

Version: 0.130.1

The plugin is a WooCommerce delivery platform. Production ownership is split by layer:

| Layer | Path | Owns |
| --- | --- | --- |
| Bootstrap | `walls-delivery-calc.php`, `src/Core/bootstrap.php` | plugin constants, autoloading, initial boot |
| Composition root | `src/Core/Plugin.php` | service registration, WordPress hooks, carrier implementation registration |
| Container | `src/Core/Container.php` | lazy singleton factories |
| Domain | `src/Domain` | immutable value objects and status concepts |
| Application | `src/*/Application`, `src/*/Services` | use cases and orchestration |
| Infrastructure | `src/Infrastructure`, storage classes | database, settings, logging, queue, encryption |
| Admin | `src/*/Admin`, `assets/admin` | admin screens, AJAX controllers, shipment metabox UI |
| Checkout | `src/Checkout`, `assets/frontend` | WooCommerce rates, checkout selection, frontend pickup UI |
| Carriers | `src/Carriers`, `src/Shipments/{Cdek,Dpd,RussianPost,YandexDelivery}` | carrier APIs, quote adapters, shipment adapters, carrier persistence mapping |
| Tests | `tests` | smoke/regression contracts |

## Dependency Direction

Generic layers may depend on domain contracts and registries. Carrier implementations depend on generic contracts. Generic Shipment Framework services must not depend on carrier implementations except in the composition root (`Plugin.php`) where concrete implementations are registered.

Jet Logistic follows the same boundary: `Plugin.php` wires its runtime carrier, geography/status repositories, admin pages, and shipment adapter. Jet is not registered in document providers, modal extensions, lifecycle continuation, or shipment creation persistence mappers because the carrier supports quote, manual attach, status update, local remove, and autosync only.

PEK follows the carrier-owned boundary as a foundation-only carrier in version 0.130.1. `Plugin.php` wires `PekSettings`, `PekCredentials`, PEK HTTP/API transport, request budget, user-scoped sender warehouse search cache, diagnostics, warehouse lookup/validation, and the PEK delivery-service settings tab. `DeliveryServicesAdminPage` only performs minimal routing for `PekAdminPage::supports_action()` and redirects PEK POST actions back to `service=pek&tab=pek_settings`; PEK credential handling, API parsing, diagnostics, and warehouse rules stay carrier-owned. PEK is intentionally absent from `CarrierRegistry` and from every Shipment Framework registry until checkout quoting and shipment creation are implemented in later stages.

Allowed carrier references in generic code are limited to:

- composition root registration;
- generic admin request helpers that map existing carrier-specific UI inputs until those inputs have a registry-backed extension;
- tests that assert known carrier registrations.

Forbidden ownership:

- carrier business logic in `OrderShipmentsMetabox`;
- carrier persistence inside `ShipmentCreationService`;
- document action metadata inside shipment adapters;
- document download inside shipment adapters;
- carrier UI selectors in generic JS;
- new lifecycle AJAX endpoints outside the shared lifecycle contract.

## Runtime Flow

1. `walls-delivery-calc.php` defines `WDC_VERSION` and boots `src/Core/bootstrap.php`.
2. `Plugin` registers services in `Container`.
3. `Plugin::register_hooks()` connects WooCommerce, admin pages, AJAX, REST, cron, and document download hooks.
4. `Plugin::boot_modules()` runs migrations and startup tasks on `plugins_loaded`.
5. Checkout uses `CarrierRegistry`, rules, packaging, and runtime carriers to calculate rates.
6. Admin shipment creation uses `ShipmentCreationService`, carrier shipment adapters, persistence mappers, registries, and AJAX controllers.

## Storage

Storage is owned by repositories and mappers. Shipment carrier data must be persisted through `OrderShipmentRepository` plus the matching `CarrierShipmentPersistenceMapperInterface`. Settings are accessed through settings classes and `SettingsRepository`; credentials use `EncryptionService` where applicable.

Shipment cost analytics uses a dedicated read-model table, `{$wpdb->prefix}wdc_shipment_cost_analytics`, owned by `ShipmentCostAnalyticsRepository`. The canonical source remains WooCommerce order metadata and `_wdc_shipments`; the indexer rebuilds one order row after canonical mutations. The analytics admin page must query this table only and must not scan WooCommerce orders for filters, sorting, pagination, or totals.

## Admin And AJAX

Admin controllers live in `src/Shipments/Admin/Ajax`. They must perform capability checks, nonce checks, order resolution, request sanitization, and carrier validation before calling application services. Generic controllers may call registries and payload builders; they must not embed carrier creation or document download behavior.

## JS

Generic shipment JS lives in `assets/admin/shipments/*.js`. Carrier extensions live in `assets/admin/shipments/extensions/*.js`. Generic JS owns shared state, rendering, polling, document action buttons, and event dispatch. Carrier extensions own carrier-only UI details.

## Regression

The unified shipment runner is `tests/shipments/run-shipment-regression-profile.php`. Its manifest is `tests/shipments/regression/shipment-regression-manifest.php`. The default profile is mandatory except explicit `baseline` and `optional` entries documented in [operations/technical-debt.md](../operations/technical-debt.md).

`tests/architecture/run-plugin-architecture-smoke.php` protects bounded architecture invariants for adapters, document providers, registries, composition-root wiring, canonical payloads, generic shipment JS boundaries, canonical docs, and version consistency.

The smoke uses Reflection to discover production adapter and document-provider implementations. Adapter public API is allowed only from implemented interfaces, a real parent class, and a small documented exception list for existing guarded hooks; production `method_exists()` call sites do not expand the whitelist. Providers are not instantiated without constructors, so duplicate production provider keys are not inferred from uninitialized objects; duplicate registration behavior is checked at the registry contract level. The composition-root check guards the current `Container::register()` wiring pattern outside `Plugin.php`, not every possible future way to create objects. Generic shipment JS is checked for carrier-key branching, with a narrow pickup exception for the existing pickup context helpers in `assets/admin/shipments/shipment-picker.js`.
