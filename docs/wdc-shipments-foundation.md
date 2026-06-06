# WDC Shipments Foundation

Version 0.37.2 separates Russian Post cancellation from local WooCommerce shipment removal and uses an inline SVG tracking-copy icon. Version 0.37.0 adds Russian Post backlog cancellation and manual ШПИ attachment while keeping documents/labels outside the plugin workflow. Version 0.36.4 stores Russian Post Otpravka `result-id` as the explicit technical shipment-state field `backlog_order_id` while keeping barcode/ШПИ as the primary tracking identifier. Version 0.36.2 closes the shipment preparation modal after successful create, shows a 10-second toast, and automatically runs the first Russian Post status refresh. Version 0.36.0 added manual Russian Post tracking status refresh from the existing `Обновить статус` button in the order metabox. Version 0.35.2 keeps the manual shipment runtime foundation on the unified Russian Post domestic service and removes the need for visible technical WooCommerce shipping item meta or pickup-code data in `shipping_address_2`. Version 0.34.0 added the admin-only Russian Post OPS/PVZ selector for shipment drafts. The scope is intentionally manual and carrier-neutral, with Russian Post as the first adapter.

## Scope

- Shipments are never created automatically.
- A manager opens the WooCommerce order admin metabox `Отправления`, checks the draft, edits recipient, delivery scenario (`pickup` or `courier`), tariff, pickup/address, postoffice-code and parcel places, then clicks `Создать отправление`.
- The first adapter calls Russian Post Otpravka `PUT /2.0/user/backlog`.
- For `Почта России -> ПВЗ/ОПС`, a manager can choose another local Russian Post pickup point inside the shipment modal. The selection is used only for the shipment draft/preview/create request.
- Manual Russian Post status refresh is included for created shipments with barcode. The first refresh runs automatically after successful create; background polling is not included in this stage.
- Russian Post cancellation is included for backlog shipments that are still at operation `28 / Присвоение идентификатора`.
- Manual ШПИ attachment is included for shipments created manually in the Russian Post account.
- Russian Post labels, batches, F103 and documents are not created or downloaded in WDC; managers prepare them manually in the Russian Post account.
- Checkout, tariff calculation, the saved order delivery method and WooCommerce order meta are not changed by the admin pickup selector.

## Code

