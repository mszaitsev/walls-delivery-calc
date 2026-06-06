# Russian Post Domestic Carrier

Version: 0.22.00.

This document fixes the stage-1 contract for the future domestic Russian Post carrier. It is documentation and API diagnostics only; checkout quoting and shipment creation are intentionally not implemented here.

## Carrier Scope

- `carrier_key`: `russian_post_domestic`
- Country scope: `RU` only.
- Delivery types: `pickup`, `courier`.
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

Declared-value candidates receive `sumoc` in the tariff probe and future API requests:

- `4020`
- `47020`
- `54020`
- `23020`
- `24020`
- `7020`

If Russian Post API documentation or behavior requires `group`, the integration should first observe the response without `group`; when the API returns an error, retry with `group=0` and record that behavior in diagnostics.

## Indices

- From indices: multiple configured indices are expected.
- Default from index: `630005`.
- Checkout uses the default from index.
- Return index: single configured index.

## Checkout And Order Data

The future checkout calculation should use the default from index. Later, order shipping data must store:

- destination postcode;
- selected tariff object;
- `delivery_type`.

Pickup remains a platform mechanism and must not depend on test/demo fixtures.

## Shipment Creation Foundation

The first shipment runtime uses the saved domestic service and selected tariff object from WooCommerce order meta as defaults for a manual admin flow. A manager opens the order metabox `Отправления`, reviews recipient, delivery type, pickup/address and parcel places, then creates a Russian Post Otpravka backlog order.

Object-code to Otpravka product mapping is handled in `src/Shipments/RussianPost/RussianPostShipmentProductMapper.php`. Multiple places are allowed only for MMO-compatible products: `ECOM_MARKETPLACE`, `EMS_RT`, `EMS_TENDER`, `ONLINE_COURIER`, and `ONLINE_PARCEL`. For MMO the payload sends one backlog object per place with `add-to-mmo=true` and `group-name` equal to the WooCommerce order number.

Plain parcel/courier/EMS shipment variants use Otpravka `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`. Domestic shipment payloads send `mail-direct=643`.

Normal pickup/OPS shipment payloads are not ECOM. They use `address-type-to=DEMAND` with `index-to`, `region-to`, and `place-to`; `ecom-data` is not sent. The corresponding human-readable admin address is `{index}, {region}, {place}, до востребования`.

In the WooCommerce order admin shipment modal, managers may choose another Russian Post OPS/PVZ from the local `wp_wdc_pickup_points_russian_post` table. The selector searches `postcode`, `city_name` and `address`, reuses the configured Leaflet/Yandex pickup map provider, and updates only the shipment draft/preview/create request. It does not recalculate checkout tariffs, change the saved order delivery method, or write WooCommerce order meta.

ECOM shipment payloads are enabled by a per-tariff `is_ecom` setting in Delivery Services. For these tariffs the shipment builder sends `ecom-data.delivery-point-index` and omits the normal pickup address schema unless a future product requires additional fields. Object `54020` maps to `ECOM_MARKETPLACE`, but using `ecom-data` is still controlled by the tariff setting.

Courier payloads use `address-type-to=DEFAULT`, `index-to`, `region-to`, `place-to`, `raw-address`, `courier=true`, and `delivery-to-door=true`. `raw-address` is assembled from WooCommerce shipping postcode, state, city, address line 1 and address line 2; address line 2 is skipped when it starts with `Код ПВЗ`.

The runtime calls:

```text
PUT /2.0/user/backlog
```

through the shared Otpravka client. API credentials are edited on the unified domestic delivery service tab `API / Credentials`.

## Russian Post Tariff API

The domestic carrier should call:

```text
/v2/calculate/tariff/delivery
```

Request shape for diagnostics:

```text
https://tariff.pochta.ru/v2/calculate/tariff/delivery?json&errorcode=0&object=OBJECT&from=FROM&to=TO&weight=WEIGHT&date=YYYYMMDD
```

For declared-value tariff candidates, add:

```text
sumoc=VALUE
```

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

Внутренняя модель `DomesticTariffVariant` хранит `object_code`, `title`, `enabled`, `delivery_type`, `requires_declared_value`, `always_available`, weight limits и `sort_order`.

