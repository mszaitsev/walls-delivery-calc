# New Carrier Guide

Version: 0.133.7

For carriers like Jet Logistic that return multiple delivery options from one quote call, prefer a carrier-agnostic capability path: a carrier whose capabilities include pickup and courier can return multiple `DeliveryRate` objects from one `quote()` call. A pickup `DeliveryRate` may set `requires_pickup_point=false` when the carrier has no selectable pickup-point identifiers.

Use `ExampleCarrier` as a mental model only; do not add it to production. This guide is implementable: follow it in order and add only capabilities the carrier actually supports.

## Canonical Values

- Carrier key: lowercase `sanitize_key()`-safe string, for example `example`.
- Delivery type: existing `DeliveryType` values.
- Document payload key: `document_actions`.
- JS state field: `documentActions`.
- Actual shipment cost owner: `actual_cost_kopecks`.
- Shipment status: map external statuses into `DeliveryStatus`.
- DI registration: `src/Core/Plugin.php` only.

## 1. Carrier Key And Settings

Mandatory. Define stable settings and credentials classes under `src/Carriers/Example`.

```php
final class ExampleSettings {
	public const CARRIER_KEY = 'example';

	public function enabled(): bool {}
	public function request_timeout(): int {}
}
```

Typical mistakes: changing the key later, using display names as keys, reading raw options outside settings, or storing credentials unencrypted.

## 2. API Client

Mandatory for API-backed carriers. Keep transport and response parsing out of shipment adapters.

```php
final class ExampleApiClient {
	public function create_order( array $payload ): array {}
	public function get_status( string $external_id ): array {}
	public function download_label( string $external_id ): string {}
}
```

Required: timeout, safe exception boundary, credential redaction in logs. Optional: separate HTTP client interface if the carrier needs isolated transport tests.

## 3. Quote/Tariff Integration

Mandatory if checkout rates are shown. Implement a runtime carrier registered in `CarrierRegistry`.

Responsibility: convert `QuoteRequest` into `DeliveryQuote`, including source API price, customer price, delivery type, delivery days/date, and diagnostics. Do not create shipments here.

Carriers must return the raw carrier lead time as structured `DateRange` data. Do not add shop processing days, do not convert carrier working days with calendars inside a carrier, and do not bake the lead time into the title. The shared checkout pipeline applies `shop_processing_working_days` with `CalendarTypes::SHOP`, optionally converts service lead time with `CalendarTypes::CARRIER_RU` when `delivery_days_are_working` is enabled, then runs rules and formats the final title/comment.

Rule simulation support is required for API-backed carriers. Reuse the production quote path from simulation input to canonical `QuoteRequest`; the rules page must not maintain a separate carrier request builder or carrier-specific UI branch.

## 4. Pickup/Courier Support

Optional per carrier. Pickup imports/repositories belong under carrier or pickup namespaces. Shared checkout UI should receive normalized pickup point data. If pickup selection changes price, make checkout recalculate only when the customer-visible price can change.

For new credentialed pickup providers, use `CarrierPickupPointProviderInterface` and `CarrierPickupPointProviderRegistry` only when the carrier has enough trusted cargo/location context to search safely. The provider query uses canonical project units through `PickupCargoConstraints`, validates complete coordinate pairs, and may be fallback-only for carriers that can safely support that mode. Carrier-specific services convert those units into API units. `resolve_selection()` must perform fresh server-side validation and must not trust repository/cache rows as selection authority. Public REST integration is a separate checkout-stage decision, not automatic registry wiring.

PEK is the first provider on this contract. In version 0.133.7 the same provider supports admin destination diagnostics and checkout pickup map/search/save. Checkout REST never trusts browser cargo/location authority for PEK: the browser supplies nonce, shipping method id, pickup family, and point code, while `CheckoutPickupPointProviderQueryResolver` rebuilds the provider query from server-stored `rate_meta['pickup_provider_query']` metadata on the WooCommerce session rate. Top-level session rate fields own carrier/service/family/delivery-type checks; nested `rate_meta` owns carrier-safe provider context and must carry a non-empty destination fingerprint. PEK snapshots may carry bounded coordinates or address-only `null/null` coordinates; full address stays out of session and is resolved again from canonical `location_id`. Registry-backed selections should preserve provider-specific destination binding in a separate `provider_destination_fingerprint` and leave the generic checkout `destination_fingerprint` for broad location lifecycle checks. PEK resolves terminal searches from carrier-owned mappings, treats partial/invalid canonical coordinates as address fallback, always sends a non-empty address to `/branches/nearestdepartments/`, adds decimal-string coordinates when available, fingerprints the full outgoing terminal payload, validates typed zone and `/branches/nearestdepartments/` collections before use, validates terminal-cache payloads through format `2` safe projection, fresh-validates checkout selections through `resolve_selection()`, and presents own/partner points with public titles instead of internal warehouse UUIDs. Shipment Framework integration remains intentionally absent.

