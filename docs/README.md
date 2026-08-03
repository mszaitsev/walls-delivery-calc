# Walls Delivery Calc Documentation

Version: 0.131.2

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

PEK uses one stable carrier key and one delivery service key, both `pek`. Version 0.131.2 implements the PEK foundation and delivery-service settings only: the built-in service is disabled by default, initially available only for `RU`, and designed for later `AM`, `BY`, `KG`, and `KZ` validation. PEK admin actions route back to the PEK service tab after POST, and notices are user-scoped one-shot transients. `/typesOfDelivery/all/` uses GET with PEK JSON headers and no body; branch/legal/warehouse methods use POST. PEK connection diagnostics run product, country, legal-form, and selected-warehouse checks independently, so one unavailable reference endpoint does not stop the rest. The diagnostic result separates `connection_ok` from `all_checks_passed`, treats HTTP 200 from `/branches/all/` as read-only warehouse API availability, and reports saved warehouse ID matching as separate informational `warehouse_match` metadata. A semantic mismatch means the saved ID was not found by the local official-shape matcher, not that PEK rejected the ID, so it does not affect `connection_ok` or `all_checks_passed`. The admin UI shows method/endpoint/status and safe matcher counters, and machine datetime strings are preserved before phone redaction. Country diagnostics use official `shortName` and `codeByClassifier`, nested diagnostics render safely, and default sender warehouse validation is fail-closed with a short-lived user-scoped search cache that is cleared before every new search attempt. Warehouse fallback validation checks official LTL cargo acceptance without mbstring dependency and rejects closed/unavailable warehouses by machine-readable PEK closing dates. Closing dates are compared as real Unix instants using `current_datetime()->getTimestamp()`, never `current_time('timestamp')`; timezone-less PEK dates use canonical `branchTimezone`. PEK normalizes `/branches/all/` `timezone` values such as `UTC+03:00` and `/branches/nearestdepartments/` `timeZone` values such as `04:00:00` into one canonical `UTC+HH:MM` field, preserves that field and the safe source (`free`, `paid`, or `branches_all`) through search cache and sender warehouse snapshot, and can complete the normal search/select path without requiring `/branches/all/` fallback when the fresh user cache is sufficient. Impossible ISO-date regression fixtures stay future-dated so they prove strict parser rejection rather than past-expiry rejection. PEK has no checkout quote runtime, shipment adapter, status integration, documents, or cancellation flow yet, and the Shipment Framework remains unchanged.

Version 0.131.2 also adds the carrier-agnostic pickup-point provider contract and registry plus PEK geography/destination pickup foundation for closed admin diagnostics. PEK resolves canonical `wp_wdc_locations` into separate PEK location mappings using coordinates first and address fallback, stores PEK zone/branch/main-warehouse data outside canonical locations, and never backfills PEK coordinates into `wdc_locations`. Mapping coordinates are only canonical destination coordinates; PEK `warehousePoint` is branch-main-warehouse data and is not used for destination terminal lookup. Partial, invalid, or out-of-range canonical coordinates fall back to address resolution and are not sent to PEK. Coordinate zone responses require confirmed `zoneId` and `branchUID`; address zone responses are method-specific and accept only documented `precision` values (`exact`, `near`, `bad`), with empty or unknown precision failing closed. `GeoData.Address.country_code` is validated strictly when present: malformed non-empty values fail closed instead of being treated as absent, and mismatches create unsupported mappings. Destination terminal discovery calls `/branches/nearestdepartments/` with `departmentOperation=3` and `type=3`, accepts only documented `freeDepartments` and `paidDepartments` collections at the typed API boundary, sends canonical coordinates only when the mapping has a usable coordinate pair and address only otherwise, converts project cargo units into PEK units, normalizes `freeDepartments` as terminals and `paidDepartments` as PVZ, enforces positive terminal limits, and caches safe normalized results by location and cargo signature, including successful empty results. The terminal cache is format-versioned and validates cached PEK `PickupPoint` payloads before treating a transient as a hit. PEK requires canonical `location_id`, validates query/mapping country consistency, evaluates mapping freshness as Unix instants from WordPress-timezone `checked_at`, uses stale mappings only when the fingerprint still matches, and always fresh-validates selections. `PickupPoint::city` is not populated from PEK organizational `branchName`; branch, division, and work time stay in safe reference/report fields. The new registry is currently used only by PEK admin diagnostics; public pickup REST, checkout rates, checkout terminal selection, and Shipment Framework integration remain intentionally absent.
