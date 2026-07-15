# Russian Post Domestic Carrier

Version: 0.44.9.

Version 0.44.9 hardens the admin tool that fills `russianpost_courier_calc_postal_code` for courier Russian Post tariff calculation. Technical request failures retry up to 5 attempts; if all attempts fail, WDC counts one error and stores courier marker `999999999` to mean "technical error, retry later". This marker is not the same as courier delivery being unavailable. New/reset runs process marker rows first, then city rows, then other settlements. A later successful response overwrites `999999999`, while a valid "courier unavailable" response clears it and leaves the courier postcode empty.

Version 0.39.5 also pulls actual Russian Post cost after ordinary shipment creation from the order metabox. After successful create, WDC calls `backlog/search` by barcode, uses the same shared extractor as manual tracking attach, stores source `backlog_search_after_create`, and keeps create successful if the lookup fails or returns no totals.

Version 0.39.4 adds actual registered shipment cost display for Russian Post manual tracking attachment. When `backlog/search` returns `total-rate-wo-vat` and `total-vat`, WDC stores their sum in `_wdc_shipments`, shows `Цена` after `Отслеживание` in the order metabox, and compares it with checkout `Базовая стоимость API`; since 0.114.0 the shared `ShipmentActualCostComparisonService` performs this comparison for all shipment carriers with integer kopecks. Values up to and including exactly 3% over base are green/ok, more than 3% are red/warning, and missing or zero base cost is neutral. Since 0.114.1 zero actual cost is also treated as unknown and hidden.

Version 0.39.3 speeds up the admin tool that fills `russianpost_courier_calc_postal_code` for courier Russian Post tariff calculation. The `WDC -> Локации` button `Подобрать индексы для курьерской Почты России` still probes candidate postcodes sequentially and uses the same candidate order, but each backend step now targets about 6 Russian Post requests/sec, stops after 18 probes or 3 seconds, and exposes step timing/RPS diagnostics in JSON. The browser keeps one active AJAX step at a time and waits only a short delay before the next step.

## 1. Overview

Russian Post domestic is the current RU-only carrier/service runtime for checkout quoting and the manual shipment workflow.

- `carrier_key`: `russian_post_domestic`
- `service_key`: `russian_post_domestic`
- Supported country: `RU`
- Delivery types: `pickup` and `courier`
- Checkout group ids: `russian_post_domestic:pickup`, `russian_post_domestic:courier`
- Concrete rate ids: `russian_post_domestic:pickup:{object_code}`, `russian_post_domestic:courier:{object_code}`
- Current documented version: `0.44.9`

Cash on delivery / mandatory payment is not used. Insurance is represented by declared value; in Russian Post Tariff API terms this means tariff variants that require `sumoc`.

## 2. Unified Service Architecture

Russian Post domestic uses one carrier and one delivery service settings context:

- `carrier_key=russian_post_domestic`
- `service_key=russian_post_domestic`

Pickup/OPS and courier are no longer separate delivery services. Runtime separation is done through `delivery_type=pickup|courier`.

Checkout still renders pickup and courier as separate visible groups:

- `russian_post_domestic:pickup` -> `Почта России до ПВЗ / ОПС`
- `russian_post_domestic:courier` -> `Почта России курьером`

Concrete tariff rates extend the group id with the object code, for example:

- `russian_post_domestic:pickup:23030`
- `russian_post_domestic:courier:24030`

New order meta stores `_wdc_platform_service_key=russian_post_domestic`, `_wdc_platform_delivery_type`, `_wdc_platform_tariff_object`, and calculation metadata in `_wdc_delivery_calculation_data`.

The service uses the local city/postcode from checkout context and does not calculate without a valid six-digit `postal_code`. Technical postcode `999999999` is treated as missing.

