# WDC Checkout Integration

## WooCommerce integration flow

The new checkout path is registered as a WooCommerce shipping method with id `wdc_platform_delivery` and title `WDC Platform Delivery`. Runtime flow:

`WooCommerce package -> WooCommercePackageMapper -> QuoteRequest -> CheckoutOrchestrator -> DeliveryRate[] -> WooCommerceRateMapper -> WC_Shipping_Method::add_rate()`.

## Shipping method registration

`ShippingMethodRegistrar` hooks `woocommerce_shipping_methods` and registers the method only when `new_shipping_method_enabled` is enabled or the `enable_new_checkout_shipping` setting is true. The default for both rollout gates is off.

## Package mapping

`WooCommercePackageMapper` maps checkout destination country/city/postcode/address, order total, contents weight, total item quantity, and package items into the domain `QuoteRequest`. Dimensions, address normalization, pickup selection, AJAX, and template overrides are intentionally out of scope.

## Orchestration runtime

`NewShippingMethod` is a real `WC_Shipping_Method`. It calculates rates through `CheckoutOrchestrator`, uses DemoCarrier through `CarrierRegistry`, applies checkout rules, maps returned rates to WooCommerce rate arrays, and adds them through `add_rate()`.

As of version 0.18.1, checkout runtime reads enabled default rules through `RuleRepository::get_default_rules()` instead of applying every enabled rule globally. Default rules have `target_type=default` and an empty `target_value`. If no default rules exist, runtime continues without rules; `database/demo/rules-demo.json` is not used as a checkout fallback.

`RuleRepository` also exposes `get_rules_for_target_or_default()` and `get_rules_for_carrier_with_default_fallback()` for the future carrier-specific rules stage. Once carrier keys are wired into rule selection, carrier rules can override default rules without changing the rule engine contract.

## Session persistence

`CheckoutSessionManager` stores selected `delivery_type`, selected sort mode, last mapped rates, and debug data in the WooCommerce session abstraction. Pickup points and tariffs are not persisted yet.

## Order meta persistence

`OrderShippingMetaPersister` hooks `woocommerce_checkout_create_order` and saves selected platform delivery data to `_wdc_platform_*` order meta:

- `_wdc_platform_carrier_key`
- `_wdc_platform_rate_id`
- `_wdc_platform_delivery_type`
- `_wdc_platform_crossed_price`
- `_wdc_platform_planned_delivery_comment`
- `_wdc_platform_comments`
- `_wdc_platform_fallback_used`

When checkout has an unambiguous local selected location, the order stores only:

- `_wdc_platform_location_fias_id`
- `_wdc_platform_location_display_name`

Other local location fields such as region, district, city, place, GAR/KLADR ids, and postcode stay in checkout hidden state only and are not expanded into order meta.

## Checkout City Picker V2

The city picker AJAX response returns grouped results:

`groups[].region_key`, `region_label`, `region_sort_name`, `total_in_region`, `shown_count`, `has_more`, `expand_query`, and `items[]`.

The checkout settings are:

- `include_region_in_checkout_city_picker_query`, default `true`;
- `checkout_location_search_limit`, default `100`, min `10`, max `500`;
- `checkout_location_region_limit`, default `10`, min `3`, max `50`.

When region prefill is enabled, opening the picker seeds the modal query from `state + ", " + city`; with only one field filled it uses that field, and with both empty it leaves the query empty. Each region initially shows up to `checkout_location_region_limit` rows. If the result contains only one region, checkout shows up to `checkout_location_region_limit * 3` rows, capped by `checkout_location_search_limit`. If more rows exist, the frontend shows `Показать все варианты в области` and repeats search with `force_region_code`, returning only that region up to the global checkout search limit.

The global search limit counts only items actually shown in groups. Per-region hidden rows and show-all buttons do not consume that limit; `limit_reached` means the picker filled `checkout_location_search_limit` visible items and still had more visible candidates to show.

Selecting a location writes formatted state/city values, fills postcode only when the local row has one, stores the selected local payload in hidden checkout fields, and renders a full-width one-line selected notice: `Выбран: {display_name}` or `Выбран: {display_name}, {postal_code}`. On checkout load, after `updated_checkout`, and after manual state/city edits, the frontend calls `wdc_platform_resolve_checkout_location`; only one confident local match restores hidden selected state, otherwise checkout shows `Просим проверить название и внести верный населенный пункт`.

Checkout city search is hierarchy-aware:

- query normalization lowercases text, replaces `ё` with `е`, converts punctuation to spaces, and collapses whitespace;
- type words from raw GAR types and admin display rules are treated as level markers, not as database search values;
- `МО` is treated as a region-level alias for `Московская область`, independent of display rules;
- matching checks region, district, city, and place names independently;
- only exact and prefix matches are allowed, so there is no contains-inside-word behavior;
- place/city matches are stronger than district/region context matches;
- exact/prefix matches use hierarchy seniority inside the same bucket: city-level matches rank above place-level matches, then same-seniority groups sort alphabetically by region name;
- region-name-only matches remain visible but rank below strong place/city matches;
- upper-level matches expand downward, so a city query can show the city and its nested places.

The picker searches only when the modal opens with the initial query or when the modal search input emits a real `input` event. External checkout city focus/select/click/change events do not run search. AJAX search uses a request sequence guard, so stale responses cannot replace current results or render fallback after a newer query. Show-all-region keeps the user's base query in the AJAX payload and sends the selected region separately as `force_region_code`; the region label may be shown visually in the input but is not required as a search token. The selected-region response keeps the same hierarchy ranking inside that region, so exact and stronger city/place matches remain at the top before the full list is sliced by the global limit.

The modal has a stronger loading state: `Идёт поиск, подождите несколько секунд` plus a CSS spinner. Below the search input, two permanent actions are shown: `Использовать введенное название` applies manual fallback with the current input, and `Очистить название` clears the input, force-region state, and results hint.

The admin `Населенные пункты` search now reuses the same hierarchy-aware exact/prefix logic as checkout. Before hierarchy search, admin search performs exact identifier lookup by `fias_id`, `gar_id`/`gar_object_id`, `kladr_id`, and `postal_code`; if an exact identifier match exists, only those rows are returned.

## Debug panel

`CheckoutDebugPanel` renders checkout debug data only for users with `manage_options`. It shows orchestration rate count, cache hits, fallback state, carrier errors, and returned orchestration rates.

## Fallback behavior

Checkout calculation is wrapped in exception handling. Carrier failures are captured by the orchestration guard, and if no visible rates remain the fallback rate is returned. If the shipping method itself throws during calculation, a zero-cost checkout fallback rate is still added so checkout remains usable.

## Feature flags

`FeatureFlags::new_shipping_method_enabled()` defaults to false. The setting `enable_new_checkout_shipping` also defaults to false. The new shipping method appears in checkout only when one of those gates is enabled.
