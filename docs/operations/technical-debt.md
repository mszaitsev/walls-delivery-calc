# Technical Debt

Version: 0.122.0

Active items only.

## Regression Baseline

- `baseline.dpd-shipment-preparation`: `tests/dpd/run-dpd-shipment-preparation-smoke.php` still fails because the DPD dry-run payload does not include `request.header.datePickup`.
- `baseline.cdek-pickup-points`: `tests/cdek/run-cdek-pickup-points-smoke.php` still fails because the checkout pickup map does not render every CDEK point returned by backend for large-city lists beyond the first 100 rows.

## Optional Environment Gaps

- `optional.russian-post-carrier`: `tests/carriers/run-russian-post-smoke.php` still requires a `WC_Shipping_Method` test stub.
- `optional.russian-post-domestic-carrier`: `tests/carriers/run-russian-post-domestic-smoke.php` still instantiates `NewShippingMethod` without initialized settings repository.

## Architectural Debt

- `ShipmentCreationService` has a registry plus adapter-array fallback. New code should use `CarrierShipmentAdapterRegistry`; remove the fallback only after direct construction tests are migrated.

## Completed Architectural Migrations

- Shipment document UI now uses `document_actions()` in PHP, `documentActions` in JS state, and `document_actions` as the wire payload key.
- Historical stage/foundation/current-map docs were removed from the active docs tree and replaced by canonical documents.
