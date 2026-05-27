# Russian Post Pickup Points

Version: 0.22.24.

This stage adds the production foundation for a local Russian Post pickup-point directory. It does not add a checkout map, REST endpoint, checkout modal, required pickup selection, order pickup persistence, shipment registration, labels, or tracking statuses.

## Storage

Russian Post points now live in a carrier-specific table created by `database/migrations/0021_create_russian_post_pickup_points_table.php`:

`wp_wdc_pickup_points_russian_post`

The generic legacy table `wp_wdc_pickup_points` is no longer used for Russian Post import or REST. It is intentionally not supported by this stage; an administrator can remove it manually:

```sql
DROP TABLE IF EXISTS wp_wdc_pickup_points;
```

The Russian Post table stores point identity, address parts, FIAS/GAR ids, geohash, compact readable `work_time`, e-commerce options, weight/size limits, payment/service flags, `source_hash`, `last_seen_at`, and timestamps. It no longer stores `raw_reference` or `work_time_json`; fresh imports normalize raw `workTime` during parsing and keep only the compact text needed by REST and the future map. Indexes include `uniq_point_code`, `idx_type_active`, `idx_city_active`, `idx_postcode`, `idx_lat_lng`, `idx_geohash`, and `idx_source_hash`.

Existing test tables are not migrated to the compact schema. To recreate the table before a repeat import, remove it manually:

```sql
DROP TABLE IF EXISTS wp_wdc_pickup_points_russian_post;
```

## API Layer

Shared API "Отправка" classes live in `src/Carriers/RussianPost/Otpravka/`:

- `RussianPostOtpravkaApiSettings`
- `RussianPostOtpravkaApiClient`

These settings are intentionally not pickup-only. The same credentials/client layer will later be reused for shipment registration in the Russian Post personal account, labels, statuses, and other shipment operations.

Stored settings:

- `russian_post_otpravka_access_token`
- `russian_post_otpravka_login`
- `russian_post_otpravka_password_encrypted`
- `russian_post_otpravka_basic_key_encrypted`
- `russian_post_otpravka_timeout`
- `russian_post_pickup_unload_type`
- `russian_post_pickup_schedule_enabled`
- `russian_post_pickup_last_import_result`
- `russian_post_pickup_last_success_at`

Secret fields are not rendered back into HTML. Empty secret inputs preserve existing values; clear checkboxes remove saved values.

## Import

Pickup import classes live in `src/Pickup/RussianPost/`:

- `RussianPostPassportPointNormalizer`
- `RussianPostPickupImporter`
- `RussianPostPickupImportStateService`
- `RussianPostPickupPointRepository`

The importer downloads `GET https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=<ALL|OPS|PVZ|APS>` with WordPress HTTP streaming into a temp ZIP file. The import is resumable and split into short background jobs:

- init job `wdc_russian_post_pickup_import_init`: create a staging table `wp_wdc_pickup_points_russian_post_staging_<import_id>`, download ZIP, extract the first `.json`/`.txt` payload to a temp file, delete the ZIP, save `payload_file` and `payload_offset=0`, then schedule the first batch;
- batch job `wdc_russian_post_pickup_import_batch`: open the payload, seek to `payload_offset`, parse up to 500 `passportElements` objects, normalize and insert only that small batch into staging, save the new byte offset and counters, then schedule the next batch or finalize;
- finalize job `wdc_russian_post_pickup_import_finalize`: atomically swap staging into `wp_wdc_pickup_points_russian_post` with `RENAME TABLE`, verify that main exists, delete the backup only after successful verification, delete the payload temp file, save success state, and unlock.

The parser resumes from the saved byte offset and does not re-read the whole payload from the beginning. The full ALL payload is never decoded at once, and no single PHP process performs the full import.

The current main table remains readable while staging is being built. REST and future checkout map reads always use the main table only. If import fails, staging is dropped and the old main table remains untouched. Full snapshots do not use `mark_missing_inactive`; the swapped main table contains the current snapshot.

If a swap fails after the previous main table has been renamed to backup, the repository attempts to rename backup back to main. A recovered failure still marks the import failed and records a clear message, but leaves the production table restored. If recovery also fails, the backup table is kept for manual repair and is not deleted by failed cleanup.

After a successful swap, the importer runs `ANALYZE TABLE wp_wdc_pickup_points_russian_post` to refresh InnoDB statistics for bbox/search queries and admin tools. Analyze failure is stored as a warning in import errors, but does not turn a successful import into failed.

The import result stores `downloaded`, `parsed`, `inserted`, `updated`, `deactivated`, `skipped`, `errors`, `started_at`, and `finished_at`.

Download diagnostics are stored in the same state/result: `download_url`, `download_started_at`, `download_duration_ms`, `download_http_code`, `download_response_message`, `temp_file_size`, and `download_error`. The Otpravka download timeout defaults to 120 seconds and is clamped to 30..300 seconds; the HTTP request also passes a short connect timeout when supported by WordPress HTTP transports. A download stage with no activity for 5 minutes is marked failed and the lock is cleared on the next status/lock check.

