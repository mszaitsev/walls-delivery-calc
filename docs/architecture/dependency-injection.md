# Dependency Injection

Version: 0.128.21

`src/Core/Plugin.php` is the composition root. `src/Core/Container.php` is a small lazy singleton container with `register()`, `get()`, and `has()`.

## Rules

- Register global services only in `Plugin::register_services()`.
- Concrete carriers are wired only in `Plugin.php`.
- Do not instantiate registered services inside production services as a fallback.
- Do not make required collaborators nullable just to hide missing wiring.
- Avoid duplicate registrations; the last registration replaces the previous factory.
- Do not move wiring into repositories, metabox renderers, or adapters.

## Carrier Registration Checklist

For a carrier with shipment support, register:

- settings and credentials;
- API client and HTTP/SOAP client;
- quote runtime carrier in `CarrierRegistry`;
- shipment adapter in `CarrierShipmentAdapterRegistry`;
- persistence mapper in `ShipmentCreationService`;
- document provider in `ShipmentDocumentProviderRegistry`, if documents exist;
- modal extension in `ShipmentModalExtensionRegistry`, if UI fields are needed;
- lifecycle continuation implementation through the adapter, if the carrier requires continuation steps.

## Current Notes

`CheckoutFeatureGate` is wired in `Plugin.php` with `SettingsRepository` only. Runtime checkout components depend on the gate rather than reading `enable_new_checkout_shipping` directly.

`DeliveryLeadTimeNormalizer` is wired in `Plugin.php` and is the checkout/order-admin runtime component that reads delivery lead-time settings. Calendar arithmetic stays in `DeliveryDateCalculator`; carriers, order metaboxes, and WooCommerce renderers consume normalized `DeliveryRate` values instead of constructing planned dates themselves.

`ShipmentCreationService` receives both a registry and an adapter array. The registry is the canonical path. The adapter array remains a temporary test-construction fallback for direct construction tests; it is documented technical debt, not a production compatibility contract and not a pattern for new code.
