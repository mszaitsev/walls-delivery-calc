# WDC DPD Create Order

Version: 0.66.0.

DPD shipment creation is manual-only. WDC never creates DPD shipments from checkout, recalculation, cron, hooks or background jobs. A manager must open the WooCommerce order `Отправления` modal, verify `Предпросмотр payload`, then click `Создать отправление DPD`.

## DPD Method

WDC uses SOAP `order2/createOrder2` from the DPD WSDL selected by environment:

- test: `https://wstest.dpd.ru/services/order2?wsdl`;
- production: `https://ws.dpd.ru/services/order2?wsdl`.

The local DPD guide `docs/dpd/ws-integration-guide.docx` documents both `createOrder` and `createOrder2`. `createOrder2` is selected because it is the v2 create method, its input is documented as identical to `createOrder`, and its response can include `pickupDate` and `dateFlag` in addition to `orderNumberInternal`, `orderNum`, `status` and `errorMessage`.

`DpdApiClient::createOrder2()` uses the shared `DpdSoapClientInterface` infrastructure and the common auth wrapper. `DpdSoapRequest` adds the external `orders` SOAP parameter and injects `auth.clientNumber` / `auth.clientKey`; business payload builders remain auth-free.

## Payload

`DpdShipmentPayloadBuilder` is the single source for both preview and live create. Preview wraps the same body in diagnostic metadata and sets `dry_run=true`, `live_api_call=false`; live create sends the body to `DpdApiClient::createOrder2()`.

Required create body:

- `header.datePickup` from the modal date field;
- `header.senderAddress` with sender DPD `cityId` and sender pickup `terminalCode`;
- `header.pickupTimePeriod`, currently `9-18` unless meta overrides it;
- `order.orderNumberInternal`, truncated to 20 chars;
- `order.serviceCode` from saved DPD tariff/order meta or the modal tariff select;
- `order.serviceVariant`: `ТТ` for pickup, `ТД` for courier;
- `order.cargoNumPack`, `cargoWeight`, `cargoVolume`, `cargoRegistered=false`, `cargoValue`, `cargoCategory`;
- `order.receiverAddress`;
- `order.parcel[]` from manager-entered cargo places.

Pickup delivery:

- sends sender `header.senderAddress.terminalCode`;
- sends receiver `order.receiverAddress.terminalCode`;
- uses receiver DPD cityId and selected receiver pickup terminal snapshot.

Courier delivery:

- sends sender `header.senderAddress.terminalCode`;
- does not send receiver terminalCode;
- sends the normalized DPD courier address as receiver address data.

WDC does not send COD/NPP, `unitLoad`, labels, cancellation requests or status-sync fields in 0.66.0.

## UI Flow

The DPD modal keeps `Предпросмотр payload` and adds `Создать отправление DPD`. JavaScript enables the create button only when:

- an enabled tariff is selected;
- at least one cargo place has positive weight and dimensions;
- DPD `datePickup` is a `YYYY-MM-DD` value;
- pickup delivery has a receiver terminal without a pickup warning;
- courier delivery has a successful normalized-address snapshot.

The AJAX create request uses the existing `wdc_create_shipment` action, nonce `wdc_shipments_admin`, `manage_woocommerce` capability, WooCommerce order lookup and carrier request validation. The DPD adapter performs server-side validation again before any SOAP call.

After success the modal closes and the `Отправления` block is updated through the existing carrier UI payload flow used by CDEK/Russian Post.

## Saved Shipment Record

Successful create stores `_wdc_shipments[dpd]` through `OrderShipmentRepository` with:

- `carrier_key=dpd`;
- `status=pending_creation_in_carrier` and `universal_status_code=pending_creation_in_carrier`;
- `tracking_number` / `barcode` / `external_id` from the first available DPD identifier;
- `dpd_order_number` from DPD `orderNum` / `orderNumber` when present;
- `dpd_request_number` from DPD request fields or `orderNumberInternal` fallback when present;
- `dpd_parcel_numbers` if response contains parcel numbers;
- selected `dpd_service_code`, delivery type, sender terminalCode, receiver terminalCode for pickup, `dpd_date_pickup`, `dpd_cargo_value`;
- manager-entered places;
- sanitized `request_snapshot` and `response_snapshot`;
- `created_at`, `updated_at`, `created_by`, `created_by_context=admin_manual`.

If DPD returns only a registration/request number and not a final shipment number, WDC stores exactly that value in `dpd_request_number` and uses the first available identifier as the visible tracking value.

## Duplicate Protection

Before create, `ShipmentCreationService` asks `OrderShipmentRepository::has_created_for_carrier()`. Active statuses include `pending_creation_in_carrier`, `registration_pending`, `created` and `registered`. If an active DPD shipment exists, WDC does not call SOAP and returns:

`DPD отправление уже создано для этого заказа.`

Deleted/cancelled records are not treated as active by this guard.

## Errors

DPD errors are returned to the manager in the modal and do not cause fatals:

- local validation: missing `serviceCode`, terminalCode, datePickup, phone, address or cargo places;
- invalid/past/non-working `datePickup` from `DpdShipmentDateResolver`;
- unavailable DPD API client;
- SOAP fault / SOAP transport exception normalized by `DpdApiClient::createOrder2()`;
- DPD business response with non-OK status or `errorMessage`.

Failed DPD create requests are not saved as shipment records. The existing last-error/order-note path stores the manager-facing failure message, matching the existing shipment creation pattern.

## Not Implemented

0.66.0 deliberately does not implement:

- DPD auto-create hooks;
- cron/background creation;
- status sync or DPD tracing updates;
- labels;
- cancellation;
- COD/NPP;
- `unitLoad`;
- checkout pricing changes;
- DPD order recalculation changes;
- CDEK/Russian Post runtime changes.