# Russian Post Domestic Carrier

Version: 0.21.20.

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
| `27030` | Посылка стандарт |
| `27020` | Посылка стандарт с ОЦ |
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
| `28030` | Посылка курьер EMS |
| `28020` | Посылка курьер EMS с ОЦ |
| `7030` | EMS |
| `7020` | EMS с ОЦ |

Declared-value candidates receive `sumoc` in the tariff probe and future API requests:

- `27020`
- `4020`
- `47020`
- `54020`
- `23020`
- `24020`
- `28020`
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
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=27030,27020,4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,28030,28020,7030,7020
```

Optional parameters:

- `--sumoc=500000`
- `--date=YYYYMMDD`
- `--insecure`

The script does not require WooCommerce checkout. It prints sanitized JSON summaries to the console and does not save raw full responses in the repository.

For local Windows diagnostics only, when PHP fails with `self-signed certificate in certificate chain (19)` and the trust store is not configured, run:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=27030,27020,4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,28030,28020,7030,7020 --insecure
```

`--insecure` disables SSL verification only in this test helper and adds a warning to JSON output. Do not use this behavior in production runtime.
# Почта России — по России

Этап foundation добавляет carrier `russian_post_domestic` и две service:

- `russian_post_domestic_pickup`
- `russian_post_domestic_courier`

Обе службы доступны только для `RU`, используют локальный город/индекс из checkout context и не рассчитываются без валидного шестизначного `postal_code`. Технический индекс `999999999` считается отсутствующим индексом.

## Tariff variants

Внутренняя модель `DomesticTariffVariant` хранит `object_code`, `title`, `enabled`, `delivery_type`, `requires_declared_value`, `always_available`, weight limits и `sort_order`.

Pickup variants: `27030`, `27020`, `4030`, `4020`, `47030`, `47020`, `54020`, `23030`, `23020`.

Courier variants: `24030`, `24020`, `28030`, `28020`, `7030`, `7020`, `41030`, `52030`.

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

Если API отклоняет отдельный object code, carrier пропускает только этот tariff variant и показывает диагностику в debug/simulation: `object_code`, request params, `http_code`, `errorcode`/`errormsg` и нормализованные error code/message. Для `27030` runtime передает `pack=99`; если live API для конкретного договора требует дополнительные параметры, нужно фиксировать точный API error и добавлять параметр явно.

## Pickup without selector

Pickup variants выставляют `no_pickup_selection=true`. Это означает доставку до почтового отделения по индексу, без выбора ПВЗ в checkout.
