# WDC Checkout Integration

As of version 0.20.0, checkout is part of the `src/`-only runtime. The legacy `includes/*` shipping method and demo runtime fallback have been removed; demo datasets used by smoke tests live under `tests/fixtures/demo`.

## WooCommerce integration flow

The new checkout path is registered as a WooCommerce shipping method with id `wdc_platform_delivery` and title `WDC Platform Delivery`. Runtime flow:

`WooCommerce package -> WooCommercePackageMapper -> QuoteRequest -> CheckoutOrchestrator -> DeliveryRate[] -> WooCommerceRateMapper -> WC_Shipping_Method::add_rate()`.

### Pickup POST and selected-method stabilization 0.104.11

Each rendered pickup rate keeps its own family-specific values, but `wdc-pickup-checkout.js` enables named `input`/`select`/`textarea` fields only for the container matching the checked shipping method. Inactive containers are hidden and disabled for form serialization without clearing their values; `boot()` and `updated_checkout` resynchronize this state after WooCommerce replaces rate HTML.

After saving a `yandex_delivery:pickup` point, the script remembers `yandex_pickup` before triggering repricing. If WooCommerce changes the checked method while rates are repriced or reordered, the script restores Yandex when that radio still exists and is enabled, then sends at most one guarded recovery `update_checkout`; unavailable/disabled Yandex rates are never forced. `OrderShippingMetaPersister` removes visible shipping-item `pickup_family`, while `_wdc_pickup_family`, calculation pickup data and family session buckets remain persisted.

### Standalone rate delivery labels 0.104.8

For rates without `domestic_tariff_grouped` or `tariff_variants`, `WooCommerceRateMapper` treats final `DeliveryRate::delivery_days` as the display source of truth and formats it through `DeliveryDaysFormatter`. A title that already ends with the final formatted term is retained; when it ends with formatted `original_delivery_days`, only that suffix is replaced; otherwise the final term is appended once as `{title} — {delivery label}`. `planned_delivery_comment` remains available in rate/order metadata for compatibility and diagnostics but is no longer concatenated into the WooCommerce label or rendered as a separate block below a standalone method. Ordinary `comments` continue to render, and grouped tariff selector labels/rows remain unchanged.

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

As of version 0.21.8, service rows can define separate pickup and courier customer comments. For normal rates, checkout prepends the service comment matching `delivery_type` and then appends comments produced by `add_comment` rules. As of 0.21.9, each comment is rendered as its own block line, so service comments and rule-added comments do not visually merge on wide checkout layouts. The old automatic courier checkout notice is not rendered unless equivalent text is explicitly configured as a service or rule comment.

As of version 0.19.0, `russian_post` / `russian_post_worldwide_parcel` is registered as the real “Почта России — международная доставка” carrier. It is international-only, excludes `RU`, uses the new `Package` weight plus shared packaging tiers, and returns a zero-cost manager fallback instead of failing checkout for API errors, missing tariff/price, unsupported country, or overweight.

As of version 0.19.2, Russian Post country support is read from the persistent `wdc_russian_post_country_mappings` table. Checkout does not rely on live country dictionary calls by default. If a destination country has no enabled mapping, the carrier returns its configured fallback/no-rate behavior with `unsupported_country_{code}`.

For Russian Post service availability, `carrier_directory` no longer filters out disabled countries before the carrier runs. This lets Russian Post return a visible zero-cost terminal fallback rate with the configured `fallback_text` as the method title when fallback is enabled; otherwise checkout may fall through to the generic fallback if no visible rates remain.

Russian Post terminal fallback rates carry `fallback=true`, `terminal_fallback=true`, `skip_rules=true`, and `skip_service_post_processing=true`. Checkout skips rule evaluation, service customer comments, minimum price, and ruble rounding for those rates. The fallback text is not duplicated in comments.

As of 0.21.11, Russian Post international keeps `delivery_type=pickup` but does not require a customer-selected pickup point. Its rates carry `no_pickup_selection=true`. Checkout validation and the pickup selector UI both honor that flag: Russian Post passes without a selected pickup point, while other pickup carriers still require one when their rate requires pickup selection.

