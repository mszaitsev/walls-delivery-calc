# WDC DPD Pickup Points

Version: 0.71.0.

0.71.0 update: DPD pickup points can now be refreshed automatically by WP-Cron. The DPD -> ПВЗ tab has a dedicated enable checkbox and three independent Moscow-time (GMT+3) 15-minute slots; cron calls the same `DpdPickupPointImportService::import_all()` path as the manual “Обновить все” button, stores context `auto_cron` in the last import report, and uses the same lock and safe no-wipe failure behavior. This is separate from DPD shipment status autosync. See `docs/wdc-dpd-pickup-autosync.md`.

0.62.0 update: `DpdPickupPointService` now provides runtime `parcel_shop` terminal selection for DPD Parcels3 pricing.
It uses only active `parcel_shop` rows, avoids duplicate `terminal_self_delivery` rows with the same `terminal_code`
when possible, and falls back to a duplicated `parcel_shop` when no unambiguous parcel shop exists.

0.60.3 update: `raw_json` is diagnostic/admin/internal storage only. It remains in `wdc_dpd_pickup_points` but is not
returned by checkout REST, not saved in checkout session snapshots and not saved to order meta. Checkout-facing DPD point
reads are deduplicated by `terminal_code` in `DpdPickupPointService`: `parcel_shop` wins over duplicate
`terminal_self_delivery`, while terminal-only rows remain available. Import behavior and stored supplementary terminal
rows are unchanged.

0.60.2 update: DPD schedules are normalized for display. `DpdPickupPointScheduleFormatter` accepts array, object, JSON
string and plain string values, chooses timetable operations by priority (`SelfDelivery`, `SelfPickup`, `Payment`,
`PaymentByBankCard`), and returns readable strings such as `Пн–Вс: 10:00–22:00`. REST and checkout summary layers also
format existing JSON schedule strings already stored in `wdc_dpd_pickup_points`; `raw_json` remains unchanged.

0.60.0 update: local DPD points are now used by checkout pickup selection through the shared pickup UI and REST
controllers. The selected active `terminal_code` is saved to checkout/order meta and now replaces the auto-selected
receiver terminalCode in DPD Parcels3 pickup pricing.

0.59.2 cleanup: the old full-source `mark_source_inactive()` helper was removed after safe-replace switched to
upserting the new valid set first and then inactivating only stale missing keys. Import behavior did not change.

## Scope

This stage stores local DPD pickup points / terminals, supplies them to checkout pickup selection, and supplies active
`parcel_shop` terminalCode values to DPD Parcels3 runtime pricing.

## DPD Methods Checked

Sources checked:

- `docs/dpd/ws-integration-guide.docx`;
- production `geography2?wsdl`;
- production `geography2?xsd=1`.

Methods checked:

- `geography2/getParcelShops`;
- `geography2/getTerminalsSelfDelivery2`.

`getParcelShops` is the primary source for DPD parcel shops / PVZ. WSDL shows request element `request` of type `dpdParcelShopRequest`, with `auth` plus optional filters: `countryCode`, `regionCode`, `cityCode`, `cityName`. The response returns `return.parcelShop[]`.

`getTerminalsSelfDelivery2` is the supplementary source for DPD self-delivery terminals. WSDL shows direct root-level `auth` and no country/region/city filters. The response returns `return.terminal[]`.

## Fields

`getParcelShops` rows expose:

- `code`: local terminal code equivalent for storage;
- `parcelShopType`, `state`;
- `address`;
- `brand`, `metro`, `clientDepartmentNum`;
- `geoCoordinates`;
- `limits`;
- `schedule`;
- `extraService`;
- `services`.

`getTerminalsSelfDelivery2` rows expose:

- `terminalCode`;
- `terminalName`;
- `address`;
- `geoCoordinates`;
- `schedule`;
- `extraService`;
- `services`.

Shared `address` fields include `cityId`, `countryCode`, `regionCode`, `regionName`, `cityCode`, `cityName`, `index`, `street`, `streetAbbr`, `houseNo`, `building`, `structure`, `ownership`, `descript`.

`geoCoordinates` contains `latitude` and `longitude`. `schedule` contains operation-level `timetable[]` with `weekDays` and `workTime`.

