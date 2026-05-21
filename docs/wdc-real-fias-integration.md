# WDC Real FIAS/GAR Integration

## Architecture

The checkout address runtime uses a fallback-first chain:

1. Local locations database and aliases.
2. FIAS/GAR HTTP API when local confidence is low and API is enabled.
3. DaData placeholder fallback, currently disabled and without real requests.
4. Manual fallback address, which never blocks checkout.

Runtime URL construction lives in `FiasEndpoints`. HTTP transport lives in `FiasHttpClient`, and checkout code only receives a normalized result or a safe unsuccessful result.

## Hybrid Normalization

Local DB remains the first UX layer. Exact city or settlement matches normalize immediately and can fill missing postcode from the local `Location`. Prefix/uncertain matches may call FIAS/GAR. If FIAS returns a more precise postcode, that postcode overwrites the local or checkout postcode.

Unknown cities are allowed. They become fallback/manual results and checkout continues.

## Limiter

`FiasRateLimiter` uses WordPress transients for per-minute and per-day counters. Limits are configured through settings:

- `fias_api_daily_limit`
- `fias_api_minute_limit`

When the limiter blocks a request, no exception reaches checkout. The normalizer returns an unsuccessful FIAS result and the chain continues to fallback.

## Timeout Behavior

`FiasHttpClient` passes the configured timeout to `wp_remote_get` and `wp_remote_post`. Timeouts, HTTP errors, malformed JSON, and unexpected response shapes are logged and converted into safe unsuccessful responses.

## GAR Sync

`GarChangesClient` wraps GAR changes endpoints on `fias-public-service.nalog.ru`. `GarSyncManager` schedules a daily detect-only check through the existing ActionScheduler abstraction. It stores detection records in `wdc_gar_changes` but does not auto-apply changes.

## Aliases

`LocationAliasGenerator` generates aliases for imported locations. Prepared imports persist generated aliases into `wdc_location_aliases`. Examples:

- Новосибирск: `новосиб`, `нск`, `новосибирская`
- Бердск: `бердск`

## Prepared Imports

`FiasImportManager` supports prepared JSON datasets, batch processing, location inserts, and alias generation. The sample dataset is `database/demo/fias-prepared-sample.json` and intentionally stays small.

## Checkout Safety

Checkout never depends on a successful external API response. API disabled, rate-limited, timeout, parse failure, and GAR failure states all resolve into fallback-safe results. The renderer may show a calm API-unavailable notice, but validation keeps unknown cities allowed.
