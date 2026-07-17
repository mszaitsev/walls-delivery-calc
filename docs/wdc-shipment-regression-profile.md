# Shipment Regression Profile

Version: 0.121.0.

Canonical command:

```text
php tests/shipments/run-shipment-regression-profile.php
```

The runner uses `tests/shipments/regression/shipment-regression-manifest.php` and executes every smoke as a separate PHP process via `PHP_BINARY`. This keeps globals, stubs and function/class declarations isolated between tests.

Default mode collects the full mandatory report. It does not stop at the first failure unless `--fail-fast` is passed.

CLI:

```text
--list
--group=framework
--group=cdek
--group=dpd
--group=russian-post
--group=yandex
--group=status-core
--include-baseline
--include-optional
--fail-fast
```

Exit codes:

```text
0 mandatory profile passed
1 one or more required tests failed, or an included baseline mismatched
2 invalid CLI/manifest/configuration
3 process infrastructure failure or timeout
```

`INFRASTRUCTURE` is separate from `FAIL`. It is used only when the runner cannot create temporary output files or cannot start the child PHP process. This classification happens before baseline matching, so a baseline or optional exact-signature entry that cannot start is reported as `INFRASTRUCTURE`, not `BASELINE-MISMATCH`. A child smoke that exits with code `3` normally is still a regular `FAIL`; exit code `3` becomes infrastructure only when the process result carries the explicit infrastructure flag.

Mandatory groups:

- `framework`: lifecycle contract, admin AJAX ownership, JS structure, modal extensions, document actions, persistence mappers, actual-cost presentation, allocation, status and adapter registry.
- `russian-post`: shipment create/status, price, cancel, documents and pickup import smokes.
- `cdek`: foundation, order creation including barcode/documents, tariff calculation and tariff sync.
- `dpd`: foundation, create order, lifecycle, buttons, status mapping, documents, event sync and status autosync.
- `yandex`: all stable `tests/yandex-delivery/*.php` smokes, listed individually to avoid wrapper duplication.
- `status-core`: order status mapping, status autosync, Packaging, Checkout, WooCommerce checkout, checkout location picker, runtime stabilization and order delivery recalculation.

Baseline entries are not run by default. With `--include-baseline`, a baseline counts as `BASELINE` only when the process exits non-zero and output contains the exact configured signature. A different failure is `BASELINE-MISMATCH` and returns a failure exit code. If a known baseline unexpectedly passes, the runner prints `KNOWN BASELINE NOW PASSES - remove/update baseline entry`.

Optional entries are not run by default. With `--include-optional`, optional failures are real failures unless the entry carries an exact expected harness signature. The Russian Post extended carrier harnesses are optional exact-signature entries because clean `develop` currently fails with `Class "WC_Shipping_Method" not found` and `Typed property WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod::$settings_repository must not be accessed before initialization`.

Timeouts are configured per manifest entry, defaulting to 90 seconds. On timeout the runner terminates the child process and reports `TIMEOUT`. The runner terminates the direct child PHP process; it does not promise process-tree cleanup for external children a smoke might spawn.

Skipped counts are scoped to the selected group. The default run reports skipped baseline/optional entries from the whole profile; `--group=dpd` reports `Skipped: 0`; `--group=baseline` without `--include-baseline` reports only baseline entries in the baseline group as skipped.

When adding a smoke:

1. Add one canonical manifest entry with a stable id.
2. Use a relative `tests/*.php` path only.
3. Assign one or more known groups.
4. Keep default mandatory entries deterministic.
5. Add baseline signatures only after reproducing the exact failure on clean `develop`.