Pickup variants: `4030`, `4020`, `47030`, `47020`, `54020`, `23030`, `23020`.

Courier variants: `24030`, `24020`, `7030`, `7020`, `41030`, `52030`.

Если `insurance_enabled=false`, resolver берет тарифы без объявленной ценности. Если `insurance_enabled=true`, берет declared-value аналоги. `54020` помечен `always_available`.

## API

`RussianPostDomesticApiClient` вызывает `GET /v2/calculate/tariff/delivery`. Runtime всегда передает:

- `object`
- `from`
- `to`
- `weight`
- `date`
- `pack=99`
- `sumoc` только для declared-value variants

В meta сохраняются нормализованные поля `pay`, `nds`, `paynds`, `delivery_min_days`, `delivery_max_days`, `transtype`, `delivery_to`, `items_summary`, request params и cache/debug metadata. Полный raw response в order calculation payload не сохраняется.

Если API отклоняет отдельный object code, carrier пропускает только этот tariff variant и показывает диагностику в debug/simulation: `object_code`, `pack`, request URL, request params, `http_code`, `errorcode`/`errormsg`, decoded body, raw error body и нормализованные error code/message. Deprecated object codes `27030`, `27020`, `28030`, `28020` больше не создаются в defaults.

## Unified Service Settings

All domestic Russian Post settings live on `admin.php?page=wdc-delivery-services&service=russian_post_domestic`.

Tabs:

- `Основные`: canonical service identity, enabled state, RU availability and configurable checkout method titles `pickup_method_title`/`courier_method_title`.
- `Расчет`: tariff calculation origin/return postcodes, insurance, timeout/cache/debug, packaging weight, rounding, minimum price and fallback settings.
- `Тарифы`: one merged tariff list with `delivery_type`, enabled state, ECOM flag, declared-value flag, weight limits, custom titles and sort order.
- `ПВЗ / ОПС`: point type settings, local pickup import state and pickup diagnostics.
- `API / Credentials`: Tariff API endpoint/token, Otpravka AccessToken/login/password/timeout, postoffice acceptance indices, default from postcode, plus stored-only tracking login/password fields.
- `Отправления`: `shelf_life_days_default`, `send_goods_items`, `combine_goods_items_default`, `combined_goods_name_template`.
- `Статусы / Mapping`: stored-only placeholder for future status mapping, polling defaults and WooCommerce status sync settings.
- `Диагностика`: service/settings/PVZ quick diagnostics.

`WDC -> Перевозчики` is no longer registered. Tariff API endpoint/token, Otpravka credentials and postoffice codes are edited only inside the domestic delivery service. The unified service settings table is the only runtime source of truth for domestic Russian Post settings.

`from_postcodes` is labeled `Индекс отправки для расчета доставки`; `return_postcode` is labeled `Индекс возврата для расчета доставки`. `default_from_postcode` is edited on `API / Credentials` beside the postoffice acceptance list, but keeps the same storage key and is still used by tariff calculation as a fallback origin index. `russian_post_otpravka_postoffice_codes` is a separate Otpravka/shipment setting used by the order shipment modal as the selectable `postoffice-code`.

Checkout method labels are built from the configured method title plus the selected tariff title and delivery days, for example `Почта России до отделения, Посылка онлайн - 7 дней` or `Почта России до двери, Курьер онлайн`. Visible domestic WooCommerce shipping item meta contains only `Срок доставки`. Technical values such as delivery type, selected tariff, service key and pickup point code/type/postcode/address are stored in hidden WDC order meta and `_wdc_delivery_calculation_data`. Pickup code is not written to `shipping_address_2`; shipment creation reads pickup data from WDC meta/calculation data.

Migration `0026_unify_russian_post_domestic_service.php` creates/activates the unified service, copies old service settings and carrier credentials into the service settings table, merges tariff variants by `delivery_type:object_code`, copies pickup type settings to `russian_post_domestic_point_type_*`, and pins RU availability. After the data is copied, it physically deletes the old `russian_post_domestic_pickup` and `russian_post_domestic_courier` service rows, their `wdc_delivery_service_settings` rows, `wdc_delivery_service_countries` rows, and service-rule bindings/conditions. Backward compatibility with old domestic service keys is intentionally not supported.