As of 0.21.12, checkout persists a separate order-level calculation payload in `_wdc_delivery_calculation_data`. This payload is the technical source for support/debug views and contains carrier/service keys, rate id, delivery type, pickup data when selected, destination, package weights, sanitized API data, rule audit/formula, and final result values.

For Russian Post international, standard WooCommerce shipping item meta is intentionally minimal: only `Способ доставки: международная доставка Почтой России` is visible. Technical fields such as `carrier_key`, `service_key`, `rules_source`, `delivery_type`, `no_pickup_selection`, API/package data, and rule audit are hidden from the shipping item and stored in `_wdc_delivery_calculation_data`.

The order admin metabox `Калькулятор доставок` prefers `_wdc_delivery_calculation_data`. For normal Russian Post rates it shows destination country, products/packaging/final API weight, API base price and VAT status, readable rule formula lines, final price, and delivery days only when non-empty. For Russian Post terminal fallback it shows fallback reason/text and final price `0`; rules are not displayed because fallback rates skip rules and service post-processing.

As of version 0.20.0, `CarrierRegistry` registers the real Russian Post international carrier only. The previous demo carrier toggle and demo pickup provider are test fixtures only and are not registered by `Plugin`.


## Deterministic Sorting

As of 0.103.5, checkout sorting is centralized in `src/Checkout/Sorting/RateSorter.php` for all carriers and deliberately uses different values for the two levels. Only rates marked with `meta['tariff_selector_group']` are grouped as tariff-selector variants, and the grouping key is `meta['checkout_group_id']`. `service_key` is not a grouping key because one delivery service can expose several independent checkout methods, such as Yandex pickup and Yandex courier. Rates without `tariff_selector_group` are standalone checkout methods keyed by their own `rate_id`. Rates inside one selector group are sorted by original carrier values: price mode uses `DeliveryRate::original_cost` with fallback to `price`, and fastest mode uses `DeliveryRate::original_delivery_days.min_days` with fallback to `delivery_days.min_days`. Rule Engine discounts or delivery-day changes do not reshuffle selector variants.

Checkout methods themselves are sorted by the active rate values that the buyer actually sees. Price mode uses final `price`; fastest mode uses final `delivery_days.min_days`. These final values are after Rule Engine, discounts, minimum price, rounding and service post-processing. Ties stay deterministic through the secondary value, title, `tariff_key`, `rate_id`, then original input index.

`NewShippingMethod::rates_for_wc()` collapses tariff selector groups by replacing the first group rate with the selector method instead of appending it later. Variants keep the original-value order from `RateSorter::sort_group_rates()`. If the session already has a selected tariff, that tariff becomes the selector active rate and method-level sorting uses its final value; otherwise the first sorted variant is active. Carrier payloads, Rule Engine behavior, checkout UI and pickup selection are unchanged.
## Session persistence

`CheckoutSessionManager` stores selected `delivery_type`, selected sort mode, last mapped rates, selected tariffs, pickup selections, city context and debug data in the WooCommerce session abstraction. Checkout order creation persists the selected pickup point into WDC order meta/calculation data and writes pickup shipping address fields when the selected rate requires a pickup point.

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

`_wdc_platform_*` keys remain for compatibility, but new technical calculation details should be read from `_wdc_delivery_calculation_data`. Raw carrier responses are not copied into that calculation payload; only a sanitized subset such as request params, cache hit, HTTP code, country mapping, API price, package weights, fallback status, and rule audit/formula is saved.

When checkout has an unambiguous local selected location, the order stores only:

- `_wdc_platform_location_fias_id`
- `_wdc_platform_location_display_name`

These keys are saved only when the checkout country is present in the local location country index and the location was actually selected or auto-resolved. Unsupported-country checkouts do not persist stale hidden local location values. Other local location fields such as region, district, city, place, GAR/KLADR ids, and postcode stay in checkout hidden state only and are not expanded into order meta.

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

## Country-aware Local City Picker

