# WDC Shipment Statuses

Version: 0.109.0.

Version 0.109.0 adds Yandex Delivery to carrier raw-status mapping. The Yandex catalog is implemented in `YandexStatusMapping` and uses the existing `DeliveryStatus` universal registry; it does not add new universal statuses. Overrides are stored in `wdc_core_settings[yandex_delivery_status_mapping]` and edited on the Yandex delivery-service tab alongside DPD/CDEK carrier mappings. Canonical Yandex `request/info` persistence saves both raw carrier diagnostics (`yandex_status`, description, reason, timestamp, snapshot) and the resolved universal status. The shared `ShipmentOrderStatusMappingService` applies universal→WooCommerce mapping for canonical current status updates, while `request/history` mapping is informational only.

Version: 0.39.2.

Version 0.39.2 changes WooCommerce order notes for shipment status flows. A successful shipment status refresh only updates `_wdc_shipments` and the metabox payload; it does not create an order note. WDC creates a note only when `ShipmentOrderStatusMappingService` automatically changes the WooCommerce order status. The note is compact:

```text
Посылка {barcode}
Статус: {universal status}.
Статус заказа изменён:
{from_status} → {target_status}
```

Version 0.39.1 fixes terminal shipment autosync with order status mapping. Terminal universal statuses are still not refreshed through the carrier Tracking API, but autosync now applies the universal status to WooCommerce order status mapping against the already saved shipment state. This covers the case where a shipment was already `delivered`, `returned_to_sender`, `cancelled`, or `rejected` before the administrator later enabled mapping such as `delivered -> wc-completed`.

Version 0.39.0 adds universal shipment status to WooCommerce order status mapping. The `WDC -> Статусы -> Соответствие статусов` tab now contains `Включить автоматическое изменение статусов заказов` and a table from every `DeliveryStatus::all()` universal status to a WooCommerce order status. The WooCommerce status list is loaded with `wc_get_order_statuses()`, so standard statuses and custom statuses from WooCommerce Order Status Manager are supported.

Mapping storage:

- global enable flag: `shipment_status_order_status_mapping_enabled`, disabled by default;
- mapping setting: `shipment_status_order_status_mapping`;
- format: `array( 'delivered' => 'wc-completed', 'returned_to_sender' => 'wc-returned' )`;
- empty rows are not stored and mean "do nothing";
- there is no per-row enabled flag.

Runtime is handled by `ShipmentOrderStatusMappingService`. `ShipmentStatusUpdateService` calls it immediately after saving the updated shipment state into order meta, so manual status refresh, cron autosync, manual autosync, first automatic refresh after shipment creation, and first automatic refresh after manual tracking attach all use the same path. The service validates the universal status, target WooCommerce status, current order status, and then calls WooCommerce `update_status()`. On success it adds a separate compact private WDC order note; standard WooCommerce status notes remain untouched. Plain shipment status refreshes do not create order notes.

Version 0.38.2 stores and shows `tracking_checked_at` / `Проверено` for managers in `Asia/Novosibirsk` (GMT+7) with the existing `Y-m-d H:i:s` format. `carrier_operation_date` is carrier data from Russian Post Tracking API and remains unchanged, without timezone conversion.

Version 0.38.1 defaults autosync order statuses to:

- `wc-processing`
- `wc-on-hold`

`wc-completed` is not selected by default because completed orders are usually closed. Administrators can enable it manually on `WDC -> Статусы` when a shop workflow needs continued tracking after completion.

## Autosync

Version 0.38.0 adds a separate `WDC -> Статусы` admin page for shipment status synchronization. The page is intentionally separate from `WDC -> Службы доставки` and from Russian Post settings.

Tabs:

- `Основные`: enables/disables autosync, shows the fixed 6-hour interval, and stores selected WooCommerce order statuses from `wc_get_order_statuses()`. Custom WooCommerce statuses, including statuses from WooCommerce Order Status Manager, are preserved as `wc-*` status keys.
- `Соответствие статусов`: enables/disables automatic WooCommerce order status changes and stores universal shipment status to WooCommerce order status mapping.
- `Диагностика`: shows the last run timestamps, trigger type, duration, order/shipment counters, order status mapping counters, per-carrier updates, skip reasons, up to 20 error samples, and the manual `Запустить синхронизацию сейчас` action.

WP Cron:

- hook: `wdc_shipment_status_autosync`;
- schedule: `wdc_every_6_hours`;
- interval: `6 * HOUR_IN_SECONDS`;
- disabled autosync returns early in the handler, but the cron event is not removed.

Lock:

- key: `wdc_shipment_status_autosync_lock`;
- TTL: 30 minutes;
- scope: all delivery services/carriers, so concurrent carrier status refreshes do not overlap.

Runtime service:

- class: `ShipmentStatusAutoSyncService`;
- order selection: `wc_get_orders()` by selected WooCommerce order statuses only, with no shipment-age filter and no order limit;
- shipment source: order meta `_wdc_shipments`;
- required shipment fields: `carrier_key` and `tracking_number` or `barcode`;
- terminal universal statuses skip carrier tracking refresh: `delivered`, `returned_to_sender`, `cancelled`, `rejected`;
- terminal universal statuses still run `ShipmentOrderStatusMappingService` against the saved shipment state and record skip reason `terminal_status_no_tracking_update`;
- `unknown` is non-terminal and continues to be refreshed;
- dispatch: `carrier_key -> updater`, currently `russian_post_domestic -> ShipmentStatusUpdateService::update_russian_post()`.
- order status mapping diagnostics: `order_statuses_changed`, `order_statuses_skipped`, `order_status_change_errors`.

