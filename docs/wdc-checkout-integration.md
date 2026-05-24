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
- `checkout_location_search_limit`, default `100`, min `10`, max `300`;
- `checkout_location_region_limit`, default `10`, min `3`, max `50`.

When region prefill is enabled, opening the picker seeds the modal query from `state + ", " + city`; with only one field filled it uses that field, and with both empty it leaves the query empty. Each region initially shows up to `checkout_location_region_limit` rows. If more rows exist, the frontend shows `Показать все варианты в области` and repeats search with `force_region_code`, returning only that region up to the global checkout search limit.

Selecting a location writes formatted state/city values, fills postcode only when the local row has one, stores the selected local payload in hidden checkout fields, and renders a full-width selected notice. On checkout load, after `updated_checkout`, and after manual state/city edits, the frontend calls `wdc_platform_resolve_checkout_location`; only one confident local match restores hidden selected state, otherwise checkout shows `Просим проверить название и внести верный населенный пункт`.

## Debug panel

`CheckoutDebugPanel` renders checkout debug data only for users with `manage_options`. It shows orchestration rate count, cache hits, fallback state, carrier errors, and returned orchestration rates.

## Fallback behavior

Checkout calculation is wrapped in exception handling. Carrier failures are captured by the orchestration guard, and if no visible rates remain the fallback rate is returned. If the shipping method itself throws during calculation, a zero-cost checkout fallback rate is still added so checkout remains usable.

## Feature flags

`FeatureFlags::new_shipping_method_enabled()` defaults to false. The setting `enable_new_checkout_shipping` also defaults to false. The new shipping method appears in checkout only when one of those gates is enabled.
