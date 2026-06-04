# WDC Shipments Foundation

Version 0.34.0 adds the first shipment runtime foundation. The scope is intentionally manual and carrier-neutral, with Russian Post as the first adapter.

## Scope

- Shipments are never created automatically.
- A manager opens the WooCommerce order admin metabox `Отправления`, checks the draft, edits recipient, service, tariff, pickup/address, postoffice-code and parcel places, then clicks `Создать отправление`.
- The first adapter calls Russian Post Otpravka `PUT /2.0/user/backlog`.
- Status sync, documents, batches, F103, cancellation and automatic polling are not included in this stage.

## Code

- `src/Shipments/Contracts/ShipmentCarrierAdapterInterface.php` defines the carrier-neutral adapter contract.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` builds shipment drafts from HPOS-safe WooCommerce order APIs and saved WDC order meta.
- `src/Shipments/Application/ShipmentCreationService.php` performs idempotency checks, adapter dispatch, safe snapshot persistence and order notes.
- `src/Shipments/Application/ShipmentServiceSettings.php` owns per-service shipment settings.
- `src/Shipments/Storage/OrderShipmentRepository.php` stores shipment state in order meta through WooCommerce CRUD.
- `src/Shipments/RussianPost/*` maps domestic tariff object codes, builds safe backlog payloads and normalizes create responses.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the order metabox plus AJAX preview/create actions.
- `src/Shipments/Admin/CarriersAdminPage.php` adds `WDC -> Перевозчики -> Почта России`.
- `assets/admin/shipments-admin.js` and `assets/admin/shipments-admin.css` provide the admin modal behavior.

## Russian Post Payload

The adapter builds an array of backlog order objects. For one place it sends one order object. For multiple places it sends one object per place and sets:

- `add-to-mmo=true`;
- `group-name=<WooCommerce order number>`;
- per-place `mass` and `dimension`.

The builder fills `order-num`, `mail-type`, `mail-category`, `postoffice-code`, recipient fields, `payment=0`, `compulsory-payment=0`, `delivery-with-cod=false`, `payment-method=CASHLESS`, and `notice-payment-method=CASHLESS`. Ordinary parcel/courier/EMS variants use `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`.

Domestic Russian Post shipment payloads always send `mail-direct=643`.

Normal pickup/OPS shipments are not treated as ECOM by default. They send `address-type-to=DEMAND`, `index-to`, `region-to`, and `place-to`; `ecom-data` is omitted. In the admin modal the human-readable address is shown as `{index}, {region}, {place}, до востребования`.

ECOM pickup shipments use `ecom-data.delivery-point-index` and omit the normal recipient address schema unless a future API/product explicitly requires it. The ECOM decision comes from the per-tariff `is_ecom` setting in Delivery Services, not from a hard-coded object code. Object `54020` still maps to `ECOM_MARKETPLACE`, but it only uses `ecom-data` when the tariff setting is enabled.

Courier shipments use `address-type-to=DEFAULT`, `courier=true`, `delivery-to-door=true`, `index-to`, `region-to`, `place-to`, and `raw-address`. If a manager did not enter a custom raw address, it is assembled from `shipping_postcode`, `shipping_state`, `shipping_city`, `shipping_address_1`, and `shipping_address_2`; `shipping_address_2` is skipped when it starts with `Код ПВЗ`.

`tel-address` is normalized to digits only before payload creation. If the normalized phone is empty, validation returns `Телефон получателя обязателен.`

The admin parcel-place UI accepts only integer values. Insurance is entered in rubles and converted before payload creation to Otpravka kopecks: `1000` rub -> `insr-value=100000`.

Postoffice acceptance indices are configured on `WDC -> Перевозчики -> Почта России`. The default list contains `630005`; each configured value must be a 6-digit index and is used in the modal select for `postoffice-code`.

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

## Admin Preview And Known Debt

The preparation modal renders a safe server-side API payload preview. Field changes, service/tariff changes and place add/remove actions refresh the preview through debounced AJAX; if preview refresh temporarily fails, the old preview stays visible and the UI shows a warning.

The admin pickup map is intentionally left for a separate stage. The current button shows an inline message: `Выбор ПВЗ на карте будет подключен отдельным этапом; сейчас код ПВЗ можно скорректировать вручную.` The existing checkout pickup map is not reused here yet to avoid changing checkout frontend behavior in this stage.
