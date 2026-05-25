# WDC Checkout Integration

As of version 0.20.0, checkout is part of the `src/`-only runtime. The legacy `includes/*` shipping method and demo runtime fallback have been removed; demo datasets used by smoke tests live under `tests/fixtures/demo`.

## WooCommerce integration flow

The new checkout path is registered as a WooCommerce shipping method with id `wdc_platform_delivery` and title `WDC Platform Delivery`. Runtime flow:

`WooCommerce package -> WooCommercePackageMapper -> QuoteRequest -> CheckoutOrchestrator -> DeliveryRate[] -> WooCommerceRateMapper -> WC_Shipping_Method::add_rate()`.

## Shipping method registration

`ShippingMethodRegistrar` hooks `woocommerce_shipping_methods` and registers the method only when `new_shipping_method_enabled` is enabled or the `enable_new_checkout_shipping` setting is true. The default for both rollout gates is off.

## Package mapping

`WooCommercePackageMapper` maps checkout destination country/city/postcode/address, order total, contents weight, total item quantity, and package items into the domain `QuoteRequest`. Dimensions, address normalization, pickup selection, AJAX, and template overrides are intentionally out of scope.

## Orchestration runtime

`NewShippingMethod` is a real `WC_Shipping_Method`. It calculates rates through `CheckoutOrchestrator`, uses registered carriers through `CarrierRegistry`, applies checkout rules, maps returned rates to WooCommerce rate arrays, and adds them through `add_rate()`.

As of version 0.18.3, checkout runtime reads enabled default rules through `RuleRepository::get_default_rules()` instead of applying every enabled rule globally. Default rules have `target_type=default` and an empty `target_value`. If no default rules exist, runtime continues without rules; there is no demo rules fallback in runtime paths. Rules are applied in the same top-to-bottom order shown in the rules admin table.

As of version 0.18.4, rule context carries selected location FIAS ID when available. City conditions are FIAS-only: they compare the selected location FIAS ID with the condition value and do not fall back to city or display-name text. Package dimensions are aggregated from WooCommerce product dimensions using max length, width, and height across cart items, while volume remains total package volume in `cm3` and is converted to cubic meters by the rule evaluator.

As of version 0.21.0, runtime resolves rules per service with `RuleRepository::get_rules_for_service_with_default_fallback($serviceKey, $fallback)`. Service rules override default rules. If no service-specific rules exist, default rules are applied only when the service enables default fallback.

As of version 0.21.1, only enabled service rules count as service-specific runtime rules. If a service has only disabled own rules, runtime still falls back to default rules when `use_default_rules_when_no_service_rules` is enabled; otherwise it applies no rules and records `rules_source=none`.

The quote cache key includes `service_key` in addition to carrier, package, destination, total, delivery type, and date. Different services backed by the same carrier no longer share cached quotes.

As of version 0.21.3, Russian Post carrier settings are read from the delivery service settings table rather than the platform settings page. The Russian Post calculation tab writes service-specific endpoints, origin/tariff controls, timeout, VAT, fallback, debug, cache, country-refresh, max-weight, and packaging-tier values. The service row `enabled` state remains the single authoritative enable switch.

Admin simulation has two modes. The default rules page applies rules to the entered delivery price only. The service rules tab performs a real service quote first, then applies enabled service rules only; default fallback is intentionally not mixed into service simulation.

As of version 0.21.6, checkout applies service packaging before calling a carrier. `include_packaging_weight=false` leaves the package unchanged. `total_weight` adds packaging to total package weight. `package_item` adds the `WDC_PACKAGING` virtual item. Quote cache keys, carrier API requests, overweight checks, and rule weight conditions all see the package after this preprocessing.

As of version 0.21.8, service rows can define separate pickup and courier customer comments. For normal rates, checkout prepends the service comment matching `delivery_type` and then appends comments produced by `add_comment` rules. For fallback rates, the carrier fallback text remains the primary customer-facing comment, with rule comments after it when rules apply. The old automatic courier checkout notice is not rendered unless equivalent text is explicitly configured as a service or rule comment.

As of version 0.19.0, `russian_post` / `russian_post_worldwide_parcel` is registered as the real “Почта России — международная доставка” carrier. It is international-only, excludes `RU`, uses the new `Package` weight plus shared packaging tiers, and returns a zero-cost manager fallback instead of failing checkout for API errors, missing tariff/price, unsupported country, or overweight.

As of version 0.19.2, Russian Post country support is read from the persistent `wdc_russian_post_country_mappings` table. Checkout does not rely on live country dictionary calls by default. If a destination country has no enabled mapping, the carrier returns its configured fallback/no-rate behavior with `unsupported_country_{code}`.

For Russian Post service availability, `carrier_directory` no longer filters out disabled countries before the carrier runs. This lets Russian Post return a visible zero-cost fallback rate with the configured fallback text when fallback is enabled; otherwise checkout may fall through to the generic fallback if no visible rates remain.

As of version 0.20.0, `CarrierRegistry` registers the real Russian Post international carrier only. The previous demo carrier toggle and demo pickup provider are test fixtures only and are not registered by `Plugin`.

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
- `_wdc_platform_requires_pickup_point`
- `_wdc_platform_service_key`
- `_wdc_platform_service_title`
- `_wdc_platform_rules_source`
- `_wdc_platform_round_up_applied`
- `_wdc_platform_minimum_price_applied`
- `_wdc_platform_rate_meta`

Russian Post rate metadata includes sanitized API request/response diagnostics, cache metadata, API base price data, package weight data, country mapping, and fallback reason when fallback is used. Secrets and tokens are not stored in the request params.

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
# Checkout Integration

Checkout runtime is service-aware. `DeliveryServiceRegistry` lists active services, resolves their carrier adapter, and `DeliveryServiceManager` checks availability, resolves service rules, and applies service post-processing after the rule engine.

Availability modes:

- `carrier_directory`: uses carrier-specific availability, currently `RussianPostCountryDirectory`.
- `selected_countries`: allows countries listed in `wdc_delivery_service_countries`.
- `all_countries`: allows every country.
- `all_except_selected`: allows every country except listed countries.

Post-processing order after rules:

1. `minimum_price_rub`
2. `round_up_to_ruble`

Disabled rates are ignored, and zero fallback rates stay zero.

Order/shipping metadata now includes `service_key`, `service_title`, `carrier_key`, `rules_source`, `round_up_applied`, and `minimum_price_applied`.
