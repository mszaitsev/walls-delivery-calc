# WDC DPD Integration

## Scope

Version 0.55.0 keeps the DPD integration limited to foundation plus geography. The built-in delivery service uses:

- `service_key`: `dpd`
- `carrier_key`: `dpd`
- title: `DPD`
- default state: disabled

DPD is not registered as a checkout runtime quote carrier and is not registered in `CarrierShipmentAdapterRegistry`. Enabling the service row alone does not produce checkout rates because there is no DPD carrier adapter in `CarrierRegistry`.

Stages 1-2 intentionally do not implement DPD tariffs, checkout rates, pickup points, city FTP import, order creation, cancellation, tracing/statuses, labels, COD, `unitLoad`, fiscal receipts, or receipt storage.

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

No static DPD city table and no FTP import are implemented in stage 1 or stage 2.

The existing WDC/FIAS/GAR settlement model already has `wdc_locations` and the foundation table `wdc_location_carrier_codes`. DPD `cityId` values are stored as carrier mappings there:

- `carrier_key = dpd`
- `external_code = <dpd_city_id>`
- `location_id` / `gar_object_id` / `fias_id` linked to the WDC location
- `meta` for DPD-specific matching evidence such as source, duplicate matching fields and resolver status

Implemented `DpdCityResolver` strategy:

- primary source: already saved `dpd_city_id` linked to the WDC/FIAS/GAR settlement;
- secondary source: DPD geography API wrapper `getCitiesCashPay(countryCode)`, matching the returned city list;
- `getPossibleExtraService` remains an optional future wrapper and is not used as primary city lookup because DPD expects a fuller possible-services payload and sparse live diagnostics can fail with SOAP errors;
- handle city-list duplicates and future `too-many-rows` data via `DpdDuplicateCityResolver`, matching FIAS/GAR guid, `regionCode`, `indexMin`/`indexMax`, postal code, city name, and city code/KLADR where available;
- after a match, persist the mapping so later calculations do not repeat ambiguous lookup;
- use FTP files `GeographyDPD_YYYYMMDD` and `GeographyNewDPD_YYYYMMDD` only as optional future import data, not as a runtime dependency.

Stage 2 also adds admin-only geography diagnostics/manual mapping in the DPD settings tab. Diagnostics report whether a cityId was found, the source, whether mapping was saved, whether duplicate rows were present, and whether the duplicate resolver was applied. DPD API/transport exceptions are caught and saved as non-fatal diagnostic messages. No mass enrichment is started automatically.

If FTP import becomes necessary, it must be a separate task with manual run or WP-Cron no more often than once every 6 months, no hardcoded FTP credentials, audit/logging, and safe rollback.

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

## Not Implemented In Stage 1

- tariff calculation, including `getServiceCostByParcels3`;
- checkout runtime DPD rates;
- DPD pickup points / parcel shops / maps;
- DPD city FTP import;
- DPD order creation, cancellation, statuses, labels;
- COD / NPP;
- `unitLoad`;
- fiscal receipts / receipt storage;
- DPD shipment adapter registration.
