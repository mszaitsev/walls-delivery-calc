# Cron And Background Jobs

Version: 0.122.0

Background jobs are registered from `Plugin::register_hooks()` and `Plugin::boot_modules()`.

Current jobs:

- shipment status autosync through `ShipmentStatusAutoSyncCron`;
- DPD pickup auto sync through `DpdPickupPointAutoSync`;
- Yandex geo pipeline scheduled hooks;
- Russian Post pickup importer scheduling;
- calendar/GAR/FIAS support jobs through their managers.

Cron handlers should call application services, not controllers or renderers.
