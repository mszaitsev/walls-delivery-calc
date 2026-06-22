# WDC DPD Pickup Autosync

Version: 0.71.0.

## Purpose

DPD recommends refreshing pickup points regularly. WDC now supports automatic daily refresh of the local DPD pickup point table without adding a second importer: cron uses the same `DpdPickupPointImportService::import_all()` path as the manual DPD -> ПВЗ “Обновить все” action.

## Settings

Open:

`WDC -> Службы доставки -> DPD -> ПВЗ`

The autosync block stores four DPD settings:

- `dpd_pickup_autosync_enabled`;
- `dpd_pickup_autosync_time_1`;
- `dpd_pickup_autosync_time_2`;
- `dpd_pickup_autosync_time_3`.

Each time field is a select with `Не выбрано` plus `00:00`, `00:15`, `00:30` ... `23:45`. Invalid values normalize to empty, empty means “do not run”, and duplicate selected times are ignored for effective scheduling.

These settings are separate from DPD shipment status autosync (`getEvents`/confirm). Pickup autosync never changes shipment statuses or WooCommerce order statuses.

## Schedule And GMT+3

`DpdPickupPointAutoSync` registers the WP-Cron hook `wdc_dpd_pickup_points_autosync`.

When enabled, it schedules one daily event for each unique selected time. Times are interpreted as Moscow time (GMT+3) independent of the WordPress timezone:

- `09:00` GMT+3 -> `06:00` UTC;
- `00:15` GMT+3 -> previous day `21:15` UTC;
- `23:45` GMT+3 -> `20:45` UTC.

On settings save the admin page clears existing events for the hook and creates the new schedule. Plugin activation also ensures the schedule exists when settings are enabled; deactivation clears the pickup autosync hook. Existing events are checked by hook plus selected-time argument, so duplicate cron events are not created.

## Import Path

The cron callback validates that autosync is still enabled and that the event time is still selected, then calls:

`DpdPickupPointImportService::import_all( 'auto_cron' )`

The import still fetches the full DPD pickup list from the existing sources:

- `geography2/getParcelShops`;
- `geography2/getTerminalsSelfDelivery2`.

The repository writes the same `wdc_dpd_pickup_points` table as manual import.

## Lock And Failures

`DpdPickupPointImportService` uses a short option-based lock (`wdc_dpd_pickup_import_lock`) around manual and automatic imports. If another import is already running, the second run returns a non-fatal `skipped_lock_busy` report and does not call DPD.

Safe-replace behavior is unchanged: if DPD returns no rows, if normalization produces no valid rows, or if the API is unavailable, existing pickup points remain untouched. The last import report records the error/status instead of wiping old data.

## Last Import Report

Manual and automatic imports both update `dpd_last_pickup_import_report`. Reports include counts, errors, `context` and `status`:

- manual actions use context `manual`;
- cron uses context `auto_cron`;
- lock skips use status `skipped_lock_busy`.

The DPD -> ПВЗ tab displays the last import date, source and result so automatic refreshes appear in the same place as manual imports.
