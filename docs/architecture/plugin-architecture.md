# Plugin Architecture

Version: 0.132.4

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

PEK follows the carrier-owned boundary as a foundation-only carrier in version 0.132.4. `Plugin.php` wires `PekSettings`, `PekCredentials`, PEK HTTP/API transport, request budget, user-scoped sender warehouse search cache, user-scoped admin notice store, diagnostics, warehouse lookup/validation, and the PEK delivery-service settings tab. `DeliveryServicesAdminPage` only performs minimal routing for `PekAdminPage::supports_action()` and redirects PEK POST actions back to `service=pek&tab=pek_settings`; PEK credential handling, API parsing, diagnostics, notice storage, and warehouse rules stay carrier-owned. PEK diagnostics remain carrier-owned: each reference/warehouse endpoint is checked independently, `connection_ok` records whether any authenticated endpoint confirms API access, `all_checks_passed` records API-check completeness, and `/branches/all/` API availability is separated from informational saved-warehouse semantic matching. The matcher follows the official `branches[].divisions[].warehouses[].id` path, stores only safe counters and match metadata, and never stores raw warehouse rows. PEK warehouse availability parsing remains inside the carrier foundation: it compares real Unix instants, normalizes `/branches/all/` `timezone` and `/branches/nearestdepartments/` `timeZone` into canonical `branchTimezone` (`UTC+HH:MM`), rejects impossible ISO dates fail-closed, and preserves timezone/availability/source fields in the user-scoped search cache and compact sender warehouse snapshot. Destination terminal diagnostics keep a stable general failure message and expose PEK logical/API detail only as a separately redacted `api_error_message`; field-level validation details are stored as normalized `field_errors` field/message lists with limits and without rejected values. The 0.132.4 destination terminal request follows the PEK production validator by always sending a non-empty address, adding coordinates as an additional field when available, and fingerprinting the full outgoing payload. Raw error objects, request bodies, headers, and credentials stay outside reports and logs. PEK is intentionally absent from `CarrierRegistry` and from every Shipment Framework registry until checkout quoting and shipment creation are implemented in later stages.

The generic pickup provider extension point lives under `src/Pickup/Providers` and is intentionally minimal: immutable query objects, cargo constraints in canonical project units, provider interface, and duplicate-protected registry. `Plugin.php` registers the registry with only `PekPickupPointProvider` in version 0.132.4. Existing CDEK, DPD, Russian Post, Yandex Delivery, and Jet Logistic runtime paths are not migrated. Public pickup REST controllers do not receive the registry yet; PEK uses it only from a closed admin diagnostic flow because PEK terminal search is credentialed, cargo-sensitive, and rate-limited.

The PEK quote foundation is also carrier-owned. `src/Carriers/Pek/Quote` contains the PEK-only options, cargo builder, calculator payload builder, response parser, result object, message sanitizer, and quote service; `src/Carriers/Pek/Admin` owns the quote diagnostic service/store. `Plugin.php` is the only registration point for these classes. The subsystem calls only `POST /calculator/calculateprice/`, supports RU domestic pickup/courier diagnostics, validates configured sender warehouse/counterpart/declared value/cargo/planned datetime before transport, and returns `PekQuoteResult` rather than `DeliveryRate`. The cargo builder owns the PEK light-cargo sealing rule: product weight `Package::weight_g < 3000` g keeps `isHP=false` and requests `sealingPositionsCount=1`, while calculator weight still comes from `Package::total_weight_g`; PEK service prices are never hardcoded and `costTotal` stays authoritative. `isHP` is treated only as documented protective transport packaging, not as a bag selector, and no undocumented `bag`/`packagingType` payload aliases are introduced. Calculator root `hasError` is deliberately deferred from generic API handling to the typed parser, which reports `pek_quote_root_error`, requires transfer-level `hasError` as a Boolean, preserves safe endpoint/method/status metadata, requires service text fields as strings, and keeps service `insuranceTerm` as Boolean in diagnostics. Sensitive redaction belongs to `PekQuoteMessageSanitizer` at the service boundary, not to the parser: it removes actual credentials and counterpart identifiers from API messages, field messages, and arbitrary field names while preserving safe canonical paths such as `counterpart.inn`; logger context stores no API message, field-message text, or raw/original field names. It deliberately leaves `CarrierRegistry`, checkout orchestration, public pickup REST, rules, quote cache, Shipment Framework registries, and shipment creation untouched until the future PEK checkout runtime stage.

