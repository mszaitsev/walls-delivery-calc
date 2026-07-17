# Cron And Background Jobs

Version: 0.124.1

Background jobs are registered from `Plugin::register_hooks()` and `Plugin::boot_modules()`.

Current jobs:

- shipment status autosync through `ShipmentStatusAutoSyncCron`;
- DPD pickup auto sync through `DpdPickupPointAutoSync`;
- Yandex geo pipeline scheduled hooks;
- Russian Post pickup importer scheduling;
- calendar/GAR/FIAS support jobs through their managers.

Cron handlers should call application services, not controllers or renderers.

Background jobs must be idempotent where possible, guarded against overlapping runs when they call carrier APIs, and documented in tests or operations docs when they affect shipment state.
