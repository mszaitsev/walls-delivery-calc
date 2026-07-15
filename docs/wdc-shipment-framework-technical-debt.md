# Shipment Framework technical debt

## 0.111.1 focused debt register

1. Shipment actual cost comparison is duplicated across carriers
   - Current problem: DPD and Yandex now implement the same actual-cost comparison locally: read saved Base API cost, compare actual cost against `base + 3%`, format `actual_cost_label`, and produce `actual_cost_compare_status` / `actual_cost_compare_message`.
   - Target architecture: extract this into a carrier-neutral service, for example `ShipmentActualCostComparisonService`, and reuse it from every shipment adapter/service that exposes actual carrier cost.
   - Affected classes/files: `DpdShipmentAdapter`, `YandexShipmentAdapter`, future carrier actual-cost presenters and shipment price smokes.
   - Migration sequence: preserve existing output contract and threshold semantics, introduce the neutral service with equality tests, migrate one carrier at a time, then remove duplicated helpers.

## 0.108.5 focused debt register

1. Carrier-specific modal fields remain partly hardcoded
   - Current problem: `OrderShipmentsMetabox` now renders Yandex source/destination/ready fields correctly, but CDEK/DPD/Russian Post/Yandex field blocks are still explicit PHP branches inside the shared metabox.
   - Target architecture: the shared metabox renders common fields plus a small carrier modal extension contract for carrier-owned fields.
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
   - Current problem: the shared metabox still contains hardcoded CDEK/DPD/Russian Post/Yandex field blocks.
   - Target architecture: the metabox renders common fields plus carrier-provided field fragments or a small field schema.
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
