# Project Status

Version: 0.122.1

Stable subsystems:

- core bootstrap and DI composition root;
- checkout orchestration;
- packaging;
- locations and pickup infrastructure;
- rules engine;
- shipment adapters, persistence mappers, lifecycle continuation, document providers, modal extensions, admin AJAX, carrier JS extensions;
- unified shipment regression profile.

Active known limitations:

- `ShipmentCreationService` still accepts an adapter array temporary test-construction fallback beside the registry for direct test construction.
- Regression manifest keeps two baseline failures: DPD shipment preparation dry-run date pickup and CDEK large pickup list rendering.
- Two optional Russian Post carrier tests still require fuller WooCommerce test stubs.

Canonical docs:

- [plugin architecture](../architecture/plugin-architecture.md)
- [shipment framework](../architecture/shipment-framework.md)
- [new carrier guide](../development/new-carrier-guide.md)
- [testing and regression](../development/testing-and-regression.md)

Primary test command:

```bash
php tests/shipments/run-shipment-regression-profile.php
```