PEK runtime reuses the quote foundation instead of rebuilding calculator payloads in checkout. `PekQuoteService` accepts canonical `QuoteRequest` plus PEK-specific `PekQuoteOptions` and returns `PekQuoteResult`; `PekCarrier` converts final adjusted pickup/courier results into `DeliveryRate` objects. The quote parser owns calculator-specific root errors, carries safe response metadata into successful and failed results, requires transfer `hasError` as a Boolean, requires service text fields as strings, and preserves Boolean `insuranceTerm` in service diagnostics. `PekQuoteCargoBuilder` always sends `isHP=false` and `sealingPositionsCount=0`; `Package::total_weight_g` remains the transport calculator weight. `PekLightCargoSurchargePolicy` applies store-owned configurable bag/plombing surcharges after parsing carrier `costTotal`, using product weight before store packaging (`Package::weight_g`) and the strict configured limit. Sensitive carrier-provided messages and arbitrary field-error names are redacted by the quote service boundary with `PekQuoteMessageSanitizer`; canonical field paths remain visible, raw/original field names are not stored, and logs keep only sanitized field names/counts.

## 5. Shipment Adapter

Mandatory for shipment creation.

```php
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

final class ExampleShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function carrier_key(): string {
		return ExampleSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return ExampleSettings::CARRIER_KEY === $request->carrier_key;
	}

	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		return array(
			'method' => 'POST',
			'path' => '/orders',
			'body' => array(),
			'errors' => array(),
		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return new ShipmentCreateResult( true, external_id: 'EX-1', tracking_number: 'TRACK-1' );
	}

	public function presentation(): array {
		return array(
			'label' => 'Example',
			'tracking_label' => 'Tracking',
		);
	}

	public function status_payload( object $order, array $shipment ): array {
		return array(
			'carrier_key' => $this->carrier_key(),
			'has_shipment' => array() !== $shipment,
			'can_create' => array() === $shipment,
			'can_update_status' => array() !== $shipment,
			'can_cancel' => false,
			'can_remove_from_order' => array() !== $shipment,
			'tracking_presentation' => $this->tracking_presentation( $shipment ),
		);
	}

	public function update_status( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false, 'message' => 'Status update is not supported for this carrier.' );
	}

	public function attach_manual( object $order, array $payload ): array {
		return array( 'success' => false, 'message' => 'Manual attach is not supported for this carrier.' );
	}

	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false, 'message' => 'Carrier cancellation is not supported for this carrier.' );
	}

	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false, 'message' => 'Local removal is not supported for this carrier.' );
	}

	public function supports_status_auto_sync(): bool { return false; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return 0; }

	private function tracking_presentation( array $shipment ): array {
		$value = (string) ( $shipment['tracking_number'] ?? '' );
		return array(
			'label' => 'Tracking',
			'display_text' => $value,
			'url' => '',
			'copy_value' => $value,
		);
	}
}
```

Required: all interface methods. Supported capability is separate from interface implementation: lifecycle/cancel/manual attach methods always exist, but a carrier may return a public-safe unsupported response when the feature is not available. Document actions are not adapter methods; implement a document provider only when the carrier exposes downloadable artifacts. Typical mistakes: persisting inside the adapter, doing document download inside the adapter, leaking raw API errors, or adding carrier branches to generic JS.

Actual shipment cost extraction is optional, but when a carrier can return the real shipment cost immediately or during a later status/reconciliation update, convert it into a common actual-cost candidate and pass it to `ShipmentActualCostService`. The service writes integer `actual_cost_kopecks`, `actual_cost_currency=RUB`, `actual_cost_source`, optional `actual_cost_source_detail`, and `actual_cost_updated_at`; strictly positive carrier values overwrite any previous source, while missing/null/zero values leave the stored cost unchanged. Do not introduce `example_actual_cost_*` fields or implement a carrier-local overwrite policy. Carriers with no actual-cost API still get the shared manual fallback in the shipment card.

## 6. ShipmentCreateResult

Mandatory result object for create.