## Local Table

Migration `0031_create_dpd_pickup_points_table.php` creates `wdc_dpd_pickup_points`:

- `id`;
- `terminal_code`;
- `type`: `parcel_shop` or `terminal_self_delivery`;
- `country_code`, `region_code`, `region_name`;
- `city_id`, `city_code`, `city_name`;
- `address`, `name`;
- `latitude`, `longitude`;
- `schedule`, `raw_json`;
- `is_active`;
- `source`: `getParcelShops` or `getTerminalsSelfDelivery2`;
- `imported_at`, `updated_at`.

Indexes include unique `terminal_code,type`, city lookup indexes, country, coordinates, source and active flag.

This table is intentionally separate from `wdc_location_delivery_codes`. `wdc_location_delivery_codes` stores cityId mapping for WDC locations; `wdc_dpd_pickup_points` stores concrete DPD pickup/terminal rows.

## Import

`DpdPickupPointImportService` supports:

- `import_parcel_shops()`;
- `import_terminals_self_delivery()`;
- `import_all()`.

Each import fetches SOAP data and normalizes rows before storage changes. Replacement is safe:

- if `fetched_count = 0`, existing points are left unchanged;
- if `fetched_count > 0` but `normalized_count = 0`, existing points are left unchanged;
- only when `normalized_count > 0` does the repository update storage;
- successful storage first upserts the new valid points, then marks inactive only old active rows from the same `source` whose `terminal_code + type` is absent from the new valid set.

This prevents an empty or unrecognized DPD response from wiping the local working directory. The admin report uses explicit messages:

- `DPD pickup import returned no rows. Existing points were left unchanged.`
- `DPD pickup import returned rows, but no valid points were normalized. Existing points were left unchanged.`

The report contains `source`, `started_at`, `finished_at`, `fetched_count`, `normalized_count`, `saved_count`, `skipped_invalid`, `marked_inactive`, `errors`, `message`, `context` and `status`. The last report is stored in DPD settings as `dpd_last_pickup_import_report`.

## Admin

Open:

`WDC -> Службы доставки -> DPD -> DPD ПВЗ`

The tab shows total active points, counts for `getParcelShops` and `getTerminalsSelfDelivery2`, last import date/source/result, manual import buttons, DPD pickup autosync settings and diagnostics.

Diagnostics can search by:

- `terminalCode`;
- `cityId`;
- `cityName`.

The first 20 matching active rows are rendered in the admin table.

## Checkout And Runtime Service

`DpdPickupPointService` is read-only for checkout and runtime pricing:

- `get_points_for_location_id(int $location_id)`;
- `get_points_by_city_id(int $city_id)`;
- `get_point_by_terminal_code(string $terminal_code)`.
- `find_runtime_parcel_shop_for_city_id(int $city_id)`;
- `find_runtime_parcel_shop_by_terminal_code(string $terminal_code, ?int $city_id = null)`;
- `find_runtime_parcel_shop_for_location_id(int $location_id)`.

`get_points_for_location_id()` resolves `wdc_location_delivery_codes.dpd_city_id` through `LocationDeliveryCodeRepository` and then reads local points by `city_id`.
The service returns consumer-facing points: rows with the same `terminal_code` are grouped, `parcel_shop` is preferred,
and a `terminal_self_delivery` row is returned only when no parcel shop exists for that code. Runtime terminal selection
uses only `parcel_shop` rows and never uses a standalone `terminal_self_delivery` as a pricing terminal.

The service is connected to the checkout pickup selection layer, but `raw_json` remains diagnostic-only and is not exposed
in checkout REST, checkout session snapshots or order meta.

## TerminalCode Pricing

Current checkout pricing uses:

- `calculator2/getServiceCostByParcels3`;
- sender `pickup.terminalCode` from a sender-city `parcel_shop`;
- receiver `delivery.terminalCode` from a buyer-selected or auto-selected receiver-city `parcel_shop` for pickup;
- no `delivery.terminalCode` for courier;
- `parcel[]` as packaging places.

Do not switch to `getServiceCost3` by default because it does not match the current `parcel[]` package-place model.
