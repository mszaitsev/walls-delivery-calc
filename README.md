# Walls Delivery Calc

Version: 0.22.24.

Version 0.22.24 improves Russian Post passport download diagnostics: default timeout is now 120 seconds, settings clamp to 30..300 seconds, streamed requests include connect timeout, import state records download URL/start/duration/HTTP/message/temp size/error, and stale download is failed after 5 minutes.

Version 0.22.23 compacts the Russian Post pickup table before the map stage: fresh tables no longer store `raw_reference` or `work_time_json`, `workTime` is normalized during import into readable `work_time`, and successful finalize runs `ANALYZE TABLE wp_wdc_pickup_points_russian_post`. Existing test tables are not migrated; remove them before reimport with `DROP TABLE IF EXISTS wp_wdc_pickup_points_russian_post;`.

Version 0.22.22 makes the Russian Post pickup table swap recovery-safe: after `RENAME TABLE` the importer verifies that `wdc_pickup_points_russian_post` exists, restores it from backup when possible, keeps backup on unrecovered failure, and records a clear swap error in import state.

Version 0.22.21 moves Russian Post pickup points out of the generic `wdc_pickup_points` table into `wdc_pickup_points_russian_post`. Imports now build a full snapshot in a staging table and atomically swap it into place, REST reads only the carrier-specific main table, and the old `wp_wdc_pickup_points` table can be dropped manually with `DROP TABLE IF EXISTS wp_wdc_pickup_points;`.

Version 0.22.20 adds public read-only REST endpoints for the local pickup database: `GET /wp-json/wdc/v1/points`, `GET /wp-json/wdc/v1/points/search`, and `GET /wp-json/wdc/v1/points/{id}`. The endpoints support carrier/type/limit filters, bbox validation, search, and safe point detail responses without raw import snapshots or secrets.

Version 0.22.13 changes Russian Post pickup import into a resumable background batch pipeline. The init job only downloads/extracts the payload, each batch job parses and upserts 75 objects from the saved payload offset, and finalize deactivates missing points and cleans temp files, so one PHP process no longer writes to MySQL for the whole import.

Version 0.22.12 adds timeout-safe diagnostics for Russian Post pickup background import: Otpravka ZIP download timeout defaults to 300 seconds, failed downloads store HTTP/WP error details and a short body excerpt, stale download stages are failed after 15 minutes, and admins can manually cancel/reset a stuck import without deleting imported points.

Version 0.22.11 keeps Russian Post pickup import state honest when a background job cannot be scheduled: `queued` is saved only after the job is actually created, otherwise state becomes `failed` with `Unable to schedule background import job.`.

Version 0.22.10 runs Russian Post pickup import in the background from the admin UI. The page returns immediately, stores live state in `wdc_russian_post_pickup_import_state`, and polls progress every 3 seconds while the job is queued or running. Stale queued/running locks older than 2 hours are marked failed so a new import can be started; the current ALL test import produced 37302 active points.

Version 0.22.01 fixes Russian Post pickup import identity and temp-file cleanup: `point_code` is now unique per concrete point even when several objects share one postcode, and imported ZIP files are deleted after reading.

Version 0.22.00 adds the production foundation for a local Russian Post pickup-point directory. It extends `wdc_pickup_points`, adds shared API "Отправка" credentials/client classes, imports `unloading-passport` ZIP data through `RussianPostPickupImporter`, exposes manual import/status on the domestic pickup service tab, and keeps checkout map/REST/selected-point persistence for the next stage.

Version 0.21.29 removes deprecated domestic defaults `27030`, `27020`, `28030`, and `28020` while preserving old saved tariff JSON, and simplifies the order calculation metabox by hiding VAT status and technical service keys.

Version 0.21.28 hides domestic selector/runtime technical keys from visible WooCommerce shipping item meta and stores both original API delivery range and final rule-adjusted delivery range in `_wdc_delivery_calculation_data`.

Version 0.21.27 adds skipped-tariff diagnostics for Russian Post domestic API errors, extends courier variants with EMS object codes, formats delivery days with Russian plural forms, and makes domestic checkout/order method titles include service, selected tariff, and delivery range.

Version 0.21.26 keeps Russian Post domestic single-tariff grouped delivery days in the WooCommerce method label, suppresses the separate planned-delivery line for that single-tariff case, and leaves multi-tariff timing only inside selector rows.

Version 0.21.25 gives Russian Post domestic pickup/courier services distinct predefined admin titles, keeps courier availability bootstrapped for RU, uses final rule-adjusted delivery days in checkout and orders, supports internal tariff admin comments, shows per-variant crossed prices, and hides the radio selector when only one tariff is available.

Version 0.21.24 preserves the current enabled state when predefined Russian Post domestic services are upserted, so bootstrap reactivates soft-deleted system rows without undoing a normal admin toggle-off.

Version 0.21.23 protects predefined delivery services from deletion, keeps Russian Post domestic service bootstrap idempotent by service_key, renders domestic service simulation across active tariffs, and labels checkout/order shipping as `Почта России — {тариф}` without duplicating the delivery-days comment under the method.

Version 0.21.22 preserves a valid user-selected Russian Post domestic tariff during repeated checkout recalculations; the first available tariff is saved only as an initial/default fallback.

Version 0.21.21 fixes Russian Post domestic foundation blockers: tariff variant object-code mappings now match the domestic API catalog, postcode fallback can use the existing DaData enrichment path, API item summaries keep nested service/tariff/delivery fields, and API `errorcode`/`errormsg` responses are treated as errors.

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

Version 0.21.20 adds `--insecure` to the Russian Post domestic probe for local Windows environments where PHP's trust store is not configured. SSL verification remains enabled by default and the flag must not be used in production runtime.

PowerShell Russian Post domestic probe:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020
```

Local Windows probe when PHP reports a self-signed certificate chain and the trust store is not configured:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020 --insecure
```

Version 0.21.6 moves packaging weight into the new `src/` foundation. Global tiers live on `Правила расчета -> Упаковка` as `packaging_weight_tiers`; services choose whether to include packaging and whether to apply it as `total_weight` or a `WDC_PACKAGING` virtual package item. Russian Post international uses final total weight.
# Russian Post domestic foundation

This branch adds the foundation for `russian_post_domestic` with pickup/courier services, domestic tariff variants, `pack=99` tariff requests, declared-value `sumoc`, checkout tariff selector, delivery range rules, selected-tariff session persistence and public order meta for service/tariff/delivery range.
