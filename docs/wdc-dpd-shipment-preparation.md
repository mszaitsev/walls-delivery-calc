# WDC DPD Shipment Preparation

Version: 0.64.0.

0.64.0 update: DPD order-admin delivery recalculation now writes the same DPD order meta that checkout-created DPD orders
write. After a manager saves a recalculated DPD pickup option, the shipment preparation draft reads the selected
serviceCode, delivery type, sender `pickup_terminal_code`, receiver `delivery_terminal_code`, selected pickup snapshot and
DPD alias meta from the order. After a manager saves DPD courier, receiver pickup meta is cleared and the draft uses the
courier address with an empty receiver terminalCode. Live DPD create calls remain disabled.

0.63.4 update: the DPD `Дата отправки` control uses two rows: `.wdc-dpd-date-label` for the label and
`.wdc-dpd-date-row` for the compact date input plus `−`/`+` buttons. Pointer/click/focus interaction with
`input[data-wdc-dpd-date-pickup]` attempts native `showPicker()` behind a short guard and falls back to `focus()` without
making the field readonly. This only improves UI opening behavior; value changes still flow through the existing preview
and validation paths.

0.63.3 update: the DPD `Дата отправки` input is rendered as a compact inline control instead of the modal-wide input
style. Small `−` and `+` buttons with `data-wdc-date-step="-1"` / `data-wdc-date-step="1"` move the selected value by
one calendar day, keep `YYYY-MM-DD`, and dispatch `input`/`change` so the existing dry-run preview receives the updated
`date_pickup`. The buttons do not auto-skip non-working days; `DpdShipmentDateResolver` validation continues to reject
past or non-working dates.

0.63.2 update: the DPD preparation modal no longer shows receiver pickup point `Тип точки`, no longer renders or posts a
DPD comment field, and no longer emits `comment` in the DPD dry-run payload. The modal now has `Дата отправки` after the
sender pickup point controls, and the payload includes `request.header.datePickup`. The default date uses the store
timezone with a 17:00 cutoff and the store calendar (`Календарь магазина`) to choose today or the next working day; if
the calendar service is unavailable, it falls back to today before 17:00 or the next calendar day after 17:00. DPD courier
normalization keeps using the shared CDEK-like address-processing flow, but the DPD UI hides `Код города СДЭК` and the
DPD payload does not use `cdek_city_code` as a DPD-specific field.

0.63.1 update: the DPD preparation modal supports temporary sender/receiver pickup-point changes, pickup/courier delivery
scenario switching and active tariff switching. DPD courier preparation reuses the CDEK-like address processing button and
normalized-address snapshot flow. All modal changes live only in hidden inputs/admin request data until the page reloads;
they are not written to order meta or DPD settings.

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

The preview includes:

- operation `createOrder`, method path `order2/createOrder`, `dry_run=true`, `live_api_call=false`;
- `request.header.datePickup` from the modal `Дата отправки` field;
- `orderNumberInternal` and tariff/service labels;
- DPD `serviceCode`;
- `serviceVariant` as `ТТ` for pickup delivery and `ТД` for courier delivery;
- `pickupAddress` with sender `cityId` and sender `terminalCode`;
- `deliveryAddress` with receiver `cityId`, receiver `terminalCode` for pickup delivery, or courier address fields;
- receiver name, phone and email;
- `cargoNumPack`, `cargoValue`, `cargoRegistered=false`;
- `parcel[]` built only from manager-entered modal places: weight kg, length cm, width cm, height cm and expanded quantity.

DPD comment is intentionally absent from the modal and payload in 0.63.2.

The 0.63.1 modal can temporarily override these payload fields:

- `serviceCode`/tariff from active DPD service codes configured in `DPD -> Тарифы`;
- delivery type `pickup` or `courier`;
- sender `pickup.terminalCode`;
- receiver `delivery.terminalCode` for pickup delivery;
- normalized courier delivery address for courier delivery.

No checkout tariff-calculation `parcel[]` is read for shipment payloads. No parcel array is persisted into the order.
Declared value is derived from order goods/order total and is not saved as a separate custom meta field.

## Validation

Dry-run validation returns modal errors/warnings without fatal errors.

Errors:

- order/rate must be DPD;
- `serviceCode` is required;
- `header.datePickup` is required, must be `YYYY-MM-DD`, cannot be in the past and must be a store working day when the
  store calendar is available;
- pickup cityId is required;
- delivery cityId is required;
- sender pickup terminalCode is required;
- pickup delivery requires receiver delivery terminalCode;
- courier delivery requires recipient address;
- courier delivery requires a successful DPD address processing snapshot;
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
