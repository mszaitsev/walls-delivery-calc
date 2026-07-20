# Project Status

Version: 0.125.3

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

Active known limitations:

- `ShipmentCreationService` still accepts an adapter array temporary test-construction fallback beside the registry for direct test construction.
- Russian Post document action is temporarily hidden because Otpravka currently returns `Forbidden mail type` for `/1.0/forms/backlog/{id}/forms` before batch formation; the download implementation remains in place for future API re-check.
- Regression manifest keeps two baseline failures: DPD shipment preparation dry-run date pickup and CDEK large pickup list rendering.
- Two optional Russian Post carrier tests still require fuller WooCommerce test stubs.

Recent fixes:

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

Canonical docs:

- [plugin architecture](../architecture/plugin-architecture.md)
- [shipment framework](../architecture/shipment-framework.md)
- [new carrier guide](../development/new-carrier-guide.md)
- [testing and regression](../development/testing-and-regression.md)

Primary test command:

```bash
php tests/shipments/run-shipment-regression-profile.php
```
