# Russian Post Pickup Points

Version: 0.22.10.

This stage adds the production foundation for a local Russian Post pickup-point directory. It does not add a checkout map, REST endpoint, checkout modal, required pickup selection, order pickup persistence, shipment registration, labels, or tracking statuses.

## Storage

The existing `wdc_pickup_points` table is extended by `database/migrations/0021_extend_pickup_points_for_russian_post_passport.php`. The migration is tolerant: if the base table is absent it exits, and it adds only missing columns/indexes.

New columns store import identity, last-seen state, address parts, FIAS/GAR ids, geohash, JSON snapshots in `LONGTEXT`, e-commerce options, weight/size limits, and payment/service flags. The existing `active` column is used as `is_active`; disappeared points are marked `active=0` and are not physically deleted. `raw_reference` stores the raw imported item JSON snapshot.

Russian Post points use `carrier_key=russian_post` because the directory belongs to the carrier as a whole, not only to `russian_post_domestic`. `postcode` is stored separately; `point_code` is `postcode . '-' . substr(source_hash, 0, 10)` when a postcode is present, otherwise `source_hash`, so several objects with one postcode do not overwrite each other.

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

The importer downloads `GET https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=<ALL|OPS|PVZ|APS>` with WordPress HTTP streaming into a temp ZIP file. It extracts the first `.json` or `.txt` file from the ZIP into a second temp payload file, then parses the top-level `passportElements` array object-by-object. Rows are normalized and flushed through `PickupPointRepository` in batches of 250, so the full ALL payload is not held in PHP memory.

The import result stores `downloaded`, `parsed`, `inserted`, `updated`, `deactivated`, `skipped`, `errors`, `started_at`, and `finished_at`.

Locking uses `wdc_russian_post_pickup_import_lock` via transients, with an option fallback in non-WP smoke tests, so parallel imports return a readable status instead of running twice.

Manual imports from the admin UI now queue a background job instead of running in the HTTP request. The persistent live state is stored in the `wdc_russian_post_pickup_import_state` option with `status`, `stage`, timestamps, counters, first errors, type, and memory peak. The importer updates state before download, after extraction, during batch upserts, before deactivation, and on success/failure. If a queued/running state has no activity for more than 2 hours, stale lock recovery marks it failed and allows a new run with a warning.

## Admin

Manual import is available at:

`Службы доставки -> Почта России — по России / pickup service -> ПВЗ / ОПС`

The tab is shown only for `russian_post_domestic_pickup`. It contains:

- shared API "Отправка" credentials;
- timeout;
- unload type `ALL|OPS|PVZ|APS`;
- schedule enabled flag;
- "Запустить импорт сейчас";
- live import status/progress;
- last import result;
- active counts for `OPS`, `PVZ`, `APS`;
- lock status.

The "run import now" button schedules `wdc_russian_post_pickup_import` through Action Scheduler when available, otherwise through `wp_schedule_single_event(time()+5, ...)`, then redirects back to the tab. The status box polls `admin-ajax.php?action=wdc_russian_post_pickup_import_status` every 3 seconds while the state is `queued` or `running`; polling stops on `success` or `failed`. On the current test import, `ALL` produced 37302 active points.

The existing `Калькулятор доставок -> ПВЗ` page remains in place and now shows a Russian Post summary with active total, type counts, and last successful import date.

## Scheduling

`RussianPostPickupImporter::SCHEDULE_HOOK` registers the scheduled import hook. When scheduling is enabled, WP Cron schedules a daily import; disabling clears the hook when the WordPress cron functions are available.

## Tests

Smoke coverage:

```powershell
php tests/pickup/run-russian-post-pickup-import-smoke.php
php tests/delivery-services/run-delivery-services-smoke.php
php tests/runtime/run-no-legacy-smoke.php
```
