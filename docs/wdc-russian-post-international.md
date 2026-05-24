# WDC Russian Post International Carrier

Version: 0.19.0.

## Scope

The new runtime carrier is `russian_post` with service `russian_post_worldwide_parcel`.
It implements “Почта России — международная доставка” in the `src/` architecture without using `includes/*` as runtime dependencies.

Legacy files used as logic references:

- `includes/carriers/russian-post/class-wdc-russian-post-carrier.php`
- `includes/carriers/russian-post/class-wdc-russian-post-api.php`
- `includes/carriers/russian-post/class-wdc-russian-post-countries.php`
- `includes/class-wdc-weight-calculator.php`
- `includes/class-wdc-settings.php`

Those files remain legacy-only and must not be modified by this migration.

## Runtime Classes

- `WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostSettings`

The carrier supports only non-RU destinations. `RU` is excluded in `supports_country()` and direct quotes for RU return no ordinary rate.

`DeliveryType::COURIER` is used temporarily because the checkout validation/UI currently recognizes courier and pickup as first-class customer flows. The actual service is postal international delivery; the service/tariff metadata keeps `russian_post_worldwide_parcel`.

## Pricing

Russian Post tariff API prices are normalized before applying the storefront formula:

```text
ceil((API price with VAT applied) / 0.89 + 200)
```

If API response contains VAT fields (`paynds` or `paymoneynds`), the value is treated as already including VAT. If only non-VAT fields (`paymoney` or `pay`) are present, VAT is applied once using the configured VAT rate, default `0.2`.

The resulting shipping price is stored as RUB and rounded with `ceil`.

## Fallback

API errors, missing tariff/price, unsupported countries, and overweight packages do not throw checkout errors. When fallback is enabled, the carrier returns a visible zero-cost rate with:

- title/comment from `fallback_text`, default `Стоимость доставки рассчитает менеджер`
- `meta.fallback = true`
- `meta.fallback_reason`
- safe API/package metadata without secrets

When fallback is disabled, the quote returns no visible rate and the checkout-level fallback can take over if no other carrier has visible rates.

## Weight And Packaging

Product weight comes from the new `Package` model. Products without weight contribute `0g`.

Packaging weight is added from the shared `packaging_tiers` setting. The carrier checks `max_package_weight_g` from the Russian Post service settings, not a global limit. Overweight suppresses the ordinary tariff and uses fallback when enabled.

## API, Country Mapping, And Cache

All HTTP requests use the WordPress HTTP API. Runtime settings include tariff endpoint, country dictionary endpoint, API token, timeout, debug flag, service max weight, and fallback text.

Debug logs include endpoint, sanitized params, raw response, parsed response, cache hit/miss, formula calculation, and fallback reason. Token-like fields are omitted from request params and the shared logger redactor handles sensitive context keys.

The country directory uses the Russian Post dictionary endpoint and maps carrier country IDs to WooCommerce ISO2 country codes when present. RU is excluded even if present in the dictionary.

Tariff API results are cached until the end of the current WordPress timezone day. Cache keys include carrier, service, country, request params, and weight.

## Rules

Checkout continues to apply rules after the carrier quote. The new shipping method now resolves rules per carrier with `get_rules_for_carrier_with_default_fallback($carrierKey)` when available, otherwise it falls back to default rules.

`add_comment` rule text is merged into rate comments and persisted through the existing rate/session/order meta flow. `disable_rate` marks the rate unavailable, so it is hidden before final checkout rates are returned.
