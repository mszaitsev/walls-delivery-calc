# Walls Delivery Calc

Version: 0.20.0.

Walls Delivery Calc is a WooCommerce delivery calculator plugin. The runtime is now `src/` only: the old `includes/*` legacy bootstrap, shipping method, carriers, API clients, settings, helpers, and cache wrappers have been removed.

This branch targets fresh installs only. Compatibility migrations for old legacy state are not part of the active install path. Current migrations still create the active platform schema: calendar, locations/GAR, pickup points, rules, DaData-related settings/options, and Russian Post country mappings.

## Runtime

- Main plugin file loads `src/Core/bootstrap.php`.
- `WallsShop\WDC\Core\Plugin` registers the service container, hooks, activation install, migrations, WooCommerce shipping method, checkout runtime, admin pages, and scheduled jobs.
- `CarrierRegistry` registers the current real carrier: Russian Post international.
- Demo JSON fixtures live under `tests/fixtures/demo` and are not used from runtime paths.

## Russian Post International

Russian Post international delivery runs through the `src` architecture:

- `WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostSettings`

The carrier is international-only, excludes `RU`, uses shared package and packaging-weight logic, caches quotes until the end of the current WordPress day, and returns configured manager fallback rates for API/availability failures when enabled.