As of 0.21.15, checkout enables the local city picker only for countries represented in the local locations table. `LocationCountryIndexService` stores the supported country list in the `wdc_location_country_codes` option as normalized ISO-2 codes. The index is rebuilt from distinct active `wdc_locations.country_code` values after imports, inserts, country-changing updates, and location clears. Postal-code updates, display-name rebuilds, type display mapping, DaData enrichment, and other non-country changes leave the index untouched. If the option is missing, empty, or stale, the service performs one lazy rebuild instead of running a heavy `DISTINCT` on every checkout request.

The frontend receives `supported_location_countries`, derives the active checkout country from shipping country when shipping to another address is active and billing country otherwise, and enables the modal plus auto-resolve only for supported countries. Switching from a supported country such as `RU` to an unsupported country such as `PL`, `DE`, or `US` closes the modal, clears hidden local location fields, cancels auto-resolve, removes notices, and leaves the normal WooCommerce city/state inputs fully manual.

Both checkout location AJAX endpoints accept `country_code`. Unsupported countries return empty search results with `local_database_available=false`; resolve returns `manual_allowed` without searching. Supported countries search and resolve only rows with the same `country_code`, so RU, BY, and KZ rows cannot leak into each other's picker results.

For `RU`, `BY`, and `KZ`, latin letters in picker queries are treated as transliteration or wrong keyboard layout input. The correction path runs before database lookup for latin input in those countries, so raw latin text is not searched directly against the local table. Existing wrong-layout cases such as `yjdjc` and `ghbdtn` continue to use `KeyboardLayoutTransformer`.

The warning `Просим проверить название и внести верный населенный пункт` is shown only when the current country is supported and local auto-resolve/search actually fails or is ambiguous. Unsupported countries never show this warning and never block manual city input.

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
# Domestic tariff selector

`russian_post_domestic` keeps the single WooCommerce shipping method (`wdc_platform_delivery`) and collapses domestic tariff variants into one visible service rate. The selected variant controls the rate cost, delivery range and comments.

Domestic method labels use the actual service title, selected tariff title, and final rule-adjusted delivery range: `Почта России — до отделения: Посылка онлайн - 3 дня` or `Почта России — курьером: EMS - 1-2 дня`. The same title is persisted to the WooCommerce shipping item. Delivery days are formatted through the shared Russian plural formatter, so selector rows, labels, visible order meta, and calculation metabox use `1 день`, `3 дня`, `5 дней`, and ranges like `1-3 дня`.

The frontend selector is implemented in `assets/frontend/domestic-tariff-selector.js` and posts the selected `object_code` to the checkout session. After selection it triggers `update_checkout`, so WooCommerce totals refresh and the selected tariff survives checkout recalculation.

For pickup domestic tariffs, `no_pickup_selection=true` skips the pickup point selector because delivery is to the post office associated with the destination postcode.

## Yandex Delivery Checkout Preparation

As of version 0.101.3, Yandex Delivery has an admin-only source platform station selector on `WDC -> Службы доставки -> Яндекс Доставка -> Расчет`. It searches the local `wp_wdc_locations` directory, resolves the selected `location_id` to all mapped/manual `yandex_geo_id` values through `wp_wdc_yandex_location_mapping_v2`, then reads all active locally imported Yandex PVZ rows with `available_for_dropoff=true`, matching any of those geo ids, and non-empty `platform_station_id`, without limiting the list inside that geo id set. The admin page filters the already loaded PVZ options client-side by `full_address` from 3 characters. WDC stores the selected `platform_station_id` in delivery service settings and also stores `source_location_id` only to restore the admin selector. The saved address is displayed read-only by looking it up from the local PVZ table.

As of version 0.103.6, Yandex checkout pricing uses the B2B `pricing-calculator` endpoint with shared PackagingBuilder places. `yandex_pickup` sends `tariff=self_pickup` with the saved source `platform_station_id` and a temporary representative destination PVZ selected from the local imported PVZ table by all mapped/manual geo ids for the checkout destination `location_id`. `yandex_courier` sends `tariff=time_interval` with a destination address assembled from checkout address fields. Successful responses use the API price and Russian plural delivery time such as `1 день`, `2 дня`, or `5 дней`. Each Yandex rate handles its own failure independently and returns a disabled reason instead of breaking checkout. Pricing-calculator `places[]` now comes from `src/Packaging/PackagingBuilder` using generic `PackagingBuilderConfig::defaults()`: each `PackagingParcel` becomes one or more places, `quantity` is expanded into repeated places, and `total_weight` equals the sum of all `physical_dims.weight_gross` values. If the packaging result is empty or invalid, the request builder falls back to the previous single-place model with generic `500 g` and `20x15x10 cm` defaults. Rate meta includes safe package diagnostics without addresses or tokens.

