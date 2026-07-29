# Testing And Regression

Version: 0.129.11

Jet Logistic critical coverage is registered as the mandatory `jet-logistic` group. It covers API envelope handling, token redaction, CSV/geography basics, one-call two-rate quoting, discounted package goods cost and `D_SDOC`, terminal-city presentation, status mapping with compact events, manual attach, unsupported create, and local remove.

## Commands

```bash
php tests/shipments/run-shipment-regression-profile.php --list
php tests/shipments/run-shipment-regression-profile.php --group=framework
php tests/architecture/run-plugin-architecture-smoke.php
php tests/shipments/run-shipment-regression-profile.php --group=cdek
php tests/shipments/run-shipment-regression-profile.php --group=dpd
php tests/shipments/run-shipment-regression-profile.php --group=russian-post
php tests/shipments/run-shipment-regression-profile.php --group=yandex
php tests/shipments/run-shipment-regression-profile.php --group=status-core
php tests/shipments/run-shipment-regression-profile.php
```

Also run `php -l` for changed PHP files, `node --check` for changed JS files, docs link checks, and `git diff --check`.

Checkout pickup map lifecycle changes should also run `node tests/checkout/run-pickup-map-lifecycle-smoke.js`. This smoke executes the frontend map controller with fake providers/API and protects one-shot auto-fit, user interaction cancellation, provider pending-fit cancellation, and coordinate validation.

Checkout city selector changes should also run `node tests/checkout/run-checkout-city-selector-smoke.js`. This smoke executes the frontend selector with a minimal DOM/timer harness and protects selected local-location field application, canonical hidden fields, country-change clearing, first-load and same-country no-op behavior, active shipping/billing scope handling, and the single `update_checkout` recalculation contract.

DPD Geography browser-import lifecycle changes should also run `node tests/dpd/run-dpd-geography-import-runner-smoke.js` in addition to `php tests/dpd/run-dpd-geography-import-smoke.php`. The Node smoke executes the admin runner with a fake DOM/fetch/timer harness and protects the read-only status endpoint contract, sequential step scheduling, network recovery through status, busy/stale step handling, legacy runner reset-required handling, monotonic cross-job `state_revision` rendering, foreign counter rendering, and the no-`setInterval` source guard. The PHP geography smoke owns the server lock/start/reset contract, including atomic compare-and-delete lease races, failed-artifact reset requirements, SFTP start continuation, legacy job refusal before reset, and RU DPD matcher regressions where own `fias_id` must not be hidden by another row's `city_fias_id`. `php tests/dpd/run-dpd-location-index-smoke.php` covers the separated own/city FIAS buckets, lazy own-FIAS disambiguation, true own-FIAS ambiguity, city-FIAS fallback, and the compact no-`location_meta` index contract.

## Manifest

The manifest is `tests/shipments/regression/shipment-regression-manifest.php`. Shipment and carrier smoke tests that protect framework contracts should appear there.

The plugin architecture smoke is `framework.plugin-architecture`; it is registered in the framework group and checks architecture boundaries rather than carrier business behavior. It favors dynamic Reflection-based checks where the contract is inspectable. Where runtime proof would require changing production construction, the smoke checks a narrower contract instead: registry duplicate behavior is tested with stubs, providers are discovered without uninitialized method calls, and composition-root ownership is checked for the current `Container::register()` wiring pattern.

Allowed matrix values:

- `contract`: shared framework contract smoke.
- `carrier smoke`: required carrier-specific smoke.
- `partial`: covered, but not all variants are represented.
- `baseline`: known failing baseline entry.
- `optional`: optional environment-dependent entry.
- `N/A`: capability does not apply.

## Coverage Matrix

| Capability | Framework | CDEK | DPD | Russian Post | Yandex |
| --- | --- | --- | --- | --- | --- |
| architecture invariants | `contract`: `framework.plugin-architecture` | `N/A` | `N/A` | `N/A` | `N/A` |
| create | `contract`: `framework.persistence-mappers` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| preview | `contract`: `framework.admin-ajax` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-payload` |
| persistence | `contract`: `framework.persistence-mappers` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| status update | `contract`: `framework.status` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.status-mapping` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| autosync/polling | `contract`: `framework.lifecycle-contract` | `partial`: `cdek.order-creation` | `carrier smoke`: `dpd.status-autosync` | `carrier smoke`: `status.status-autosync` | `carrier smoke`: `yandex.shipment-framework` |
| lifecycle continuation | `contract`: `framework.lifecycle-contract` | `partial`: `cdek.order-creation` | `carrier smoke`: `dpd.shipment-lifecycle` | `N/A` | `carrier smoke`: `yandex.shipment-framework` |
| cancel/remove | `contract`: `framework.admin-ajax` | `partial`: `cdek.order-creation` | `carrier smoke`: `dpd.shipment-buttons` | `carrier smoke`: `russian-post.cancel` | `carrier smoke`: `yandex.shipment-framework` |
| manual attach | `contract`: `framework.admin-ajax` | `partial`: `cdek.order-creation` | `partial`: `dpd.shipment-buttons` | `carrier smoke`: `russian-post.cancel` | `carrier smoke`: `yandex.shipment-framework` |
| documents | `contract`: `framework.document-actions` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.documents` | `carrier smoke`: `russian-post.documents` | `carrier smoke`: `yandex.shipment-framework` |
| tracking presentation | `contract`: `framework.status` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.status-mapping` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| actual cost | `contract`: `framework.actual-cost-presentation` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.event-sync` | `carrier smoke`: `russian-post.price` | `carrier smoke`: `yandex.shipment-framework` |
| modal | `contract`: `framework.modal-extensions` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| AJAX wiring | `contract`: `framework.admin-ajax` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.cancel` | `carrier smoke`: `yandex.shipment-framework` |
| JS structure | `contract`: `framework.admin-js-structure` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `partial`: `russian-post.cancel` | `carrier smoke`: `yandex.shipment-framework` |
| pickup/courier | `partial`: `core.checkout-location-picker` | `baseline`: `baseline.cdek-pickup-points` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.pickup-import` | `carrier smoke`: `yandex.pickup-selection` |
| allocation | `contract`: `framework.allocation` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |
| source station/dropoff | `contract`: `framework.admin-ajax` | `N/A` | `partial`: `dpd.create-order` | `N/A` | `carrier smoke`: `yandex.source-station` |
| error paths | `contract`: `framework.admin-ajax` | `carrier smoke`: `cdek.order-creation` | `carrier smoke`: `dpd.create-order` | `carrier smoke`: `russian-post.shipments` | `carrier smoke`: `yandex.shipment-framework` |

## Baseline And Optional

Current baseline/optional allowances are active, not historical:

- `baseline.dpd-shipment-preparation`
- `baseline.cdek-pickup-points`
- `optional.russian-post-carrier`
- `optional.russian-post-domestic-carrier`

Reasons are tracked in [technical-debt.md](../operations/technical-debt.md).