PEK geography mappings carry an internal mapping contract revision inside their fingerprint, separate from the plugin version, and persisted rows are structurally validated before fresh hits or stale fallback. This lazily invalidates legacy mappings that stored address `warehousePoint` coordinates or lacked `mainWarehouseId`, while avoiding destructive migrations and mass PEK API calls. PEK destination terminal cache uses format `2`; format `1` transients are deleted as misses because they predate the safe `PickupPoint` projection.

PEK geography and destination pickup remain carrier-owned under `src/Carriers/Pek`. `PekLocationResolver` reads canonical locations, computes an address fingerprint, resolves PEK zones/branches with coordinates first and address fallback, and persists compact mappings in `wdc_pek_location_mappings` without mutating `wp_wdc_locations`. Mapping coordinates are canonical destination coordinates only; `warehousePoint` is not a destination coordinate source. Partial or invalid canonical coordinates are ignored for PEK coordinates calls and use address fallback. Zone response normalization is method-specific and typed: coordinate responses must be a list with zero or one object for the single coordinate request, critical zone IDs/text fields must be strings, address responses must be objects, `GeoData` and `GeoData.Address` are strict objects when present, `GeoData.Address.formatted` must be a string when present, precision is read only from documented `GeoData.precision`, and top-level address aliases are ignored. Address `exact`/`near` mappings require `zoneId`, `branchUID`, and `mainWarehouseId`; coordinate mappings do not require `mainWarehouseId`. Business unsupported results (`precision=bad`, empty valid response, valid ISO2 mismatch) may persist as unsupported mappings; malformed roots, critical field types, malformed non-empty countries, missing/unknown/non-string address precision, malformed `GeoData`/`Address`/`formatted`, and non-empty incomplete contract rows throw safe `PekApiException` errors and do not overwrite working mappings. Stale mapping fallback is allowed only for the same fingerprint, and freshness compares Unix instants from WordPress-timezone `checked_at` values. `PekApiClient` validates destination `/branches/nearestdepartments/` responses at the typed boundary and requires both documented terminal collections to be JSON lists. `PekTerminalService` calls that endpoint with `departmentOperation=3` and `type=3`, always sends the non-empty mapping destination address, adds canonical coordinates as decimal strings when the mapping has a usable coordinate pair, sends address only otherwise, checks query country against mapping country, strictly normalizes `freeDepartments`/`paidDepartments` rows and schedule/holiday data, leaves `PickupPoint::city` empty instead of using organizational `branchName`, treats malformed terminal IDs/text/coordinates/limits/schedules as invalid rows, enforces positive terminal limits against total cargo values, rejects all-invalid non-empty collections as API contract failures, persists safe terminal snapshots in `wdc_pek_terminals`, caches only contract-valid successful results through a format-versioned safe `PickupPoint` projection, and treats repository/cache data as optimization rather than selection authority. `resolve_selection()` performs fresh server validation with no opt-out flag and still does not touch checkout session, orders, shipments, or public REST. PEK destination diagnostic reports are cleared before a new explicit run and recursively sanitized. PEK 0048/0049 schemas are installed only by migrations; runtime repositories do not call `dbDelta`.

PEK destination terminal diagnostics expose operation-level evidence without expanding runtime scope. `PekApiException` context and `PekTerminalService::last_report()` carry stable failure stages, endpoint, method, HTTP status, safe response shape, query fingerprint, preserved mapping context, and rejection reason counters. The admin report renders those fields as named sections instead of positional arrays, and failed explicit diagnostic runs write one project logger event with allowlisted context only. Raw responses, headers, request bodies, credentials, terminal rows, and terminal addresses are not stored or logged by the diagnostic path.

Migration history is separate from physical schema integrity. PEK adds `PekSchemaIntegrityService` and migration `0050_repair_pek_foundation_schema.php` for controlled, idempotent recovery of the two carrier-owned foundation tables. The service checks `wdc_pek_location_mappings` and `wdc_pek_terminals` with the current WordPress table prefix, invokes the existing repository `install_schema()` method only for missing tables, verifies that both tables exist after repair, and throws before migration history can advance if a postcondition fails. The mapping table uses physical `mapping_precision`, while the domain mapping contract remains `precision`; repository SQL payloads translate between those names so MySQL reserved identifiers do not appear in the schema. Migration `0051_migrate_pek_mapping_precision_column.php` handles legacy physical `` `precision` `` columns by adding/backfilling `mapping_precision` non-destructively. Installers fail closed on unavailable `dbDelta()` and `$wpdb->last_error`; `Plugin` catches migration failures, logs a safe message, and shows an admin notice instead of allowing an uncaught bootstrap fatal. Recovery does not run on checkout, public REST, ordinary diagnostics, or repository read/write paths, and it never performs destructive SQL or PEK API calls.

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
