# WDC DPD Integration

## Scope

Version 0.57.0 keeps the DPD integration limited to foundation, geography and admin-only tariff diagnostics. The built-in delivery service uses:

- `service_key`: `dpd`
- `carrier_key`: `dpd`
- title: `DPD`
- default state: disabled

DPD is not registered as a checkout runtime quote carrier and is not registered in `CarrierShipmentAdapterRegistry`. Enabling the service row alone does not produce checkout rates because there is no DPD carrier adapter in `CarrierRegistry`.

Current stages intentionally do not implement DPD checkout rates, pickup points, order creation, cancellation, tracing/statuses, labels, COD, `unitLoad`, fiscal receipts, or receipt storage. DPD city FTP/SFTP/manual CSV import and tariff calculation are admin-only preparation/diagnostics and do not register runtime carrier behavior.

As of 0.56.3, DPD geography import is a stateful staging process. SFTP/manual CSV actions create an admin import job, build a `DpdLocationIndex` from active RU `wdc_locations`, create a per-job `wdc_dpd_geography_stage_<job_hash>` table, and process rows through AJAX polling with visual progress. The importer avoids SQL lookup per CSV row, does not write to `wdc_location_delivery_codes` during steps, and finalizes candidates into the working table only after EOF. Import state is stored in `wdc_dpd_geography_import_state`; the final report remains in DPD settings.

## DpdSoapClient Architecture

DPD integration must go through `src/Carriers/Dpd/DpdSoapClientInterface`. The public transport boundary is intentionally narrow:

```php
call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse
```

`DpdApiClient` owns the higher-level API boundary and receives a replaceable `DpdSoapClientInterface`. It does not depend directly on PHP `SoapClient`, `wp_remote_post`, or a concrete HTTP transport. `DpdSoapClient` is only the current concrete SOAP transport wrapper.

Authentication is added through `DpdSoapRequest::payload_with_auth()` as:

- `auth.clientNumber`
- `auth.clientKey`

Secrets must not be logged as plaintext. `DpdSettings::save_connection_result()` redacts `clientNumber`, `clientKey`, token/secret-like fragments, and saved diagnostics. `LogRedactor` also treats `clientKey` / `client_key` as sensitive keys.

Transport-level failures are normalized as `DpdException`: missing SOAP extension, SOAP faults, generic transport errors, timeout-related transport failures, and empty/malformed responses.

## Service Areas

DPD guide v1.44 describes these planned service areas:

- `geography2`: geography, terminals, possible extra services, storage periods.
- `calculator2`: tariff calculation.
- `order2`: order creation and order creation status.
- `tracing`, `tracing1-1`, `event-tracking`: status lookup and event feed.
- `label-print`: label file and parcel label generation.
- `delivery-management`: delivery changes and receiver-side cancellation flow from the guide's management section.
- Reports and receipt storage: out of current scope.

## Endpoints

`DpdEndpoints` selects WSDL URLs by environment:

- test base: `https://wstest.dpd.ru/services/`
- production base: `https://ws.dpd.ru/services/`

Current endpoint keys:

- `geography2`
- `calculator2`
- `order2`
- `tracing`
- `tracing1-1`
- `event-tracking`
- `label-print`
- `delivery-management`

The stage 1 smoke test verifies test/production URL selection only. Runtime methods are not called.

## Admin Tariff Calculation

As of 0.57.0, `DpdApiClient::getServiceCostByParcels3()` calls `getServiceCostByParcels3` on `calculator2` through the same `DpdApiClient::call()` path as other low-level DPD wrappers. `DpdSoapRequest` adds `auth`; the tariff builder never inserts credentials.

The `DPD Расчет` tab is admin-only. It stores sender/default parcel settings in `DpdSettings`, resolves sender `cityId` from explicit `dpd_tariff_sender_dpd_city_id` first and then from `dpd_tariff_sender_location_id` via `DpdCityResolver`, resolves receiver `location_id` via `wdc_location_delivery_codes.dpd_city_id`, and displays a visible result after redirect. It does not create checkout rates, write tariff rows, create shipments, or mutate orders.

## Credentials And Diagnostics

DPD credentials are stored in the existing settings/encryption layer:

