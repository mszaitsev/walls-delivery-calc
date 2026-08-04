# Walls Delivery Calc Documentation

Version: 0.132.1

Start here when changing the plugin. The documentation is intentionally small: each topic has one canonical owner, and historical stage notes were removed because this plugin has not been deployed to production.

## Canonical Map

| Topic | Canonical document |
| --- | --- |
| Whole plugin architecture | [architecture/plugin-architecture.md](architecture/plugin-architecture.md) |
| Dependency injection and composition root | [architecture/dependency-injection.md](architecture/dependency-injection.md) |
| Shipment Framework | [architecture/shipment-framework.md](architecture/shipment-framework.md) |
| Add a carrier | [development/new-carrier-guide.md](development/new-carrier-guide.md) |
| Development workflow | [development/development-workflow.md](development/development-workflow.md) |
| New chat start | [development/chat-start.md](development/chat-start.md) |
| Codex prompt template | [development/codex-prompt-template.md](development/codex-prompt-template.md) |
| Regression runner and smoke policy | [development/testing-and-regression.md](development/testing-and-regression.md) |
| Coding and ownership rules | [development/coding-rules.md](development/coding-rules.md) |
| Checkout | [subsystems/checkout.md](subsystems/checkout.md) |
| Packaging | [subsystems/packaging.md](subsystems/packaging.md) |
| Locations, pickup points, FIAS/GAR | [subsystems/locations.md](subsystems/locations.md) |
| Rules | [subsystems/rules.md](subsystems/rules.md) |
| Carrier shipment behavior | [subsystems/shipments.md](subsystems/shipments.md) |
| Runtime, installation, hooks | [operations/installation-and-runtime.md](operations/installation-and-runtime.md) |
| Cron and background jobs | [operations/cron-and-background-jobs.md](operations/cron-and-background-jobs.md) |
| Troubleshooting | [operations/troubleshooting.md](operations/troubleshooting.md) |
| Current project status | [operations/project-status.md](operations/project-status.md) |
| Active technical debt | [operations/technical-debt.md](operations/technical-debt.md) |

## Supporting References

`docs/dpd/ws-integration-guide.docx` is a vendor reference for DPD SOAP behavior. `docs/reference/walls-delivery-calc-tech-spec.md` is a historical product tech spec reference. These files are useful evidence, but they are not project sources of truth. When a reference conflicts with code or canonical docs, verify against production code and update the canonical docs.

## Quick Start

New ChatGPT chat:

1. Read [development/chat-start.md](development/chat-start.md).
2. Read this README, then the relevant architecture/subsystem docs.
3. Prepare a Codex prompt from [development/codex-prompt-template.md](development/codex-prompt-template.md).

New Codex task:

1. Read the prompt, branch, version, allowed paths, forbidden paths, and checks.
2. Read the docs named in the prompt before editing.
3. Run inventory/audit first when the task is architectural or cross-cutting.
4. Do not commit, push, or open a PR unless explicitly asked.

New developer:

1. Start with [architecture/plugin-architecture.md](architecture/plugin-architecture.md).
2. For shipments, read [architecture/shipment-framework.md](architecture/shipment-framework.md).
3. For carriers, follow [development/new-carrier-guide.md](development/new-carrier-guide.md).
4. For validation, use [development/testing-and-regression.md](development/testing-and-regression.md).

## Documentation Rules

- Update docs in the same change as code when behavior, extension points, payload keys, service wiring, tests, or regression manifest entries change.
- Do not add stage notes, "current", "final", "v2", or migration-plan documents as active docs.
- Do not preserve compatibility aliases in docs unless an active consumer exists.
- Put historical context only in an archive when it is still needed to understand a live decision.
- Prefer one canonical document per subsystem over multiple partial documents.

## Current Carrier Scope

CDEK uses one carrier key and one delivery service key, both `cdek`. The existing CDEK service can be enabled per country for `RU`, `AM`, `BY`, `KZ`, and `KG` through the delivery-service country repository; there is no separate international CDEK carrier or service.

Jet Logistic uses one carrier key, `jet_logistic`, and one delivery service key, `jet_logistic`. Its runtime carrier returns two stable rates from one API calculation: `jet_logistic_pickup` and `jet_logistic_courier`. Jet pickup is a pickup delivery type but does not require a concrete pickup point, because the Jet API returns terminal cities rather than warehouse identifiers.

