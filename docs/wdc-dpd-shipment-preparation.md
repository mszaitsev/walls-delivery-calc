# WDC DPD Shipment Preparation

Version: 0.63.0.

DPD shipment preparation is manual-only in 0.63.0. The manager opens the order `Отправления` block, reviews saved DPD
delivery data, enters cargo places manually and clicks `Предпросмотр payload`. WDC builds a dry-run preview shaped for the
future DPD `order2/createOrder` request. No live DPD create API call is made.

DPD auto shipment creation will not be implemented. The future live step, if added, must be an explicit manual button in
the same modal after the dry-run payload has been verified.

## Existing Order Data

Checkout already saves enough DPD data to prepare a dry-run payload:

- `_wdc_platform_carrier_key`, `_wdc_platform_service_key`, `_wdc_platform_rate_key`, `_wdc_platform_delivery_type`,
  `_wdc_platform_tariff_key`, `_wdc_platform_tariff_title`, `_wdc_platform_rate_price`;
- `_wdc_platform_rate_meta`, including selected tariff object/title, DPD `serviceCode`, pickup/delivery city IDs,
  sender pickup terminalCode, delivery terminalCode for pickup delivery and sanitized runtime request diagnostics;
- `_wdc_delivery_calculation_data`, including DPD API/rate details captured by the delivery calculator block;
- canonical pickup meta `_wdc_pickup_point_code`, `_wdc_pickup_point_title`, `_wdc_pickup_point_address`,
  `_wdc_pickup_point_city`, `_wdc_pickup_point_snapshot`;
- DPD aliases `_wdc_dpd_pickup_terminal_code`, `_wdc_dpd_pickup_type`, `_wdc_dpd_pickup_name`,
  `_wdc_dpd_pickup_address`, `_wdc_dpd_pickup_city_name`, `_wdc_dpd_pickup_latitude`, `_wdc_dpd_pickup_longitude`,
  `_wdc_dpd_pickup_source`.

The DPD preparation draft reads:

- selected DPD tariff/service code and tariff title;
- delivery type `pickup`/`courier`;
- pickup cityId and delivery cityId;
- sender pickup terminalCode from the new default sender setting, falling back to checkout/runtime meta;
- receiver delivery terminalCode for pickup delivery;
- selected pickup point snapshot;
- recipient name, phone, email and courier address from the WooCommerce order;
- declared value from order goods value/order total.

Known missing/manager-owned data:

- cargo places for the shipment. Checkout `parcel[]` is not reused and parcels are not saved to order meta;
- final live-create policy fields such as payer/COD/NPP/extra services, labels and status sync are out of scope;
- a configured default sender parcel shop is recommended for preparation, but checkout can still auto-select sender
  terminalCode for pricing when it is empty.

## CDEK Pattern Reused

DPD uses the existing shipment architecture rather than a separate flow:

- `OrderShipmentsMetabox` renders the shared order `Отправления` modal, package-place editor and AJAX preview path;
- `OrderShipmentDraftFactory` collects order data and parses manager-entered places;
- `CarrierShipmentAdapterRegistry` exposes carrier presentation/status/preview hooks to the metabox;
- `CdekShipmentAdapter`, `CdekCreateRequestBuilder` and the CDEK modal flow were used as the model for safe preview,
  validation, package-place handling and registry wiring.

`ShipmentCreationService` intentionally remains live-create only for Russian Post and CDEK. DPD is registered in
`CarrierShipmentAdapterRegistry` for button visibility and preview, but its live lifecycle methods are disabled.

## Sender Parcel Shop Setting

`WDC -> Службы доставки -> DPD -> DPD Расчет` has `ПВЗ отправителя по умолчанию`.

The value stores a DPD sender `terminalCode`. The admin summary reads local DPD pickup-point storage and shows:

- terminalCode;
- name;
- address;
- city_name;
- source.

The summary warns when the value is empty, not found, not an active `parcel_shop`, or belongs to a different DPD cityId
than the configured sender city. `terminal_self_delivery` rows are not accepted as the default sender parcel shop.

## Dry-Run Payload

`DpdShipmentPayloadBuilder` returns a preview for the future DPD `order2/createOrder` method. The local DPD integration
guide `docs/dpd/ws-integration-guide.docx` documents `order2?wsdl`, `createOrder/createOrder2`, `serviceCode`,
`serviceVariant`, `cargoNumPack`, `cargoValue`, `selfPickup`, `selfDelivery`, address blocks and `terminalCode`
requirements: terminalCode is required in pickup when `selfPickup=true`, and in delivery when `selfDelivery=true`.

The 0.63.0 preview includes:

- operation `createOrder`, method path `order2/createOrder`, `dry_run=true`, `live_api_call=false`;
- `orderNumberInternal`, optional comment and tariff/service labels;
- DPD `serviceCode`;
- `serviceVariant` as `ТТ` for pickup delivery and `ТД` for courier delivery;
- `pickupAddress` with sender `cityId` and sender `terminalCode`;
- `deliveryAddress` with receiver `cityId`, receiver `terminalCode` for pickup delivery, or courier address fields;
- receiver name, phone and email;
- `cargoNumPack`, `cargoValue`, `cargoRegistered=false`;
- `parcel[]` built only from manager-entered modal places: weight kg, length cm, width cm, height cm and expanded quantity.

No checkout tariff-calculation `parcel[]` is read for shipment payloads. No parcel array is persisted into the order.
Declared value is derived from order goods/order total and is not saved as a separate custom meta field.

## Validation

Dry-run validation returns modal errors/warnings without fatal errors.

Errors:

- order/rate must be DPD;
- `serviceCode` is required;
- pickup cityId is required;
- delivery cityId is required;
- sender pickup terminalCode is required;
- pickup delivery requires receiver delivery terminalCode;
- courier delivery requires recipient address;
- recipient phone is required;
- at least one cargo place is required;
- every cargo place must have positive weight and dimensions.

Warnings:

- `ПВЗ отправителя по умолчанию не задан.`

## Explicit Non-Goals

0.63.0 does not implement:

- live DPD `createOrder/createShipment` API calls;
- DPD auto shipment creation;
- labels;
- status sync;
- cancellation;
- COD/NPP;
- `unitLoad`;
- CDEK/Russian Post runtime changes;
- DPD checkout pricing changes.