### Yandex courier preliminary pricing fallback 0.104.10

`yandex_courier` still calls `YandexDeliveryPricingRequestBuilder::courier()` with `tariff=time_interval`. It first uses the real address assembled from `QuoteRequest::destination`; a successful API/parser result sets `courier_pricing_source=checkout_address` and `courier_fallback_used=false`. If the address is empty or that request/parser fails, the carrier performs at most one fallback request. It reuses the existing pickup destination priority (`pickup_selections['yandex_delivery:pickup']`, then representative PVZ for the checkout location), looks up that station through `destination_pickup_point_by_platform_station_id()`, and uses `full_address` or complete `locality/street/house` only in the fallback pricing payload.

Successful fallback meta contains `courier_pricing_source=pickup_address_fallback`, `courier_fallback_used=true`, pickup source/station id and the primary error code. A failed fallback also records its safe error code and leaves courier disabled without affecting pickup. The PVZ address is not written to diagnostics, QuoteRequest, session, hidden fields, WooCommerce customer/order addresses or order meta. Courier checkout validation still requires the buyer's real address, and every later checkout recalculation retries that real address before considering fallback.


### Leaflet zoom cluster rebuild 0.104.6

The Leaflet pickup provider stores the latest complete marker dataset in `lastPoints` and the current origin/search marker in `lastSearchMarker`. On `zoomend` it removes only rendered marker layers, reruns the existing `clusterPoints()` projection for the new zoom, redraws clusters/single markers, reapplies `activePointId`, restores the search marker, and reopens retained popup state when the active point is individually rendered. This rebuild is client-side and does not request pickup points again; the existing Yandex city dataset and viewport-only side-list behavior remain unchanged. The Yandex Maps provider is unchanged.

### Yandex Delivery pickup map presentation 0.104.5

Yandex checkout pickup points no longer use platform_station_id in buyer-facing titles or display_code. YandexDeliveryCheckoutPickupPointFormatter keeps the station id only in id, point_code, platform_station_id and snapshot.platform_station_id, while point_title/card_title/display_title/title are derived from operator_id, type and name. 5Post, Yandex.Market terminals, partner terminals and generic terminals get explicit titles, and warning text is stored as presentation_comment instead of description/storage_notice.

The map frontend renders presentation_comment directly below the title in both the popup and the side-list item. For carrier=yandex_delivery the REST endpoint still loads every active destination pickup point for the selected location_id city, but wdc-pickup-map.js now keeps that full city dataset for markers/clusters and filters only the side list by the current map bbox. Moving or zooming the map rerenders the list locally and shows counts as `Показано X из Y`; a committed selected point is not cleared when it leaves the viewport. Leaflet and Yandex map providers now use 128px cluster grids so markers aggregate sooner at lower zoom levels.

### Yandex Delivery checkout chain fix 0.104.4

The yandex_pickup button path was verified end to end from DeliveryRate to JS. WooCommerceRateMapper now exposes top-level pickup_family for pickup rates, deriving yandex_delivery:pickup from the yandex_pickup rate/carrier when the rate meta does not already provide one. CheckoutRateRenderer now normalizes WooCommerce meta-data key/value objects before checking carrier_key, delivery_type and requires_pickup_point, so it renders the pickup UI for real WC shipping rates, not only test objects with plain associative arrays.

The frontend now treats the legacy Woo method id yandex_pickup as the pickup family yandex_delivery:pickup. This keeps shippingMethodFamily(), isPickupRateValue(), containerMatchesActivePickup() and toggleForMethod() aligned with the hidden wdc_pickup_family field, so the container remains visible after boot/updated_checkout. Saving yandex_delivery:pickup continues to trigger update_checkout so the selected platform_station_id can refresh pricing through the existing session and carrier logic.
### Yandex Delivery checkout PVZ renderer 0.104.3

