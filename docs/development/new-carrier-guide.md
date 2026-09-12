# New Carrier Guide

Version: 1.0.12

Add a carrier only for a real integration with its own API, credentials, geography, quoting, pickup data, or shipment lifecycle. Administrator-defined flat-price services belong to the existing `manual` carrier.

## Stable identity and settings

Choose one lowercase `sanitize_key()`-safe carrier key and keep it stable. Read options only through a carrier settings object. Encrypt secrets through the existing credential boundary and never expose them in logs, previews, rate metadata, or browser responses.

## API boundary

Keep HTTP transport and response parsing under `src/Carriers/<Carrier>/`. Define timeouts, classify transport/API/parse/contract failures, redact carrier messages, and test with fake HTTP. Do not let checkout or shipment adapters parse raw responses.

## Checkout quotes

Implement the runtime carrier contract and register it in `CarrierRegistry`. Convert `QuoteRequest` into canonical `DeliveryQuote`/`DeliveryRate` values with stable raw rate IDs, structured delivery periods, source price, final price, and generic metadata. Do not add shop processing days or create shipments in quote code.

Expected no-tariff or unsupported-mode results are normal empty results. Technical failures may be warnings; successful fallback details are debug-only when the carrier already has an explicit debug setting. Follow [the logging policy](../operations/logging-policy.md).

Pickup rates expose canonical `carrier_key`, `service_key`, `delivery_type=pickup`, and `requires_pickup_point`. Use the shared pickup provider registry and `CheckoutLocationFingerprint`. Browser input is never authority for cargo, location, family, address, or price; rebuild provider queries from trusted server-side rate metadata and revalidate selected points server-side.

## Shipment Framework

Put shipment implementations under `src/Shipments/<Carrier>/` and implement only supported capabilities:

- preview/create through a carrier adapter;
- persistence through the carrier mapper and generic order repository;
- external status mapping to `DeliveryStatus`;
- cancellation, documents, tracking, actual cost, returns, and lifecycle continuation only when the API contract supports them.

Use framework creation attempts and locks. Do not add carrier-specific order-meta lifecycle state or hidden service construction. Preview output must be redacted, and mutation requests must validate current server-owned destination/account identity immediately before submission.

## Registration and tests

Register services and hooks only in `src/Core/Plugin.php`. Duplicate registry keys must fail closed. Add carrier smoke coverage to `tests/shipments/regression/shipment-regression-manifest.php` for each supported capability and error path, plus checkout, persistence, status, document, cancellation, and JavaScript coverage where applicable.

Before considering the carrier complete, run its focused smokes, the architecture smoke, and the full shipment regression profile. Update current subsystem documentation without adding a new version-by-version narrative.
