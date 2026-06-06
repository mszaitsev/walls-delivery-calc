# Russian Post Domestic Carrier

Version: 0.37.5.

## Tracking Statuses 0.37.1

Manual status refresh is available in the WooCommerce order metabox `Отправления` for created Russian Post domestic shipments with a barcode. The existing `Обновить статус` button calls AJAX action `wdc_update_shipment_status`, then `RussianPostTrackingApiClient` requests `getOperationHistory` from `https://tracking.russianpost.ru/rtm34` using SOAP 1.2.

Tracking credentials are edited on the unified domestic service tab `API / Credentials`:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

They are not the Otpravka AccessToken/login/password and not the Tariff API token. The latest `historyRecord` is selected by `OperDate`, mapped by operation type/attribute through `RussianPostTrackingStatusMapper`, and saved in `_wdc_shipments`. Unknown operation/attribute pairs are saved as `unknown` / `не определён` while preserving the raw Russian Post operation and attribute names. Automatic polling is not part of 0.36.2.

The 0.36.1 mapping correction moves `8:2` and related pickup operations, all `12:1..12:31`, and all `42:1..42:30` to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`. Pairs `8:15` and `8:18` map to `handed_to_courier` / `передан курьеру`. Unknown pairs remain `unknown` / `не определён`.

The 0.36.2 mapping fallback treats empty, absent, `0`, and `-` attributes as compatible with `type:-` mappings when no exact pair exists. Russian Post operation `28` without an attribute maps to `created_in_carrier` / `создан в ТК`; operation `46` without an attribute maps to `cancelled` / `отменён`. After successful shipment creation, the order metabox closes the preparation modal, shows a 10-second toast with barcode, and starts the first status refresh automatically.

Version 0.36.4 stores Otpravka create-response `result-id` as `backlog_order_id` in `_wdc_shipments`. Barcode/ШПИ is still the primary shipment identifier and is used by the Tracking API (`getOperationHistory`). The success raw response snapshot remains safe and does not need to keep `result-id` because the value is stored explicitly. In 0.37.0 `backlog_order_id` is kept hidden and used for internal Otpravka operations such as cancellation; it is not shown to customers, emails, account pages, public tracking blocks, toasts, or status-refresh messages.

Version 0.37.0 keeps Russian Post documents out of the plugin workflow: labels, batches, F103 and documents are prepared manually in the Russian Post account. WDC does not call Forms API, does not create batches, and does not show a disabled document-download placeholder.

Version 0.37.1 extends manual tracking attachment with a fallback lookup. WDC searches `GET /1.0/backlog/search?query={barcode}` first, then `GET /1.0/shipment/search?query={barcode}` when backlog search returns no rows. The saved state records `source_lookup=backlog_search` or `source_lookup=shipment_search`. If shipment search returns barcode but no `id`, WDC still saves tracking and runs Tracking API by barcode; cancellation remains disabled because `DELETE /1.0/backlog` requires `backlog_order_id`.

Cancellation uses Otpravka `DELETE /1.0/backlog` with a JSON array body such as `[2285075494]`. The id is `backlog_order_id`, not barcode. Cancellation is enabled only when shipment state has barcode and `backlog_order_id`, and the latest Russian Post operation is `28 / Присвоение идентификатора`. On success WDC clears the shipment state so the manager can create or attach a shipment again.

Manual attachment searches `GET /1.0/backlog/search?query={barcode}` first and falls back to `GET /1.0/shipment/search?query={barcode}` when backlog search returns no rows. The manager enters ШПИ, WDC normalizes it, saves barcode plus returned `id` as `backlog_order_id` when available, marks `source=manual_tracking_attach`, records the lookup source, and attempts the first Tracking API refresh. Tracking still uses barcode/ШПИ.

This document records the current Russian Post domestic foundation after WDC 0.37.5. The early carrier contract now includes checkout quoting, unified service settings, shipment preview/create, tracking status refresh, backlog cancellation, manual tracking attachment, and a deliberately manual documents workflow.

## Carrier Scope

- `carrier_key`: `russian_post_domestic`
- `service_key`: `russian_post_domestic`
- Country scope: `RU` only.
- Delivery types: `pickup`, `courier`.
- Pickup/OPS and courier are runtime delivery types, not separate services.
- Checkout group ids are `russian_post_domestic:pickup` and `russian_post_domestic:courier`.
- Concrete tariff rates are `russian_post_domestic:pickup:{object_code}` and `russian_post_domestic:courier:{object_code}`.
- Cash on delivery / mandatory payment is not used.
- Insurance is represented by declared value. In Russian Post tariff API terms this includes object-code analogs that use `sumoc`.

## Tariff Candidates

| Object code | Name |
| ---: | --- |
| `4030` | Посылка нестандартная |
| `4020` | Посылка нестандартная с ОЦ |
| `47030` | Посылка 1 класса |
| `47020` | Посылка 1 класса с ОЦ |
| `54020` | ЕКОМ Маркетплейс с ОЦ |
| `41030` | EMS РТ |
| `52030` | EMS Тендер обыкновенное |
| `23030` | Посылка онлайн |
| `23020` | Посылка онлайн с ОЦ |
| `24030` | Курьер онлайн |
| `24020` | Курьер онлайн с ОЦ |
| `7030` | EMS |
| `7020` | EMS с ОЦ |

Declared-value candidates receive `sumoc` in tariff API requests. `sumoc` is the declared value in kopecks:

- `4020`
- `47020`
- `54020`
- `23020`
- `24020`
- `7020`

If Russian Post API documentation or behavior requires `group`, the integration should first observe the response without `group`; when the API returns an error, retry with `group=0` and record that behavior in diagnostics.

## Indices

- `from_postcodes`: configured origin indices for tariff calculation.
- `default_from_postcode`: fallback origin index for tariff calculation and the default shipment-registration from index.
- `return_postcode`: return index for tariff calculation.
- `russian_post_otpravka_postoffice_codes`: Otpravka acceptance indices used as `postoffice-code` in the shipment modal.

## Checkout And Order Data

Checkout tariff calculation uses the configured origin index from service settings. Order shipping data stores:

- destination postcode;
- selected tariff object;
- `delivery_type`.
- service key and calculation metadata in hidden WDC order meta / `_wdc_delivery_calculation_data`.

Pickup remains a platform mechanism and must not depend on test/demo fixtures.

## Shipment Creation Foundation

The first shipment runtime uses the saved domestic service and selected tariff object from WooCommerce order meta as defaults for a manual admin flow. A manager opens the order metabox `Отправления`, reviews recipient, delivery type, pickup/address and parcel places, then creates a Russian Post Otpravka backlog order.

Object-code to Otpravka product mapping is handled in `src/Shipments/RussianPost/RussianPostShipmentProductMapper.php`. Multiple places are allowed only for MMO-compatible products: `ECOM_MARKETPLACE`, `EMS_RT`, `EMS_TENDER`, `ONLINE_COURIER`, and `ONLINE_PARCEL`. For MMO the payload sends one backlog object per place with `add-to-mmo=true` and `group-name` equal to the WooCommerce order number.

Plain parcel/courier/EMS shipment variants use Otpravka `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`. Domestic shipment payloads send `mail-direct=643`.

Normal pickup/OPS shipment payloads are not ECOM. They use the selected pickup point from order/local DB and send `address-type-to=DEMAND` with `index-to`, `region-to`, and `place-to`; `ecom-data` is not sent. `index-to` is always a six-digit postcode, so a pickup code such as `660017-...` is never sent as `index-to`. The builder does not use client-side `до востребования` fallback logic. The corresponding human-readable admin address is `{index}, {region}, {place}, до востребования`.

In the WooCommerce order admin shipment modal, managers may choose another Russian Post OPS/PVZ from the local `wp_wdc_pickup_points_russian_post` table. The selector searches `postcode`, `city_name` and `address`, reuses the configured Leaflet/Yandex pickup map provider, and updates only the shipment draft/preview/create request. It does not recalculate checkout tariffs, change the saved order delivery method, or write WooCommerce order meta.

ECOM shipment payloads are enabled by a per-tariff `is_ecom` setting in Delivery Services. For these tariffs the shipment builder sends `ecom-data.delivery-point-index` with a six-digit delivery point index and omits the normal pickup address schema unless a later Russian Post product requires additional fields. Object `54020` maps to `ECOM_MARKETPLACE`, but using `ecom-data` is still controlled by the tariff setting.

Courier payloads use normalized Russian Post address fields from the address-cleaning result: `address-type-to`, `index-to`, `region-to`, `area-to`, `place-to`, `location-to`, `street-to`, `house-to`, `slash-to`, `letter-to`, `building-to`, `corpus-to`, `room-to`, and `num-address-type-to`. The modal shows the original WooCommerce shipping address and the manager runs `Обработать адрес`. The normalization result is cached and validated by the original-address hash/snapshot; successful creation is blocked until a valid normalization exists. If the source address changes, the normalized payload is cleared and creation is blocked again. Failed normalization can be used only for safe preview fallback, not for create.

The runtime calls:

```text
PUT /2.0/user/backlog
```

through the shared Otpravka client. API credentials are edited on the unified domestic delivery service tab `API / Credentials`.

## Russian Post Tariff API

The domestic carrier calls:

```text
GET https://tariff.pochta.ru/v2/calculate/tariff/delivery
```

Request shape for diagnostics:

```text
https://tariff.pochta.ru/v2/calculate/tariff/delivery?json&errorcode=0&object=OBJECT&from=FROM&to=TO&weight=WEIGHT&date=YYYYMMDD&pack=99
```

For declared-value tariff candidates, add:

```text
sumoc=VALUE_IN_KOPECKS
```

The Tariff API token remains supported as `Authorization: Bearer <token>` when configured.

The API response must be inspected for:

- `pay`
- `nds`
- `paynds`
- `delivery.min`
- `delivery.max`
- `postoffice`
- `items`

## Probe Script

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
# Почта России — по России

As of WDC 0.35.2, Russian Post domestic uses one carrier and one delivery service settings context:

- `carrier_key`: `russian_post_domestic`
- `service_key`: `russian_post_domestic`

Pickup/OPS and courier are no longer separate services. Runtime separation is done through `delivery_type=pickup|courier`. Checkout still renders two visible groups:

- `russian_post_domestic:pickup` -> `Почта России до ПВЗ / ОПС`
- `russian_post_domestic:courier` -> `Почта России курьером`

Concrete tariff rates extend the group id with the object code, for example `russian_post_domestic:pickup:23030` and `russian_post_domestic:courier:24030`. Order meta for new orders stores `_wdc_platform_service_key=russian_post_domestic`, `_wdc_platform_delivery_type`, and `_wdc_platform_tariff_object`.

The service is available only for `RU`, uses the local city/postcode from checkout context and does not calculate without a valid six-digit `postal_code`. Technical postcode `999999999` is treated as missing.

## Tariff variants

Внутренняя модель `DomesticTariffVariant` хранит `object_code`, `title`, `enabled`, `delivery_type`, `requires_declared_value` / shipment `has_declared_value`, `is_ecom`, `always_available`, weight limits (`min_weight_g`, `max_weight_g`), `sort_order` и `admin_comment`.

Pickup variants: `4030`, `4020`, `47030`, `47020`, `54020`, `23030`, `23020`.

Courier variants: `24030`, `24020`, `7030`, `7020`, `41030`, `52030`.

Если тарифы явно настроены в service settings, resolver не отбрасывает declared-value тариф только из-за `insurance_enabled=false`: используются `enabled`, `delivery_type`, весовые ограничения и порядок сортировки из настройки. Глобальный `insurance_enabled` влияет только на набор defaults, когда явного списка `tariff_variants` еще нет. Диагностика пропущенных тарифов сохраняет причину (`filtered_by_settings`, `filtered_by_delivery_type`, `filtered_by_weight`, `filtered_by_insurance`, `api_error`, `empty_price`) и безопасные детали API-запроса/ответа.

## API

`RussianPostDomesticApiClient` вызывает `GET https://tariff.pochta.ru/v2/calculate/tariff/delivery`. Runtime всегда передает:

