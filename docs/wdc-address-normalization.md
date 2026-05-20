# WDC Address Normalization

Version 0.12.0 adds checkout runtime address behavior for the new platform shipping method. It does not add external FIAS or DaData HTTP calls, AJAX autocomplete, maps, modal UI, REST endpoints, shipment export, order statuses, or real carrier integrations.

## Normalization pipeline

Checkout address runtime reads the WooCommerce checkout destination fields:

- `shipping_country`
- `shipping_city`
- `shipping_postcode`
- `shipping_address_1`
- `shipping_address_2`

`CheckoutAddressRuntime` builds a small context, resolves a postcode from local locations when possible, then calls `CheckoutAddressNormalizer`.

The normalizer tries providers in order:

1. `FiasAddressNormalizer`
2. `DaDataAddressNormalizer`
3. `FallbackAddressNormalizer`

The result is stored in `CheckoutSessionManager` as an `AddressNormalizationResult`.

## FIAS abstraction

`FiasAddressNormalizer` is a local stub. It does not perform HTTP requests. It uses `CheckoutCityResolver` and the local `LocationRepository` through `CheckoutLocationSearch`.

When the city resolves to a known location, the result is successful:

- `source=fias`
- `success=true`
- `address.normalized=true`
- city, region, postcode, FIAS id, and GAR id are copied from the location

## DaData abstraction

`DaDataAddressNormalizer` is intentionally unsuccessful for now:

- `source=dadata`
- `success=false`
- `error_code=normalizer_not_configured`

The class exists so the future real API adapter can be added without changing checkout orchestration.

## Fallback strategy

If local FIAS resolution and DaData both fail, checkout uses fallback normalization:

- unknown city is allowed
- `address.fallback=true`
- no postcode is invented
- raw checkout address is preserved

This keeps checkout usable while marking the destination as lower confidence.

## Checkout runtime behavior

`woocommerce_checkout_update_order_review` feeds posted checkout data into the runtime. The selected city, fallback city, and normalized address result are persisted in the WooCommerce session.

`WooCommercePackageMapper` uses the normalized session address for `QuoteRequest.destination`, so the orchestrator and rule engine receive resolved city, postcode, normalized flag, and fallback flag.

## Postcode autofill

When a checkout city matches a local location, postcode is stored in runtime/session context and passed into `QuoteRequest.destination`.

This release does not overwrite checkout fields with JavaScript.

## Informational rendering

`CheckoutAddressRenderer` displays:

- normalized city
- resolved postcode
- fallback notice
- normalization source

The output is informational only.

## Order meta

`OrderShippingMetaPersister` stores:

- `_wdc_platform_normalized`
- `_wdc_platform_normalization_source`
- `_wdc_platform_fallback_city`
- `_wdc_platform_fallback_address`
- `_wdc_platform_resolved_postcode`
- `_wdc_platform_fias_id`
- `_wdc_platform_gar_id`

## Future work

Planned extensions can add AJAX/autocomplete, real FIAS API calls, real DaData API calls, and map UI on top of the current runtime contracts.
