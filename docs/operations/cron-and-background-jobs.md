# Cron And Background Jobs

Version: 0.155.20

WDC business clock times are interpreted in `Asia/Novosibirsk`; scheduler APIs continue to receive Unix timestamps. Local-clock owners share `TimezoneService`. Relative intervals retain interval semantics, and all next-run values in WDC admin are formatted in Novosibirsk time.

Background jobs are registered from `Plugin::register_hooks()` and `Plugin::boot_modules()`.

Action Scheduler owners register their task callbacks during WDC bootstrap, then defer schedule inspection and creation through the shared wrapper until the official `action_scheduler_init` hook. If an owner is registered after that hook, its ensure callback runs immediately. The expected pre-init state is silent; genuine unavailability after WordPress `init` is logged once per wrapper operation.

Current jobs:

- shipment status autosync through `ShipmentStatusAutoSyncCron`; its persisted interval is 15–1440 minutes in 15-minute steps, defaults to 360 minutes, and owns one dynamic WP-Cron recurrence such as `wdc_shipment_status_autosync_360`. Saving the setting clears the previous event and schedules the next run at `now + interval`; disabled execution remains a guarded no-op and the 30-minute lock TTL is unchanged;
- DPD pickup auto sync through `DpdPickupPointAutoSync`;
- Yandex geo pipeline scheduled hooks;
- Russian Post pickup importer scheduling;
- Ozon Delivery pickup catalog: `wdc_ozon_delivery_pickup_daily` starts at most one leased import and `wdc_ozon_delivery_pickup_step` handles one bounded phase-aware unit. In `discovery`, a step calls only `/v1/delivery-point/list` and freezes unique IDs in local staging; in `enrichment`, a step reads up to 100 pending frozen IDs and calls `/v1/delivery-point/info`. The scheduler remains phase-agnostic: it renews the import lock, invokes the importer, schedules the next generic step or retry delay, and releases the lock only on terminal completion. The default is daily at `02:00` Asia/Novosibirsk; changing `ПВЗ Ozon` settings replaces the recurring action. These hooks never run checkout logic. The pickup provider reads the already published active snapshot only and never starts a sync.

The `ПВЗ Ozon` browser polling endpoint is read-only: it reads local generation progress and never executes Action Scheduler work. A stale-progress warning does not fail a generation, release its lease, or activate a snapshot.
- calendar support uses one self-scheduling Action Scheduler single action at 09:00 Asia/Novosibirsk on the first Monday of each month. Bootstrap replaces the legacy daily action. Each run checks the next calendar year and generates only missing calendar types before scheduling the next month's first Monday.

The former prepared-dataset and automatic GAR/SPAS placeholders are no longer jobs. A combined versioned cleanup, deferred to Action Scheduler readiness, removes their queued legacy actions and dead options. The historical GAR changes table remains inert; this does not affect manual GAR CSV import or incremental Locations maintenance.

Cron handlers should call application services, not controllers or renderers.

Background jobs must be idempotent where possible, guarded against overlapping runs when they call carrier APIs, and documented in tests or operations docs when they affect shipment state.