- `object`
- `from`
- `to`
- `weight`
- `date`
- `pack=99`
- `sumoc` только для declared-value variants

Tariff API token остается поддержанным: при заполненном поле клиент отправляет его как `Authorization: Bearer ...`.

В meta сохраняются нормализованные поля `pay`, `nds`, `paynds`, `delivery_min_days`, `delivery_max_days`, `transtype`, `delivery_to`, `items_summary`, request params и cache/debug metadata. Полный raw response в order calculation payload не сохраняется.

Если API отклоняет отдельный object code, carrier пропускает только этот tariff variant и показывает диагностику в debug/simulation: `object_code`, `pack`, request URL, request params, `http_code`, `errorcode`/`errormsg`, decoded body, raw error body и нормализованные error code/message. Deprecated object codes `27030`, `27020`, `28030`, `28020` больше не создаются в defaults.

## Unified Service Settings

All domestic Russian Post settings live on `admin.php?page=wdc-delivery-services&service=russian_post_domestic`.

Tabs:

- `Основные`: canonical service identity, enabled state, RU availability and configurable checkout method titles `pickup_method_title`/`courier_method_title`.
- `Расчет`: tariff calculation origin/return postcodes, insurance, timeout/cache/debug, packaging weight, rounding, minimum price and fallback settings.
- `Тарифы`: one merged tariff list with `delivery_type`, enabled state, ECOM flag, declared-value flag, weight limits, custom titles and sort order.
- `ПВЗ / ОПС`: point type settings, local pickup import state and pickup diagnostics.
- `API / Credentials`: Tariff API endpoint/token, Otpravka AccessToken/login/password/timeout, postoffice acceptance indices, default from postcode for shipment registration, plus tracking login/password fields used separately by status refresh.
- `Отправления`: `shelf_life_days_default`, `send_goods_items`, `combine_goods_items_default`, `combined_goods_name_template`.
- `Статусы / Mapping`: stored status mapping/polling/WooCommerce sync settings; Russian Post operation mapping used by current manual refresh is bundled in code.
- `Диагностика`: service/settings/PVZ quick diagnostics.