PEK uses one stable carrier key and one delivery service key, both `pek`. Version 0.132.1 implements the PEK foundation and delivery-service settings only: the built-in service is disabled by default, initially available only for `RU`, and designed for later `AM`, `BY`, `KG`, and `KZ` validation. PEK admin actions route back to the PEK service tab after POST, and notices are user-scoped one-shot transients. `/typesOfDelivery/all/` uses GET with PEK JSON headers and no body; branch/legal/warehouse methods use POST. PEK connection diagnostics run product, country, legal-form, and selected-warehouse checks independently, so one unavailable reference endpoint does not stop the rest. The diagnostic result separates `connection_ok` from `all_checks_passed`, treats HTTP 200 from `/branches/all/` as read-only warehouse API availability, and reports saved warehouse ID matching as separate informational `warehouse_match` metadata. A semantic mismatch means the saved ID was not found by the local official-shape matcher, not that PEK rejected the ID, so it does not affect `connection_ok` or `all_checks_passed`. The admin UI shows method/endpoint/status and safe matcher counters, and machine datetime strings are preserved before phone redaction. Country diagnostics use official `shortName` and `codeByClassifier`, nested diagnostics render safely, and default sender warehouse validation is fail-closed with a short-lived user-scoped search cache that is cleared before every new search attempt. Warehouse fallback validation checks official LTL cargo acceptance without mbstring dependency and rejects closed/unavailable warehouses by machine-readable PEK closing dates. Closing dates are compared as real Unix instants using `current_datetime()->getTimestamp()`, never `current_time('timestamp')`; timezone-less PEK dates use canonical `branchTimezone`. PEK normalizes `/branches/all/` `timezone` values such as `UTC+03:00` and `/branches/nearestdepartments/` `timeZone` values such as `04:00:00` into one canonical `UTC+HH:MM` field, preserves that field and the safe source (`free`, `paid`, or `branches_all`) through search cache and sender warehouse snapshot, and can complete the normal search/select path without requiring `/branches/all/` fallback when the fresh user cache is sufficient. Impossible ISO-date regression fixtures stay future-dated so they prove strict parser rejection rather than past-expiry rejection. PEK has no checkout quote runtime, shipment adapter, status integration, documents, or cancellation flow yet, and the Shipment Framework remains unchanged.

