# WDC CDEK Tariff Calculation

Version: 0.46.0.

0.46.0 tariff management update: CDEK runtime calculation still obtains prices and delivery periods through `POST /v2/calculator/tarifflist`, but tariff presentation is now managed through a dedicated table synced from `GET /v2/calculator/alltariffs`. If a returned `tariff_code` exists in the table, WDC uses its active flag, delivery type and display title (`custom_title`, then `tariff_name_from_cdek`) for checkout/admin rate labels. If no row exists yet, runtime falls back to the API response name and delivery mode classification.

0.45.11 cache safety update: CDEK `/v2/calculator/tarifflist` api_error responses such as 403/429/5xx, transport failures and non-JSON/HTML errors continue to return `error_code=api_error` and are not cached as successful empty quotes. Successful CDEK quotes are cached only when they contain rates, so a temporary API failure or zero-rate response cannot replace a previous good result with a stable "0 rates" cache entry. The delivery cache reset path also clears CDEK city and deliverypoints transients while leaving OAuth token cache untouched.

0.45.0 update: CDEK pickup tariffs now require pickup point selection and are connected to the shared checkout/admin pickup map. Pickup point loading itself is documented in `docs/wdc-cdek-pickup-points.md`.

This stage connects CDEK as a checkout/runtime carrier for tariff preview only. It uses CDEK API v2 `POST /v2/calculator/tarifflist` to fetch available tariffs and converts supported pickup/courier tariffs into WDC `DeliveryRate` objects.

## Implemented

- Runtime carrier key and service key: `cdek`.
- Checkout/admin delivery types:
  - `pickup`: default `СДЭК до пункта выдачи`.
  - `courier`: default `СДЭК курьер`.
- The CDEK service main tab stores service-specific `pickup_method_title` and `courier_method_title`; custom values are used by checkout grouped rates, admin recalculation preview, saved rate meta, and calculation data.
- The CDEK service `Тарифы` tab stores tariff-specific `custom_title`, `delivery_type`, `admin_comment` and active state; names sync from `GET /v2/calculator/alltariffs` without overwriting admin presentation fields.
- CDEK OAuth bearer token reuse from the foundation stage.
- Authorized JSON requests through the existing `CdekHttpClientInterface`.
- `CdekLocationResolver` for destination CDEK city code resolution via `/v2/location/cities` with fallback attempts and transient cache.
- Sender settings:
  - CDEK sender city code.
  - Sender postal code.
  - Sender city name for diagnostics.
  - Default package length/width/height in cm.
- Tarifflist request payload:
  - `type = 1`.
  - `currency = 1` (RUB in CDEK API).
  - `from_location.code`.
  - `to_location.code`.
  - package weight in grams.
  - package dimensions from WDC package or CDEK defaults.
- Response mapping:
  - `tariff_code`.
  - `tariff_name`.
  - `delivery_mode`.
  - `delivery_sum` as rubles.
  - `period_min` / `period_max`.
  - `calendar_min` / `calendar_max` saved in meta.
- Delivery mode classification:
  - `1` door-door -> courier.
  - `2` door-warehouse -> pickup.
  - `3` warehouse-door -> courier.
  - `4` warehouse-warehouse -> pickup.
  - unknown modes are skipped.
- Existing WDC rule engine processing and service post-processing.
- Checkout grouped tariff selector reuse through generic `tariff_selector_group` / `checkout_group_id`.
- Admin order delivery recalculation preview support.
- Safe calculation meta without access tokens, secure passwords, or raw sensitive payloads.

## Not Implemented

- CDEK order/shipment creation.
- CDEK tracking/statuses.
- CDEK webhooks.
- CDEK print forms.
- COD, declared value, and insurance-specific CDEK order logic.

The next planned stage is `feature/cdek-order-creation`.

## Runtime Visibility

CDEK rates are not shown when:

- The common delivery service `cdek` is disabled on the main delivery service tab.
- Active environment Account / Secure password are incomplete.
- Sender CDEK city code is empty.
- Destination city cannot be resolved to a confident CDEK city code.
- CDEK API returns an error and no common carrier fallback applies.

## Stored Meta

Each CDEK tariff rate stores safe meta such as:

- `carrier_key = cdek`.
- `service_key = cdek`.
- `delivery_type`.
- `tariff_code`.
- `tariff_name`.
- `api_base_price_rub`.
- `api_delivery_days_min`.
- `api_delivery_days_max`.
- `api_delivery_days_text`.
- `request_payload_sanitized`.
- `response_tariff_sanitized`.
- `location.cdek_from_city_code`.
- `location.cdek_to_city_code`.
- `location.cdek_location_source`.
- `package.weight_g`.
- `package.dimensions_cm`.

Order calculation data also exposes API base cost, API delivery text, products/package/final weights, package dimensions, and rule audit details through the existing WDC calculation data structure.

## Smoke Test

The stage adds:

```bash
php tests/cdek/run-cdek-tariff-calculation-smoke.php
```

The test uses fake HTTP for OAuth, `/v2/location/cities`, and `/v2/calculator/tarifflist`.