CheckoutRateRenderer now emits the common pickup picker block for any checkout rate whose meta has requires_pickup_point=true and delivery_type=pickup. For yandex_pickup the block includes data-wdc-pickup-checkout, data-shipping-method-id=yandex_pickup, data-wdc-pickup-open/data-wdc-pickup-empty-open, the standard wdc_pickup_* hidden fields and wdc_pickup_family=yandex_delivery:pickup. yandex_courier does not render pickup UI.

CheckoutDeliveryTypeSelector remains available for posted selection capture/session compatibility, but it no longer registers a second woocommerce_after_shipping_rate HTML renderer. The checkout JS continues to reboot on updated_checkout and delegated clicks open the modal for ajax-recreated buttons. Saving yandex_delivery:pickup now also requests WooCommerce update_checkout, so the selected platform_station_id flows through the existing family-specific session and Yandex pricing path.
### Yandex Delivery checkout PVZ button init 0.104.2

PickupMapCheckout now enqueues the shared pickup picker assets on checkout independently from the current session rate cache. The class is still registered only when the new checkout platform is enabled and still returns outside checkout pages, but it no longer waits for session_manager->rates() to already contain a requires_pickup_point rate. This prevents the first page load from missing wdc-pickup-checkout.js before WooCommerce ajax returns yandex_pickup.

The checkout pickup script keeps listening to WooCommerce updated_checkout and reruns boot() for fresh data-wdc-pickup-checkout containers. Opening the modal now uses delegated document click handling for data-wdc-pickup-open, so buttons recreated by ajax replacement remain clickable. For yandex_pickup, the rendered block carries carrier_key=yandex_delivery, wdc_pickup_family=yandex_delivery:pickup and the standard hidden pickup fields.
### Yandex Delivery checkout PVZ selection 0.104.1

Yandex checkout PVZ loading is intentionally unbounded within the selected city. PickupPointsRestController ignores the request limit for carrier=yandex_delivery, resolves checkout location_id to every mapped/manual yandex_geo_id, and asks YandexDeliveryPickupPointV2Repository for all active destination pickup_point/terminal rows without array_slice or SQL LIMIT. DPD, CDEK and Russian Post keep their existing limits.

NewShippingMethod includes both pickup_selection and pickup_selections in QuoteRequest customer_context. YandexDeliveryCarrier checks pickup_selections['yandex_delivery:pickup'] first, then the global pickup_selection only when it is a Yandex payload, and sends the selected platform_station_id to pricing-calculator with pickup_source=selected. Without a saved Yandex selection it keeps the representative PVZ fallback with pickup_source=representative.
### Yandex Delivery checkout PVZ selection 0.104.0

The shared checkout pickup picker now supports carrier=yandex_delivery. PickupPointsRestController resolves the checkout location_id through YandexLocationMappingV2Repository::geo_ids_for_location() and returns active Yandex destination pickup rows from wp_wdc_yandex_delivery_pickup_points_v2 for all mapped/manual geo ids. Rows must have a non-empty platform_station_id, ctive=1, and 	ype pickup_point or 	erminal; vailable_for_dropoff is intentionally not required for buyer pickup. The response uses the common pickup point shape with carrier_key=yandex_delivery, point_code/platform_station_id, address/name/city/region/coordinates/schedule/description/operator fields and a safe snapshot without raw Yandex JSON.

Checkout hidden fields keep the existing names (wdc_pickup_carrier_key, wdc_pickup_point_code, name/address/city/region/work-time fields). For Yandex, wdc_pickup_carrier_key=yandex_delivery and wdc_pickup_point_code is the selected platform_station_id. CheckoutValidation requires this selection for yandex_pickup, rejects selections from another carrier, and does not require it for yandex_courier. OrderShippingMetaPersister writes the shared _wdc_pickup_* meta plus Yandex aliases such as _wdc_yandex_delivery_pickup_platform_station_id, name, address, city, region and coordinates.