Checkout method labels are built from the configured method title plus the selected tariff title and delivery days, for example `Почта России до отделения, Посылка онлайн - 7 дней` or `Почта России до двери, Курьер онлайн`. Visible domestic WooCommerce shipping item meta contains only `Срок доставки`; technical values such as delivery type, selected tariff, service key and pickup point code/type/postcode/address are stored in hidden WDC order meta and `_wdc_delivery_calculation_data`. Pickup code is not written to `shipping_address_2`.

## 3. Delivery Service Settings

All domestic Russian Post settings live on:

```text
admin.php?page=wdc-delivery-services&service=russian_post_domestic
```

Tabs:

- `Основные`: canonical service identity, enabled state, RU availability, and configurable checkout method titles `pickup_method_title` / `courier_method_title`.
- `Расчет`: tariff calculation origin/return postcodes, insurance, timeout/cache/debug, packaging weight, rounding, minimum price, and fallback settings.
- `Тарифы`: one merged tariff list with `delivery_type`, enabled state, ECOM flag, declared-value flag, weight limits, custom titles, and sort order.
- `ПВЗ / ОПС`: point type settings, local pickup import state, and pickup diagnostics.
- `API / Credentials`: Tariff API endpoint/token, Otpravka AccessToken/login/password/timeout, postoffice acceptance indices, default from postcode for shipment registration, plus tracking login/password fields used separately by status refresh.
- `Отправления`: `shelf_life_days_default`, `send_goods_items`, `combine_goods_items_default`, `combined_goods_name_template`.
- `Диагностика`: service/settings/PVZ quick diagnostics.

`WDC -> Перевозчики` is no longer registered. Tariff API endpoint/token, Otpravka credentials and postoffice codes are edited only inside the domestic delivery service. The unified service settings table is the only runtime source of truth for domestic Russian Post settings.

Shipment status autosync settings are not stored inside Russian Post domestic settings. They live on the separate `WDC -> Статусы` page. For Russian Post domestic shipments, autosync dispatches `carrier_key=russian_post_domestic` to the existing `ShipmentStatusUpdateService::update_russian_post()` status refresh pipeline.

Important index settings:

- `from_postcodes`: configured origin indices for tariff calculation.
- `return_postcode`: return index for tariff calculation.
- `default_from_postcode`: edited on `API / Credentials` beside the postoffice acceptance list; it keeps the same storage key, is still used by tariff calculation as a fallback origin index, and acts as the default shipment-registration from index.
- `russian_post_otpravka_postoffice_codes`: separate Otpravka/shipment setting used by the order shipment modal as selectable `postoffice-code`.

Tracking credentials are stored separately from Otpravka AccessToken/login/password and from the Tariff API token:

- `russian_post_tracking_login`
- `russian_post_tracking_password_encrypted`

## 4. Tariff Variants

The internal `DomesticTariffVariant` model stores:

- `object_code`
- `title`
- `enabled`
- `delivery_type`
- `requires_declared_value` / shipment `has_declared_value`
- `is_ecom`
- `always_available`
- `min_weight_g`
- `max_weight_g`
- `sort_order`
- `admin_comment`

Pickup/OPS variants:

| Object code | Name | Declared value | ECOM |
| ---: | --- | :---: | :---: |
| `4030` | Посылка нестандартная | no | no |
| `4020` | Посылка нестандартная с ОЦ | yes | no |
| `47030` | Посылка 1 класса | no | no |
| `47020` | Посылка 1 класса с ОЦ | yes | no |
| `54020` | ЕКОМ Маркетплейс с ОЦ | yes | setting-controlled |
| `23030` | Посылка онлайн | no | no |
| `23020` | Посылка онлайн с ОЦ | yes | no |

Courier variants:

| Object code | Name | Declared value |
| ---: | --- | :---: |
| `24030` | Курьер онлайн | no |
| `24020` | Курьер онлайн с ОЦ | yes |
| `7030` | EMS | no |
| `7020` | EMS с ОЦ | yes |
| `41030` | EMS РТ | no |
| `52030` | EMS Тендер обыкновенное | no |

Declared-value variants receive `sumoc` in Tariff API requests. `sumoc` is declared value in kopecks.

