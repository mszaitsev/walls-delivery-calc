# WDC CDEK Order Creation

Version: 0.52.0.

0.52.0 update: CDEK shipments are now included in the shared `WDC -> Статусы` auto-sync loop controlled by the existing `Автообновление статусов отправлений` setting. The dispatcher reuses `CdekOrderStatusService`, so automatic refresh follows the same latest non-deleted `entity.statuses[]` logic as the order metabox `Обновить статус` button, saves raw CDEK status fields, applies `CdekStatusMappingService` to `universal_status_code`/label, and then runs the carrier-neutral WooCommerce order status mapping when enabled. CDEK auto-sync throttles status API calls with a 10 ms pause between CDEK requests and reports counts in diagnostics under `updates_by_carrier[cdek]`.

0.51.3 update: after CDEK BARCODE status becomes `READY`, the admin UI now downloads the label with `fetch()` and a Blob/Object URL instead of a hidden iframe. The browser response is checked for HTTP success, PDF content type and non-empty blob size; failures show a visible toast. The server-side ready-PDF download path also rejects empty bodies, failed HTTP responses and explicit non-PDF content types.

0.51.2 update: the CDEK label UX now uses one managed `Скачать этикетку` action. The old `Открыть этикетку` link was removed. A click starts AJAX preparation, polls CDEK print status every 2 seconds for up to 5 minutes, then downloads the ready PDF from the final endpoint. Ready print UUIDs are cached for 50 minutes, so repeat downloads reuse the existing BARCODE form instead of posting a new print request. The final `admin-post` PDF endpoint only downloads a ready cached form and no longer creates or long-polls print requests. Saving `Статусы СДЭК` redirects back to the CDEK service tab `cdek_statuses`.

0.51.1 update: registered CDEK shipments gained manager-facing label actions and the CDEK raw-status mapping UI moved from the general `WDC -> Статусы` page into `WDC -> Службы доставки -> СДЭК -> Статусы СДЭК`, while keeping the same settings key.

0.51.0 update: registered CDEK shipments expose BARCODE print actions in the order `Отправления` block. WDC follows the attached CDEK print documentation: it creates the async form with `POST /v2/print/barcodes` using `orders[]`, `copy_count=1`, `format=A6` and `lang=RUS`, polls `GET /v2/print/barcodes/{uuid}` until `READY`, and downloads `GET /v2/print/barcodes/{uuid}.pdf`. Print-form UUIDs and PDF files are not stored, and print creation/download errors are not logged.

Raw CDEK order status from `entity.statuses[]` is still selected by max `date_time` among non-deleted rows and remains the source for CDEK-specific buttons such as cancel/local remove, but WDC also saves a carrier-neutral `universal_status_code` through the configurable CDEK mapping. Defaults now cover every code from the CDEK documentation appendix `Приложение 1. Статусы заказов`: movement/customs/warehouse statuses map to `in_transit`, pickup/postamat-ready statuses to `ready_for_pickup`, `TAKEN_BY_COURIER` to `handed_to_courier`, `DELIVERED`/`POSTOMAT_RECEIVED` to `delivered`, `INVALID`/`NOT_DELIVERED` to `rejected`, and `REMOVED` to `cancelled`.

0.50.4 update: CDEK postamats are handled as the same warehouse/PVZ endpoint class for order registration. No special shipment creation branch is added: postamat pickup still sends `delivery_point`, just like PVZ. The shipment modal now shows a separate CDEK row `Тип точки: ПВЗ СДЭК` or `Тип точки: Постамат СДЭК` when the selected pickup metadata contains a known type; unknown types are not rendered.

0.50.3 update: CDEK courier order creation now follows the documented direction fields for `POST /v2/orders`. Managed tariffs carry editable `delivery_mode`: `1` door-door uses `from_location` + `to_location`, `2` door-warehouse uses `from_location` + `delivery_point`, `3` warehouse-door uses `shipment_point` + `to_location`, and `4` warehouse-warehouse uses `shipment_point` + `delivery_point`; incompatible pairs are not sent together. Door-origin modes use sender city/postcode/address settings, while warehouse-origin modes keep the sender CDEK pickup point. The modal shows `Комментарий курьеру` only for recipient-door CDEK modes and sends it as `comment` when present, trimmed to CDEK's 255 character limit.

Admin delivery recalculation now uses the shared apartment/office/premise splitter before DaData normalization. Inputs such as `125252, Москва, Ходынский б-р, д 13, кв 150` are normalized by querying the house address without the flat suffix and then restoring the flat into the saved shipping address.

0.50.1 update: CDEK courier address preparation still uses DaData to verify the recipient address and build the door-delivery address fields, but the CDEK city code now reuses already known calculation data first: prepared `normalized_address.fields.cdek_city_code`, `_wdc_delivery_calculation_data.api.cdek_to_city_code`, `_wdc_platform_rate_meta.location.cdek_to_city_code`, and saved `request_payload_sanitized.to_location.code`. `GET /v2/location/cities` is only a fallback through the shared `CdekLocationResolver`, so admin recalculation and shipment creation no longer lose a known city code or produce `to_location.code = 0`.

