# Cron And Background Jobs

Version: 0.139.0

Background jobs are registered from `Plugin::register_hooks()` and `Plugin::boot_modules()`.

Current jobs:

- shipment status autosync through `ShipmentStatusAutoSyncCron`;
- DPD pickup auto sync through `DpdPickupPointAutoSync`;
- Yandex geo pipeline scheduled hooks;
- Russian Post pickup importer scheduling;
- Ozon Delivery pickup catalog: `wdc_ozon_delivery_pickup_daily` starts at most one leased import and `wdc_ozon_delivery_pickup_step` handles one cursor page plus its detail batch. The default is daily at `02:00` in the WordPress timezone; changing `ПВЗ Ozon` settings replaces the recurring action. These hooks never run checkout logic.
- calendar/GAR/FIAS support jobs through their managers.

Cron handlers should call application services, not controllers or renderers.

Background jobs must be idempotent where possible, guarded against overlapping runs when they call carrier APIs, and documented in tests or operations docs when they affect shipment state.