- `dpd_environment`: `test` or `production`
- `dpd_test_client_number`
- `dpd_test_client_key_encrypted`
- `dpd_production_client_number`
- `dpd_production_client_key_encrypted`
- `dpd_request_timeout`
- `dpd_debug`
- last diagnostic timestamp/status/message

`clientKey` is never stored plaintext. The admin password field is write-only: an empty value keeps the encrypted secret, and a clear checkbox removes it.

The stage 1 connection check is a dry diagnostic. It checks credentials completeness, endpoint selection, and SOAP transport availability, then clearly reports that no DPD API call was executed. It does not create orders, calculate checkout rates, import pickup points, import statuses, write DPD external data, or mutate orders/shipments.

## CityId Strategy

No static DPD city table is implemented. DPD city mappings are imported into the existing 1:1 delivery-code table.

The existing WDC/FIAS/GAR settlement model stores settlement identity in `wdc_locations`. DPD `cityId` values are stored in the 1:1 delivery-code table:

- table: `wdc_location_delivery_codes`
- key: `location_id`
- value: nullable `dpd_city_id`
- timestamp: nullable `updated_at`

The cancelled `wdc_location_carrier_codes` storage is no longer created or used.

Implemented `DpdCityResolver` strategy:

- primary source: already saved `dpd_city_id` linked to the WDC/FIAS/GAR settlement by `location_id`;
- if mapping is missing, return an import/DaData/manual-mapping-required diagnostic and do not call live DPD SOAP;
- `getCitiesCashPay` and `getPossibleExtraService` remain optional low-level wrappers only. They are not used by `DpdCityResolver` automatically because live DPD test/production checks returned `java.lang.NullPointerException` for sparse city lookup attempts;
- future imported/API candidate matching should use `DpdDuplicateCityResolver`, matching FIAS/GAR guid, `countryCode`, `regionCode`, `indexMin`/`indexMax`, postal code, city name, and city code/KLADR where available;
- after a future verified match/import, persist `dpd_city_id` and `updated_at` so later calculations do not repeat ambiguous lookup;
- use `GeographyNewDPD_YYYY_MM_DD.csv` as the current admin import source, never as a runtime dependency.

The DPD География tab contains admin-only geography diagnostics/manual mapping, SFTP/manual CSV import, last import report, and single-location DaData fallback. The current diagnostic checks only whether a cityId mapping exists and does not run a live SOAP call. No mass DaData enrichment is started automatically.

SFTP settings default to `ftp.dpd.ru`, port `22`, username `integration`, and `/integration`; the password default is empty and stored encrypted when entered. If PHP `ssh2` is unavailable, the admin action safely instructs the manager to upload `GeographyNewDPD` manually. Future cron import, if needed, must be a separate task with no hardcoded credentials, audit/logging, and safe rollback.

## Status Strategy

DPD statuses are not implemented in stage 1.

The guide states that `getEvents` requires additional DPD setup and works through `confirm`. After confirmation, events are deleted on the DPD side. DPD stores statuses for a limited period of 90 days.

For WDC this is acceptable because the order shipment record will store the latest received status and timestamp. Detailed DPD status history is out of scope. It is enough to persist the terminal status Delivered / `Доставлено` with transition time. After a terminal status, autosync for that shipment must stop.

Future implementation can start with per-order status refresh, then add a separate `getEvents` + `confirm` feed stage.

## UnitLoad, COD, Receipts

COD / наложенный платеж DPD is out of scope for WDC.

The future DPD shipment builder should keep an extension point for `unitLoad`, but stage 1 does not add `unitLoad` fields to order meta and does not change shipment payloads.

Basket composition should be sent to DPD only if it is required for declared value, insurance, fiscalization, or another mandatory DPD option. The final decision belongs to the future tariff/order creation stage after checking DPD declared value / insurance requirements.

Fiscal receipts and DPD receipt storage are not implemented.

## Not Implemented In Current Stage

- checkout runtime DPD rates;
- DPD pickup points / parcel shops / maps;
- DPD order creation, cancellation, statuses, labels;
- COD / NPP;
- `unitLoad`;
- fiscal receipts / receipt storage;
- DPD shipment adapter registration.
