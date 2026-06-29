# WDC DPD Tariff Calculation

Version: 0.103.1.

This document covers the DPD tariff calculation foundation used by checkout runtime. As of 0.62.0, checkout runtime uses
`calculator2/getServiceCostByParcels3` with terminalCode. As of 0.62.1, the remaining admin test calculator UI was
removed from `DPD Расчет`; that tab is settings-only. As of 0.103.1, package-place building is provided by the shared `src/Packaging/PackagingBuilder.php` layer. The shared default config comes from `PackagingBuilderConfig::defaults()` instead of `DpdSettings`; DPD passes a separate legacy `PackagingBuilderConfig(1000, 20, 20, 20, 1000)` so its fallback payload remains unchanged, and adapts neutral `PackagingParcel` objects from `PackagingResult::parcels()` to `DpdTariffParcel` before payload creation.

## Scope

- Legacy low-level API wrapper: `DpdApiClient::getServiceCostByParcels2()`.
- Runtime low-level API wrapper: `DpdApiClient::getServiceCostByParcels3()`.
- SOAP service: `calculator2`, method `getServiceCostByParcels3`.
- Transport/auth: existing `DpdApiClient::call()`, `DpdSoapClientInterface`, `DpdSoapRequest`, `DpdSettings` credentials.
- Admin UI: `WDC -> Службы доставки -> DPD -> DPD Расчет`.
- Receiver city: `DpdCityResolver` reads `wdc_location_delivery_codes.dpd_city_id`.
- Sender city: explicit sender DPD cityId override first, then sender `location_id` through `DpdCityResolver`.

## Payload

`DpdTerminalCodeTariffRequestBuilder` builds the runtime payload without `auth`:

- `pickup.cityId`
- `pickup.terminalCode`
- `delivery.cityId`
- `delivery.terminalCode` for pickup delivery only
- `selfPickup`
- `selfDelivery`
- optional `serviceCode`
- optional `pickupDate`
- `declaredValue`
- `parcel[]` with `weight` in kg, `length`, `width`, `height` in cm, and `quantity`

`parcel[]` represents packaging places, not cart items. The checkout runtime uses the shared `PackagingBuilder::build(QuoteRequest): PackagingResult` API to expand product quantities, split long items with any side over 49 cm into separate parcels, aggregate <=50 cm3 small items into one synthetic volume block, optimize identical groups into grid blocks, and pack regular units with a bounded deterministic 3D shelf/bin packer. The packer supports `box_50_50_30` and `box_40_40_40`, sends actual occupied dimensions, attempts one box and then two boxes, and falls back to stacked rows. Package-level dimensions are used only when item dimensions are missing, and the builder config dimensions are the final fallback. DPD converts each neutral `PackagingParcel` to `DpdTariffParcel`; `DpdTariffCalculationService` can still accept explicit DPD parcels/array parcels for smoke tests/future admin tooling.

`DpdSoapRequest::payload_with_auth()` adds `auth.clientNumber` and `auth.clientKey` centrally when the SOAP transport executes the request. `calculator2/getServiceCostByParcels3` uses the explicit `request` wrapper strategy, so the SOAP argument shape is:

```php
array(
    'request' => array(
        'auth' => array(
            'clientNumber' => '...',
            'clientKey' => '...',
        ),
        'pickup' => array( 'cityId' => '...', 'terminalCode' => '...' ),
        'delivery' => array( 'cityId' => '...', 'terminalCode' => '...' ),
        // other business fields...
    ),
)
```

Geography methods such as `getCitiesCashPay` and `getPossibleExtraService` keep the direct payload shape with root-level `auth`.

## Admin Settings

The `DPD Расчет` tab stores:

- `dpd_tariff_sender_location_id`
- `dpd_tariff_sender_dpd_city_id`
- default weight, length, width, height
- default declared value

The tab no longer contains a manual `Тестовый расчет DPD` form or tariff result block. Future manual calculators should
be added as a separate admin tool, not inside these runtime settings.

The old display-only sender city text field was removed. Sender display now comes from the saved `sender_location_id`
through `LocationRepository::find_by_id()` and `resolved_display_name()`. The read-only sender summary shows that display
name plus the configured sender DPD cityId override, or the DPD cityId resolved from `DpdCityResolver` when the override is
empty.

Checkout runtime settings no longer live on `DPD Расчет`. Method titles are edited on `Основное`, while DPD service-code enablement, custom tariff titles and the `Использовать курьерские тарифы` checkbox are edited on the DPD `Тарифы` tab. Checkout runtime mode flags are not configurable: runtime always sends from a DPD terminal, and courier delivery is calculated by a separate request only when enabled.

Runtime pricing now uses `calculator2/getServiceCostByParcels3`. In checkout, pickup rates send
`selfPickup=true/selfDelivery=true` plus sender and receiver terminalCode. Courier rates send
`selfPickup=true/selfDelivery=false` plus sender terminalCode only. `getServiceCost3` is not used because it does not
match the current `parcel[]` packaging-place model.

## getServiceCostByParcels3 WSDL Notes

`docs/dpd/ws-integration-guide.docx` contains section `2.5.5. Параметры входного сообщения для getServiceCostByParcels3`. The calculator request supports the same package-place `parcel[]` model, `declaredValue`, `selfPickup`, `selfDelivery`, optional `serviceCode` and optional `pickupDate`. The guide lists `terminalCode` under both pickup and delivery city blocks alongside `cityId`, `cityName`, `regionCode`, `countryCode` and `guidFIAS`. `DpdApiClient::getServiceCostByParcels3()` calls `calculator2` with wrapper `request`; auth is placed under `request.auth` by `DpdSoapRequest`.

`extraService` / option types exist in the DPD guide, but WDC does not include them in runtime terminalCode payloads.

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

`DpdTariffCalculationService` returns controlled errors for incomplete active-environment credentials, missing sender city ID or missing receiver city ID. Incomplete credentials return `DPD credentials are incomplete for current environment.` before any SOAP call. `DpdException`, including missing PHP SOAP extension and SOAP faults, is caught and returned in the calculation result instead of breaking checkout.

## Not Implemented

- DPD `CarrierShipmentAdapterRegistry` adapter.
- Checkout pickup map/selection.
- DPD shipment creation and terminalCode-aware shipment requests.
- Order creation, cancellation, statuses, labels.
- COD / NPP.
- `unitLoad`.
- Fiscal receipts or receipt storage.
- Complex multi-box/bin packing.
- Cron, Action Scheduler or automatic tariff sync.
- Writing DPD tariffs into delivery rates tables.

CDEK and Russian Post runtime behavior are unchanged.
