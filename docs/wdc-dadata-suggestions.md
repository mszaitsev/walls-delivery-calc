# WDC DaData Suggestions 0.14.6

## Purpose

DaData suggestions are a visual, step-by-step checkout flow, not only post-factum address normalization. The buyer should be able to type a city, choose a city suggestion, type a street or address, choose a street, then choose a house and resolve the final address.

The flow is:

`city -> address/street -> house_after_street -> resolve`

Manual checkout input remains valid fallback.

## Server Proxy

The browser calls only the WordPress AJAX action:

`wdc_platform_dadata_address_suggest`

The DaData API key is never sent to the browser. It is stored encrypted on the server through the same setting used by post-factum DaData normalization:

`dadata_api_token_encrypted`

Frontend config may contain only service flags and labels:

- `ajax_url`
- `nonce`
- `min_chars`
- `debug`
- `suggestions_requested`
- `enabled`
- `api_key_ready`
- `encryption_ready`
- `strings`
- `stages`
- `actions`

## Endpoint

Server-side requests use DaData Suggest API:

`https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`

Headers:

- `Authorization: Token <api_key>`
- `Content-Type: application/json`
- `Accept: application/json`

`X-Secret` is not used.

## Stages

`stage=city`:

- `locations: [{ country_iso_code: "RU" }]`
- `from_bound: city`
- `to_bound: settlement`

`stage=address`:

- `locations: [{ country_iso_code: "RU" }]`
- `locations_boost` with selected `city_kladr_id` or `settlement_kladr_id`
- `from_bound: street`
- `to_bound: house`

The city is a boost, not a hard restriction.

`stage=house_after_street`:

- `locations: [{ fias_id: street_fias_id }]`
- `from_bound: house`
- `to_bound: house`
- `restrict_value: true`
- `count: 20`

`stage=resolve`:

- `query: unrestrictedValue`
- `count: 1`

## Frontend

When `dadata_suggestions_enabled=true`, `checkout-address-suggestions.js` and CSS are loaded even if the API key is missing or encryption is not ready. In that case `config.enabled=false`, no AJAX request is sent, and admins with debug enabled see a clear message under `address_1`.

Search handlers are delegated to `document.body` and listen only to `input`, `keyup`, and `paste`. `blur` and `change` do not start searches. WooCommerce refreshes are handled through `updated_checkout` and `wc_fragments_refreshed`.

Supported selectors:

- city: `shipping_city`, `billing_city`
- address 1: `shipping_address_1`, `billing_address_1`, including `input` and `textarea`
- address 2: `shipping_address_2`, `billing_address_2`
- postcode/state are filled when available

No `update_checkout` is triggered while typing. It is triggered only after a suggestion is selected and applied.

## Street To House

When a street item is selected:

- `address_1` becomes `street_with_type + " "`
- status becomes `street_selected`
- `street_fias_id` and `street_kladr_id` are saved
- the next address input uses `stage=house_after_street`

When a house or flat item is selected:

- frontend calls `stage=resolve`
- the resolved item fills city, state, postcode, address_1, address_2
- status becomes `resolved`
- popup closes
- `update_checkout` runs

## Hidden Fields And Order Meta

The frontend writes `{billing|shipping}_dadata_*` hidden fields for region, city, settlement, street, house, flat, FIAS/KLADR ids, unrestricted value, status, and FIAS level.

Order persistence stores `_billing_dadata_*`, `_shipping_dadata_*`, and compatible WDC meta such as `_wdc_platform_fias_id`, `_wdc_platform_resolved_postcode`, `_wdc_platform_normalized`, and `_wdc_platform_normalization_source`.

## Troubleshooting

1. Check that `checkout-address-suggestions.js` is loaded on checkout.
2. Enable checkout debug panel.
3. Check the debug block under `billing_address_1` or `shipping_address_1`: `DaData подсказки: script loaded`, `config enabled`, `api key ready`, `encryption ready`, `address field`, `last query`, `last ajax status`, `last items count`.
4. Check Console for `address suggestions script loaded`, `address field found`, `address input event`, `ajax request start`.
5. Check Network for `admin-ajax.php?action=wdc_platform_dadata_address_suggest`.
6. Manual endpoint probe: POST `admin-ajax.php?action=wdc_platform_dadata_address_suggest` with `stage=address` and `query=тверская`.
7. If `config enabled: no`, check that DaData suggestions are enabled, the API key is saved, and `APP_ENCRYPTION_KEY` is configured.
8. If `address field: not found`, inspect the real checkout input names. The expected names are `billing_address_1`, `shipping_address_1`, `billing_city`, and `shipping_city`.

## Fallback

If DaData is disabled, missing credentials, API failure, or the buyer ignores suggestions, checkout should not be blocked. The address is treated as manual fallback unless city is empty. Empty city should still produce the normal validation error asking for the settlement.
