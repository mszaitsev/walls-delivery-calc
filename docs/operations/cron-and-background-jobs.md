# Cron And Background Jobs

Version: 0.149.0

Background jobs are registered from `Plugin::register_hooks()` and `Plugin::boot_modules()`.

Current jobs:

- shipment status autosync through `ShipmentStatusAutoSyncCron`;
- DPD pickup auto sync through `DpdPickupPointAutoSync`;
- Yandex geo pipeline scheduled hooks;
- Russian Post pickup importer scheduling;
- Ozon Delivery pickup catalog: `wdc_ozon_delivery_pickup_daily` starts at most one leased import and `wdc_ozon_delivery_pickup_step` handles one bounded phase-aware unit. In `discovery`, a step calls only `/v1/delivery-point/list` and freezes unique IDs in local staging; in `enrichment`, a step reads up to 100 pending frozen IDs and calls `/v1/delivery-point/info`. The scheduler remains phase-agnostic: it renews the import lock, invokes the importer, schedules the next generic step or retry delay, and releases the lock only on terminal completion. The default is daily at `02:00` in the WordPress timezone; changing `ПВЗ Ozon` settings replaces the recurring action. These hooks never run checkout logic. The pickup provider reads the already published active snapshot only and never starts a sync.

The `ПВЗ Ozon` browser polling endpoint is read-only: it reads local generation progress and never executes Action Scheduler work. A stale-progress warning does not fail a generation, release its lease, or activate a snapshot.
- calendar/GAR/FIAS support jobs through their managers.

Cron handlers should call application services, not controllers or renderers.

Background jobs must be idempotent where possible, guarded against overlapping runs when they call carrier APIs, and documented in tests or operations docs when they affect shipment state.
