# WDC DPD Geography

Version: 0.56.4.

## Scope

This stage implements only a production-safe DPD geography foundation needed before tariff work. It reads and stores DPD `cityId` mappings for existing WDC/FIAS/GAR locations, adds admin diagnostics/manual mapping, SFTP/manual CSV import for `GeographyNewDPD_*.csv`, and a manual DaData delivery fallback for one `location_id`.

It does not implement DPD tariffs, `getServiceCost*`, checkout integration, pickup points, `getParcelShops`, order creation, cancellation, statuses, labels, cron jobs, Action Scheduler jobs, COD, `unitLoad` or fiscal receipts.

## Storage

DPD city IDs are stored in the 1:1 location delivery codes table:

- table: `wdc_location_delivery_codes`
- primary key: `location_id`
- DPD mapping: nullable `dpd_city_id`
- maintenance timestamp: nullable `updated_at`

No `carrier_key`, `external_code`, `meta`, `gar_object_id`, `fias_id`, `kladr_id`, `created_at`, or diagnostic fields are stored in this table. GAR/FIAS/KLADR data remains in `wdc_locations`. Future carriers can be added as new columns, for example `yandex_city_id` or `pec_city_id`.

The legacy `wdc_location_carrier_codes` model is removed from code and is not created by migrations.

## Components

- `LocationDeliveryCodeRepository` is the repository for `wdc_location_delivery_codes`.
- `DpdCityResolver` resolves cityId from stored mapping only.
- `DpdDuplicateCityResolver` handles DPD `pickupDups` and `deliveryDups` arrays.
- `DpdApiClient::getCitiesCashPay()` and `DpdApiClient::getPossibleExtraService()` are API wrappers only.
- `DpdGeographyDiagnosticService` supports admin diagnostics and manual mapping for a single existing location.
- `DpdGeographyFtpClient` downloads the newest `GeographyNewDPD_YYYY_MM_DD.csv` through SFTP when `ssh2` is available and an encrypted password is configured.
- `DpdLocationIndex` builds reusable FIAS/KLADR/name lookup maps from active RU `wdc_locations` rows.
- `DpdGeographyCsvParser`, `DpdGeographyMatcher`, `DpdGeographyImportStateService`, `DpdGeographyStageRepository` and `DpdGeographyImportService` stream-parse, match, stage, finalize and report DPD city mappings.
- `DpdDaDataDeliveryFallbackService` uses the shared DaData token pool for an admin-triggered single-location fallback.

## Live API Note

Live API city lookup is disabled in `DpdCityResolver`.

`getPossibleExtraService` is not used as the primary city resolver. DPD guide describes it as a method for possible services and its input is a fuller address/service request, not a sparse city lookup. A live diagnostic with only WDC location fields returned a SOAP `java.lang.NullPointerException`, so using it for city lookup is unsafe.

`getCitiesCashPay(countryCode)` is also not used by the resolver. The guide documents it as returning a `city` list, but live test and production checks returned `java.lang.NullPointerException` even for `countryCode`, so WDC cannot treat it as a reliable primary city lookup without a confirmed DPD contract or an imported geography directory.

The current supported primary path is DPD `GeographyNewDPD` CSV import into `wdc_location_delivery_codes.dpd_city_id`. Manual mapping remains the last fallback. `DpdApiClient::getCitiesCashPay()` and `DpdApiClient::getPossibleExtraService()` remain low-level wrappers for future verified work, but admin geography diagnostics and `DpdCityResolver` do not call them.

## GeographyNewDPD Import

The DPD География tab supports two admin-only import paths:

- SFTP download from configured host/port/username/password/directory. Defaults are `ftp.dpd.ru`, port `22`, username `integration`, directory `/integration`; the password default is empty and is stored encrypted when entered.
- Manual upload of a `.csv` file. The uploaded temporary file is moved into a temporary import path and removed after processing or reset.

The importer reads `;`-delimited CSV row by row, supports UTF-8 and Windows-1251, detects the header row when present, and maps the documented first columns: DPD city ID, country code, region, district, main city, settlement, settlement type, postal code, FIAS and KLADR. Only `Код страны = RU` rows are imported. Postal codes, services/options, terminal data, schedules, raw rows and per-row diagnostics are not stored.

As of 0.56.3, imports are stateful staging jobs rather than one synchronous PHP request:

- start action creates an import job, counts data rows by streaming the file, builds a `DpdLocationIndex` from active RU locations, creates a per-job `wdc_dpd_geography_stage_<job_hash>` table, and stores the job in `wdc_dpd_geography_import_state`;
- the DPD География tab polls `wp_ajax_wdc_dpd_geography_import_status`;
- each AJAX step reads from the saved byte offset, processes a limited batch of rows, and writes only to the staging table;
- progress state records phase, source, source file, rows read, total rows, RU/skipped rows, matching counters, saved/unchanged mappings, conflicts, ambiguous/unmatched rows, errors and timestamps;
- finish finalizes candidates into `wdc_location_delivery_codes.dpd_city_id`, stores the final report in DPD settings, and deletes the temporary CSV/index/staging resources;
- reset/cancel deletes stale temporary CSV/index/staging resources and marks the job cancelled.

The import state includes an internal `delete_file_on_finish` flag. Manual upload and SFTP jobs set it to `true`, because WDC owns those temporary CSV files. CLI/smoke/diagnostic calls through `DpdGeographyImportService::import_file()` set it to `false`, so the caller-provided CSV is preserved after finish/reset. The serialized index file and staging table are always removed by finish/reset. `delete_file_on_finish` is not exposed in public AJAX state.