Version 0.132.1 also adds the carrier-agnostic pickup-point provider contract and registry plus PEK geography/destination pickup foundation for closed admin diagnostics. PEK resolves canonical `wp_wdc_locations` into separate PEK location mappings using coordinates first and address fallback, stores PEK zone/branch/main-warehouse data outside canonical locations, and never backfills PEK coordinates into `wdc_locations`. Mapping coordinates are only canonical destination coordinates; PEK `warehousePoint` is branch-main-warehouse data and is not used for destination terminal lookup. PEK mapping fingerprints include an independent mapping contract revision, not `WDC_VERSION`, so legacy 0.131.0-0.131.4 mappings are lazily invalidated without destructive migration when persisted semantics change. Persisted mappings must pass structural validation before fresh cache hit or stale fallback: address mappings never carry coordinates and `resolved`/`near` require `mainWarehouseId`, while coordinate mappings must match canonical destination coordinates. Partial, invalid, or out-of-range canonical coordinates fall back to address resolution and are not sent to PEK. Zone API contracts are strictly typed: `/branches/findzonebycoordinates/` must return an empty list or exactly one associative row for the single coordinate request; `/branches/findzonebyaddress/` must return an object, `GeoData`, `GeoData.Address`, and `GeoData.Address.formatted` are type-checked, address precision is read only from documented `GeoData.precision`, top-level address aliases are ignored, and address `exact`/`near` mappings require non-empty `mainWarehouseId`. Business unsupported results such as empty responses, `precision=bad`, or valid ISO2 country mismatch are separated from malformed contract responses, which throw safe `PekApiException` errors and do not overwrite working mappings. Destination terminal discovery calls `/branches/nearestdepartments/` with `departmentOperation=3` and `type=3`, always sends a non-empty destination address, serializes outgoing coordinates as PEK decimal strings when present, and includes address plus coordinates for coordinate search because PEK ignores `address` for geosearch when `coordinates` is present but still validates that the field is filled. Address-only terminal requests send address without `coordinates`. The terminal request fingerprint is derived from the actual endpoint, method, mapping scope, country, and full outgoing payload, so entries created for the former coordinate-without-address payload are not reused. PEK accepts only documented JSON-list `freeDepartments` and `paidDepartments`, converts project cargo units into PEK units, strictly validates terminal IDs/text/coordinates/limits/schedules, treats malformed limits and malformed schedule rows as invalid rows rather than unlimited/raw data, and caches only contract-valid successful results, including valid empty collections. All-invalid non-empty terminal collections fail closed and are not cached or persisted; mixed valid/invalid collections keep exact counters and safe rejection reason buckets. The terminal cache uses format `2` for the safe point projection, invalidates older format `1` transients, projects `PickupPoint` values through a safe raw-reference allowlist, and validates cached PEK payloads before treating a transient as a hit. PEK requires canonical `location_id`, validates query/mapping country consistency, evaluates mapping freshness as Unix instants from WordPress-timezone `checked_at`, uses stale mappings only when the fingerprint still matches and the persisted structure is current-contract usable, always fresh-validates selections, and resets `PekTerminalService::last_report()` for each search, including resolver failures, so failures cannot leak old success data. Destination admin diagnostics clear the previous one-shot report before every explicit run, show location mapping and terminal search as separate failure stages, render endpoint/status/error code/response shape/rejection reasons without raw response values, separately render a redacted `api_error_message` for PEK logical/API failures, and render redacted field-level `field_errors` from `error.fields` without rejected values while keeping the general diagnostic message stable. Explicit admin diagnostic failures are logged through the project logger with safe context only, and stored reports are sanitized recursively without raw PEK error objects, request bodies, headers, rejected field values, or credentials. `PickupPoint::city` is not populated from PEK organizational `branchName`; branch, division, and work time stay in safe reference/report fields. The new registry is currently used only by PEK admin diagnostics; public pickup REST, checkout rates, checkout terminal selection, and Shipment Framework integration remain intentionally absent.

Version 0.132.1 adds the PEK quote/calculator foundation as an admin-only diagnostic subsystem. `PekApiClient::calculate_price()` is a typed POST boundary for `/calculator/calculateprice/`, and PEK-owned quote classes build domestic RU LTL `types=[3]` requests for sender self-delivery to receiver warehouse pickup or sender self-delivery to courier delivery. The request builder uses the configured sender warehouse snapshot only, always sends `currencyCode=643`, mandatory insurance from `Package::declared_value`, explicit counterpart roles `[1,3]`, optional client card, and one aggregate cargo place with PEK upward rounding to hundredths. The response parser owns calculator root logical errors, so calculator `hasError=true` becomes `pek_quote_root_error` instead of generic `pek_has_error`; it also requires `transfers[].hasError` to be a documented Boolean, preserves endpoint/method/HTTP status on success and parser failures, normalizes `costTotal` to kopecks, reads `estDeliveryTime`, and keeps only a safe service breakdown where `insuranceTerm` remains Boolean. The quote diagnostic is explicit-action only, user-scoped, clears stale reports before rerun, stores safe request/result metadata without raw request/response, and does not create `DeliveryRate`, register `PekCarrier`, touch checkout/session/public REST, or modify Shipment Framework.

PEK schema integrity is checked in the controlled migration lifecycle, not during diagnostics or repository lookups. Migration history is not treated as proof that physical tables still exist: `0050_repair_pek_foundation_schema.php` delegates to the carrier-owned `PekSchemaIntegrityService`, checks both PEK foundation tables with the active WordPress prefix, creates only missing tables through the existing repository installers, verifies postconditions, and fails the migration if either table is still absent. The location mapping physical column is `mapping_precision` because `precision` is reserved in MySQL 8; the domain mapping array still exposes `precision`, and the repository translates between domain and database rows. `0051_migrate_pek_mapping_precision_column.php` supports legacy tables by adding `mapping_precision` and backfilling from quoted legacy `` `precision` `` without dropping the old column. Installers check unavailable `dbDelta()` and `$wpdb->last_error`, and migration failures are logged/shown as safe admin notices instead of uncaught site-wide fatals. The recovery never drops, truncates, imports data, edits canonical locations, or calls PEK APIs.
