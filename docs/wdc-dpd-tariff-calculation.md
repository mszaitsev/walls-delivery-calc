# WDC DPD Tariff Calculation

Version: 0.57.1.

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

`DpdSoapRequest::payload_with_auth()` adds `auth.clientNumber` and `auth.clientKey` centrally when the SOAP transport executes the request. `calculator2/getServiceCostByParcels3` uses the explicit `request` wrapper strategy, so the SOAP argument shape is:

```php
array(
    'request' => array(
        'auth' => array(
            'clientNumber' => '...',
            'clientKey' => '...',
        ),
        'pickup' => array( 'cityId' => '...' ),
        'delivery' => array( 'cityId' => '...' ),
        // other business fields...
    ),
)
```

Geography methods such as `getCitiesCashPay` and `getPossibleExtraService` keep the direct payload shape with root-level `auth`.

## Admin Calculator

The `DPD Расчет` tab stores:

- `dpd_tariff_sender_location_id`
- `dpd_tariff_sender_dpd_city_id`
- `dpd_tariff_sender_city_name`
- default weight, length, width, height
- default declared value
- last visible tariff action result

The test form accepts sender override, receiver `location_id`, parcel values, pickup/delivery mode and optional `serviceCode`. After POST it redirects back to the same tab and displays success/failure, raw count, normalized service list and, when DPD debug is enabled, the business payload plus redacted SOAP payload shape metadata. The action result block is one-shot: after it renders, `clear_tariff_action_result()` removes it from settings so a normal page reload does not repeat the notice.

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

`DpdTariffCalculationService` returns controlled errors for incomplete active-environment credentials, missing sender city ID or missing receiver city ID. Incomplete credentials return `DPD credentials are incomplete for current environment.` before any SOAP call. `DpdException`, including missing PHP SOAP extension and SOAP faults, is caught and stored as an admin-visible result instead of breaking the page.

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
