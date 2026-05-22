# WDC DaData Suggestions 0.14.8

## Purpose

DaData suggestions are now an address picker flow, not inline autocomplete inside the WooCommerce `address_1` field.

The buyer clicks or focuses the standard checkout address field, WDC opens its own modal, and all DaData search happens inside the modal search input. After the buyer chooses a final house or flat, WDC fills the WooCommerce fields programmatically.

This is more stable for WooCommerce checkout because WooCommerce may redraw billing and shipping fields during `updated_checkout`, while the picker keeps its own search input, results area, state, and selection flow.

## Server Proxy

The browser calls only the WordPress AJAX action:

`wdc_platform_dadata_address_suggest`

The DaData API key is never sent to the browser. It is stored encrypted on the server through the same setting used by DaData normalization:

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

## Address Picker UX

Opening:

- active `billing_address_1` opens the picker by default;
- active `shipping_address_1` opens it only when ship-to-different-address is checked and visible usable shipping fields exist;
- no extra “choose address” button is rendered;
- disabled suggestions do not open the picker.

Modal structure:

- title: `Выберите адрес доставки`
- search input: `.wdc-address-picker-search`
- results: `.wdc-address-picker-results`
- hint area: `.wdc-address-picker-hint`
- close button, ESC, and outside click support

Desktop layout uses a wide panel up to 1300px with two-column results when space allows. Mobile layout uses one column and fits the viewport.

## Stages

`stage=address`:

- used while searching street or full address in the modal;
- sends `locations: [{ country_iso_code: "RU" }]`;
- sends `locations_boost` with selected `city_kladr_id` or `settlement_kladr_id` when available;
- city is a boost, not a hard restriction.

`stage=house_after_street`:

- used after a street-only suggestion is selected;
- restricts by `street_fias_id`;
- searches houses only;
- requests up to 20 items.

`stage=resolve`:

- used after a house or flat suggestion is selected;
- sends the selected `unrestrictedValue`;
- requests `count: 1`;
- final resolved item fills WooCommerce fields and hidden DaData fields.

`stage=city` remains available for DaData city mode, but the modal address picker uses `address`, `house_after_street`, and `resolve`.

## Street To House

When a street item is selected:

- standard `address_1` becomes `street_with_type + " "`;
- status becomes `street_selected`;
- street FIAS/KLADR hidden fields are saved;
- the modal stays open;
- hint says `Добавьте номер дома`;
- the next modal search uses `stage=house_after_street`;
- the buyer can click `Изменить улицу` to return to `stage=address`.

When a house or flat item is selected:

- frontend calls `stage=resolve`;
- resolved data fills city, state, postcode, address_1, and address_2 when available;
- status becomes `resolved`;
- hidden fields are filled;
- modal closes;
- a selected notice appears near `address_1`;
- `update_checkout` runs.

## Manual Fallback

If DaData returns no useful results, the modal shows:

`Адрес не найден. Можно продолжить ручной ввод.`

The buyer can click:

`Использовать введенный адрес`

Manual fallback:

- writes the modal search value into `address_1`;
- sets status to `manual`;
- clears address-specific DaData ids while keeping city fields;
- closes the modal;
- triggers `update_checkout`;
- does not block checkout.

City is still required by normal checkout validation.

## Hidden Fields And Order Meta

The frontend writes `{billing|shipping}_dadata_*` hidden fields for region, city, settlement, street, house, flat, FIAS/KLADR ids, unrestricted value, status, and FIAS level.

Order persistence stores `_billing_dadata_*`, `_shipping_dadata_*`, and compatible WDC meta such as `_wdc_platform_fias_id`, `_wdc_platform_resolved_postcode`, `_wdc_platform_normalized`, and `_wdc_platform_normalization_source`.

## Troubleshooting

1. Check that `checkout-address-suggestions.js` is loaded on checkout.
2. Enable checkout debug panel.
3. Click or focus the active `address_1` field.
4. Check Console for `address picker opened`, `modal search input`, `ajax request start`, and `ajax success items count`.
5. Check the debug block near `address_1`: `script loaded`, `config enabled`, `active prefix`, `modal opened`, `last stage`, `last query`, `last ajax status`, `last items count`.
6. Check Network for `admin-ajax.php?action=wdc_platform_dadata_address_suggest`.
7. Manual endpoint probe: POST `admin-ajax.php?action=wdc_platform_dadata_address_suggest` with `stage=address` and `query=тверская`.
8. If `config enabled: no`, check that DaData suggestions are enabled, the API key is saved, and `APP_ENCRYPTION_KEY` is configured.
9. If the modal opens near the wrong field, check active mode: shipping is used only when ship-to-different-address is checked and usable shipping fields are visible.
