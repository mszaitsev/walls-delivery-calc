# WDC CDEK Order Creation

Version: 0.48.8.

0.48.8 update: the CDEK pickup map used from the shipment preparation modal now shares the same carrier-independent DaData address search as checkout and admin recalculation. The map keeps CDEK context (`carrier_key=cdek`, `pickup_family=cdek:pickup`), uses the recipient locality/address context, places a temporary marker for the found address, focuses the map on it, and keeps the CDEK pickup list for the current location. CDEK pickup point loading is no longer truncated to 50 rows in the shipment/admin map paths.

0.48.7 update: the CDEK shipment preparation modal now loads all active managed CDEK tariffs instead of only the tariff saved on the order. The tariff select is filtered by the order scenario: pickup orders show only active pickup tariffs, courier orders show only active courier tariffs. The saved order tariff remains selected; if it is no longer active or no longer present in the managed tariff list, the modal keeps that value as a marked fallback so it is not lost before creation. The modal also replaces technical CDEK rows with manager-facing labels: `В заказе тариф`, `ПВЗ отправителя`, and `Код ПВЗ` for the recipient point. The admin “Выбрать другой ПВЗ” map sends CDEK carrier context (`carrier_key=cdek`, `pickup_family=cdek:pickup`) and recipient locality/address context from the WooCommerce order, not sender city settings.

0.48.6 update: internal CDEK registration status now distinguishes documented order statuses from request states more precisely. `CREATED` is the only CDEK order status that means the order has been created and validated. `ACCEPTED` from `entity.statuses[]` is saved and displayed as the real CDEK status, but internally remains `registration_pending` and polling continues. `INVALID` from `entity.statuses[]` is a failed/incorrect CDEK order, `REMOVED` is stored as `removed`, and other movement/operation statuses are treated as active registered shipments without introducing a full universal CDEK-to-store status mapping yet.

0.48.5 update: CDEK request state is used only to track registration processing. `INVALID` still fails registration, and `ACCEPTED`/`SUCCESSFUL` without real `entity.statuses[]` keeps the shipment in `registration_pending`. `SUCCESSFUL` no longer marks a shipment as registered by itself. Internal `registered` state is set only when an actual CDEK order status exists in `entity.statuses[]`; `CREATED` and later statuses such as `READY_FOR_SHIPMENT_IN_SENDER_CITY` are all read from that order status list and displayed from the latest active status.

0.48.4 update: CDEK order status is now derived from `entity.statuses[]`, not from request state. Deleted statuses are ignored and the active status is the non-deleted status with the maximum parsable `date_time`; if dates cannot be parsed, the fallback is the last non-deleted status. `entity.planned_delivery_date` is saved as shipment data and shown in the grey shipment info block when present. The actual CDEK delivery price is read from `entity.delivery_detail.total_sum`, saved in the shipment payload, shown as `Цена: ... руб.`, and compared with the saved “Базовая стоимость API” using the same 3% tolerance as Russian Post.

0.48.2 update: the existing `Отправления` metabox can manually attach an existing CDEK shipment by `cdek_number`, then use the same `Обновить статус` action as created shipments. CDEK cancel/delete uses `DELETE /v2/orders/{uuid}` only when the order status from Appendix 1 is `CREATED / Создан`; after API success the local shipment snapshot is removed so the order can be created again. Local-only removal does not call CDEK and is allowed only for known CDEK order statuses other than protected `ACCEPTED / Принят` and `CREATED / Создан`.

0.48.1 update: pre-live-test fixes only. `ajax_create()` returns the status payload for the created carrier, CDEK `POST /v2/orders` with `requests[0].state=INVALID` fails with `cdek_registration_invalid` and is not saved as `registration_pending`, logs/snapshots are sanitized to remove recipient PII and full item lists, and the metabox status label is carrier-aware for CDEK.

This stage starts CDEK shipment creation from the existing WooCommerce order metabox `Отправления`. The flow is carrier-aware: Russian Post keeps the existing behavior, while orders saved with `carrier_key=cdek` use the CDEK-specific preview, validation, creation and status polling path.

## API Contract

The implementation follows the attached CDEK API HTML documentation for:

- `POST /v2/orders` - registration request for an order;
- `GET /v2/orders` - lookup by CDEK number or IM number;
- `GET /v2/orders/{uuid}` - lookup by CDEK entity UUID;
- Appendix 1 order statuses, especially `ACCEPTED` and `CREATED`;
- Appendix 6 additional services, where `INSURANCE` is automatic for `type=1`;
- documented `package`, `item`, `money`, `print`, `from_location`, `shipment_point`, `to_location` and `delivery_point` formats.

`POST /v2/orders` only submits an asynchronous registration request. A successful response means the request was accepted for processing; it is not treated as the final created order. The final registration state is checked through `GET /v2/orders/{uuid}` when CDEK returns `entity.uuid`, otherwise through `GET /v2/orders` by CDEK number or IM number.

## Payload Rules