Order status mapping is carrier-neutral. It uses only universal shipment statuses such as `delivered`, `returning_to_sender`, and `cancelled`; it does not map Russian Post operation ids or any other carrier-specific statuses directly.

## Universal Status Model

WDC uses carrier-neutral shipment statuses so future delivery services can share the same state model:

| Code | UI label |
| --- | --- |
| `created_in_carrier` | `создан в ТК` |
| `in_transit` | `в пути` |
| `ready_for_pickup` | `ожидает самовывоза из ПВЗ/постамата` |
| `handed_to_courier` | `передан курьеру` |
| `delivered` | `доставлен` |
| `returning_to_sender` | `возвращается отправителю` |
| `returned_to_sender` | `возвращен отправителю` |
| `cancelled` | `отменён` |
| `rejected` | `отказ` |
| `unknown` | `не определён` |

The implementation lives in `src/Domain/Status/DeliveryStatus.php`.

## Russian Post Tracking

Manual status refresh uses Russian Post Tracking API single access:

- endpoint: `https://tracking.russianpost.ru/rtm34`;
- WSDL: `https://tracking.russianpost.ru/rtm34?wsdl`;
- method: `getOperationHistory`;
- SOAP: 1.2;
- request fields: `Barcode`, `MessageType=0`, `Language=RUS`, `AuthorizationHeader.login`, `AuthorizationHeader.password`.

The client is `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php`. It uses `wp_remote_post` and does not require external Composer dependencies.

Tracking credentials are stored in the unified Russian Post domestic service settings:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

They are separate from Otpravka AccessToken/login/password and from the Tariff API token.

## Russian Post Mapping

`src/Shipments/RussianPost/RussianPostTrackingStatusMapper.php` contains the fixed mapping generated from the attached `status pocha.xlsx` table. Runtime does not read Excel.

Version 0.36.1 corrects the first mapping import for Russian Post pickup/courier operations:

- `8:2` and related pickup operations `8:9`, `8:10`, `8:14`, `8:27`, `8:28`, `8:33`, `8:35`, `8:42`, `8:43`, `8:56`, `8:57`, `8:58`, `8:59` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `12:1..12:31` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `42:1..42:30` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `8:15` and `8:18` map to `handed_to_courier` / `передан курьеру`;
- unknown operation/attribute pairs remain `unknown` / `не определён`.

Version 0.36.2 adds the no-attribute fallback: if an exact `operation_type_id:operation_attr_id` key is absent, the mapper tries `operation_type_id:-`. Empty, absent, `0`, and `-` attributes therefore share the same no-attribute mapping. This maps Russian Post `28:0`/`28:''`/`28:-` to `created_in_carrier` / `создан в ТК`, and `46:0`/`46:''`/`46:-` to `cancelled` / `отменён`. Unknown no-attribute operations still map to `unknown` / `не определён`.

Mapping key:

- `operation_type_id`;
- `operation_attr_id`.

If the pair exists, WDC saves the mapped universal status and the terminal flag from the table. If the pair is absent, WDC saves `unknown` / `не определён`.

The raw Russian Post status is always preserved:

- operation type id/name;
- operation attribute id/name;
- operation date;
- operation address;
- operation index.

## Manual Metabox Refresh

The WooCommerce order metabox `Отправления` uses the existing `Обновить статус` button. The button is enabled only when the shipment is created and has a barcode.

After a successful shipment create, the preparation modal closes, WDC shows a local success toast for 10 seconds, and the first status refresh starts automatically through the same `wdc_update_shipment_status` action. If that automatic refresh fails, creation remains successful and the metabox shows `Отправление создано, но статус пока не обновлен: ...`.

AJAX action:

```text
wdc_update_shipment_status
```

The status block shows:

- `Статус в плагине`;
- `Статус Почты России`;
- `Последняя операция`;
- `Проверено`;
- `ШПИ` / `Barcode`.

The metabox shows `Статус посылки` above the carrier status block and `Отслеживание` for barcode/ШПИ with a copy action. The grey carrier block no longer duplicates plugin status or barcode; it shows Russian Post status, latest operation and checked time. Barcode/ШПИ remains the main tracking identifier and status refresh uses it for Tracking API requests. Otpravka `result-id` is stored separately as hidden `backlog_order_id` for internal API operations; it is not included in status payloads, status toasts, customer output, emails, account pages, or public tracking blocks.

Cancellation is allowed only when the latest Russian Post operation is `28 / Присвоение идентификатора`; it uses `backlog_order_id` through `DELETE /1.0/backlog` and clears shipment state on success.

Automatic polling/synchronization is available since version 0.38.0 through `WDC -> Статусы`; the metabox button remains the manual per-order control.

0.37.1 manual tracking attachment note: WDC searches `GET /1.0/backlog/search?query={barcode}` first and falls back to `GET /1.0/shipment/search?query={barcode}`. Tracking status refresh continues to use barcode. Cancellation continues to use hidden `backlog_order_id` and is disabled when shipment search does not return an internal id. The manual attach UI uses the wording `Номер отслеживания`, and the tracking copy action is a compact accessible icon button.