`WDC -> Перевозчики` is no longer registered. Tariff API endpoint/token, Otpravka credentials and postoffice codes are edited only inside the domestic delivery service. The unified service settings table is the only runtime source of truth for domestic Russian Post settings.

`from_postcodes` is labeled `Индекс отправки для расчета доставки`; `return_postcode` is labeled `Индекс возврата для расчета доставки`. `default_from_postcode` is edited on `API / Credentials` beside the postoffice acceptance list, but keeps the same storage key and is still used by tariff calculation as a fallback origin index. `russian_post_otpravka_postoffice_codes` is a separate Otpravka/shipment setting used by the order shipment modal as the selectable `postoffice-code`.

Checkout method labels are built from the configured method title plus the selected tariff title and delivery days, for example `Почта России до отделения, Посылка онлайн - 7 дней` or `Почта России до двери, Курьер онлайн`. Visible domestic WooCommerce shipping item meta contains only `Срок доставки`. Technical values such as delivery type, selected tariff, service key and pickup point code/type/postcode/address are stored in hidden WDC order meta and `_wdc_delivery_calculation_data`. Pickup code is not written to `shipping_address_2`; shipment creation reads pickup data from WDC meta/calculation data.

Historical migration note: migration `0026_unify_russian_post_domestic_service.php` creates/activates the unified service, copies old service settings and carrier credentials into the service settings table, merges tariff variants by `delivery_type:object_code`, copies pickup type settings to `russian_post_domestic_point_type_*`, and pins RU availability. After the data is copied, it physically deletes the old `russian_post_domestic_pickup` and `russian_post_domestic_courier` service rows, their `wdc_delivery_service_settings` rows, `wdc_delivery_service_countries` rows, and service-rule bindings/conditions. Backward compatibility with old domestic service keys is intentionally not supported.