The working `wdc_location_delivery_codes` table is not changed while rows are being imported. During a job, `DpdGeographyStageRepository` creates:

- `location_id BIGINT UNSIGNED NOT NULL PRIMARY KEY`;
- `dpd_city_id BIGINT UNSIGNED NULL`;
- `match_method VARCHAR(20) NOT NULL`;
- `status VARCHAR(20) NOT NULL DEFAULT 'candidate'`;
- `updated_at DATETIME NULL`;
- optional `status` index.

The import state stores the staging table name for services, but public state never exposes `file_path`, `index_path` or `stage_table`. The cancelled heavy state arrays `seen_mappings`, `saved_by_job` and `blocked_locations` are not stored in the option.

The admin UI shows the current phase, progress bar, `Обработано X из Y строк`, counters, last message and reset button. If JavaScript is unavailable, the current state and reset action remain visible on page reload.

Matching is conservative:

- `DpdLocationIndex` is built once from selected `wdc_locations` columns in chunks: `id`, country/active flags, FIAS/city FIAS, KLADR/city KLADR, region, district, place/settlement/city name and type.
- FIAS exact match checks DPD `ФИАС` against indexed `wdc_locations.fias_id` and `city_fias_id`.
- KLADR matching normalizes DPD codes such as `RU54000001000` and compares exact/padded/trimmed numeric variants against indexed `kladr_id` and `city_kladr_id`.
- Name fallback runs only when FIAS/KLADR fail and saves only a single confident indexed candidate by settlement name, region, district and type.
- If an index key points to multiple locations, the key is marked ambiguous and is not used for automatic mapping.

Duplicate rows with the same DPD city ID for one location are idempotent. If a later row maps the same `location_id` to a different DPD city ID, the staging row becomes `status=conflict` and `dpd_city_id=NULL`; it is excluded from finalization. Ambiguous name matches are counted and not staged. The last import report is stored in DPD settings under `dpd_last_geography_import_report`.

Finalization is DPD-column-only and safe for future carrier code columns. It:

1. clears `wdc_location_delivery_codes.dpd_city_id` for locations absent from candidate staging rows;
2. upserts candidate staging rows with `ON DUPLICATE KEY UPDATE`, changing only `dpd_city_id` and `updated_at`;
3. saves the final report;
4. deletes the CSV temp file, serialized index file and staging table;
5. marks the job `finished`.

SFTP import requires the PHP `ssh2` extension. If it is unavailable, the admin action returns a safe message: `SFTP extension is not available. Upload GeographyNewDPD CSV manually.`

## DaData Fallback

The DPD География tab includes a manual DaData delivery fallback for one `location_id`. It reads the location KLADR, calls `POST https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/delivery`, and saves `suggestions[0].data.dpd_id` when present.

This fallback uses the existing address suggestions DaData token pool. No DPD-specific DaData credentials are added. Each HTTP attempt increments the shared DaData usage counter and records the same request-attempt metadata used by the existing DaData infrastructure. DaData fallback is not used during CSV import and is not called automatically by `DpdCityResolver`, checkout or runtime tariff code.

## Synchronization With Locations

Rows in `wdc_location_delivery_codes` are created lazily when a manager saves a manual code or a future import writes a code. A new row in `wdc_locations` does not require a matching delivery-code row.

When locations are deleted, `LocationDeliveryCodeRepository::cleanup_orphans()` removes rows whose `location_id` no longer exists in `wdc_locations`. If `wdc_locations` is fully rebuilt with new IDs, DPD mappings must be rebuilt by a future geography import. Planned DPD geography import should update only `dpd_city_id` and `updated_at`.

## Duplicate City Matching

`DpdDuplicateCityResolver` is isolated from future tariff services/import work. It scores city-list or duplicate candidates by:

- FIAS GUID;
- GAR ID;
- `cityCode` / KLADR;
- `regionCode`;
- exact postal code or `indexMin` / `indexMax` range;
- city/place name.

When a future verified import/API flow selects a cityId, it should save the mapping so tariff stages can reuse it without repeating ambiguous lookup.

## Admin Diagnostics

The DPD География tab can diagnose one WDC location ID or save a manual DPD cityId mapping. Diagnostics report:

- whether cityId was found;
- source: stored mapping, geography API, FIAS fallback or manual admin save;
- whether mapping was saved;
- whether multiple DPD cities were present;
- whether duplicate resolver was applied.

Diagnostics do not call live DPD SOAP methods. They only check whether a mapping exists for the selected WDC location. If no mapping exists, the admin message asks to run DPD geography import, DaData fallback, or add the cityId manually.

Diagnostics do not calculate rates, do not create orders, do not import pickup points, do not mutate shipments and do not run cron jobs.

## Future Tariff Stage

Future tariff implementation should consume `DpdCityResolver` instead of adding carrier-specific branches to checkout or tariff services. At tariff stage, WDC must require an existing `dpd_city_id` mapping and must not silently call DPD geography APIs to guess a city.

Future tariff work should require an existing `dpd_city_id` mapping and must not perform city guessing in tariff services. Future automatic population can build on the staging step CSV importer with an explicit scheduled task, but 0.56.3 does not add cron or Action Scheduler jobs.
