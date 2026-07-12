# Shipment Framework technical debt

## 0.108.4 focused debt register

1. CDEK modal fallback `cdek_items[]`
   - Current problem: the shared modal now submits canonical `shipment_items[]`, but `ShipmentModalRequestMapper` still accepts `cdek_items[]` for the CDEK migration window.
   - Target architecture: only `shipment_items[]` is accepted by shared modal submit parsing.
   - Affected classes/files: `ShipmentModalRequestMapper`, CDEK smoke fixtures, any future external admin submit callers.
   - Migration sequence: confirm all CDEK admin submit tests use `shipment_items[]`, remove fallback, add a source assertion that `cdek_items[]` is absent from common submit code.

2. CDEK meta `cdek_item_rows`
   - Current problem: `OrderShipmentDraftFactory` writes canonical `shipment_item_rows` and temporary `cdek_item_rows` because CDEK builders still read the CDEK-specific key.
   - Target architecture: CDEK builders read canonical `shipment_item_rows` first and carrier-specific meta is kept only for fields that are truly CDEK-specific.
   - Affected classes/files: `OrderShipmentDraftFactory`, `CdekCreateRequestBuilder`, CDEK order-creation smoke.
   - Migration sequence: switch CDEK builder to canonical-first, keep fallback for one patch, then remove `cdek_item_rows` writes and tests.

3. Non-Yandex carrier persistence in `ShipmentCreationService`
   - Current problem: DPD, CDEK and Russian Post carrier-specific persistence fields are still built inside `ShipmentCreationService`.
   - Target architecture: each carrier that needs custom fields owns a small `CarrierShipmentPersistenceMapperInterface` implementation; `ShipmentCreationService` persists only the common envelope.
   - Affected classes/files: `ShipmentCreationService`, DPD/CDEK/Russian Post adapters/repositories/smokes.
   - Migration sequence: migrate one carrier at a time with payload/persistence equality smoke, then remove carrier switches from the common service.

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
   - Current problem: Yandex registration reads `shipment_item_rows` first but keeps `yandex_item_rows` and `cdek_item_rows` fallbacks; CDEK still persists `cdek_item_rows`.
   - Target architecture: `shipment_item_rows` is the only shared allocation meta key.
   - Affected classes/files: `YandexShipmentRegistrationService`, `OrderShipmentDraftFactory`, `CdekCreateRequestBuilder`, shipment framework smokes.
   - Migration sequence: remove `yandex_item_rows` fallback after all Yandex drafts write canonical rows; then migrate CDEK builder and remove `cdek_item_rows`.

8. Adapter interface alias
   - Current problem: resolved in 0.108.3. The empty `ShipmentCarrierAdapterInterface` alias was removed.
   - Target architecture: `CarrierShipmentAdapterInterface` remains the only adapter contract.
   - Affected classes/files: production adapters and tests now import `CarrierShipmentAdapterInterface`.
   - Migration sequence: keep source assertions/grep in smoke or review checklist to prevent reintroducing alias interfaces.

9. Unified carrier regression suite
   - Current problem: carrier regression exists as multiple targeted smokes, but a full post-refactor suite is still manual to assemble.
   - Target architecture: one documented command/profile runs all shipment framework, Yandex, CDEK, DPD, Russian Post, Packaging and checkout regressions.
   - Affected classes/files: `tests/*`, development workflow docs.
   - Migration sequence: stabilize current smoke list, add a wrapper only after individual smoke failures are deterministic and baseline DPD preparation behavior is documented.

10. Assessed price in shipment allocation
   - Current problem: the canonical modal/parser can read `assessed_cost` / `assessed_unit_price`, but `CdekShipmentAllocationAdapter` still uses `cost` simultaneously for `unit_price_kopecks` and `assessed_unit_price_kopecks`. A separate assessed price is therefore not yet carried into the neutral `ShipmentAllocation`.
   - Target architecture: the common modal contract, persistence snapshot and allocation adapter carry unit price and assessed/declared value as distinct carrier-neutral values, and each carrier maps them explicitly.
   - Affected classes/files: `ShipmentModalRequestMapper`, `CdekShipmentAllocationAdapter`, `ShipmentAllocationItem`, CDEK/Yandex payload builders and smoke fixtures.
   - Migration sequence: first add/verify separate assessed value in neutral allocation without changing CDEK payload, then migrate CDEK/Yandex adapters with regression payload equality. This is intentionally not part of 0.108.4 to preserve current CDEK business behavior.
