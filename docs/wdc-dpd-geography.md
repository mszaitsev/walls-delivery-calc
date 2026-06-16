# WDC DPD Geography

Version: 0.55.0.

## Scope

This stage implements only the DPD geography layer needed before tariff work. It resolves and stores DPD `cityId` for existing WDC/FIAS/GAR locations and adds admin diagnostics/manual mapping.

It does not implement DPD tariffs, `getServiceCost*`, checkout integration, pickup points, `getParcelShops`, order creation, cancellation, statuses, labels, FTP import, cron jobs, Action Scheduler jobs, COD, `unitLoad` or fiscal receipts.

## Storage

DPD city IDs are stored in the existing shared table:

- table: `wdc_location_carrier_codes`
- `carrier_key`: `dpd`
- `external_code`: DPD `cityId`
- location link: `location_id`, `gar_object_id`, `fias_id`
- `meta`: source and matching diagnostics

No DPD-specific city table is created.

## Components

- `LocationCarrierCodeRepository` is the carrier-neutral repository for `wdc_location_carrier_codes`.
- `DpdCityResolver` resolves cityId from stored mapping, DPD geography API, then FIAS-only fallback.
- `DpdDuplicateCityResolver` handles DPD `pickupDups` and `deliveryDups` arrays.
- `DpdApiClient::getCitiesCashPay()` and `DpdApiClient::getPossibleExtraService()` are API wrappers only.
- `DpdGeographyDiagnosticService` supports admin diagnostics and manual mapping for a single existing location.

## Duplicate City Matching

`DpdDuplicateCityResolver` is isolated from future tariff services. It scores candidates by:

- FIAS GUID;
- GAR ID;
- `cityCode` / KLADR;
- `regionCode`;
- exact postal code or `indexMin` / `indexMax` range;
- city/place name.

The selected cityId is saved so future tariff stages can reuse the mapping without repeating ambiguous lookup.

## Admin Diagnostics

The DPD service settings tab can diagnose one WDC location ID or save a manual DPD cityId mapping. Diagnostics report:

- whether cityId was found;
- source: stored mapping, geography API, FIAS fallback or manual admin save;
- whether mapping was saved;
- whether multiple DPD cities were present;
- whether duplicate resolver was applied.

Diagnostics do not calculate rates, do not create orders, do not import pickup points, do not mutate shipments, do not run cron jobs and do not call FTP.

## Future Tariff Stage

Future tariff implementation should consume `DpdCityResolver` instead of adding carrier-specific branches to checkout or tariff services. If DPD returns duplicate-city data during tariff calls, that handling should remain in `DpdDuplicateCityResolver`.

FTP geography files may be considered only as an optional future import, with explicit manual/WP-Cron scheduling no more often than once every six months, no hardcoded credentials, audit logging and rollback.
