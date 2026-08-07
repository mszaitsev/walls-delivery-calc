# Walls Delivery Calc Documentation

Version: 0.134.12

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

PEK uses one stable carrier key and one delivery service key, both `pek`. Version 0.134.12 keeps the first PEK Shipment Framework implementation under read-only contract verification; production submit is not yet approved pending live validation for RU LTL `type=3`, `orderType=0`: sender self-delivery to a PEK warehouse, shipment-modal sender warehouse override, pickup and courier recipient modes, physical recipient with SMS release, mandatory insurance, sender-paid services, persistence mapper, manual attach by cargo code, status/autosync, actual cost from `/cargos/status/` `services.sum`, application/label PDFs through the document provider, pre-acceptance cancellation through `/order/cancellation/`, and local removal after acceptance. Legal recipients, international shipment creation, accepted-cargo return, accounting documents, CargoInvoice/CargoAssignment flows, and `/cargos/cancelandreturncargo/` remain out of scope. Courier `addressStock` is built only from persisted order destination data: shipping DaData/Woo fields remain authoritative when shipping is filled, billing DaData can only fill an empty or semantically identical destination, stale DaData is rejected against current Woo fields, country must be current RU, typed apartment/office/premise/corpus/structure units, including full DaData type values, are preserved through checkout order meta and raw Woo address fallback, selected-location/city/settlement FIAS identifiers are carried for every courier address source, including Woo fallback, and fail closed on explicit mismatch without confusing house/full-address FIAS with locality FIAS, and preview exposes only component flags plus a SHA-256 hash. Counterpart confirmation is bound to sender identity and PEK account login hash, settings save validates before writing, PEK sender and recipient phones share one fail-closed RU normalizer instead of cleaning malformed text into a valid number, SMS availability uses exact declared-value cache keys plus strict official list/scalar boundaries, and status updates use a required typed normalizer with strict DateTime, exact cargo cardinality, presence-aware optional fields, and fail-closed actual-cost parsing. Pending uncertain PEK submissions now surface manual reconciliation in the shared UI: managers can attach the cargo code or remove the local pending record after checking the PEK cabinet, while duplicate automatic create stays blocked.

Version 0.133.9 connected the carrier-agnostic pickup-point provider contract and PEK geography/destination pickup foundation to checkout runtime while Shipment Framework integration was still absent. In the current 0.134.12 line that PEK Shipment Framework integration exists, but remains under read-only contract verification and production submit is not yet approved. After code review, read-only safe preview may be run on the test site; it may perform documented read-only PEK checks but must not call `/preregistration/submit/`. PEK still resolves canonical `wp_wdc_locations` into carrier-owned mappings, keeps PEK zone/branch/main-warehouse data outside canonical locations, and never uses PEK `warehousePoint` as destination coordinates. Destination terminal discovery keeps the documented `/branches/nearestdepartments/` contract with non-empty address plus optional decimal-string coordinates. In checkout, public pickup REST first bootstraps the existing WooCommerce customer session for the current request, then uses the PEK provider only through server-owned `pickup_provider_query` metadata saved inside the production `rate_meta` envelope of the selected rate; browser cargo/location/coordinates/radius/limit/address are not trusted. Session bootstrap failure is reported as `provider_session_unavailable` (503), while a successfully loaded session without a valid stored rate still reports `provider_rate_context_missing` (400). The top-level session rate envelope owns carrier, service, delivery type, pickup family, and requirement flags, while nested `rate_meta` owns carrier-specific provider context. Trusted snapshots allow either a bounded numeric coordinate pair or address-only `null/null` coordinates; partial, empty-string, non-numeric, and out-of-range coordinates fail closed. Full destination address is not stored in the session snapshot: `location_id` remains the authority and the PEK provider reloads the canonical mapping/address server-side. Saving a PEK terminal accepts only a point code, fresh-validates it through `resolve_selection()`, preserves the PEK provider SHA-256 as `provider_destination_fingerprint`, keeps the generic checkout location fingerprint separate, and triggers a rate refresh. The selected warehouse ID becomes calculator `receiverWarehouseId`; a provider-valid point can still fail calculator pricing, so selected-terminal quote failure is recoverable: PEK marks the current recovered rate with generic rejected-selection metadata, checkout clears only `pek:pickup`, restores an explicit preliminary PEK rate, keeps the PEK method selected when recovery succeeds, and renders an inline transient message inside that shipping method. The server event is removed before session/order/calculation persistence; the checkout page keeps the message only in module memory across internal stabilization recalculations. DOM hidden fields, local `selectedPickupPoints`, and POST save 200 are not treated as calculator success; only post-calculation `/checkout/state` with a valid family selection clears the message. Authoritative empty state removes stale local selections but leaves the recovery message visible. Destination change, method change, and browser reload still clear it. Free PEK points render as `Собственный пункт выдачи ПЭК`; paid points render as `Партнерский пункт выдачи ПЭК` with a possible surcharge warning. Internal warehouse UUIDs remain technical `point_code` metadata and are not used as customer-facing titles.

Version 0.133.9 uses the PEK quote/calculator foundation in buyer checkout. `PekApiClient::calculate_price()` remains the typed POST boundary for `/calculator/calculateprice/`; `PekCarrier` reuses `PekQuoteService` to produce pickup and courier `DeliveryRate` objects without rebuilding payloads. The calculator payload always sends `isHP=false` and `sealingPositionsCount=0`; store-owned configurable bag/plombing surcharges are kept separate from PEK services and added to final pre-rules `price_kopecks`. For saved checkout/order calculation data, PEK `api_base_price_rub` is the adjusted base before rules (`costTotal` plus store surcharges), while `pek_carrier_base_price_rub` and `pek_carrier_price_kopecks` preserve the pure PEK `costTotal`. The response parser still treats PEK `costTotal` as authoritative carrier cost, keeps safe service breakdown only, preserves endpoint/status metadata, and redacts carrier messages/field errors through the quote service boundary. Rule formula visualization shows `Добавлен мешок и пломбировка`, `Добавлен мешок`, or `Добавлена пломбировка` when the corresponding configured store surcharge is non-zero; these lines are not Rule Engine entries and do not affect `price_delta_rub`. Checkout quote cache distinguishes preliminary and selected terminals by point code plus provider destination fingerprint, and also includes courier address scope, full-address fingerprint, planned datetime bucket, sender warehouse, surcharge settings, product weight, transport fields, and generic destination/pickup-selection fingerprints without raw credentials or contract identifiers. PEK plannedDateTime is memoized per resolver instance so one request lifecycle uses the same value for quote cache context and calculator payload.

PEK schema integrity is checked in the controlled migration lifecycle, not during diagnostics or repository lookups. Migration history is not treated as proof that physical tables still exist: `0050_repair_pek_foundation_schema.php` delegates to the carrier-owned `PekSchemaIntegrityService`, checks both PEK foundation tables with the active WordPress prefix, creates only missing tables through the existing repository installers, verifies postconditions, and fails the migration if either table is still absent. The location mapping physical column is `mapping_precision` because `precision` is reserved in MySQL 8; the domain mapping array still exposes `precision`, and the repository translates between domain and database rows. `0051_migrate_pek_mapping_precision_column.php` supports legacy tables by adding `mapping_precision` and backfilling from quoted legacy `` `precision` `` without dropping the old column. Installers check unavailable `dbDelta()` and `$wpdb->last_error`, and migration failures are logged/shown as safe admin notices instead of uncaught site-wide fatals. The recovery never drops, truncates, imports data, edits canonical locations, or calls PEK APIs.
