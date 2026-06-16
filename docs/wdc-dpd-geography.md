# WDC DPD Geography

Version: 0.56.1.

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
- `DpdGeographyCsvParser`, `DpdGeographyMatcher` and `DpdGeographyImportService` stream-parse, match and save DPD city mappings.
- `DpdDaDataDeliveryFallbackService` uses the shared DaData token pool for an admin-triggered single-location fallback.

## Live API Note

Live API city lookup is disabled in `DpdCityResolver`.

`getPossibleExtraService` is not used as the primary city resolver. DPD guide describes it as a method for possible services and its input is a fuller address/service request, not a sparse city lookup. A live diagnostic with only WDC location fields returned a SOAP `java.lang.NullPointerException`, so using it for city lookup is unsafe.

`getCitiesCashPay(countryCode)` is also not used by the resolver. The guide documents it as returning a `city` list, but live test and production checks returned `java.lang.NullPointerException` even for `countryCode`, so WDC cannot treat it as a reliable primary city lookup without a confirmed DPD contract or an imported geography directory.

The current supported primary path is DPD `GeographyNewDPD` CSV import into `wdc_location_delivery_codes.dpd_city_id`. Manual mapping remains the last fallback. `DpdApiClient::getCitiesCashPay()` and `DpdApiClient::getPossibleExtraService()` remain low-level wrappers for future verified work, but admin geography diagnostics and `DpdCityResolver` do not call them.

## GeographyNewDPD Import

The DPD География tab supports two admin-only import paths:

- SFTP download from configured host/port/username/password/directory. Defaults are `ftp.dpd.ru`, port `22`, username `integration`, directory `/integration`; the password default is empty and is stored encrypted when entered.
- Manual upload of a `.csv` file. The uploaded temporary file is parsed as a stream and removed after processing.

The importer reads `;`-delimited CSV row by row, supports UTF-8 and Windows-1251, detects the header row when present, and maps the documented first columns: DPD city ID, country code, region, district, main city, settlement, settlement type, postal code, FIAS and KLADR. Only `Код страны = RU` rows are imported. Postal codes, services/options, terminal data, schedules, raw rows and per-row diagnostics are not stored.

Matching is conservative:

- FIAS exact match checks DPD `ФИАС` against `wdc_locations.fias_id` and `city_fias_id`.
- KLADR matching normalizes DPD codes such as `RU54000001000` and compares exact/padded/trimmed numeric variants against `kladr_id` and `city_kladr_id`.
- Name fallback runs only when FIAS/KLADR fail and saves only a single confident candidate by settlement name, region, district and type.

Duplicate rows with the same DPD city ID for one location are idempotent. Conflicting DPD city IDs for one `location_id` are counted as conflicts and are not overwritten. Ambiguous name matches are counted and not saved. The last import report is stored in DPD settings under `dpd_last_geography_import_report`.

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

Future tariff work should require an existing `dpd_city_id` mapping and must not perform city guessing in tariff services. Future automatic population can build on the CSV importer with an explicit scheduled task, but 0.56.1 does not add cron or Action Scheduler jobs.
