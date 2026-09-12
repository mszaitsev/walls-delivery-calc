# Testing And Regression

Version: 1.0.12

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

## Schema acceptance

`tests/database/run-initial-schema-smoke.php` starts from an empty fake database boundary and asserts every 1.0 table, critical column/index, seed, and retired-table absence. Release acceptance additionally requires activation against real MySQL/MariaDB on a clean WordPress/WooCommerce installation.

## ZIP acceptance

Build `dist/walls-delivery-calc-1.0.12.zip`, inspect its one-folder layout, lint/check the extracted runtime files, and install that ZIP through the standard WordPress upload UI. With `WP_DEBUG` and `WP_DEBUG_LOG` enabled, exercise activation, WDC admin pages, checkout location/rates, pickup map/selection, and recalculation. Any WDC notice, warning, deprecation, or fatal is a release blocker.

Russian Post release checks include `tests/pickup/run-russian-post-weekly-schedule-smoke.php`, the import lifecycle smoke, and the optimization smoke. The latter asserts bounded exact-FIAS prefetch, bounded bulk staging writes, duplicate/ambiguity behavior, and result parity without depending on the retired production profiler.

Shipment status releases also run `tests/shipments/run-russian-post-status-mapping-smoke.php`. It protects the complete 490-row native catalog, the intentional 1.0.12 `2:25` terminal correction and otherwise-stable 486-row fingerprint, strict override persistence, immutable carrier terminal metadata, the protected admin form, the shared autosync hook, and independence from the Russian Post pickup schedule.

Old regression tests remain when they protect a current business contract. Tests that only replay removed pre-1.0 schema transitions or retired runtime paths do not belong to the 1.0 suite.
