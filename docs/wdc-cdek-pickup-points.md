# WDC CDEK Pickup Points

Version: 0.45.1.

0.45.1 QA fix: CDEK pickup validation is now restored from the CDEK checkout/session payload and no longer falls through to the Russian Post pickup repository for CDEK point codes. CDEK `point_code`/`cdek_code` is the CDEK delivery point code, for example `KEM7`, and is not the postcode. CDEK `PVZ` and `POSTAMAT` are rendered separately: `PVZ` is `Пункт выдачи СДЭК`, `POSTAMAT` is `Постамат СДЭК`. CDEK postamats use a separate marker color and show `Срок хранения 3 дня` as red bold text in map popup, selected point cards, order display and emails. CDEK pickup descriptions are preserved in session/order calculation data and rendered with the pickup card.

This stage connects CDEK pickup points to the existing WDC pickup map/picker flow. It uses CDEK API v2 `GET /v2/deliverypoints` and reuses the same checkout/admin pickup infrastructure that already serves Russian Post.

## Implemented

- `CdekApiClient::deliveryPoints()` for authorized `GET /v2/deliverypoints`.
- `CdekDeliveryPointService` for loading, normalizing and caching CDEK delivery points.
- Minimal request parameters:
  - `city_code`.
  - `country_code=RU`.
  - `type=ALL` by default.
- Transient cache key includes active CDEK environment, `city_code`, delivery point type, and optional weight/dimensions when passed by caller.
- Cache TTL is 6 hours.
- Manual refresh can bypass cache through `refresh=true` in the pickup REST request.
- Normalized pickup payload compatible with the shared picker:
  - `carrier_key=cdek`;
  - `point_code`;
  - `point_type`;
  - `point_name`;
  - `point_address`;
  - `point_postcode`;
  - `city_name`;
  - `region_name`;
  - `latitude` / `longitude`;
  - `work_time`;
  - `description`;
  - `storage_notice`;
  - sanitized `raw`.
- CDEK-specific preserved fields:
  - `cdek_code`;
  - `cdek_uuid`;
  - `cdek_type`;
  - `cdek_owner_code`;
  - `cdek_nearest_station`;
  - `cdek_note`.
- `point_code` and `cdek_code` are always the CDEK point code from `code` (`KEM7` style). `point_postcode` keeps the postal index separately.
- Supported CDEK point types:
  - `PVZ` -> `Пункт выдачи СДЭК`;
  - `POSTAMAT` -> `Постамат СДЭК` plus `Срок хранения 3 дня`.
- CDEK pickup rates now require a pickup point: `requires_pickup_point=true`.
- Checkout map/picker supports `carrier_key=cdek`, passes CDEK city context, saves the selected point in the WooCommerce session, and keeps grouped CDEK pickup tariffs in one method family.
- Checkout order creation saves CDEK pickup data into `_wdc_delivery_calculation_data.pickup` and writes the selected pickup point address to the WooCommerce shipping address.
- Admin order delivery recalculation can load/search CDEK pickup points, blocks pickup save without a selected point, and writes the selected CDEK pickup address on save.
- Visible shipping item meta remains carrier-neutral and contains only delivery time.

## API

Endpoint:

```text
GET /v2/deliverypoints
```

The implementation currently sends:

```text
city_code={cdek_city_code}
country_code=RU
type=ALL
```

The CDEK HTML documentation used for this stage includes the `GET /v2/deliverypoints` section. The control phrase `Удаление подписки по UUID` was found in the same HTML export.

## Checkout Behavior

When the customer selects a CDEK pickup rate, the shared pickup map is opened for CDEK. The point payload saved in session must belong to `carrier_key=cdek`; changing to another pickup carrier family or courier family invalidates the selection. Switching between grouped CDEK pickup tariffs keeps the selected point only while the carrier family remains `cdek:pickup`.

On order create, WDC writes:

```text
shipping_country = RU
shipping_state = selected point region
shipping_city = selected point city
shipping_postcode = selected point postcode
shipping_address_1 = selected point address
shipping_address_2 = ''
```

The same address behavior is used by admin order delivery recalculation save.

## Calculation Data

`_wdc_delivery_calculation_data.pickup` stores:

```text
carrier_key
point_code
point_type
point_name
point_address
point_postcode
city_name
region_name
latitude
longitude
work_time
description
storage_notice
cdek_code
raw_sanitized
```

Sensitive data such as access tokens, client secrets and account credentials are not stored in order meta, calculation data or logs.

## Error Handling

If CDEK delivery points cannot be loaded, checkout/admin picker UI remains usable and shows a carrier-specific error message:

```text
Не удалось загрузить пункты выдачи СДЭК. Попробуйте позже.
```

Logs contain sanitized diagnostic context:

```text
carrier=cdek
city_code
endpoint=/v2/deliverypoints
http_code
cdek_error_code
cdek_error_message
```

## Not Implemented

- CDEK order creation.
- CDEK shipment statuses.
- CDEK webhooks.
- CDEK print forms.
- Permanent FIAS/GAR -> CDEK `city_code` mapping. Current runtime still uses the existing resolver/context flow; permanent storage/mapping is deferred as technical debt.

The next planned stage is `feature/cdek-order-creation`.

## Smoke Test

```bash
php tests/cdek/run-cdek-pickup-points-smoke.php
```

The test uses fake CDEK HTTP responses for OAuth and `GET /v2/deliverypoints`, verifies request parameters, normalization, cache separation, checkout session persistence, checkout order address/meta persistence, admin save blocking and admin pickup address persistence.
