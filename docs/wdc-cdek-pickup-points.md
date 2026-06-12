# WDC CDEK Pickup Points

Version: 0.45.14.

0.45.14 temporary diagnostics: checkout pickup state now has an explicit debug mode behind `define( 'WDC_PICKUP_DEBUG', true );`. When enabled, `wdc-pickup-checkout.js` writes grouped console snapshots for boot, map context, selected point before save, REST save response, `applySelection`, `updated_checkout`, and place-order submit. PHP logs sanitized pickup summaries for REST save/state, localized config and checkout validation. The diagnostics are temporary and do not change CDEK/Russian Post pickup restore or validation behavior.

0.45.13 reload/validation follow-up: selected pickup restore now depends only on `pickup_family + destination/location + point identity`. A selected CDEK/Russian Post point is not invalidated by tariff changes, grouped rate suffixes, package contents, weight or dimensions. `pickupSelections` in checkout localization keeps raw saved family buckets even when a visual card address has to be resolved from aliases; `selectedPickupPoints` remains the renderable-card subset. CDEK address aliases now include top-level, snapshot and raw address fields, and checkout validation accepts the active `cdek:pickup` bucket without hidden fields when the destination still matches.

0.45.12 canonical pickup state fix: checkout now treats `wdc_platform_pickup_selections` as the source of truth. Each selected point is stored as `pickupSelections[pickup_family]` (`russian_post_domestic:pickup`, `cdek:pickup`, future custom families), while `wdc_platform_pickup_selection` and `wdc_pickup_point` remain derived mirrors/migration fallback only when the dictionary is empty. `PickupMapCheckout` localizes the complete bucket dictionary, active shipping method and active family for reload restore. Validation reads the active family bucket before posted hidden fields, Russian Post aliases normalize to `russian_post_domestic`, and destination fingerprints use stable city/location identifiers so reload of the same city keeps the selected CDEK/Russian Post point while a real city change invalidates it.

0.45.11 reload/validation fix: checkout boot restores the active selected pickup from localized `pickupSelections[activePickupFamily]` and the top-level `selectedPickupPoint`, then writes the hidden checkout fields through the same `applySelection()` path used after map selection. Russian Post checkout validation now accepts the active `russian_post_domestic:pickup` bucket by family before falling back to carrier/rate-id checks, so technical `point_code` values do not break order placement. Grouped tariff UI also keeps nested rates disabled when the active shipping method is temporarily missing after a carrier disappears.

0.45.10 checkout restore fix: checkout pickup state now uses the `pickupSelections` / `pickup_selections` dictionary as the frontend and backend source of truth. `PickupMapCheckout` localizes every complete saved family bucket plus `activePickupFamily`; checkout state/save/reset REST responses return the same dictionary and active family; and `wdc-pickup-checkout.js` restores the selected card from `pickupSelections[activePickupFamily]` after method switching, checkout updates and page reloads without replacing full payloads with code-only fallbacks. Map popup and side-list rows share `display_title` / `display_code`, so Russian Post side rows show `Отделение Почты России {postcode}` while CDEK keeps `Пункт выдачи СДЭК {cdek_code}` / `Постамат СДЭК {cdek_code}`.

0.45.9 reset-scope fix: bucketed pickup selections are no longer vulnerable to accidental full clears during normal checkout method switching. `clear_pickup_selection()` is explicitly documented as a global reset for destination/location identity changes and full checkout-context resets. Family-scoped clears use `clear_pickup_selection_for_family($pickup_family)` so clearing `cdek:pickup` does not remove `russian_post_domestic:pickup`, and vice versa. Cross-location recalculation can still request an explicit global reset when the destination changes and the active pickup method disappears.

0.45.8 checkout bucket fix: selected pickup points are stored by `pickup_family` in the checkout session. Selecting a CDEK point writes the `cdek:pickup` bucket, selecting a Russian Post point writes `russian_post_domestic:pickup`, and one carrier no longer clears the other. Checkout reload and shipping-method switching restore only the active family's complete selection when the saved destination identity still matches; validation also checks only the active family bucket. CDEK pickup prefetch now starts in the background when `cdek:pickup` is the active shipping method. Russian Post map/list/popup titles show the pickup postcode from the repository, while CDEK titles keep `cdek_code` (`KEM7`, `KEM41`). Inactive grouped tariff rates are disabled until their parent shipping method is selected.