If tariffs are explicitly configured in service settings, the resolver does not filter out a declared-value tariff merely because `insurance_enabled=false`; it uses the configured `enabled`, `delivery_type`, weight limits, and sort order. The global `insurance_enabled` flag affects only default variants when no explicit `tariff_variants` list exists.

Skipped tariff diagnostics preserve the reason and safe API details. Current reasons include:

- `filtered_by_settings`
- `filtered_by_delivery_type`
- `filtered_by_weight`
- `filtered_by_insurance`
- `api_error`
- `empty_price`

If Russian Post API documentation or behavior requires `group`, the integration should first observe the response without `group`; when the API returns an error, retry with `group=0` and record that behavior in diagnostics.

Deprecated object codes `27030`, `27020`, `28030`, `28020` are no longer created in defaults.

## 5. Tariff API

`RussianPostDomesticApiClient` calls:

```text
GET https://tariff.pochta.ru/v2/calculate/tariff/delivery
```

Runtime always sends:

- `object`
- `from`
- `to`
- `weight`
- `date`
- `pack=99`
- `sumoc` only for declared-value variants

Request shape for diagnostics:

```text
https://tariff.pochta.ru/v2/calculate/tariff/delivery?json&errorcode=0&object=OBJECT&from=FROM&to=TO&weight=WEIGHT&date=YYYYMMDD&pack=99
```

For declared-value tariff candidates, add:

```text
sumoc=VALUE_IN_KOPECKS
```

The Tariff API token remains supported: when configured, the client sends it as `Authorization: Bearer ...`.

Normalized rate metadata stores `pay`, `nds`, `paynds`, `delivery_min_days`, `delivery_max_days`, `transtype`, `delivery_to`, `items_summary`, request params, cache/debug metadata, and safe diagnostics. Full raw API responses are not stored in order calculation payload.

If the API rejects one object code, the carrier skips only that tariff variant and exposes diagnostics in debug/simulation: `object_code`, `pack`, request URL, request params, `http_code`, `errorcode`/`errormsg`, decoded body, raw error body, and normalized error code/message.

## 6. Pickup Point Logic

The local Russian Post pickup directory is stored in `wp_wdc_pickup_points_russian_post`. Checkout, REST, diagnostics, and the shipment modal read the local table rather than embedding pickup fixtures in checkout.

In the WooCommerce order admin shipment modal, managers may choose another Russian Post OPS/PVZ from the local table. The selector:

- searches `postcode`, `city_name`, and `address`;
- reuses the configured Leaflet/Yandex pickup map provider;
- updates only the shipment draft/preview/create request;
- does not recalculate checkout tariffs;
- does not change the saved checkout delivery method;
- does not write WooCommerce order meta.

Normal pickup/OPS shipment payloads are not ECOM. They use the selected pickup point from order/local DB and send:

- `address-type-to=DEMAND`
- `index-to`
- `region-to`
- `place-to`

`index-to` is always a six-digit postcode. A pickup code such as `660017-...` is never sent as `index-to`. The builder does not use client-side `до востребования` fallback logic. The human-readable admin address is `{index}, {region}, {place}, до востребования`.

ECOM shipment payloads are controlled by the per-tariff `is_ecom` setting. For ECOM pickup, the builder sends:

- `ecom-data.delivery-point-index`

The value is a six-digit delivery point index, not the full pickup point code. ECOM payloads omit the normal pickup address schema unless a later Russian Post product requires additional fields. Object `54020` maps to `ECOM_MARKETPLACE`, but `ecom-data` is still enabled only by the tariff setting.

## 7. Courier Address Normalization

Courier shipment creation uses Russian Post address normalization before create.

The modal shows the original WooCommerce shipping address. A manager runs `Обработать адрес`; `RussianPostAddressNormalizer` calls the Otpravka address-cleaning API through `RussianPostOtpravkaApiClient::clean_address()`.

Successful normalized payload fields are:

- `address-type-to`
- `index-to`
- `region-to`
- `area-to`
- `place-to`
- `location-to`
- `street-to`
- `house-to`
- `slash-to`
- `letter-to`
- `building-to`
- `corpus-to`
- `room-to`
- `num-address-type-to`

`address-type-to` defaults to `DEFAULT` when the normalized row does not provide one.

The normalization result is cached and validated by the original-address hash/snapshot. Creation is blocked until a valid normalization exists. If the source address changes, the cached normalized payload is cleared and creation is blocked again. Failed normalization can be used only for safe preview fallback, not for create.

## 8. Shipment Lifecycle

The shipment workflow is manual and starts from the WooCommerce order metabox `Отправления`. Shipments are never created automatically.

Preparation modal:

- reads the saved domestic service, selected tariff object, delivery type, and pickup/address data from hidden WDC order meta / `_wdc_delivery_calculation_data`;
- lets the manager review/edit recipient fields, delivery scenario, tariff, pickup/address, `postoffice-code`, and parcel places;
- renders a safe server-side preview of the Otpravka payload;
- accepts integer-only parcel/place values;
- shows calculated weight in the field label rather than forcing it into the editable value;
- shows declared value only for tariffs whose shipment product has declared value;
- uses compact place rows and supports multiple places for MMO-compatible products.

Object-code to Otpravka product mapping is handled in `src/Shipments/RussianPost/RussianPostShipmentProductMapper.php`. Multiple places are allowed only for MMO-compatible products: `ECOM_MARKETPLACE`, `EMS_RT`, `EMS_TENDER`, `ONLINE_COURIER`, and `ONLINE_PARCEL`. For MMO the payload sends one backlog object per place with:

- `add-to-mmo=true`
- `group-name=<WooCommerce order number>`

Plain parcel/courier/EMS shipment variants use `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`. Domestic shipment payloads always send `mail-direct=643`.

Create shipment:

```text
PUT /2.0/user/backlog
```

On successful create, WDC stores barcode/ШПИ and the Otpravka create-response `result-id` as hidden `backlog_order_id`. Barcode/ШПИ is the primary tracking number shown to managers and used by Tracking API. `backlog_order_id` is reserved for internal Otpravka backlog operations such as cancellation and is not shown to customers, emails, account pages, public tracking blocks, toasts, or status-refresh messages.

After successful create, the preparation modal closes, a 10-second admin toast confirms creation, and WDC automatically starts the first `wdc_update_shipment_status` request. If the status refresh fails, creation remains successful and the metabox shows a warning.

After successful create and before saving final shipment state, WDC also tries `GET /1.0/backlog/search?query={barcode}` to fetch actual Russian Post totals. Numeric `total-rate-wo-vat + total-vat` is stored as `russian_post_actual_cost_kopecks` / `russian_post_actual_cost_rub` with `russian_post_actual_cost_source=backlog_search_after_create`. Lookup failure or missing totals does not fail creation and does not produce an admin warning.

Manual status update uses the existing `Обновить статус` button for created shipments with barcode.

Cancel shipment:

- API: `DELETE /1.0/backlog`
- body: JSON array of internal ids, for example `[2285075494]`
- identifier: `backlog_order_id`, not barcode
- available only when shipment state has barcode and `backlog_order_id`, and the latest Russian Post operation is `28 / Присвоение идентификатора`
- on success WDC clears the shipment state so the manager can create or attach a shipment again

Remove from order clears only WooCommerce `_wdc_shipments` state and does not call Russian Post. It is shown when a shipment has tracking but is not eligible for Russian Post cancellation, including when status was not refreshed yet.

Manual tracking attachment:

