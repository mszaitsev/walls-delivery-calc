# Testing And Regression

Version: 0.122.0

## Commands

```bash
php tests/shipments/run-shipment-regression-profile.php --list
php tests/shipments/run-shipment-regression-profile.php --group=framework
php tests/shipments/run-shipment-regression-profile.php --group=cdek
php tests/shipments/run-shipment-regression-profile.php --group=dpd
php tests/shipments/run-shipment-regression-profile.php --group=russian-post
php tests/shipments/run-shipment-regression-profile.php --group=yandex
php tests/shipments/run-shipment-regression-profile.php --group=status-core
php tests/shipments/run-shipment-regression-profile.php
```

Also run `php -l` for changed PHP files, `node --check` for changed JS files, docs link checks, and `git diff --check`.

## Manifest

The manifest is `tests/shipments/regression/shipment-regression-manifest.php`. All shipment and carrier smoke tests that protect framework contracts should appear there.

## Coverage Matrix

| Capability | Framework | CDEK | DPD | Russian Post | Yandex | Covered |
| --- | --- | --- | --- | --- | --- | --- |
| create | yes | yes | yes | yes | yes | yes |
| preview | yes | yes | yes | yes | yes | yes |
| persistence | yes | yes | yes | yes | yes | yes |
| status update | yes | yes | yes | yes | yes | yes |
| autosync/polling | yes | yes | yes | yes | yes | yes |
| lifecycle continuation | yes | carrier-specific | yes | optional | yes | yes |
| cancel/remove | yes | yes | yes | yes | yes | yes |
| manual attach | yes | yes | yes | yes | yes | yes |
| documents | yes | yes | yes | yes | yes | yes |
| tracking presentation | yes | yes | yes | yes | yes | yes |
| actual cost | yes | yes | yes | yes | yes | yes |
| modal | yes | yes | yes | yes | yes | yes |
| AJAX wiring | yes | yes | yes | yes | yes | yes |
| JS structure | yes | yes | yes | yes | yes | yes |
| pickup/courier | shared helpers | baseline gap for pickup list | yes | yes | yes | partial |
| allocation | yes | yes | yes | yes | yes | yes |
| source station/dropoff | yes | no | yes | no | yes | yes |
| error paths | yes | yes | yes | yes | yes | yes |

Current baseline/optional allowances are listed in [technical-debt.md](../operations/technical-debt.md).