0.45.7 propagation fix: pickup REST output and checkout save now carry the full normalized carrier payload end-to-end: `carrier_key`, `service_key`, `pickup_family`, `point_title`, `point_type_label`, `marker_type`, address aliases and `snapshot`. CDEK map popups and side-list rows now receive CDEK presentation (`Пункт выдачи СДЭК` / `Постамат СДЭК`) instead of falling back to Russian Post titles. Russian Post save normalizes the REST carrier back to `russian_post_domestic:pickup`, so selected Russian Post points appear on checkout again, while CDEK selected points keep `cdek:pickup` and pass checkout validation.

0.45.6 checkout state fix: CDEK pickup validation again sees full selected-point payloads posted from checkout hidden fields/session. Checkout reload no longer treats code-only CDEK payloads as valid selected cards; a selected card is shown only when `point_code`, matching `pickup_family` and address are present. Switching between CDEK and Russian Post hides inactive pickup-family cards, shows the empty `Выбрать пункт выдачи` action for the active family until a complete point is selected, and keeps Russian Post map requests on the `russian_post` REST carrier context after returning from CDEK.

0.45.5 restore hardening: CDEK checkout restore no longer falls through to `RussianPostPickupPointRepository` by posted `point_id` or `point_code`. Only `russian_post_domestic:pickup` may use the Russian Post repository; CDEK restores from current session, hidden/full posted payload, or minimal CDEK payload fallback. This prevents collisions where a CDEK code matches a Russian Post row.

0.45.4 presentation/state refactor: CDEK pickup now uses the same normalized pickup presentation model as Russian Post and future custom pickup services. The saved payload includes `service_key`, `pickup_family=cdek:pickup`, `point_type_label`, `point_title`, `marker_type`, `description`, `storage_notice` and snapshot data. `PickupPointPresentationResolver` owns the built-in CDEK titles (`Пункт выдачи СДЭК`, `Постамат СДЭК`), POSTAMAT `Срок хранения 3 дня`, marker type and generic fallback behavior. Checkout JS state, validation, order meta and admin recalculation save now match by `pickup_family` instead of scattered CDEK/Russian Post card conditions.

0.45.3 checkout state fix: the selected CDEK pickup card under rates is intentionally visual-only and does not render `Код пункта` or `Индекс`, while keeping the selected point title, address, meaningful `work_time`, `Описание:` and POSTAMAT `Срок хранения 3 дня`. Checkout reload localizes the full CDEK selected pickup payload from session, including `point_code`, `cdek_code`, `point_address`/`address`, `point_postcode`/`postcode`, city, region, description, storage notice and snapshot data. Switching shipping method hides inactive pickup-family cards/buttons so CDEK controls cannot open the Russian Post map and vice versa. Thankyou/order/email cards read the full saved pickup payload and stay populated.

0.45.2 card fix: CDEK pickup cards now use carrier-aware titles on checkout, thankyou/order display and email output. `PVZ` renders as `Пункт выдачи СДЭК`; `POSTAMAT` renders as `Постамат СДЭК`. CDEK descriptions are persisted through checkout hidden fields/session, order meta and `_wdc_delivery_calculation_data.pickup`, then rendered with the `Описание:` label. Empty or numeric-zero `work_time`/description values (`0`, `0.0`, `0.000000`) are suppressed, so Russian Post cards no longer show accidental zero descriptions. CDEK POSTAMAT still renders red bold `Срок хранения 3 дня`.

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
  - `service_key`;
  - `pickup_family`;
  - `point_type_label`;
  - `point_title`;
  - `marker_type`;
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
- Checkout map/picker supports `carrier_key=cdek`, passes CDEK city context, saves the selected point in the WooCommerce session with the full normalized payload, and keeps grouped CDEK pickup tariffs in one method family.
- The checkout selected-point card under rates hides CDEK `point_code`/`cdek_code` and `point_postcode`; those values remain saved in session/order calculation data.
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
