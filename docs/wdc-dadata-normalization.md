# WDC DaData Address Normalization

Version 0.14.2 uses DaData address cleaning as a token-only fallback in the checkout address pipeline.

## Role in the fallback chain

The checkout runtime still starts from the local city context. The local database resolves the city, region, postcode, FIAS/GAR ids for the selected settlement, but it does not normalize street and house.

The full chain is:

1. Local city context.
2. FIAS/GAR placeholder. Real FIAS runtime normalization is intentionally disabled.
3. DaData cleaner API.
4. Manual fallback.

DaData runs only when `dadata_enabled` is enabled and the API token is configured.

## Credentials

Settings store:

- `dadata_api_token_encrypted`
- `dadata_api_token_masked`

`DaDataCredentials` encrypts the API token through `EncryptionService`. The raw token is never rendered back into the settings page. Empty password input on save keeps the current token unchanged. To delete the token, use the separate `Удалить сохраненный токен DaData` checkbox. DaData secret key is not used.

If `APP_ENCRYPTION_KEY` is not configured, credentials are not saved, settings show an admin warning, and DaData is treated as not configured.

## Request

The address query is built by `Checkout\Address\AddressQueryBuilder` from checkout-safe address fields only:

- country
- region
- city
- `address_1`
- `address_2`

It does not include phone, email, customer name, or other personal fields.

Example:

```text
Россия, Новосибирская область, Новосибирск, Красный проспект, 25
```

The HTTP request is:

```http
POST https://cleaner.dadata.ru/api/v1/clean/address
Content-Type: application/json
Accept: application/json
Authorization: Token <token>

["Россия, Новосибирская область, Новосибирск, Красный проспект, 25"]
```

No `X-Secret` header is sent.

## Response mapping

The first object from the DaData response is mapped into `Address`:

- `postal_code` -> `postcode`
- `region_with_type` or `region` -> `region_name`
- `region_iso_code` or `region_kladr_id` -> `region_code`
- `city` or `settlement` -> `city` / `settlement`
- `street_with_type` -> `street`
- `house` plus `block` -> `house`
- `result` -> `raw_address`
- `fias_id` -> `fias_id`

`geo_lat` and `geo_lon` are intentionally ignored for now.

If DaData returns no city, the normalizer keeps the checkout city context.

## Confidence

Confidence is derived from DaData quality codes:

- `qc=0` and `qc_complete=0` -> `0.95`
- `qc=0` with incomplete address -> `0.7`
- other cases -> `0.6`

Successful DaData results are marked:

- `success=true`
- `source=dadata`
- `normalized=true`
- `fallback=false`

## Failure cases

DaData runs only when there is a city context and a non-empty checkout address line (`shipping_address_1` or `billing_address_1`). If the address line is empty, no HTTP request is made.

Checkout recalculation is triggered by `assets/frontend/checkout-address-normalization.js`. It listens to `shipping_address_1`, `shipping_address_2`, `billing_address_1`, and `billing_address_2`, debounces input, then triggers WooCommerce `update_checkout`. DaData runs on the backend during that update.

DaData returns an unsuccessful normalization result without throwing fatals:

- `dadata_disabled`
- `dadata_credentials_missing`
- `dadata_empty_address`
- `dadata_api_failed`
- `dadata_timeout`

After any unsuccessful DaData result, checkout continues to manual fallback.

## Logging and privacy

`DaDataLogger` redacts sensitive context keys. Logs do not include raw token, full raw address, phone, email, or customer names. HTTP failures and parse errors are logged with status/error metadata only.

When checkout debug panel is enabled for admins, it shows the DaData query parts, final query string, normalization chain, current source, normalized flag, and DaData error code. The same debug-only render emits browser console messages:

- `dadata normalization started`
- `dadata request prepared`
- `dadata success`
- `dadata failed`
- `dadata timeout`
- `dadata skipped: disabled`
- `dadata skipped: missing token`
- `dadata skipped: empty address`

## Order data

When DaData succeeds, checkout order meta stores:

- `_wdc_platform_normalized = true`
- `_wdc_platform_normalization_source = dadata`
- `_wdc_platform_resolved_postcode = <DaData postal_code>`
- `_wdc_platform_fias_id = <DaData fias_id>` when present

The order delivery metabox shows DaData as the normalization source, plus postcode and FIAS ID when available.

## Testing

Smoke tests use mocked WordPress HTTP calls and do not contact DaData.

Run:

```bash
php tests/dadata/run-dadata-smoke.php
php tests/address/run-address-smoke.php
php tests/fias/run-fias-smoke.php
php tests/checkout/run-runtime-stabilization-smoke.php
git diff --check
```

Manual checkout test:

1. Configure `APP_ENCRYPTION_KEY`.
2. Enable DaData in WDC settings.
3. Save the DaData API token.
4. Enable the checkout debug panel.
5. Select a city.
6. Enter street/address line and house if available.
7. Wait for `update_checkout`.
8. Check the debug panel for DaData status and query string.

## Known limitations

- FIAS runtime normalization remains disabled.
- DaData is used only for checkout address cleaning, not city autocomplete.
- Coordinates from DaData are not used yet.
- Apartment/office parsing is not modeled separately unless the domain object grows a dedicated field for it.