For CDEK courier, the shipment modal calls the DaData suggestions stack directly instead of the old checkout external normalizer path. If DaData suggestions are not configured, the modal shows `Подсказки DaData не настроены. Невозможно проверить адрес СДЭК.`; the Russian Post message `Внешний нормализатор не настроен.` is no longer used for CDEK courier preparation.

0.50.0 update: CDEK courier shipment creation no longer reuses Russian Post address normalization. In the shipment preparation modal, courier CDEK scenarios normalize the recipient address through DaData and then resolve the recipient CDEK city code through the documented `GET /v2/location/cities` location lookup, preferably by DaData `geo_lat`/`geo_lon` and then by saved recipient locality coordinates. The modal keeps the result only in draft state until creation and shows `Нормализованный адрес СДЭК`, the visible CDEK city code and the success message `✅ Данные для СДЭК корректны`.

For CDEK courier tariffs, `to_location` is built from prepared CDEK fields: positive `code`, normalized city name, DaData postal code and component-built delivery address such as `Ходынский б-р, д 13, кв 150`. Postcode is never used as city code, and creation is blocked with `Не удалось определить код города СДЭК для адреса получателя. Проверьте адрес и повторите обработку.` when location lookup cannot resolve a city code. Pickup CDEK tariffs still use `delivery_point`; Russian Post keeps its existing normalization path.

0.49.2 update: shipment item rows now initialize product dimensions from WooCommerce product/variation data when catalog dimensions exist, with `0.1` used only as the empty-dimension fallback. Item price and item dimension inputs are text decimal fields so managers can type both `.` and `,`, while package place dimensions remain integer-only.

The `Грузоместа` item table now uses borderless centered split/remove icons, clamps split/base quantities to keep every row at least `1` and the group total equal to the ordered quantity, closes manual SKU search results on focus-out without auto-filling, and supports partial SKU lookup through `_sku LIKE` for products and variations.

0.49.1 update: the `Грузоместа` tab is carrier-neutral in the shipment modal instead of being CDEK-only. Russian Post and future carriers receive the same package summary, item rows, split controls and manual item entry UI, while CDEK continues to be the only carrier that currently maps those rows into `/v2/orders` items.

Package place weight/dimensions remain integer-only because carrier package payloads use whole values. Item dimensions inside the `Грузоместа` table may be fractional and are kept in modal state for package planning/future validation, but CDEK `packages.items[]` still sends only documented item fields (`name`, `ware_key`, `payment`, `cost`, `weight`, `amount`). Decimal item values accept both dot and comma separators, and forced merge after deleting a package restores base rows from the original order item data.

0.49.0 update: CDEK settings now include `cdek_shipment_point_address`, shown under `Код ПВЗ отправления СДЭК` on the service `Расчет` tab. The shipment preparation modal shows sender pickup point as code plus address and lets a manager temporarily choose another CDEK sender pickup point from the admin map; that choice updates only modal draft data and is sent as `shipment_point` in `POST /v2/orders`.

The universal `Грузоместа` UI was tightened for all carrier flows and actively feeds CDEK packages/items. A single package shows the API-weight hint from the same calculated final weight used by the delivery calculator; multiple packages hide the hint. Package summaries now show package weight, assigned item quantity, item weight and declared value. The item table uses Russian labels, plain SKU text, compact numeric fields, split rows with a delete action instead of `+/-`, automatic quantity rebalancing, manual item rows, and WooCommerce product search for manual SKU entry.

Version: 0.48.11.

0.48.11 update: the shipment preparation pickup map keeps the Russian Post carrier context (`russian_post_domestic:pickup`) and now loads recipient-location points even when there is no typed search query, fixing the empty “ПВЗ не найдены” state in the Russian Post shipment modal. The shared checkout pickup map also restores selected-point preview by matching carrier-specific codes/postcodes, not only transient REST ids, so previously selected CDEK and Russian Post points can be highlighted, opened and scrolled into view after reopening the map.

Version: 0.48.9.

0.48.9 update: the CDEK pickup map opened from the shipment preparation modal now follows the unified pickup-map UX. The existing selected point is restored as the preview when the map is reopened, the selected marker/list row are highlighted and scrolled into view, and the admin picker uses the larger two-column layout with one bottom `Выбрать этот ПВЗ` action instead of per-card selection buttons or a duplicated preview card. Address search messages no longer mention the geocoding provider in the UI, CDEK map titles render as `ПВЗ СДЭК {code}` or `Постамат СДЭК {code}`, and the Russian Post admin shipment/recalculation map paths keep their own carrier context and point lists while using the same address-search marker behavior.

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

The `Грузоместа` tab is shown for all carriers. Since 0.49.1 it renders the same table/summary/split/manual-item UI for Russian Post, CDEK and future carriers; CDEK actively uses the rows for `packages[]`, while Russian Post remains backward-compatible if its API flow does not consume item-level rows yet.

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
