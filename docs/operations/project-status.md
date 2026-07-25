# Project Status

Version: 0.128.7

Stable subsystems:

- core bootstrap and DI composition root;
- checkout orchestration;
- packaging;
- locations and pickup infrastructure;
- rules engine;
- shipment adapters, persistence mappers, lifecycle continuation, document providers, modal extensions, admin AJAX, carrier JS extensions;
- provider-owned document action contract;
- bounded plugin architecture smoke in the framework regression group;
- unified shipment regression profile.
- shipment cost analytics on the overview admin page, comparing the selected order delivery's base API planned cost with canonical actual shipment cost.
- CDEK EAEU support through the single `cdek` carrier/service with configurable `RU`, `AM`, `BY`, `KZ`, and `KG` availability.

Active known limitations:

- `ShipmentCreationService` still accepts an adapter array temporary test-construction fallback beside the registry for direct test construction.
- Russian Post document action is temporarily hidden because Otpravka currently returns `Forbidden mail type` for `/1.0/forms/backlog/{id}/forms` before batch formation; the download implementation remains in place for future API re-check.
- Regression manifest keeps two baseline failures: DPD shipment preparation dry-run date pickup and CDEK large pickup list rendering.
- Two optional Russian Post carrier tests still require fuller WooCommerce test stubs.

Recent fixes:

- CDEK now supports RU/AM/BY/KZ/KG through the existing service-country repository. Existing installs receive a one-time migration from empty/RU-only CDEK countries to the five-country default, while fresh installs seed the same defaults only when the built-in service is first created.
- DPD Geography imports AM/BY/KZ/KG locations into the shared locations table with `dpd_city_id` idempotency and without fake Russian identifiers. CDEK city resolution is country-aware, supports manual city input, and does not persist CDEK city codes.
- CDEK shipment creation now handles international pickup and courier on the shared adapter/request/status/document pipeline. Non-RU courier uses raw WooCommerce address data plus resolved CDEK city code, and the CDEK-only optional recipient document field is visible from initial modal render for AM/BY/KZ/KG, maps to `recipient.tin` for KZ/KG or `recipient.passport_number` for AM/BY, and is never persisted or logged.
- CDEK pickup maps for manual EAEU cities reuse the resolved CDEK city code and fit the initial map viewport to loaded pickup point coordinates when no trusted destination coordinates exist. The derived viewport is never persisted as location data, physical user interaction cancels pending initial auto-fit, provider fit events no longer suppress the first subsequent user pan, explicit address/geolocation viewport actions refresh pickup points without being overridden by a later initial fit, and confirmed point selection cancels pending provider fit even when it does not focus the map.
- Checkout and order delivery recalculation now share a delivery lead-time pipeline: carrier raw lead time, shop processing calendar, optional carrier working-day conversion, delivery date rules, and final planned date. The global processing default is `2` shop working days, the per-service working-day flag defaults to off, and existing manual processing additions in rules should be removed manually where they are no longer wanted.
- The overview admin page now shows only platform information and delivery quote cache cleanup, while system requirements notices remain handled globally by `AdminNotices`.
- Checkout runtime gating now uses `CheckoutFeatureGate` with `enable_new_checkout_shipping` as the single source of truth; the legacy feature flag service was removed.
- Yandex Delivery shipment modal restores source drop-off PVZ presentation from the canonical V2 pickup context, including `full_address`, `schedule_text`, and coordinates, so the modal address and drop-off map survive the shipment framework flow.
- Shipment pickup picker defines its local `pickupContext()` resolver again, so Yandex source drop-off picker can open, run initial map search, and perform address search without a `ReferenceError`.
- Yandex cancellation polling toast treats `cancellation_started` lifecycle/poll flags as pending and no longer interprets the intermediate `carrier_status_title` as terminal failure.
- Yandex cancellation polling success toast now requires explicit cancellation confirmation via `cancelled_and_removed` or `yandex_status=CANCELLED`; empty `yandex_status` stays non-success.
- Yandex cancellation polling success now finalizes the existing progress toast by replacing it with a success message and auto-hiding it, so no persistent progress toast remains after cancellation.
- Shipment place analytics now refresh directly from current `shipment_item_rows` when item place assignment changes, so split rows are counted in their selected cargo places immediately.
- Yandex cancellation `cancelled_and_removed` now finalizes an existing cancellation progress toast even after the shipment UI has already been reset.
- Yandex terminal cancellation tick now treats the active cancellation toast state as lifecycle ownership, so missing terminal purpose metadata cannot leave the last progress toast visible.
- Yandex cancellation status AJAX now preserves the adapter's `cancelled_and_removed` marker in JSON, so terminal polling resets the shipment UI and finishes the cancellation toast instead of falling back to persistent progress.
- Shipment cost analytics now appears on `admin.php?page=wdc-platform`. It uses the `wdc_shipment_cost_analytics` materialized read-model table, indexes at most one matching created shipment per eligible order, uses `ShipmentBaseApiCostResolver`, canonical `actual_cost_*` fields, registry-driven carrier filters, integer 3% threshold checks, SQL sorting/pagination/aggregates, and no runtime WooCommerce order scan.

Canonical docs:

- [plugin architecture](../architecture/plugin-architecture.md)
- [shipment framework](../architecture/shipment-framework.md)
- [new carrier guide](../development/new-carrier-guide.md)
- [testing and regression](../development/testing-and-regression.md)

Primary test command:

```bash
php tests/shipments/run-shipment-regression-profile.php
```