```php
return new ShipmentCreateResult(
	true,
	external_id: (string) $response['id'],
	tracking_number: (string) $response['tracking_number'],
	raw_reference: array( 'response' => $safe_response )
);

return new ShipmentCreateResult(
	false,
	error_code: 'example_api_error',
	error_message: 'Example carrier rejected the shipment.',
	raw_reference: array( 'response' => $safe_response )
);
```

Canonical: failed results need `error_code` or `error_message`. Keep admin messages public-safe.

## 7. Persistence Mapper

Mandatory.

```php
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

final class ExampleShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string {
		return ExampleSettings::CARRIER_KEY;
	}

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		return array(
			'example_external_id' => $result->external_id,
			'tracking_number' => $result->tracking_number,
			'universal_status_code' => 'created_in_carrier',
			'request_snapshot' => $preview,
			'response_snapshot' => $result->raw_reference,
		);
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		return null;
	}

	public function after_persist( object $order, array $shipment ): void {}
}
```

Required: carrier key, created fields, failed fields, after-persist hook. Optional: failed shipment persistence if the carrier has useful pending/uncertain states.

## 8. Status Mapping And Status Payload

Mandatory when the carrier has external statuses.

Map carrier statuses to `DeliveryStatus` and expose normalized status payload fields:

```php
return array(
	'carrier_key' => ExampleSettings::CARRIER_KEY,
	'has_shipment' => true,
	'status' => (string) ( $shipment['status'] ?? '' ),
	'status_title' => (string) ( $shipment['status_title'] ?? '' ),
	'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
	'tracking_presentation' => array(
		'label' => 'Tracking',
		'display_text' => $tracking,
		'url' => $tracking_url,
		'copy_value' => $tracking,
	),
);
```

Typical mistakes: using carrier status as universal status, omitting `carrier_key`, or adding document action metadata to the adapter status payload. The shared metabox/payload builder adds `document_actions` from the provider.

## 9. Lifecycle Continuation

Optional. Use only when create/registration needs a second submit or polling step.

```php
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentLifecycleContinuationInterface;
use WallsShop\WDC\Shipments\Lifecycle\ShipmentLifecycleResult;

final class ExampleShipmentAdapter implements CarrierShipmentLifecycleContinuationInterface {
	public function continue_lifecycle( object $order, array $payload ): ShipmentLifecycleResult {
		return new ShipmentLifecycleResult(
			ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
			poll_required: true,
			continuation_token: (string) $payload['token'],
			message: 'Waiting for carrier registration.',
			purpose: 'registration'
		);
	}
}
```

Canonical phases: `completed`, `submission_required`, `polling_required`, `pending`, `failed`, `terminal`. `submit_required` requires `continuation_token`.

## 10. Cancellation And Removal

Mandatory to implement interface methods. Carrier API cancellation is optional. If unsupported, return a public-safe error for cancellation and allow safe local removal when business rules permit.

## 11. Manual Attach

Optional but recommended. Validate tracking/external IDs, build mapper-compatible fields, and reuse common presentation/document behavior.

## 12. Tracking Presentation

Mandatory for shipments with external tracking. Canonical shape:

```php
array(
	'label' => 'Tracking',
	'display_text' => $tracking,
	'url' => $tracking_url,
	'copy_value' => $tracking,
)
```

Use empty strings for absent optional values.

## 13. Actual Cost

Optional. Store shared actual-cost fields and use `ShipmentActualCostComparisonService` plus `ShipmentBaseApiCostResolver` for presentation.

## 14. Document Provider And Actions

Optional. Implement only when the carrier exposes downloadable artifacts.

- Provider `actions()` is the canonical source used by `ShipmentAdminCarrierUiPayloadBuilder` and `OrderShipmentsMetabox` for UI `document_actions` payload, visibility, action keys, labels, types, and metadata.
- `ShipmentAdminCarrierUiPayloadBuilder` and `OrderShipmentsMetabox` normalize visible actions and add protected `download_url`.
- `ShipmentDocumentDownloadService` owns `download_url`, capability/nonce/order/action checks, and the final "is this action still visible?" authorization re-check.
- Provider `download()` owns binary bytes.
- Adapters do not expose document action metadata.

```php
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;

final class ExampleShipmentDocumentProvider implements CarrierShipmentDocumentProviderInterface {
	public function carrier_key(): string {
		return ExampleSettings::CARRIER_KEY;
	}

	public function actions( object $order, array $shipment ): array {
		return array( new ShipmentDocumentAction( 'download_label', 'Download label' ) );
	}

	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		return new ShipmentBinaryDocument( $bytes, 'application/pdf', 'example-label.pdf' );
	}
}
```

