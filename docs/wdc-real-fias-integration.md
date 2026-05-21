# WDC FIAS/GAR Integration Foundation

## Current Scope

FIAS/GAR integration is prepared, but runtime address standardization through the public API is temporarily disabled. The API documentation has several similar address methods, and the working contract must be verified manually after a real token is available.

The plugin can store a FIAS/GAR API token now. The token is encrypted with `EncryptionService`, masked in admin UI, and never rendered or logged as raw text.

## Local City Database

The local database contains only city-level data:

- regions
- cities and settlements
- settlement postcodes
- settlement `fias_id` and `gar_id`

It does not contain streets, houses, or address objects. Because of that, local data is not treated as full address normalization.

Local data is used for:

- city selector
- region and postcode context
- settlement `fias_id` / `gar_id`
- pickup filtering
- checkout context

Local data is not used for:

- street normalization
- house normalization
- full address standardization

## Checkout Chain

The checkout chain is:

1. Local city DB provides city context.
2. FIAS placeholder returns an unsuccessful result:
   - `fias_token_missing` when no token is saved.
   - `fias_runtime_disabled` when a token is saved but runtime API methods are not verified.
3. DaData fallback placeholder runs next.
4. Manual fallback keeps checkout alive.

No runtime HTTP request to FIAS is executed by `FiasAddressNormalizer` at this stage.

## Foundation Kept

These classes remain in place for the next stage:

- `FiasEndpoints`
- `FiasHttpClient`
- `FiasLogger`
- `FiasRateLimiter`
- `FiasCredentials`

They are not used by checkout runtime normalization until the API method contract is verified.

## GAR Sync

GAR detect-only architecture remains prepared. Runtime GAR requests are disabled by default and must be explicitly enabled in a later verified stage. Disabled GAR checks do not affect checkout.

## Prepared Imports And Aliases

Prepared JSON imports still support city-level dataset loading and alias generation. Aliases improve city selection only; they do not imply full address standardization.

Examples:

- Новосибирск: `новосиб`, `нск`, `новосибирская`
- Бердск: `бердск`

## Timeout And Safety

Because checkout runtime does not call FIAS now, FIAS timeouts cannot affect checkout. Future HTTP failures must remain fail-open: unsuccessful normalizer result, then DaData/manual fallback.
