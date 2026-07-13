# WDC Shipments Foundation

Version 0.108.14 closes the last Yandex pre-production UX gaps without changing the core HTTP lifecycle. Duplicate `operator_request_id` errors from `offers/create` are now auto-skipped inside one create click: the service reserves the next sequence id, rebuilds the payload and retries only `offers/create`, with a hard limit of 10 occupied ids. `offers/confirm` is still executed once and only after a successful offer response; unknown errors are not retried. Yandex cancel now behaves like bounded async status reconciliation: `request/cancel` persists `cancellation_started`, returns cancellation polling metadata (5000 ms × 14), and polling calls only `request/info`. `CANCELLED` triggers automatic local cleanup while preserving the sequence meta; exhaustion persists cancel-specific attempts/timestamp and leaves update + local remove. Manual attach accepts any valid Yandex `request_id`; sequence is synchronized only for operator ids in the current order family. Yandex courier preparation now requires explicit address verification through the existing DaData suggestions flow before structured destination fields are submitted.

Version 0.108.13 keeps the Yandex `operator_request_id` sequence persistent but compact. The sequence meta `_wdc_yandex_delivery_registration_sequence` now stores only `last_index`, `last_operator_request_id`, optional `current_attempt` and `updated_at`; it no longer stores a growing list of all allocated ids. The next id is still derived as `last_index + 1`, so an empty order starts at `1010`, then `1010/1`, `1010/2`, and so on. Reservation before real `offers/create`, no rollback after HTTP starts, the order-level lock, own-family manual attach sequence sync and slash-free temporary barcodes remain unchanged. Old 0.108.12 state with an `allocated_ids` key is ignored and normalized to the compact shape on the next repository read/save.

Version 0.108.12 adds persistent Yandex registration attempt numbering without changing the shared Shipment Framework or carrier HTTP contract. `YandexShipmentRepository` stores `_wdc_yandex_delivery_registration_sequence` separately from `_wdc_shipments`, so local remove, cancel, terminal status and reload do not reset the last Yandex `operator_request_id` index. The first Yandex create attempt uses the WooCommerce order number, and later independent attempts use `/1`, `/2` suffixes. Preview only peeks the next value; the actual reservation happens in `YandexShipmentRegistrationService::create_for_order()` immediately before real `offers/create`. After that point the id is considered used and is not rolled back even if the transport/API result is unknown. A short order-level lock prevents double-click/two-tab requests from allocating the same id at the same time. Since 0.108.14 manual attach accepts any valid request_id; the 0.108.12 strict family parser remains only for upward sequence synchronization when the attached operator id belongs to the current order family. Temporary place barcodes stay slash-free (`1010/1` becomes `1010-1-1`) while the actual `operator_request_id` remains `1010/1`.

Version 0.108.11 closes the gap between shipment metabox buttons and server actions for Yandex. Local remove now calls the same Yandex button policy on the server before deleting repository data: active `CREATED` and `cancellation_started` shipments are protected even if AJAX is sent manually, while `reconciliation_required` and terminal statuses remain removable. The shared bounded polling helper also distinguishes pending responses from transport failures. For generic/Yandex registration polling, HTTP/network/JSON errors count toward the bounded attempts and schedule the next tick instead of stopping after the first failure; `mode=dpd` preserves its previous stop-on-error behavior, and CDEK polling is unchanged.

Version 0.108.10 persists Yandex registration polling exhaustion on the server and aligns metabox buttons with the adapter after reload. A saved `reconciliation_required` Yandex shipment always remains an existing shipment with update + local remove available and create/manual attach/cancel hidden, even if the page was reloaded before 14 polling attempts finished. Since 0.108.14 `cancellation_started` also exposes update + local remove after reload/exhaustion, with a warning that the WooCommerce record is removed locally and Yandex status is unchanged. The shared `wdc_mark_shipment_poll_exhausted` AJAX action lets carriers persist exhausted polling state without changing the status endpoint; Yandex stores attempts/timestamp and keeps request id, lookup meta and selected offer audit. Polling attempts are not restored after reload, repeated pending toasts are suppressed, and local remove stops/invalidates the active polling run so stale responses cannot visually restore a removed shipment.

Version 0.108.9 adds bounded accepted-reconciliation polling for Yandex shipment registration. When `offers/confirm` succeeds but immediate `request/info` is still incomplete, the shared create flow persists the pending shipment and returns success/accepted so the modal closes and the metabox refreshes. The existing status AJAX path is then polled by the shared registration polling helper every 5 seconds up to 14 attempts; confirm is never repeated. If canonical Yandex `state.status` arrives, reconciliation is cleared and the status value renders once after the carrier label. If polling is exhausted, the UI keeps update available, shows local remove, and warns that local removal does not cancel the Yandex shipment.

