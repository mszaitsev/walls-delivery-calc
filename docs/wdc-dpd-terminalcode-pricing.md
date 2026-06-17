# WDC DPD TerminalCode Pricing Diagnostics

Version: 0.61.0.

This stage adds an admin-only diagnostic foundation for checking DPD terminalCode-aware pricing through
`calculator2/getServiceCostByParcels3`. Checkout runtime pricing is not switched: `DpdTariffCalculationService` and
`DpdQuoteCarrier` still use `getServiceCostByParcels2` with cityId/selfPickup/selfDelivery and no terminalCode.

## WSDL And Docs

Checked sources:

- `docs/dpd/ws-integration-guide.docx`;
- existing `calculator2` wrapper behavior in `DpdApiClient` / `DpdSoapRequest`.

The guide contains section `2.5.5. Параметры входного сообщения для getServiceCostByParcels3`. The request supports:

- `pickup.cityId`;
- optional `pickup.terminalCode`;
- `delivery.cityId`;
- `delivery.terminalCode`;
- `selfPickup`;
- `selfDelivery`;
- optional `serviceCode`;
- optional `pickupDate`;
- `declaredValue`;
- `parcel[]` with package-place dimensions and weight.

The guide also documents `extraService` / option types, but this diagnostic does not send `extraService`. The business
payload is built without auth; `DpdSoapRequest` adds `request.auth` centrally. `DpdApiClient::getServiceCostByParcels3()`
calls `calculator2/getServiceCostByParcels3` with wrapper `request`.

## Diagnostic Service

Implemented classes:

- `DpdTerminalCodeTariffDiagnosticRequest`;
- `DpdTerminalCodeTariffDiagnosticRequestBuilder`;
- `DpdTerminalCodeTariffDiagnosticResult`;
- `DpdTerminalCodeTariffDiagnosticService`.

The service builds a Parcels3 payload with terminalCode, calls `getServiceCostByParcels3`, then builds a Parcels2
comparison payload by removing `pickup.terminalCode` and `delivery.terminalCode` and calls `getServiceCostByParcels2`.
The normalized admin result includes Parcels3 options, Parcels2 options, and a comparison table by `serviceCode`:
Parcels2 cost, Parcels3 terminalCode cost and delta.

## Diagnostic Parcel Shop Selector

`DpdPickupPointService::find_diagnostic_parcel_shop_for_city_id()` and
`find_diagnostic_parcel_shop_for_location_id()` select a deterministic test terminalCode for admin diagnostics. The
location method resolves `location_id -> wdc_location_delivery_codes.dpd_city_id` first.

- only active `parcel_shop` rows are eligible;
- standalone `terminal_self_delivery` rows are never selected;
- a `parcel_shop` with no active same-city `terminal_self_delivery` duplicate is preferred;
- if the only available parcel shop has a duplicate terminal row with the same terminalCode, it may be used as a flagged
  fallback;
- if multiple parcel shops exist and all are duplicated by terminal rows, no automatic candidate is selected and the
  admin must enter terminalCode manually.

This selector is diagnostic-only. Checkout consumer point deduplication still prefers `parcel_shop` for duplicate
terminalCode rows.

## Admin UI

Open:

`WDC -> Службы доставки -> DPD -> DPD Расчет`

The block `DPD terminalCode диагностика` accepts pickup cityId, optional pickup terminalCode, receiver location_id or
delivery cityId, delivery terminalCode, parcel fields, declared value, pickup/delivery modes and optional `serviceCode`.
The `Подобрать тестовый parcel_shop` action uses the local `wdc_dpd_pickup_points` table and the selector above.

The result is stored through the existing one-shot DPD tariff action result. When DPD debug is enabled, raw/debug
payload and response data are visible only in the admin result block.

## Runtime Boundary

Not changed in 0.61.0:

- checkout runtime pricing;
- `DpdTariffCalculationService::calculate()`;
- `DpdQuoteCarrier`;
- runtime cache keys;
- selected terminalCode handling in checkout;
- DPD pickup import;
- DPD shipment adapter/metabox.

The next decision depends on comparing Parcels3 diagnostic prices with the DPD personal cabinet. Runtime should only
move to terminalCode-aware pricing after that comparison is accepted.