Typical mistakes: putting `download_url` in provider action data, returning old payload aliases, duplicating document metadata in adapters/status services, bypassing `ShipmentDocumentDownloadService`, or allowing direct downloads for hidden actions.

## 15. Modal Extension

Optional. Implement `CarrierShipmentModalExtensionInterface` when the modal needs carrier-only fields. Generic modal rendering must remain shared.

## 16. Admin AJAX

Use existing controllers for create, preview, lifecycle, manual attach, cancel/remove, status, products, addresses, and documents. New carrier-specific AJAX is a last resort and must not duplicate nonce/capability/order resolution logic.

## 17. JS Extension

Optional. Add `assets/admin/shipments/extensions/example.js` for carrier-only UI. Generic modules dispatch hooks; carrier extensions respond to them. Do not add carrier selectors or branches to generic modules.

## 18. DI And Registry Registration

Register in `Plugin.php`.

```php
$this->container->register( ExampleSettings::class, fn(): ExampleSettings => new ExampleSettings( $this->container->get( SettingsRepository::class ) ) );
$this->container->register( ExampleApiClient::class, fn(): ExampleApiClient => new ExampleApiClient( $this->container->get( ExampleSettings::class ), $this->container->get( Logger::class ) ) );
$this->container->register( ExampleShipmentAdapter::class, fn(): ExampleShipmentAdapter => new ExampleShipmentAdapter( $this->container->get( ExampleApiClient::class ) ) );
$this->container->register( ExampleShipmentPersistenceMapper::class, fn(): ExampleShipmentPersistenceMapper => new ExampleShipmentPersistenceMapper() );
$this->container->register( ExampleShipmentDocumentProvider::class, fn(): ExampleShipmentDocumentProvider => new ExampleShipmentDocumentProvider( $this->container->get( ExampleApiClient::class ) ) );
```

Registry additions:

```php
new CarrierShipmentAdapterRegistry( array(
	$this->container->get( RussianPostShipmentAdapter::class ),
	$this->container->get( CdekShipmentAdapter::class ),
	$this->container->get( DpdShipmentAdapter::class ),
	$this->container->get( YandexShipmentAdapter::class ),
	$this->container->get( ExampleShipmentAdapter::class ),
) );

new ShipmentDocumentProviderRegistry( array(
	$this->container->get( CdekShipmentDocumentProvider::class ),
	$this->container->get( DpdShipmentDocumentProvider::class ),
	$this->container->get( YandexShipmentDocumentProvider::class ),
	$this->container->get( RussianPostShipmentDocumentProvider::class ),
	$this->container->get( ExampleShipmentDocumentProvider::class ),
) );
```

Also add the mapper to the `ShipmentCreationService` mapper array. If checkout quotes exist, register the runtime carrier in `CarrierRegistry`.

## 19. Smoke Tests

Add focused smoke tests:

- adapter supports/preview/create/status payload;
- persistence mapper created/failed fields;
- registry registration;
- document provider and protected download;
- modal extension if present;
- JS structure if extension exists;
- carrier create/status/cancel/manual attach paths as applicable.

## 20. Regression Manifest

Register critical smokes in `tests/shipments/regression/shipment-regression-manifest.php`.

```php
'example.shipment-framework' => array(
	'path' => 'tests/example/run-example-shipment-framework-smoke.php',
	'groups' => array( 'example' ),
),
```

Required tests should be mandatory. Use `baseline` or `optional` only with a current, documented reason.

## 21. Documentation

Update this guide only when the carrier onboarding process changes. Put carrier-specific stable behavior in subsystem docs. Do not create stage notes.

## 22. Final Readiness Checklist

- Carrier key is stable and sanitize-safe.
- Settings and credentials are registered.
- API client has timeout and redacted logging.
- Quote path works or is intentionally absent.
- Adapter implements every interface method.
- Adapter public API remains limited to implemented contracts and generic guarded extension points.
- Mapper is registered in `ShipmentCreationService`.
- Status payload uses canonical fields.
- Tracking presentation uses canonical shape.
- Document buttons use `document_actions`; download uses provider/service.
- Modal and JS extensions are registered only if needed.
- Generic services and JS contain no new carrier switch.
- Regression manifest contains critical smokes.
- Required groups pass.