Locking uses `wdc_russian_post_pickup_import_lock` via transients, with an option fallback in non-WP smoke tests, so parallel imports return a readable status instead of running twice.

Manual imports from the admin UI now queue a background job instead of running in the HTTP request. The persistent live state is stored in the `wdc_russian_post_pickup_import_state` option with `status`, `stage`, timestamps, counters, first errors, type, memory peak, `import_id`, `payload_file`, `payload_offset`, `objects_processed`, `batches_processed`, `current_batch_size`, `last_batch_duration_ms`, `max_batch_duration_ms`, `parser_completed`, `staging_table`, `main_table`, `backup_table`, `rows_inserted_to_staging`, `swap_started_at`, and `swap_finished_at`. The importer updates state before download, after extraction, after every batch insert, before swap, and on success/failure. If a queued/running state has no activity for more than 2 hours, stale lock recovery marks it failed and allows a new run with a warning.

The Otpravka passport ZIP download timeout defaults to 120 seconds and is sanitized to 30..300 seconds. Download failures store the HTTP code, WP error message when present, response message/body excerpt up to 1000 characters, duration, and temp file size when available. A running `download` stage with no activity for more than 5 minutes is marked failed with `Download stage timed out/stale.` and the import lock is cleared. A stale `parse`/`upsert` batch older than 10 minutes is marked failed with `Batch stage timed out/stale.`.

`RussianPostPickupPointRepository` writes import batches only into staging. This keeps the production table stable and avoids sustained writes against the table read by REST/checkout.

## Admin

Manual import is available at:

`Службы доставки -> Почта России — по России / pickup service -> ПВЗ / ОПС`

The tab is shown only for `russian_post_domestic_pickup`. It contains:

- shared API "Отправка" credentials;
- timeout;
- unload type `ALL|OPS|PVZ|APS`;
- weekly update flag;
- "Запустить импорт сейчас";
- live import status/progress;
- last import result;
- active counts for `OPS`, `PVZ`, `APS`;
- lock status.

The "run import now" button schedules the init hook `wdc_russian_post_pickup_import_init` through Action Scheduler when available, otherwise through `wp_schedule_single_event(time()+5, ...)`, then redirects back to the tab. The status box polls `admin-ajax.php?action=wdc_russian_post_pickup_import_status` every 3 seconds while the state is `queued` or `running`; polling stops on `success` or `failed`. The status output includes parsed rows, rows inserted to staging, skipped rows, batch metrics, staging/main table names, swap timestamps, and errors. On the current test import, `ALL` produced 37302 active points.

State becomes `queued` only after the background job is actually scheduled. If scheduling fails, the state is saved as `failed` with `Unable to schedule background import job.`, so the admin screen does not get stuck in a forever-queued state.

The admin tab includes "Отменить / сбросить зависший импорт" while an import is queued/running. It clears `wdc_russian_post_pickup_import_lock`, drops the staging table, removes temp files, and marks state failed with `Import was manually cancelled/reset by admin.` without touching the main table.

The existing `Калькулятор доставок -> ПВЗ` page remains in place and now shows a Russian Post summary with active total, type counts, and last successful import date.

## REST API

The local pickup directory is exposed through public read-only REST endpoints under `wdc/v1`. For `carrier=russian_post`, they read only from `wp_wdc_pickup_points_russian_post` and do not call Russian Post or any external API. Other carriers return an empty list for list/search in this stage.

`GET /wp-json/wdc/v1/points`

Parameters:

- `carrier=russian_post`
- `bbox=minLng,minLat,maxLng,maxLat`
- `type[]=OPS|PVZ|APS`
- `limit`, default `500`, max `1000`

Example:

```text
/wp-json/wdc/v1/points?carrier=russian_post&bbox=82.80,54.90,83.10,55.20&type[]=PVZ&limit=200
```

`GET /wp-json/wdc/v1/points/search`

Parameters:

- `q`
- `carrier=russian_post`
- `city`
- `type[]=OPS|PVZ|APS`
- `limit`, default `50`, max `100`

Example:

```text
/wp-json/wdc/v1/points/search?carrier=russian_post&q=630001&city=Новосибирск&type[]=OPS
```

`GET /wp-json/wdc/v1/points/{id}` returns a safe detail card with `point_code`, address, coordinates, work time, e-commerce options, payment/service flags, and `weight_limit_grams`.

The API validates bbox ranges, clamps limits, sanitizes all query parameters, uses prepared SQL through `RussianPostPickupPointRepository`, and does not expose raw snapshots, `work_time_json`, secrets, source hash, temp files, or import state fields.

## Scheduling

`RussianPostPickupImporter::SCHEDULE_HOOK` registers the scheduled import hook. When "Обновлять еженедельно" is enabled, WP Cron schedules a weekly import; disabling clears the hook when the WordPress cron functions are available.

## Tests

Smoke coverage:

```powershell
php tests/pickup/run-russian-post-pickup-import-smoke.php
php tests/delivery-services/run-delivery-services-smoke.php
php tests/runtime/run-no-legacy-smoke.php
```
