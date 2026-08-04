# Walls Delivery Calc Documentation

Version: 0.133.0

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

PEK uses one stable carrier key and one delivery service key, both `pek`. Version 0.133.0 keeps the built-in service disabled by default and initially available only for `RU`, while adding buyer checkout rates through `PekCarrier`. PEK admin actions route back to the PEK service tab after POST, and notices are user-scoped one-shot transients. `/typesOfDelivery/all/` uses GET with PEK JSON headers and no body; branch/legal/warehouse methods use POST. PEK connection diagnostics run product, country, legal-form, and selected-warehouse checks independently, so one unavailable reference endpoint does not stop the rest. The checkout runtime reuses the same safe sender warehouse snapshot and PEK calculator foundation, but PEK still has no shipment adapter, status integration, documents, or cancellation flow, and the Shipment Framework remains unchanged.

Version 0.133.0 connects the carrier-agnostic pickup-point provider contract and PEK geography/destination pickup foundation to checkout runtime. PEK still resolves canonical `wp_wdc_locations` into carrier-owned mappings, keeps PEK zone/branch/main-warehouse data outside canonical locations, and never uses PEK `warehousePoint` as destination coordinates. Destination terminal discovery keeps the documented `/branches/nearestdepartments/` contract with non-empty address plus optional decimal-string coordinates. In checkout, public pickup REST uses the PEK provider only through server-owned `pickup_provider_query` metadata saved on the selected rate; browser cargo/location/coordinates/radius/limit are not trusted. Saving a PEK terminal accepts only a point code, fresh-validates it through `resolve_selection()`, stores a safe `pek:pickup` family selection, and triggers a rate refresh. Shipment Framework integration remains intentionally absent.

Version 0.133.0 uses the PEK quote/calculator foundation in buyer checkout. `PekApiClient::calculate_price()` remains the typed POST boundary for `/calculator/calculateprice/`; `PekCarrier` reuses `PekQuoteService` to produce pickup and courier `DeliveryRate` objects without rebuilding payloads. The calculator payload always sends `isHP=false` and `sealingPositionsCount=0`; store-owned configurable bag/plombing surcharges are kept separate from PEK services and added to final pre-rules `price_kopecks`. The response parser still treats PEK `costTotal` as authoritative carrier cost, keeps safe service breakdown only, preserves endpoint/status metadata, and redacts carrier messages/field errors through the quote service boundary. Checkout quote cache distinguishes selected terminal, courier address scope, full-address fingerprint, planned datetime bucket, sender warehouse, surcharge settings, product weight, transport fields, and generic destination/pickup-selection fingerprints without raw credentials or contract identifiers.

PEK schema integrity is checked in the controlled migration lifecycle, not during diagnostics or repository lookups. Migration history is not treated as proof that physical tables still exist: `0050_repair_pek_foundation_schema.php` delegates to the carrier-owned `PekSchemaIntegrityService`, checks both PEK foundation tables with the active WordPress prefix, creates only missing tables through the existing repository installers, verifies postconditions, and fails the migration if either table is still absent. The location mapping physical column is `mapping_precision` because `precision` is reserved in MySQL 8; the domain mapping array still exposes `precision`, and the repository translates between domain and database rows. `0051_migrate_pek_mapping_precision_column.php` supports legacy tables by adding `mapping_precision` and backfilling from quoted legacy `` `precision` `` without dropping the old column. Installers check unavailable `dbDelta()` and `$wpdb->last_error`, and migration failures are logged/shown as safe admin notices instead of uncaught site-wide fatals. The recovery never drops, truncates, imports data, edits canonical locations, or calls PEK APIs.
