# Project Status

Version: 0.124.11

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

- Yandex Delivery shipment modal restores source drop-off PVZ presentation from the canonical V2 pickup context, including `full_address`, `schedule_text`, and coordinates, so the modal address and drop-off map survive the shipment framework flow.
- Shipment pickup picker defines its local `pickupContext()` resolver again, so Yandex source drop-off picker can open, run initial map search, and perform address search without a `ReferenceError`.
- Yandex cancellation polling toast treats `cancellation_started` lifecycle/poll flags as pending and no longer interprets the intermediate `carrier_status_title` as terminal failure.
- Yandex cancellation polling success toast now requires explicit cancellation confirmation via `cancelled_and_removed` or `yandex_status=CANCELLED`; empty `yandex_status` stays non-success.

Canonical docs:

- [plugin architecture](../architecture/plugin-architecture.md)
- [shipment framework](../architecture/shipment-framework.md)
- [new carrier guide](../development/new-carrier-guide.md)
- [testing and regression](../development/testing-and-regression.md)

Primary test command:

```bash
php tests/shipments/run-shipment-regression-profile.php
```