- the manager enters barcode/ШПИ;
- WDC normalizes it;
- first lookup: `GET /1.0/backlog/search?query={barcode}`;
- fallback lookup when backlog search is empty: `GET /1.0/shipment/search?query={barcode}`;
- saved state records `source=manual_tracking_attach`;
- saved lookup source is `backlog_search` or `shipment_search`;
- returned `id`, when present, is saved as `backlog_order_id`;
- when `backlog_search` returns `total-rate-wo-vat` and `total-vat`, WDC stores `russian_post_actual_cost_kopecks`, `russian_post_actual_cost_rub`, and `russian_post_actual_cost_source=backlog_search`;
- the metabox shows `Цена: {amount} руб.` after `Отслеживание` and compares it with `_wdc_delivery_calculation_data.api.api_base_price_rub` / `Базовая стоимость API` through the shared actual-cost presentation service; up to and including exactly 3% over base is ok, more than 3% is warning;
- if only `shipment_search` finds the parcel or the total fields are absent, the actual price fields are not filled and the price row is omitted;
- if shipment search returns barcode but no `id`, WDC still saves tracking and runs Tracking API by barcode;
- cancellation remains disabled when `backlog_order_id` is absent.

Inactive metabox actions are hidden rather than shown disabled.

## 9. Tracking

Manual status refresh is available in the WooCommerce order metabox `Отправления` for created Russian Post domestic shipments with a barcode. The `Обновить статус` button calls AJAX action `wdc_update_shipment_status`; then `RussianPostTrackingApiClient` requests `getOperationHistory` from:

```text
https://tracking.russianpost.ru/rtm34
```

The client uses SOAP 1.2 and only the tracking credentials from `API / Credentials`:

- `russian_post_tracking_login`
- `russian_post_tracking_password_encrypted`

The latest `historyRecord` is selected by `OperDate`, mapped by operation type/attribute through `RussianPostTrackingStatusMapper`, and saved in `_wdc_shipments`. Unknown operation/attribute pairs are saved as `unknown` / `не определён` while preserving the raw Russian Post operation and attribute names.

The current mapping includes:

- `8:2`, related pickup operations, `12:1..12:31`, and `42:1..42:30` -> `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`
- `8:15` and `8:18` -> `handed_to_courier` / `передан курьеру`
- no-attribute fallback through `type:-`, including operation `28:-` -> `created_in_carrier` / `создан в ТК` and `46:-` -> `cancelled` / `отменён`

Automatic status polling is available since 0.38.0 through the universal `WDC -> Статусы` autosync service. It reuses the existing Russian Post status refresh pipeline and does not create shipments, cancel shipments, change WooCommerce order statuses, or generate documents.

Full universal status and mapping documentation lives in `docs/wdc-shipment-statuses.md`.

## 10. Documents

Russian Post documents are intentionally not implemented in WDC.

- WDC does not call the Russian Post Forms API.
- WDC does not generate labels.
- WDC does not create batches.
- WDC does not create F103.
- The disabled `Скачать документы` placeholder is not shown in the metabox.
- Managers prepare labels, batches, F103, and other Russian Post documents manually in the Russian Post account.

## 11. Diagnostics

PowerShell dry run:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --dry-run
```

PowerShell API probe:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020
```

Optional parameters:

- `--sumoc=500000`
- `--date=YYYYMMDD`
- `--insecure`

The script does not require WooCommerce checkout. It prints sanitized JSON summaries to the console and does not save raw full responses in the repository.

For local Windows diagnostics only, when PHP fails with `self-signed certificate in certificate chain (19)` and the trust store is not configured, run:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020 --insecure
```

`--insecure` disables SSL verification only in this test helper and adds a warning to JSON output. Do not use this behavior in production runtime.

## 12. Historical Migration Notes

Migration `0026_unify_russian_post_domestic_service.php` is the one-way migration from the previous two-service domestic Russian Post model to the unified service.

It creates/activates the unified service, copies old service settings and carrier credentials into the service settings table, merges tariff variants by `delivery_type:object_code`, copies pickup type settings to `russian_post_domestic_point_type_*`, and pins RU availability.

After the data is copied, it physically deletes the old `russian_post_domestic_pickup` and `russian_post_domestic_courier` service rows, their `wdc_delivery_service_settings` rows, `wdc_delivery_service_countries` rows, and service-rule bindings/conditions. Backward compatibility with old domestic service keys is intentionally not supported.