- `src/Shipments/Contracts/ShipmentCarrierAdapterInterface.php` defines the carrier-neutral adapter contract.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` builds shipment drafts from HPOS-safe WooCommerce order APIs and saved WDC order meta.
- `src/Shipments/Application/ShipmentCreationService.php` performs idempotency checks, adapter dispatch, safe snapshot persistence and order notes.
- `src/Shipments/Application/ShipmentStatusUpdateService.php` manually refreshes shipment status through Russian Post Tracking API and saves carrier-neutral status state.
- `src/Shipments/Application/ShipmentServiceSettings.php` owns per-service shipment settings.
- `src/Shipments/Storage/OrderShipmentRepository.php` stores shipment state in order meta through WooCommerce CRUD.
- `src/Shipments/RussianPost/*` maps domestic tariff object codes, builds safe backlog payloads, normalizes create responses and contains Russian Post tracking status mapping.
- `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php` calls `getOperationHistory` over SOAP 1.2.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the order metabox plus AJAX preview/create/search/status actions.
- `assets/admin/shipments-admin.js` and `assets/admin/shipments-admin.css` provide the admin modal behavior.
- `assets/frontend/pickup-map/providers/*` and the configured Leaflet/Yandex provider are reused for the admin pickup selector; no second map stack is introduced.

## Russian Post Payload

The adapter builds an array of backlog order objects. For one place it sends one order object. For multiple places it sends one object per place and sets:

- `add-to-mmo=true`;
- `group-name=<WooCommerce order number>`;
- per-place `mass` and `dimension`.

The builder fills `order-num`, `mail-type`, `mail-category`, `postoffice-code`, recipient fields, `payment=0`, `compulsory-payment=0`, `delivery-with-cod=false`, `payment-method=CASHLESS`, and `notice-payment-method=CASHLESS`. Ordinary parcel/courier/EMS variants use `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`.

Domestic Russian Post shipment payloads always send `mail-direct=643`.

Normal pickup/OPS shipments are not treated as ECOM by default. They send `address-type-to=DEMAND`, `index-to`, `region-to`, and `place-to`; `ecom-data` is omitted. In the admin modal the human-readable address is shown as `{index}, {region}, {place}, до востребования`.

ECOM pickup shipments use `ecom-data.delivery-point-index` and omit the normal recipient address schema unless a future API/product explicitly requires it. The ECOM decision comes from the per-tariff `is_ecom` setting in Delivery Services, not from a hard-coded object code. Object `54020` still maps to `ECOM_MARKETPLACE`, but it only uses `ecom-data` when the tariff setting is enabled.

Courier shipments use `address-type-to=DEFAULT`, `courier=true`, `delivery-to-door=true`, `index-to`, `region-to`, `place-to`, and `raw-address`. If a manager did not enter a custom raw address, it is assembled from `shipping_postcode`, `shipping_state`, `shipping_city`, `shipping_address_1`, and `shipping_address_2`. Pickup checkout no longer writes `Код ПВЗ` into `shipping_address_2`.

`tel-address` is normalized to digits only before payload creation. If the normalized phone is empty, validation returns `Телефон получателя обязателен.`

The admin parcel-place UI accepts only integer values. Insurance is entered in rubles and converted before payload creation to Otpravka kopecks: `1000` rub -> `insr-value=100000`.

Postoffice acceptance indices are configured on `WDC -> Службы доставки -> Почта России по РФ -> API / Credentials` as `Индексы места приема для регистрации отправлений`. The default list contains `630005`; each configured value must be a 6-digit index and is used in the modal select for `postoffice-code`. These indices are separate from tariff calculation `from_postcodes` on `Расчет`. `default_from_postcode` is edited on the same tab after the postoffice-code list while retaining its existing storage key.

`dimension-type` and `prepaid-amount` are not sent by default. `goods` is omitted unless service setting `send_goods_items=true`.

## Storage

Successful creation stores `_wdc_shipments` on the order with:

- `carrier_key`, `service_key`, `order_id`, service title and delivery type;
- places snapshot;
- safe request preview without headers/secrets;
- normalized response snapshot without raw headers;
- barcode/tracking number, order number, group name;
- `backlog_order_id`, parsed from Otpravka `result-id` and reserved for internal Otpravka backlog operations such as cancellation;
- status `created`, `created_at`, `updated_at`.

Manual status refresh extends the same shipment state with:

- `universal_status_code`, `universal_status_label`;
- `carrier_status_title`, `carrier_status_description`;
- `carrier_operation_type_id`, `carrier_operation_type_name`;
- `carrier_operation_attr_id`, `carrier_operation_attr_name`;
- `carrier_operation_date`, `carrier_operation_address`, `carrier_operation_index`;
- `carrier_status_is_terminal` when the mapping marks the pair terminal;
- `tracking_checked_at`, `tracking_raw_snapshot`.

Credentials are never stored in order meta.

Otpravka `result-id` is parsed as part of the create API response and saved separately as `backlog_order_id`. It is not used as the primary shipment identifier: barcode/ШПИ remains the tracking number shown to managers and used by the Tracking API. In 0.37.0 `backlog_order_id` is used by cancellation (`DELETE /1.0/backlog`) and kept hidden in the metabox. It is not shown to customers, emails, account pages, public tracking blocks, or toasts.

Manual attachment saves shipment state with `source=manual_tracking_attach`, entered barcode/ШПИ, returned `backlog_order_id`, status `created`, timestamps and a minimal safe response snapshot.

In 0.37.2 the metabox has two cleanup actions:

- `Отменить отправление` cancels in Russian Post through `DELETE /1.0/backlog` and then clears shipment state. It is available only when the latest Russian Post operation is `28 / Присвоение идентификатора` and `backlog_order_id` exists.
- `Удалить из заказа` clears only WooCommerce shipment state through `OrderShipmentRepository::delete_for_carrier()` and does not call Russian Post. It is used when a shipment already cannot be cancelled in Russian Post or when the status has not been refreshed yet.

Failed creation stores `_wdc_shipment_last_error` with safe error code/message and adds a short order note. If a Russian Post shipment is already `created` or `registered`, repeat creation is blocked.

## Settings

`WDC -> Службы доставки -> Почта России по РФ -> API / Credentials` is the editing location for Tariff API and Otpravka credentials:

- Tariff API endpoint;
- Tariff API token;
- AccessToken;
- login;
- password;
- timeout;
- postoffice codes.

Tracking login/password fields are used by manual status refresh:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

They are separate from Otpravka credentials and Tariff API token.

Shipment drafts read delivery type, selected tariff, service key and pickup point data from hidden WDC order meta and `_wdc_delivery_calculation_data`; they do not depend on visible shipping item meta such as `wdc_delivery_kind`, `delivery_kind`, `checkout_group_id`, `Пункт выдачи`, `Индекс ПВЗ`, or `Тип ПВЗ`. Pickup point type is shown in the WooCommerce order metabox `Калькулятор доставок` under `Код ПВЗ`.

Domestic Russian Post exposes shipment settings on the service `Отправления` tab:

- `shelf_life_days_default`, clamped to 15..60, default 30;
- `send_goods_items`, default false;
- `combine_goods_items_default`, default true;
- `combined_goods_name_template`, default `Товары по заказу {order_number}`.

## Tests

Smoke coverage:

```powershell
php tests/shipments/run-russian-post-shipments-smoke.php
php tests/shipments/run-shipment-status-smoke.php
```

The smoke tests cover Russian Post backlog payload building, status mapping, SOAP parsing/fault/empty-history handling, shipment status persistence, AJAX response shape and metabox/JS status UI wiring without real API credentials.

The status smoke also covers no-attribute fallback through `type:-`, including operation `28` -> `создан в ТК` and operation `46` -> `отменён`.

## Admin Preview And Pickup Selector

The preparation modal renders a safe server-side API payload preview. Field changes, service/tariff changes and place add/remove actions refresh the preview through debounced AJAX; if preview refresh temporarily fails, the old preview stays visible and the UI shows a warning.

The pickup section shows the selected OPS/PVZ index and address plus `Выбрать другой ПВЗ`. The picker opens as a second modal above the shipment modal, searches local `wp_wdc_pickup_points_russian_post` rows by `postcode`, `city_name` and `address` through `wdc_search_russian_post_pickup_points`, renders found points on the configured map provider, and shows a table with index, city, address and choose action.

Selecting a point updates only shipment draft fields: `pickup_point_code`, `pickup_point_postcode`, `pickup_point_found`, `pickup_point_row`, `recipient_address` and the visible pickup index/address. It immediately calls `requestPreview(form)` so the preview/create payload uses the selected point. The selector does not write WooCommerce order meta and does not change checkout or tariff state.
