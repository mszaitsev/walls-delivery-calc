# Testing And Regression

Version: 1.0.18

Tests are executable smoke programs with no production dependency installer. Run commands from the repository root with a supported PHP and Node.js runtime.

## Required release checks

Run every PHP smoke under `tests/`, then the manifest-driven shipment regression profile:

```powershell
Get-ChildItem tests -Recurse -Filter *.php |
  Where-Object { $_.Name -like 'run-*-smoke.php' } |
  ForEach-Object { php $_.FullName }

php tests/shipments/run-shipment-regression-profile.php
```

Run focused profiles with `--group=<name>` when diagnosing framework, carrier, pickup, checkout, or status failures. The manifest is authoritative for mandatory, baseline, and optional entries.

Lint all production PHP and check all production JavaScript:

```powershell
Get-ChildItem src,database -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l walls-delivery-calc.php
php -l uninstall.php
Get-ChildItem assets -Recurse -Filter *.js | ForEach-Object { node --check $_.FullName }
git diff --check
```

Run JavaScript smoke programs in `tests/` with `node`. Browser acceptance tests require their explicitly documented browser dependency and are not replaced by syntax checks.

Classic checkout releases run `tests/checkout/run-woocommerce-checkout-smoke.php`. Its grouped-tariff regression protects Woo shipping-package cache invalidation after nested selection, server-side recalculation of the selected title/price/crossed price and total, canonical planned-comment payloads, checked-state restoration, and preservation of the chosen top-level method and same-family pickup point.

## Schema acceptance

`tests/database/run-initial-schema-smoke.php` starts from an empty fake database boundary and asserts every 1.0 table, critical column/index, seed, and retired-table absence. Release acceptance additionally requires activation against real MySQL/MariaDB on a clean WordPress/WooCommerce installation.

## ZIP acceptance

Build `dist/walls-delivery-calc-1.0.18.zip`, inspect its one-folder layout, lint/check the extracted runtime files, and install that ZIP through the standard WordPress upload UI. With `WP_DEBUG` and `WP_DEBUG_LOG` enabled, exercise activation, WDC admin pages, checkout location/rates, pickup map/selection, and recalculation. Any WDC notice, warning, deprecation, or fatal is a release blocker.

Russian Post release checks include `tests/pickup/run-russian-post-weekly-schedule-smoke.php`, the import lifecycle smoke, the optimization smoke, and `node tests/pickup/run-russian-post-pickup-import-runner-smoke.js`. They protect CAS-backed monotonic state revisions across import IDs, owner-safe terminal writes, single-flight status polling, lower-revision rejection, transport recovery, terminal polling stop, bounded exact-FIAS prefetch, bounded bulk staging writes, duplicate/ambiguity behavior, and result parity without depending on the retired production profiler.

Shipment status releases also run `tests/shipments/run-russian-post-status-mapping-smoke.php`. It protects the complete 490-row native catalog, the intentional 1.0.12 `2:25` terminal correction and otherwise-stable 486-row fingerprint, strict override persistence, immutable carrier terminal metadata, the protected admin form, the shared autosync hook, and independence from the Russian Post pickup schedule.

DPD Geography releases run both `tests/dpd/run-dpd-geography-import-smoke.php` and `tests/dpd/run-dpd-geography-import-runner-smoke.js`. They protect polling-only single-flight browser behavior, explicit out-of-order revision rejection without counter rollback, server-side multi-step slices, exactly-one continuation, cancellation/new-owner races, bounded stage query counts, foreign mapping/identity prefetch query ceilings, changed/new canonical-location bulk writes, legacy/duplicate foreign parity, bulk-write failure handling, matching/finalization parity, and Action Scheduler unavailability. `tests/dpd/run-dpd-shipment-preparation-smoke.php` uses one injected fixture clock and must not derive expected dates from the machine clock.

Yandex full geography releases run `tests/yandex-delivery/run-yandex-delivery-geo-pipeline-v2-smoke.php` and `node tests/yandex-delivery/run-yandex-delivery-pickup-v2-browser-smoke.js`. They protect the 18-second/25-unit/80%-memory bounded WP-Cron slice, deterministic budget seams, heavy-download isolation, owner-scoped exactly-one continuation, bootstrap self-heal, owner lease takeover, pause/reset/overlap races, unchanged stage order and staging promotion, server-side rejection of concurrent standalone lower mutations, successful JSON cleanup without a stale second reader, disabled lower browser loops during running/paused ownership, and polling-only full-pipeline browser behavior.

Ozon pickup releases run all `run-ozon-delivery-pickup-*-smoke.php` entrypoints plus `node tests/ozon-delivery/run-ozon-delivery-pickup-browser-smoke.js`. The worker smoke covers the 18-second/10-unit/80%-memory slice, deterministic budget stops, retry termination, owner/cancellation races, exactly-one continuation, and bootstrap self-heal. The query-count smoke proves 250-row discovery chunks and constant-write 100-ID enrichment with rollback/row-count integrity. The browser smoke protects status-only single-flight polling, terminal stop, and transport recovery.

Ozon checkout releases also run `run-ozon-delivery-checkout-smoke.php` and `run-ozon-delivery-quote-smoke.php`. They protect runtime availability from dependency on admin quote-diagnostic history, credentials and per-mode shipment-method prerequisites, live quote success/failure behavior, diagnostic persistence, and diagnostic-independent cache context.

Old regression tests remain when they protect a current business contract. Tests that only replay removed pre-1.0 schema transitions or retired runtime paths do not belong to the 1.0 suite.
