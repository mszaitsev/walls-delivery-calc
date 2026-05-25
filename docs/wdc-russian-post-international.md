# WDC Russian Post International Carrier

Version: 0.21.6.

## Scope

The new runtime carrier is `russian_post` with service `russian_post_worldwide_parcel`.
It implements “Почта России — международная доставка” entirely in the `src/` architecture. As of `0.20.0`, the legacy `includes/*` runtime has been removed and Russian Post no longer depends on old `WDC_*` classes.

The plugin is fresh-install-only for this runtime generation. Legacy compatibility migrations for old `includes/*` state are not required by production install.

## Runtime Classes

- `WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostSettings`

The carrier supports only non-RU destinations. `RU` is excluded in `supports_country()` and direct quotes for RU return no ordinary rate.

`DeliveryType::COURIER` is used temporarily because the checkout validation/UI currently recognizes courier and pickup as first-class customer flows. The actual service is postal international delivery; the service/tariff metadata keeps `russian_post_worldwide_parcel`.

## Pricing

Russian Post tariff API prices are normalized to API/VAT base price only. If API response contains VAT fields (`paynds` or `paymoneynds`), the value is treated as already including VAT. If only non-VAT fields (`paymoney` or `pay`) are present, VAT is applied once using the configured VAT rate, default `0.2`.

The old built-in storefront formula `/0.89 + 200` has been removed. Commercial adjustments now belong to service/default rules and service post-processing.

## Fallback

API errors, missing tariff/price, unsupported countries, and overweight packages do not throw checkout errors. When fallback is enabled, the carrier returns a visible zero-cost rate with:

- title/comment from `fallback_text`, default `Стоимость доставки рассчитает менеджер`
- `meta.fallback = true`
- `meta.fallback_reason`
- safe API/package metadata without secrets

When fallback is disabled, the quote returns no visible rate and the checkout-level fallback can take over if no other carrier has visible rates.

## Weight And Packaging

Product weight comes from the new `Package` model. Products without weight contribute `0g`.

Packaging weight is added by the shared `PackagingWeightCalculator` before the carrier is called. Russian Post uses `total_weight` mode, so the tariff API receives products weight plus packaging. The carrier checks `max_package_weight_g` from the Russian Post service settings, not a global limit. Overweight suppresses the ordinary tariff and uses fallback when enabled.

## API, Country Mapping, And Cache

All HTTP requests use the WordPress HTTP API. Runtime settings include tariff endpoint, country dictionary endpoint, timeout, debug flag, service max weight, fallback text, country auto-refresh, cache mode, and VAT rate.

As of 0.21.3, Russian Post international settings are service-specific settings. They are edited at `Службы доставки -> Почта России — международная доставка -> Расчет` and stored in `wdc_delivery_service_settings`. The platform settings page no longer renders or saves Russian Post carrier fields. The service row `enabled` flag is the authoritative on/off switch; it is not duplicated in the Russian Post settings payload.

As of 0.21.6, packaging tiers are global calculator settings on `Правила расчета -> Упаковка`, not Russian Post service settings.

Debug logs include endpoint, sanitized params, raw response, parsed response, cache hit/miss, API base price, and fallback reason. Token-like fields are omitted from request params and the shared logger redactor handles sensitive context keys.

As of 0.19.2, the country directory uses the persistent `wdc_russian_post_country_mappings` table. Runtime returns only rows with `effective_enabled=1`; RU is always excluded. The Russian Post dictionary endpoint is used by the admin refresh flow and optional lazy refresh when `auto_refresh_countries_if_empty` is enabled.

As of 0.19.3, Russian Post country refresh matches API rows by normalized country name because the dictionary can omit ISO2 and return only `id`, `name`, and `parcel`. Known WooCommerce/Russian Post naming differences are handled by an alias map, and `rp_iso2` may be empty at runtime.

As of 0.19.4, refresh keeps the persistent mapping table WooCommerce-centric. Unused Russian Post API countries are returned only to the admin manual-mapping UI after refresh; they are not stored as RP-only rows and are invisible to checkout runtime until an admin maps one to a WooCommerce country.

Tariff API results are cached until the end of the current WordPress timezone day. Cache keys include carrier, service, country, request params, and weight.

Country mapping administration now lives inside the delivery service screen at `Службы доставки -> Почта России — международная доставка -> Страны Почты России`. The old standalone submenu is no longer registered. See `docs/wdc-russian-post-countries.md`.

## Rules

Checkout continues to apply rules after the carrier quote. Runtime resolves service rules first and uses default fallback only when the service has no enabled own rules and `use_default_rules_when_no_service_rules` is enabled.

`add_comment` rule text is merged into rate comments and persisted through the existing rate/session/order meta flow. `disable_rate` marks the rate unavailable, so it is hidden before final checkout rates are returned.

The Russian Post service rules simulation calls the carrier first, obtains the real API/base quote, then applies service rules only. It shows base price, final price, audit, fallback/cache metadata, and source details. It does not apply default-rule fallback.
# Russian Post International

Russian Post international is now the first delivery service in the delivery-services foundation:

- `service_key`: `russian_post_worldwide_parcel`
- `carrier_key`: `russian_post`
- `service_type`: `api`
- `availability_mode`: `carrier_directory`

The carrier still talks to the Russian Post tariff API and applies VAT when the API response does not already include VAT. The previous built-in commercial formula `/0.89 + 200` has been removed. Markups belong in service/default rules, not in the carrier.

Fallback rates remain zero-price manager-contact rates. Service post-processing keeps fallback zero as zero.
