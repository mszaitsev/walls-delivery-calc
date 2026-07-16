# Shipment Framework technical debt

## 0.111.1 focused debt register

1. Shipment actual cost comparison is duplicated across carriers
   - Status: resolved in 0.114.0. `ShipmentActualCostComparisonService` now owns formatting/status/message and the exact integer +3% comparison for CDEK, DPD, Russian Post and Yandex; `ShipmentBaseApiCostResolver` owns the repeated Base API cost read from order calculation meta.
   - Preserved contract: carrier-specific actual-cost sources and persisted shipment keys stay unchanged; metabox still reads only `actual_cost_label`, `actual_cost_compare_status` and `actual_cost_compare_message`. Since 0.114.1 carrier boundaries again treat actual cost zero as unknown and the base resolver returns only positive base values.
   - Regression coverage: `tests/shipments/run-shipment-actual-cost-presentation-smoke.php` covers hidden actual cost, carrier zero actual values, neutral missing/zero base, exact +3%, +3% plus one kopeck, non-round bases, formatting, strict input and source assertions.

2. Common document/action layer is missing
   - Status: resolved in 0.115.0. `ShipmentDocumentAction`, `ShipmentBinaryDocument`, `CarrierShipmentDocumentProviderInterface`, `ShipmentDocumentProviderRegistry` and `ShipmentDocumentDownloadService` now own the normalized document action and protected download shell. CDEK, DPD, Yandex and Russian Post have carrier-specific providers; carrier APIs, payloads, filenames and PDF/ZIP validation remain carrier-owned.
   - Preserved contract: CDEK BARCODE preparation, DPD document ZIP composition and Yandex `generate-labels` semantics are unchanged. The metabox no longer owns per-carrier admin-post download handlers.
   - Follow-up debt: `CarrierShipmentAdapterInterface::label_actions()` remains as the older technical facade name for the document/action list. Rename it to `document_actions()` in a separate interface cleanup stage.

## 0.108.5 focused debt register

1. Carrier-specific modal fields remain partly hardcoded
   - Status: resolved in 0.116.0 for PHP modal field markup. `CarrierShipmentModalExtensionInterface` and `ShipmentModalExtensionRegistry` now own the carrier modal extension boundary, and CDEK/DPD/Russian Post/Yandex delivery, pickup and courier field fragments are delegated to carrier extensions.
   - Remaining adjacent debt: carrier-specific AJAX/pickup/source-dropoff backend responsibilities remain inside `OrderShipmentsMetabox` to avoid changing `shipments-admin.js` behavior in the PHP rendering refactor.
   - Target architecture: the shared metabox renders common fields plus carrier modal extensions for all carrier-owned field fragments.
   - Affected classes/files: `OrderShipmentsMetabox`, carrier adapters/button policies, `shipments-admin.js`, carrier modal smokes.
   - Migration sequence: stabilize current render/runtime tests, extract read-only helper methods, then introduce a carrier field provider without changing the AJAX lifecycle.

2. Yandex source station picker
   - Current problem: Yandex registration modal shows `yandex_source_platform_station_id` as a read-only value from settings.
   - Target architecture: a Yandex carrier extension lets the manager select the source platform station from an approved source-station list.
   - Affected classes/files: `OrderShipmentsMetabox`, Yandex settings/repository layer, Yandex shipment registration docs and smokes.
   - Migration sequence: add source station data provider, render it through the carrier modal extension point, then persist only the selected station id in the shipment draft.

3. Yandex destination pickup picker
   - Current problem: pickup destination is read-only and comes from saved order/draft meta (`pickup_point_code`, `yandex_pickup_platform_station_id`).
   - Target architecture: the shipment modal reuses the existing pickup map/provider infrastructure to choose a Yandex destination platform station for shipment registration.
   - Affected classes/files: `OrderShipmentsMetabox`, `assets/admin/shipments-admin.js`, Yandex pickup repositories/formatters, existing pickup map providers.
   - Migration sequence: expose a Yandex destination-picker mode, keep checkout pickup map code as the source of point presentation, then submit only the selected shipment platform_station_id.

4. Destination pickup selection must not reprice checkout
   - Current problem: future Yandex destination selection is a shipment-registration concern, not an order-delivery recalculation.
   - Target architecture: changing the destination pickup point in shipment modal updates only shipment draft fields and never triggers checkout repricing or representative PVZ logic.
   - Affected classes/files: shipment modal JS, Yandex pickup map/provider integration, order-delivery recalculation controller boundaries.
   - Migration sequence: keep shipment picker AJAX/action separate from checkout repricing actions, add smoke that platform_station_id changes without touching order delivery price meta.

