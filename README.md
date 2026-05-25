# Walls Delivery Calc

Version: 0.21.19.

Walls Delivery Calc is a WooCommerce delivery calculator plugin. The runtime is now `src/` only: the old `includes/*` legacy bootstrap, shipping method, carriers, API clients, settings, helpers, and cache wrappers have been removed.

This branch targets fresh installs only. Compatibility migrations for old legacy state are not part of the active install path. Current migrations create the active platform schema: calendar, locations/GAR, pickup points, rules, DaData-related settings/options, Russian Post country mappings, and delivery service tables.

## Runtime

- Main plugin file loads `src/Core/bootstrap.php`.
- `WallsShop\WDC\Core\Plugin` registers the service container, hooks, activation install, migrations, WooCommerce shipping method, checkout runtime, admin pages, and scheduled jobs.
- `CarrierRegistry` registers the current real carrier: Russian Post international.
- `DeliveryServiceRegistry` and `DeliveryServiceManager` wrap carriers as persistent delivery services.
- Demo JSON fixtures live under `tests/fixtures/demo` and are not used from runtime paths.

## Russian Post International

Russian Post international delivery runs through the `src` architecture:

- `WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostSettings`

The carrier is international-only, excludes `RU`, uses shared package and packaging-weight logic, caches quotes until the end of the current WordPress day, and returns configured manager fallback rates for API/availability failures when enabled. It returns API/VAT base price only; the old `/0.89 + 200` built-in markup has been removed.

## Delivery Services

Version 0.21.0 adds persistent delivery services:

- `wdc_delivery_services`
- `wdc_delivery_service_settings`
- `wdc_delivery_service_countries`

Russian Post international is auto-created as `russian_post_worldwide_parcel`. Service-specific rules can override default rules, and default fallback is controlled per service. Service post-processing applies minimum price and ruble rounding after rules while preserving zero fallback rates.

Version 0.21.1 makes the rules admin reusable: the default rules page and each delivery service's `Правила` tab use the same controller with different target context. Service tabs can copy current default rules into service-specific rules, simulation stays separated by target, quote cache keys include `service_key`, and `minimum_price_rub` is normalized to a non-negative decimal.

Version 0.21.3 adds real service edit tabs. Main, availability, calculation, rules, and Russian Post countries now render separate content. Russian Post service settings moved out of platform settings and into the service calculation tab, stored in `wdc_delivery_service_settings`; the Russian Post countries UI is embedded as a service tab. New rules default to `condition_1`, no-condition summaries show `Нет условий`, and Russian Post service simulation calls the carrier before applying service rules only.

Version 0.21.4 removes the remaining standalone Russian Post countries admin page surface. The countries admin class is embedded-only and is reachable through the Russian Post delivery service tab.

Version 0.21.5 removes the last dead standalone render branch from the embedded-only Russian Post countries admin.

Version 0.21.12 adds structured order calculation data. The selected delivery calculation is saved to `_wdc_delivery_calculation_data`, and the WooCommerce order metabox `Калькулятор доставок` renders the readable admin view. For Russian Post international, visible shipping item meta is reduced to `Способ доставки: международная доставка Почтой России`; API, package, rules, fallback, and technical service metadata are stored in the hidden calculation payload instead.

Version 0.21.13 extends rule audit entries with operation value/base, so order formula visualization can render actual applied rule names and operations for multiply, divide, and fixed price changes.

Version 0.21.14 cleans WooCommerce shipping item meta copied from rate `meta_data` for Russian Post international and stores the actual final rate price in runtime meta after rules and service post-processing.

Version 0.21.15 makes the checkout city picker country-aware. The local location country index is stored in `wdc_location_country_codes`, checkout search/resolve accept `country_code`, supported countries search only their own local rows, and unsupported countries keep normal manual WooCommerce city/state input without modal, auto-resolve, local warning, or stale local location order meta. For RU/BY/KZ, latin city picker text is treated as transliteration or wrong keyboard layout input before database lookup.

Version 0.21.16 treats `wdc_location_country_codes` with `countries=[]` and `stale=false` as a valid initialized empty index, so empty local location tables do not trigger repeated lazy rebuilds on every checkout request.

Version 0.21.17 extends `wdc_location_country_codes` with cached per-country location counts and shows the country summary on the `Населенные пункты` admin page. Country names come from WooCommerce countries, with country-code fallback when a name is not available.

Version 0.21.18 starts Russian Post domestic carrier preparation. Old demo pickup rows for carrier `demo` are cleaned from the pickup admin page, and `docs/wdc-russian-post-domestic.md` plus `tests/carriers/run-russian-post-domestic-api-probe.php` document and probe domestic tariff candidates.

Version 0.21.19 makes the old demo pickup row cleanup one-time through the `wdc_demo_pickup_cleanup_done` option instead of deleting on every pickup admin page load.

PowerShell Russian Post domestic probe:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=27030,27020,4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020
```

Version 0.21.6 moves packaging weight into the new `src/` foundation. Global tiers live on `Правила расчета -> Упаковка` as `packaging_weight_tiers`; services choose whether to include packaging and whether to apply it as `total_weight` or a `WDC_PACKAGING` virtual package item. Russian Post international uses final total weight.
