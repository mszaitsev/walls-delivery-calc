# WDC DPD Tariff Calculation

Version: 0.57.0.

This stage implements the DPD tariff calculation foundation for admin diagnostics only. It verifies `getServiceCostByParcels3` request/response shape before any checkout runtime integration.

## Scope

- Low-level API wrapper: `DpdApiClient::getServiceCostByParcels3()`.
- SOAP service: `calculator2`, method `getServiceCostByParcels3`.
- Transport/auth: existing `DpdApiClient::call()`, `DpdSoapClientInterface`, `DpdSoapRequest`, `DpdSettings` credentials.
- Admin UI: `WDC -> Службы доставки -> DPD -> DPD Расчет`.
- Receiver city: `DpdCityResolver` reads `wdc_location_delivery_codes.dpd_city_id`.
- Sender city: explicit sender DPD cityId override first, then sender `location_id` through `DpdCityResolver`.

## Payload

`DpdTariffRequestBuilder` builds a payload without `auth`:

- `pickup.cityId`
- `delivery.cityId`
- `selfPickup`
- `selfDelivery`
- optional `serviceCode`
- optional `pickupDate`
- `declaredValue`
- `parcel[]` with `weight` in kg, `length`, `width`, `height` in cm, and `quantity`

`DpdSoapRequest::payload_with_auth()` adds `auth.clientNumber` and `auth.clientKey` centrally when the SOAP transport executes the request.

## Admin Calculator

The `DPD Расчет` tab stores:

- `dpd_tariff_sender_location_id`
- `dpd_tariff_sender_dpd_city_id`
- `dpd_tariff_sender_city_name`
- default weight, length, width, height
- default declared value
- last visible tariff action result

The test form accepts sender override, receiver `location_id`, parcel values, pickup/delivery mode and optional `serviceCode`. After POST it redirects back to the same tab and displays success/failure, raw count, normalized service list and, when DPD debug is enabled, raw payload/response.

## Normalization

`DpdTariffOptionNormalizer` accepts single-object, array and nested SOAP response shapes. It normalizes:

- `service_code`
- `service_name`
- `cost`
- `currency`
- `days`
- `delivery_period_min`
- `delivery_period_max`
- `pickup_date`
- `delivery_date`
- `self_pickup`
- `self_delivery`
- `raw`

Missing fields are not fatal.

## Error Handling

`DpdTariffCalculationService` returns controlled errors for missing sender or receiver city IDs. `DpdException`, including missing PHP SOAP extension and SOAP faults, is caught and stored as an admin-visible result instead of breaking the page.

## Not Implemented

- DPD checkout runtime carrier.
- DPD `CarrierShipmentAdapterRegistry` adapter.
- Pickup points / `getParcelShops` / maps.
- Order creation, cancellation, statuses, labels.
- COD / NPP.
- `unitLoad`.
- Fiscal receipts or receipt storage.
- Cron, Action Scheduler or automatic tariff sync.
- Writing DPD tariffs into delivery rates tables.

CDEK and Russian Post runtime behavior are unchanged.
