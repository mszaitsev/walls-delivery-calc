# WDC CDEK Tariff Calculation

Version: 0.52.0.

0.52.0 rules calculator update: the CDEK service `Правила` tab now uses a dedicated `Тестовый калькулятор СДЭК` instead of the generic Russian Post-style rule checker. The form accepts optional region, required city, required integer package weight/dimensions and optional declared value with comma/dot decimal input; there is no calculation date field. The calculator resolves the destination through the existing CDEK runtime/location resolver path, builds one package from the entered dimensions, requests active managed CDEK pickup and courier tariffs through `CdekCarrier`, applies service rules, and displays tariff name/code, delivery mode, API price/term before rules and final price/term after rules. If the city cannot be resolved, the tab shows `Не удалось определить код города СДЭК для указанного города.`

The CDEK `Расчет` tab now labels sender city settings by their real use: `Город отправителя СДЭК для тарифов от двери`. It is shown before `Код города отправителя СДЭК` and explains that the value is used as `from_location.city` for door-origin CDEK order registration.

0.50.5 admin bulk update: the CDEK `Тарифы` tab now includes compact mass-action controls. `CdekTariffRepository` supports `delete_all()`, `set_all_active()` and `set_active_by_delivery_mode()`, and the admin page exposes POST-only buttons to delete all tariffs, enable/disable all tariffs, or enable/disable a single delivery-mode group. Delivery mode mapping remains `1` door-door, `2` door-warehouse, `3` warehouse-door, `4` warehouse-warehouse; postamats are already classified as the warehouse side.

0.50.4 postamat direction update: the delivery-mode fallback classifier now treats postamats as the warehouse/PVZ side for CDEK order routing. `дверь-постамат` / `door-locker` map to mode `2`, `постамат-дверь` / `locker-door` map to mode `3`, and warehouse/PVZ/postamat-to-warehouse/PVZ/postamat combinations such as `склад-постамат`, `пвз-постамат`, `постамат-пвз`, `постамат-постамат`, `warehouse-locker`, `pickup-locker`, `locker-pickup`, `locker-locker` map to mode `4`.

0.50.3 tariff direction update: managed CDEK tariffs now store editable `delivery_mode` in addition to the pickup/courier presentation type. Sync fills it from CDEK API mode fields when available or from tariff names such as `дверь-дверь`, `дверь-склад`, `склад-дверь`, `склад-склад`; the admin tariff table exposes `Режим тарифа` for manual correction. Shipment creation uses this mode to choose CDEK order fields: door-origin tariffs use `from_location`, warehouse-origin tariffs use `shipment_point`, recipient-door tariffs use `to_location`, and recipient-warehouse tariffs use `delivery_point`.

0.48.4 insurance update: the CDEK `Расчет тарифов` settings block lives on the service `Расчет` tab. The new `Цена страховки` setting stores a decimal percent from discounted goods total, for example `0,75`/`0.75` means `0.75%`; negative values clamp to `0`. Runtime CDEK tariff calculation computes `insurance_amount = cart_items_total_after_discounts * insurance_percent / 100` and adds it to each CDEK API `delivery_sum` before calculation rules, merge/dedup display and saved “Базовая стоимость API”. The checkout packaging weight is not part of the insured goods total.

0.47.0 express/single-package update: CDEK settings now store `shipment_point` with default `NSK69`; WDC sends it in both the main item-level `POST /v2/calculator/tarifflist` request and the optional single-package request. The main request still expands `PackageItem::quantity` into separate packages. When every product unit fits a 50x50x30 cm box in at least one axis-aligned orientation and total product volume is within that box, WDC performs a second tarifflist request with one package whose weight is total package weight. A simple fit helper attempts to calculate actual combined box dimensions; if it cannot do so but mandatory fit checks pass, the second request may omit dimensions rather than inventing 50x50x30. New single-package `tariff_code` values are merged into the main candidates and duplicate codes are ignored. Before returning CDEK rates, WDC deduplicates exact `period_min`/`period_max` groups by price and CDEK name priority, then removes rates that are slower by `period_min` and more expensive. This is still tariff calculation only, not the future CDEK order creation package model.

0.46.6 packaging weight adjustment: CDEK runtime calculation keeps item-based `packages[]`, but aggregate `Package::$packaging_weight_g` is added once to the first item package instead of becoming a separate 1x1x1 package. If packaging is represented by a `WDC_PACKAGING` `PackageItem`, that item remains its own package and the aggregate packaging property is not applied again. This adjustment is only for `POST /v2/calculator/tarifflist`; it is not the future CDEK order-registration package model. No extra package diagnostics meta is stored.

0.46.5 package payload update: CDEK runtime calculation now sends `POST /v2/calculator/tarifflist` packages per cart item unit. Each `PackageItem` with `quantity = N` becomes `N` separate CDEK packages with `weight = max(1, item weight_g)` and item `length_cm`/`width_cm`/`height_cm`; missing dimensions fall back individually to `CdekSettings::default_package_dimensions_cm()`. A virtual packaging item such as `WDC_PACKAGING` is sent as its own package. If a package has no items, WDC keeps the previous aggregated package fallback.

0.46.4 nullable storage fix: CDEK tariff limit insert/update now builds `$wpdb` formats dynamically. Null values for `weight_*`, `length_*`, `width_*` and `height_*` are stored as SQL `NULL` and are not formatted as `%f`; numeric values continue to use decimal/float formats. This prevents empty API fields from being persisted as `0.000`.

0.46.3 tariff limits update: `GET /v2/calculator/alltariffs` sync stores nullable CDEK restriction fields in `wdc_cdek_tariffs`: `weight_min`, `weight_max`, `weight_calc_max`, `length_min`, `length_max`, `width_min`, `width_max`, `height_min`, `height_max`. In the current CDEK API response/examples these weight values are treated as kilograms and dimensions as centimeters, matching the admin display (`Вес: ... кг`, `Габариты: ... см`). Empty/null API values are stored as `null`. Sync preserves `custom_title`, `admin_comment` and `is_active`; because there is no manual delivery-type override flag yet, `delivery_type` is refreshed from the API-derived mode on sync. Obvious mojibake in CDEK names/modes is normalized only when the string clearly contains broken UTF-8 markers.

0.46.2 global cache version update: WDC adds `wdc_delivery_rates_cache_version` to WooCommerce shipping packages and bumps it on full delivery cache reset. Because WooCommerce includes the package payload in the `shipping_for_package_*` hash, existing customer checkout sessions recalculate rates after CDEK tariff save/sync or manual reset even if the cart contents did not change.

0.46.1 cache reset update: manual delivery tariff cache reset now clears WDC quote transients, runtime quote namespace, WooCommerce `shipping_for_package_*` session rates and WDC runtime rate/tariff session caches. Saving managed CDEK tariffs or confirming tariff sync triggers the same reset automatically, so deactivated tariffs disappear from checkout after reload without waiting for a cart/package hash change.

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
- The CDEK service `Тарифы` tab stores tariff-specific `custom_title`, `delivery_type`, `admin_comment`, active state and API limit fields; names and limits sync from `GET /v2/calculator/alltariffs` without overwriting admin presentation fields.
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
  - `shipment_point` when configured.
  - `from_location.code`.
  - `to_location.code`.
  - `packages[]` per `PackageItem` unit when cart/order items are available.
  - package weight in grams from the unit item weight.
  - package dimensions from the unit item dimensions, with CDEK defaults applied only for missing dimensions.
  - optional second-pass single package for carts fitting the conservative 50x50x30 cm check.
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