The CDEK order payload uses `type=1`, `number` from the WooCommerce order number, the selected `tariff_code`, recipient data, packages, and `print=BARCODE`. The BARCODE print file is intentionally not downloaded or displayed in this stage.

The shipment origin and destination are selected by tariff delivery mode:

- `1` door-door: `from_location` + `to_location`;
- `2` door-warehouse: `from_location` + `delivery_point`;
- `3` warehouse-door: `shipment_point` + `to_location`;
- `4` warehouse-warehouse: `shipment_point` + `delivery_point`.

`from_location` and `shipment_point` are never sent together. `delivery_point` and `to_location` are never sent together. `from_location` uses `cdek_sender_city_code`, `cdek_sender_address`, and optional sender city/postal fields when configured.

The implementation does not send `services`, `additional_order_types`, `delivery_recipient_cost` or `delivery_recipient_cost_adv`. Insurance is not sent as a service because the CDEK docs state that `INSURANCE` is automatic for Internet-shop orders and is not allowed for explicit `services.code` on `type=1`.

## Packages And Items

Order creation does not reuse checkout calculation packages and does not add checkout packaging weight to the CDEK order. Each manager-entered грузоместо becomes one CDEK `package` with `number`, `weight`, `length`, `width`, `height` and `items`.

Items are built from the modal `Грузоместа` tab. Each item contains `name`, `ware_key`, `payment`, `cost`, `weight` and `amount`. `payment.value` is always `0` because the shop does not use cash on delivery. `cost` is the unit declared value after discount: `line_total / quantity`, with product price only as a fallback. Manager overrides in the modal affect only the outgoing CDEK payload and do not mutate WooCommerce product cards.

The UI supports splitting order item quantities into multiple rows while keeping the total equal to the ordered quantity. Every row must be assigned to an existing package, item weights must be positive, and package weight must be at least the sum of assigned item weights. More than 126 item rows is blocked before API submission.

## Status Flow

After accepted `POST /v2/orders`, the shipment is saved with status `registration_pending`, request UUID/entity UUID/CDEK number when present, and sanitized request/response snapshots.

The admin UI then polls status every 15 seconds for up to 10 minutes, maximum 40 attempts:

- request state `INVALID` saves shipment status `failed` and shows the CDEK errors;
- order status `CREATED` from Appendix 1 saves shipment status `registered`; request state `SUCCESSFUL` alone does not;
- `ACCEPTED` and other intermediate states keep `registration_pending`;
- after timeout the UI stops automatic polling and tells the manager to refresh manually later.

The existing `Обновить статус` button now works for CDEK shipments as a manual status refresh path. Full webhook handling, print downloads, cancellation, bulk actions and cron autosync remain outside this stage.

## UI And Idempotency

The existing `Отправления` metabox is reused. The modal now has tabs:

- `Основное` for the carrier-specific shipment summary and main controls;
- `Грузоместа` for universal package/item assignment data.

The `Грузоместа` tab is shown for all carriers so other services can adopt it later. In 0.48.0 it actively drives CDEK `packages[]`; Russian Post flow is kept backward-compatible.

Repeated CDEK creation is blocked when the order already has a CDEK shipment in `registration_pending`, `created` or `registered`. The UI shows the existing UUID/number/status instead of accidentally creating another CDEK order with the same IM number.

## Manual attach and removal

Manual attach does not create a CDEK order. The manager enters a CDEK number in the existing manual tracking form, WDC calls the CDEK order lookup, and only when the order is found stores `carrier_key=cdek`, `cdek_number`, returned UUID, current order status and a sanitized response snapshot. The attached shipment then uses the unchanged manual `Обновить статус` button.

Cancel/delete in CDEK is intentionally narrow: CDEK documentation allows deleting a created order only while the shipment has no warehouse movement, which corresponds to Appendix 1 order status `CREATED / Создан`. WDC calls `DELETE /v2/orders/{uuid}` only for that status. If CDEK returns an error, the local shipment is kept.

Local remove is separate from cancel/delete. It only clears WDC shipment data from the WooCommerce order and never calls the CDEK API. `ACCEPTED / Принят` and `CREATED / Создан` are protected from local removal; for unknown, request-only or pending registration states the UI keeps removal hidden until the manager refreshes status.

Toast notifications use the same admin mechanism as Russian Post for accepted registration, successful registration, registration errors, timeout and validation errors.

## Settings

CDEK sender settings now include:

- `cdek_sender_address` - admin label `Адрес отправителя СДЭК для тарифов от двери`.

The value is trimmed, stored as plain text, and limited to 255 characters. `shipper_name` and `shipper_address` are not added because the current stage does not implement international Internet-shop shipments.

## Tests

Smoke coverage:

- `php tests/cdek/run-cdek-order-creation-smoke.php`
- `php tests/cdek/run-cdek-foundation-smoke.php`
- `php tests/cdek/run-cdek-tariff-calculation-smoke.php`
- `php tests/cdek/run-cdek-pickup-points-smoke.php`
- `php tests/runtime/run-no-legacy-smoke.php`
- `php tests/orders/run-order-delivery-recalculation-smoke.php`
