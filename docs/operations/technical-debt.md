# Technical Debt

Version: 0.128.8

Active items only.

## Regression Baseline

- `baseline.dpd-shipment-preparation`: `tests/dpd/run-dpd-shipment-preparation-smoke.php` still fails because the DPD dry-run payload does not include `request.header.datePickup`.
- `baseline.cdek-pickup-points`: `tests/cdek/run-cdek-pickup-points-smoke.php` still fails because the checkout pickup map does not render every CDEK point returned by backend for large-city lists beyond the first 100 rows.

## Optional Environment Gaps

- `optional.russian-post-carrier`: `tests/carriers/run-russian-post-smoke.php` still requires a `WC_Shipping_Method` test stub.
- `optional.russian-post-domestic-carrier`: `tests/carriers/run-russian-post-domestic-smoke.php` still instantiates `NewShippingMethod` without initialized settings repository.

## Architectural Debt

- `ShipmentCreationService` has a registry plus adapter-array temporary test-construction fallback. New code should use `CarrierShipmentAdapterRegistry`; remove the fallback only after direct construction tests are migrated.
- Shipment cost analytics intentionally has no historical import/rebuild flow because the current deployment starts without old orders. Add an explicit migration/indexing plan only if a future production rollout needs existing orders indexed.

## Completed Architectural Migrations

- Shipment document UI now uses provider `actions()` in PHP, `documentActions` in JS state, and `document_actions` as the wire payload key.
- Adapter-level document action metadata was removed; document providers are the only canonical source for action availability and visibility.
- Historical stage/foundation/current-map docs were removed from the active docs tree and replaced by canonical documents.
