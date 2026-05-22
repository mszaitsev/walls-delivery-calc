# WDC Runtime Stabilization

## Checkout Runtime

The new checkout runtime remains behind the `enable_new_checkout_shipping` setting. It registers the WDC shipping method, checkout assets, rate sorting, pickup selection, address validation, city resolving, and debug panel only when the feature gate is enabled.

## Address Flow

The runtime address normalization chain is:

`local city context -> FIAS placeholder -> manual fallback`

DaData post-factum normalization was removed. DaData is now used only by the visual address picker in checkout.

## Local City Picker

The local city picker remains enabled and continues to own city selection:

- opens from the standard WooCommerce city field;
- searches the local locations database through `wdc_platform_search_locations`;
- fills city, postcode, region/state when available;
- stores `wdc_platform_location_*` hidden fields;
- triggers WooCommerce `update_checkout` after a city is selected.

DaData address suggestions do not replace the local city picker.

## DaData Visual Address Suggestions

DaData suggestions use the server-side AJAX proxy `wdc_platform_dadata_address_suggest`. The API key is encrypted on the server and is not exposed to browser config.

The address picker opens from `address_1` and seeds its modal search input from:

`region, city, address_1`

The `address` stage sends the full query to DaData Suggest API with RU country scope. It does not send `locations_boost`.

Final DaData selections are written into `{billing|shipping}_dadata_*` hidden fields and persisted to order meta. Manual fallback remains non-blocking.

## Smoke Tests

- `php tests/checkout/run-runtime-stabilization-smoke.php`
- `php tests/checkout/run-woocommerce-checkout-smoke.php`
- `php tests/pickup/run-pickup-smoke.php`
- `php tests/address/run-address-smoke.php`
- `php tests/address/run-dadata-suggestions-smoke.php`
