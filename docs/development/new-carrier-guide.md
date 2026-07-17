# New Carrier Guide

Version: 0.122.0

Use `ExampleCarrier` as a mental model only; do not add it to production.

## 1. Carrier Key And Settings

Mandatory. Define a stable carrier key and settings class.

```php
final class ExampleSettings {
	public const CARRIER_KEY = 'example';
}
```

Typical mistakes: changing the key after data exists, reading raw options outside settings, and using a display name as the key.

## 2. API Client

Mandatory for API-backed carriers. Keep transport, timeout, credential, and response parsing concerns out of adapters.

```php
final class ExampleApiClient {
	public function create_order( array $payload ): array {}
}
```

Typical mistakes: returning raw exceptions to admin users, missing timeouts, and logging credentials.

## 3. Quote/Tariff Integration

Mandatory if checkout rates are provided. Implement a runtime carrier and register it in `CarrierRegistry`.

Expected responsibility: convert `QuoteRequest` into `DeliveryQuote`, including diagnostics and fallback reasons when applicable.

## 4. Pickup/Courier Support

Optional per carrier. Put pickup repositories/importers under carrier or pickup namespaces. Shared checkout rendering should consume normalized pickup data.

## 5. Shipment Adapter

Mandatory for shipment creation.

```php
final class ExampleShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function carrier_key(): string { return ExampleSettings::CARRIER_KEY; }
	public function supports( ShipmentCreateRequest $request ): bool { return $request->carrier_key === self::carrier_key(); }
}
```

The adapter owns carrier create/preview/status capabilities, not persistence or document download.

## 6. Creation Result

Return `ShipmentCreateResult`. Include public-safe `error_code` and `error_message`; keep raw carrier payloads in `raw_reference`.

## 7. Persistence Mapper

Mandatory.

```php
final class ExampleShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string { return ExampleSettings::CARRIER_KEY; }
}
```

The mapper converts create results into stored shipment fields. Do not add carrier fields directly in `ShipmentCreationService`.

## 8. Status Mapping

Mandatory when the carrier has external statuses. Map carrier statuses into `DeliveryStatus` and order status policy through the appropriate service.

## 9. Lifecycle

Optional. Implement `CarrierShipmentLifecycleContinuationInterface` when a carrier requires a second step after preview/create. Use `ShipmentLifecycleResult`.

## 10. Cancellation/Removal

Mandatory if carrier API supports cancellation; otherwise expose safe "remove from order" behavior only. Keep carrier cancellation in the adapter/service.

## 11. Manual Attach

Optional but recommended. Implement adapter manual attach validation and persistence-compatible fields.

## 12. Tracking Presentation

Mandatory for shipments with external tracking. Return normalized tracking presentation in status payload.

## 13. Actual Cost

Optional. Populate shared actual-cost fields and use `ShipmentActualCostComparisonService`/`ShipmentBaseApiCostResolver` presentation.

## 14. Document Provider

Optional. Implement `CarrierShipmentDocumentProviderInterface` and register it in `ShipmentDocumentProviderRegistry`. Adapters expose buttons through `document_actions()`, while download bytes come from the provider.

## 15. Modal Extension

Optional. Implement `CarrierShipmentModalExtensionInterface` when the modal needs carrier-only fields. Register it in `ShipmentModalExtensionRegistry`.

## 16. Admin AJAX Requirements

Use existing controllers. Do not create carrier-only endpoints when shared create, preview, lifecycle, manual attach, cancel/remove, document, product, address, or status endpoints fit.

## 17. JS Extension

Optional. Add `assets/admin/shipments/extensions/example.js` for carrier-only UI. Use generic events/hooks; do not add carrier branches to generic modules.

## 18. Registry And DI Registration

Register in `Plugin.php`:

- settings/API client;
- quote runtime carrier;
- shipment adapter;
- persistence mapper in `ShipmentCreationService`;
- document provider registry entry;
- modal extension registry entry;
- carrier JS enqueue if needed.

## 19. Tests

Add focused smoke tests for adapter support, creation, mapper persistence, document actions, modal extension, status mapping, AJAX wiring, and JS structure.

## 20. Regression Manifest

Add tests to `tests/shipments/regression/shipment-regression-manifest.php` under a carrier group. Critical contract tests should be required.

## 21. Documentation

Update this guide only when the process changes. Add carrier-specific operational details to a subsystem doc only if they are stable and useful.

## 22. Final Readiness Checklist

- Carrier key is stable.
- Settings and credentials are registered.
- API client has timeout and safe logging.
- Quote path works or is intentionally absent.
- Adapter, mapper, registries, and DI are wired.
- Documents and modal extensions are registered if supported.
- `document_actions` payload is used for UI document buttons.
- Generic services and JS contain no new carrier switch.
- Regression group passes.
