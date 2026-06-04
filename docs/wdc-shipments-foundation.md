# WDC Shipments Foundation

Version 0.34.0 adds the first shipment runtime foundation. The scope is intentionally manual and carrier-neutral, with Russian Post as the first adapter.

## Scope

- Shipments are never created automatically.
- A manager opens the WooCommerce order admin metabox `Отправления`, checks the draft, edits recipient, service, tariff object, pickup/address and parcel places, then clicks `Создать отправление`.
- The first adapter calls Russian Post Otpravka `PUT /2.0/user/backlog`.
- Status sync, documents, batches, F103, cancellation and automatic polling are not included in this stage.

## Code

- `src/Shipments/Contracts/ShipmentCarrierAdapterInterface.php` defines the carrier-neutral adapter contract.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` builds shipment drafts from HPOS-safe WooCommerce order APIs and saved WDC order meta.
- `src/Shipments/Application/ShipmentCreationService.php` performs idempotency checks, adapter dispatch, safe snapshot persistence and order notes.
- `src/Shipments/Application/ShipmentServiceSettings.php` owns per-service shipment settings.
- `src/Shipments/Storage/OrderShipmentRepository.php` stores shipment state in order meta through WooCommerce CRUD.
- `src/Shipments/RussianPost/*` maps domestic tariff object codes, builds safe backlog payloads and normalizes create responses.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the order metabox and AJAX create action.
- `src/Shipments/Admin/CarriersAdminPage.php` adds `WDC -> Перевозчики -> Почта России`.
- `assets/admin/shipments-admin.js` and `assets/admin/shipments-admin.css` provide the admin modal behavior.

## Russian Post Payload

The adapter builds an array of backlog order objects. For one place it sends one order object. For multiple places it sends one object per place and sets:

- `add-to-mmo=true`;
- `group-name=<WooCommerce order number>`;
- per-place `mass` and `dimension`.

The builder fills `order-num`, `mail-type`, `mail-category`, `postoffice-code`, recipient fields, `payment=0`, `compulsory-payment=0`, `delivery-with-cod=false`, `payment-method=CASHLESS`, and `notice-payment-method=CASHLESS`. Pickup shipments use `ecom-data.delivery-point-index` when a pickup code exists. Courier shipments use `courier=true`, `delivery-to-door=true` and `raw-address`.

`dimension-type` and `prepaid-amount` are not sent by default. `goods` is omitted unless service setting `send_goods_items=true`.

## Storage

Successful creation stores `_wdc_shipments` on the order with:

- `carrier_key`, `service_key`, `order_id`, service title and delivery type;
- places snapshot;
- safe request preview without headers/secrets;
- normalized response snapshot without raw headers;
- barcode/tracking number, order number, result id, group name;
- status `created`, `created_at`, `updated_at`.

Failed creation stores `_wdc_shipment_last_error` with safe error code/message and adds a short order note. If a Russian Post shipment is already `created` or `registered`, repeat creation is blocked.

## Settings

`WDC -> Перевозчики -> Почта России` is now the primary editing location for Otpravka credentials:

- AccessToken;
- login;
- password;
- timeout.

The old pickup import tab no longer renders credential inputs and links to the carriers page. Existing option keys are preserved.

Domestic Russian Post services expose shipment settings on the service `Расчет` tab:

- `shelf_life_days_default` for pickup, clamped to 15..60, default 30;
- `send_goods_items`, default false;
- `combine_goods_items_default`, default true;
- `combined_goods_name_template`, default `Товары по заказу {order_number}`.

## Tests

Smoke coverage:

```powershell
php tests/shipments/run-russian-post-shipments-smoke.php
```

The smoke test covers Russian Post backlog payload building, MMO normalization, goods omission/enabling, courier flags and service setting sanitization without real API credentials.