5. CDEK assessed price ambiguity
   - Status: resolved in 0.112.0. The modal mapper normalizes unit and assessed values into separate canonical kopeck fields, `ShipmentAllocationBuilder` carries both values, CDEK maps API item `cost` from assessed value, and Yandex keeps both fields under `billing_details`.

## Earlier shipment framework debt

1. CDEK modal fallback `cdek_items[]`
   - Status: resolved in 0.112.0. The shared modal submit contract accepts only `shipment_items[]`; production source assertions cover the removed fallback.

2. CDEK meta `cdek_item_rows`
   - Status: resolved in 0.112.0. `OrderShipmentDraftFactory` writes only `shipment_item_rows`, and `CdekCreateRequestBuilder` reads only that canonical key.

3. Non-Yandex carrier persistence in `ShipmentCreationService`
   - Status: resolved in 0.113.1. CDEK, DPD and Russian Post now use `CarrierShipmentPersistenceMapperInterface` implementations, `ShipmentCreationService` persists only the common envelope plus mapper fields, and create is blocked before preview/API/repository side effects when a registered adapter has no mapper. The common service no longer accepts the obsolete Russian Post actual-cost lookup dependency.

4. Carrier-specific modal fields in `OrderShipmentsMetabox`
   - Status: resolved in 0.116.0 for PHP field markup. Delivery, pickup and courier fragments are now carrier-owned by modal extensions; no generic form builder was introduced.
   - Current problem: the shared metabox still contains carrier-specific AJAX/pickup/source-dropoff backend methods that should move in a later JS/backend extension step.
   - Target architecture: the metabox renders common fields plus carrier-provided field fragments.
   - Affected classes/files: `OrderShipmentsMetabox`, carrier adapters/button policies, admin smoke tests.
   - Migration sequence: extract read-only render helpers first, then introduce a carrier field provider after all current smoke coverage is stable.

5. Carrier-specific JS validation in `shipments-admin.js`
   - Current problem: common allocation JS is neutral, but carrier-specific validation and UI toggles still live in one admin script.
   - Target architecture: common modal lifecycle remains in `shipments-admin.js`; carrier-specific behavior is isolated behind carrier-scoped functions or data-driven rules.
   - Affected classes/files: `assets/admin/shipments-admin.js`, `OrderShipmentsMetabox`, DPD/CDEK/Russian Post/Yandex smoke tests.
   - Migration sequence: keep current behavior, extract one carrier-specific section at a time only with source assertions and browser-free smoke coverage.

6. DPD two-stage flow
   - Current problem: DPD still has a special two-stage registration/status flow that does not fully match the ordinary create path.
   - Target architecture: DPD adapter exposes the same lifecycle shape as other carriers while preserving DPD-specific API semantics internally.
   - Affected classes/files: `DpdShipmentAdapter`, DPD order registration services, DPD button/status smokes.
   - Migration sequence: document current state, add persistence equality smoke, then move special flow behind DPD-specific services without changing UI.

7. Compatibility meta keys `yandex_item_rows` / `cdek_item_rows`
   - Status: resolved in 0.112.1. Yandex registration and CDEK creation now use only `shipment_item_rows`; Yandex also no longer rebuilds rows from `ShipmentPlace::items` when canonical rows are missing.

8. CDEK-named allocation adapter used as neutral builder
   - Status: resolved in 0.112.1. `CdekShipmentAllocationAdapter` was removed, and `ShipmentAllocationBuilder` is the neutral builder for canonical rows and places. Its money boundary now rejects non-integer kopeck values instead of silently casting decimals or scientific notation.

9. Adapter interface alias
   - Current problem: resolved in 0.108.3. The empty `ShipmentCarrierAdapterInterface` alias was removed.
   - Target architecture: `CarrierShipmentAdapterInterface` remains the only adapter contract.
   - Affected classes/files: production adapters and tests now import `CarrierShipmentAdapterInterface`.
   - Migration sequence: keep source assertions/grep in smoke or review checklist to prevent reintroducing alias interfaces.

10. Unified carrier regression suite
   - Current problem: carrier regression exists as multiple targeted smokes, but a full post-refactor suite is still manual to assemble.
   - Target architecture: one documented command/profile runs all shipment framework, Yandex, CDEK, DPD, Russian Post, Packaging and checkout regressions.
   - Affected classes/files: `tests/*`, development workflow docs.
   - Migration sequence: stabilize current smoke list, add a wrapper only after individual smoke failures are deterministic and baseline DPD preparation behavior is documented.
