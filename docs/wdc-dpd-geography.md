# WDC DPD Geography

Version: 0.56.0.

## Scope

This stage implements only a production-safe DPD geography foundation needed before tariff work. It reads and stores DPD `cityId` mappings for existing WDC/FIAS/GAR locations and adds admin diagnostics/manual mapping.

It does not implement DPD tariffs, `getServiceCost*`, checkout integration, pickup points, `getParcelShops`, order creation, cancellation, statuses, labels, FTP import, cron jobs, Action Scheduler jobs, COD, `unitLoad` or fiscal receipts.

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

## Live API Note

Live API city lookup is disabled in `DpdCityResolver`.

`getPossibleExtraService` is not used as the primary city resolver. DPD guide describes it as a method for possible services and its input is a fuller address/service request, not a sparse city lookup. A live diagnostic with only WDC location fields returned a SOAP `java.lang.NullPointerException`, so using it for city lookup is unsafe.

`getCitiesCashPay(countryCode)` is also not used by the resolver. The guide documents it as returning a `city` list, but live test and production checks returned `java.lang.NullPointerException` even for `countryCode`, so WDC cannot treat it as a reliable primary city lookup without a confirmed DPD contract or an imported geography directory.

The current supported path is manual mapping into `wdc_location_delivery_codes.dpd_city_id`. `DpdApiClient::getCitiesCashPay()` and `DpdApiClient::getPossibleExtraService()` remain low-level wrappers for future verified work, but admin geography diagnostics do not call them.

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

The DPD service settings tab can diagnose one WDC location ID or save a manual DPD cityId mapping. Diagnostics report:

- whether cityId was found;
- source: stored mapping, geography API, FIAS fallback or manual admin save;
- whether mapping was saved;
- whether multiple DPD cities were present;
- whether duplicate resolver was applied.

Diagnostics do not call live DPD SOAP methods. They only check whether a mapping exists for the selected WDC location. If no mapping exists, the admin message is `DPD cityId mapping was not found for location_id=<id>. Add cityId manually.`

Diagnostics do not calculate rates, do not create orders, do not import pickup points, do not mutate shipments, do not run cron jobs and do not call FTP.

## Future Tariff Stage

Future tariff implementation should consume `DpdCityResolver` instead of adding carrier-specific branches to checkout or tariff services. At tariff stage, WDC must require an existing `dpd_city_id` mapping and must not silently call DPD geography APIs to guess a city.

Future automatic population requires either a confirmed correct DPD API contract/response shape or a separate FTP/manual CSV geography import into `wdc_location_delivery_codes`. FTP geography files may be considered only as an optional future import, with explicit manual/WP-Cron scheduling no more often than once every six months, no hardcoded credentials, audit logging and rollback.