Version 0.108.8 makes manual factual parcel input the shared shipment modal contract for every carrier. Initial editable place `weight_g`, `length_cm`, `width_cm` and `height_cm` are rendered empty without mutating the draft; all draft places, place numbers and item allocation rows remain visible. Submitted `places[]` remain the only source for preview/create, so empty fields fail existing strict validation instead of falling back to calculated package values. A single initial place shows only the compact calculated-weight hint `⚖️{weight}`; calculated dimensions are not shown as hints. Yandex manual attach still uses the existing generic manual attach UI, now labelled `Ввести номер Яндекс вручную` with `Request ID Яндекс` and placeholder `***-udp`.

Version 0.108.7 added Yandex manual attach through the shared shipment metabox. The entered value is treated as `request_id`, verified through `request/info`, checked against `operator_request_id`, and persisted as canonical request/info state.

Version 0.108.6 adds a carrier-neutral successful-preview gate for shipment creation. Carriers that set `requires_successful_preview` in modal capabilities keep the create button disabled until preview succeeds without errors; currently this applies to Yandex and DPD. Preview/create AJAX boundaries return controlled JSON errors and public shipment validation messages are Russianized at the metabox boundary.

Version 0.108.5 keeps the existing shared shipment modal and adds Yandex-specific modal presentation through the current draft/metabox/preview pipeline: one concrete Yandex service variant, no tariff/postoffice requirement, read-only source station, destination snapshot/address fields, ready interval hidden values, populated initial places and JSON-safe preview errors. Yandex preview remains local-only and does not call carrier HTTP.

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

- `src/Shipments/Contracts/CarrierShipmentAdapterInterface.php` defines the carrier-neutral adapter contract.
- `src/Shipments/Application/ShipmentModalRequestMapper.php` parses the shared shipment modal `places[]` and canonical `shipment_items[]` allocation rows for carrier admin submit paths.
- `OrderShipmentDraftFactory::draft_array()` may expose small carrier-neutral modal capabilities such as `requires_tariff=false`; the metabox uses them without adding a second modal architecture.
- `src/Shipments/Application/ShipmentMetaboxButtonPolicy.php` resolves common metabox button capabilities from carrier status payload first and falls back to legacy status/barcode rules only when a capability is absent.
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

ECOM pickup shipments use `ecom-data.delivery-point-index` and omit the normal recipient address schema unless a later Russian Post product explicitly requires it. The ECOM decision comes from the per-tariff `is_ecom` setting in Delivery Services, not from a hard-coded object code. Object `54020` still maps to `ECOM_MARKETPLACE`, but it only uses `ecom-data` when the tariff setting is enabled.

Courier shipments use normalized Russian Post address fields from `RussianPostAddressNormalizer`: `address-type-to`, `index-to`, `region-to`, `area-to`, `place-to`, `location-to`, `street-to`, `house-to`, `slash-to`, `letter-to`, `building-to`, `corpus-to`, `room-to`, and `num-address-type-to`. The modal shows the original shipping address and requires the manager to run `Обработать адрес`; successful creation is blocked until a valid normalization result matches the original-address hash. If the address changes, the cached normalized payload is cleared. Failed normalization may be shown in preview fallback, but it is not accepted for create.

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

Manual attachment saves shipment state with `source=manual_tracking_attach`, entered barcode/ШПИ, lookup source (`backlog_search` or `shipment_search`), returned `backlog_order_id` when present, status `created`, timestamps and a minimal safe response snapshot. Lookup uses `GET /1.0/backlog/search?query={barcode}` first and falls back to `GET /1.0/shipment/search?query={barcode}`.

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
- postoffice codes;
- default postoffice/from index for shipment registration.

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

The shipment modal is intentionally compact for manual preparation: parcel/place inputs accept integer-only values, the calculated order weight is shown in the weight label instead of being written into the editable value, and the declared-value field is shown only for tariffs whose shipment product has declared value. Place rows are compact, support add/remove for MMO-capable products, and feed both the preview and final Otpravka backlog payload. After a successful create the modal closes, the metabox shows the tracking number/toast, and the first status update starts automatically.

The pickup section shows the selected OPS/PVZ index and address plus `Выбрать другой ПВЗ`. The picker opens as a second modal above the shipment modal, searches local `wp_wdc_pickup_points_russian_post` rows by `postcode`, `city_name` and `address` through `wdc_search_russian_post_pickup_points`, renders found points on the configured map provider, and shows a table with index, city, address and choose action.

Selecting a point updates only shipment draft fields: `pickup_point_code`, `pickup_point_postcode`, `pickup_point_found`, `pickup_point_row`, `recipient_address` and the visible pickup index/address. It immediately calls `requestPreview(form)` so the preview/create payload uses the selected point. The selector does not write WooCommerce order meta and does not change checkout or tariff state.
