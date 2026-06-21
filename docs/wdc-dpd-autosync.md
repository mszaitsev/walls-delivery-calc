# DPD Autosync

Version: 0.70.0.

Version 0.70.0 update: DPD autosync now carries WooCommerce order status mapping diagnostics from the global getEvents pre-pass into the shared autosync counters. DPD still does not have separate WooCommerce status logic: EventCode -> DpdStatusMapping -> DeliveryStatus -> ShipmentOrderStatusMappingService remains the only path.

DPD status autosync is connected to the existing shipment status autosync cron. It does not add a separate cron, does not change the manual DPD create/update flow, and does not implement DPD documents or labels.

## Scheduler

The existing `ShipmentStatusAutoSyncCron` keeps using WP Cron hook `wdc_shipment_status_autosync` and schedule `wdc_every_6_hours`. `ShipmentStatusAutoSyncService` owns the shared autosync lock and diagnostics.

DPD is different from Russian Post and CDEK: DPD `event-tracking/getEvents` returns a client-wide event inbox. Because of that, autosync does not loop over DPD shipments and call DPD once per order. At the start of an autosync run, `ShipmentStatusAutoSyncService` runs one global DPD pre-pass:

```php
DpdEventSyncService::sync( null, true );
```

The later selected-order scan still counts DPD shipments for diagnostics, but skips their per-order polling with `carrier_global_sync` because changed DPD shipments have already been processed by the global event sync.

## getEvents And Confirm

`DpdEventSyncService` remains the single DPD lifecycle boundary for events:

- one atomic option lock `wdc_dpd_events_lock`;
- up to 20 event packages per run;
- latest event selection by DPD order/client order;
- strict `dpdOrderNr` matching before `clientOrderNr` fallback;
- unmatched event logging without auth, client keys, phones, emails or addresses;
- optional `confirm(docId)` after a package is processed;
- stop on confirm error, without rolling back changes already saved from the package.

If the DPD event lock is busy, autosync skips DPD and records the run as successful, not as an error. If `getEvents` fails before events are processed, shipment statuses are not changed and `wdc_dpd_autosync_last_result` becomes `error`.

## Enrichment

Autosync-only enrichment is enabled by the second argument to `sync()`.

When a new DPD event updates a shipment, `DpdEventSyncService` checks whether the shipment still lacks either:

- `dpd_actual_cost_kopecks`; or
- `planned_delivery_date`.

Only then it calls `DpdShipmentEnrichmentService`, which uses `tracing1-1/getStatesByDPDOrder` for actual cost and planned delivery date. If no new event is applied, enrichment is not called.

Manual DPD `Обновить статус` keeps the previous behavior: `DpdOrderRegistrationService` runs event sync for the current order path and then calls enrichment explicitly.

## Settings And Status

`WDC -> Службы доставки -> DPD -> DPD Расчет` contains:

- `Автоматическое обновление статусов DPD`, enabled by default;
- readonly `Последний autosync DPD`;
- readonly `Последний результат` with `успешно`, `ошибка`, `отключено` or `не запускался`.

The values are stored in `SettingsRepository` keys:

- `dpd_autosync_enabled`;
- `wdc_dpd_autosync_last_run`;
- `wdc_dpd_autosync_last_result`.

Disabling DPD autosync prevents the scheduled DPD `getEvents` run. Manual DPD status refresh remains available.

## Diagnostics

The shared autosync diagnostics include the DPD sync result under `dpd_autosync`, including packages, events, updated, unchanged, unmatched, confirm status and duration. `DpdEventSyncService` also writes a sanitized final log entry with the same counters and execution time.

## Test Coverage

`tests/dpd/run-dpd-autosync-smoke.php` covers:

- enabled/disabled DPD autosync;
- one `getEvents` per autosync run;
- several DPD orders updated through one sync;
- busy DPD lock skip;
- enrichment on new event only;
- no enrichment on unchanged event;
- `getEvents` SOAP error without status mutation;
- confirm error recorded as autosync error;
- last run/result storage.