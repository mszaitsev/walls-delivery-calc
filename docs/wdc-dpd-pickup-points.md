# WDC DPD Pickup Points

Version: 0.59.0.

## Scope

This stage adds a local DPD pickup points / terminals foundation for future checkout map work. It does not connect DPD pickup selection to checkout runtime and does not change tariff calculation.

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

Each import fetches SOAP data, normalizes rows, then calls `DpdPickupPointRepository::replace_all_for_source()`. Existing rows for that source are marked inactive before valid new rows are upserted, so a failed fetch/normalization does not clear the working table.

The report contains `source`, `started_at`, `finished_at`, `fetched_count`, `normalized_count`, `saved_count`, `skipped_invalid`, `errors` and `message`. The last report is stored in DPD settings as `dpd_last_pickup_import_report`.

## Admin

Open:

`WDC -> Службы доставки -> DPD -> DPD ПВЗ`

The tab shows total active points, counts for `getParcelShops` and `getTerminalsSelfDelivery2`, last import date/result, manual import buttons and diagnostics.

Diagnostics can search by:

- `terminalCode`;
- `cityId`;
- `cityName`.

The first 20 matching active rows are rendered in the admin table.

## Future Checkout Service

`DpdPickupPointService` is read-only and prepared for the next map stage:

- `get_points_for_location_id(int $location_id)`;
- `get_points_by_city_id(int $city_id)`;
- `get_point_by_terminal_code(string $terminal_code)`.

`get_points_for_location_id()` resolves `wdc_location_delivery_codes.dpd_city_id` through `LocationDeliveryCodeRepository` and then reads local points by `city_id`.

The service is not connected to the checkout UI, public pickup REST endpoint, WooCommerce session or order metadata in this stage.

## TerminalCode Pricing Debt

Current checkout pricing remains:

- `calculator2/getServiceCostByParcels2`;
- DPD cityId only;
- `selfPickup` / `selfDelivery`;
- `parcel[]` as packaging places.

After the checkout map and selected terminal storage are implemented:

- obtain selected `delivery.terminalCode`;
- define sender `pickup.terminalCode`;
- test `calculator2/getServiceCostByParcels3` with `parcel[]`, `pickup.terminalCode` and `delivery.terminalCode`;
- compare prices with the DPD cabinet;
- only then switch runtime pricing.

Do not switch to `getServiceCost3` by default because it does not match the current `parcel[]` package-place model.
