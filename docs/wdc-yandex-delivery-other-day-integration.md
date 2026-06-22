# WDC Yandex Delivery Other-Day Integration

Status: Phase 1 foundation/API/settings implemented in 0.72.0; pickup-point storage/import, checkout, order recalculation and shipments remain planned.

Date: 2026-06-22.

## 1. Scope

This document covers only Yandex Delivery API for `Доставка по России` / delivery in another day.

In scope:

- checkout quotation for Yandex Delivery across Russia;
- WooCommerce order-admin delivery recalculation through the same checkout runtime path;
- sender handover by self-delivery to a Yandex pickup/dropoff point;
- delivery to pickup point (`self_pickup`);
- courier delivery to recipient (`time_interval`);
- declared value;
- order item list;
- manual shipment preparation and manual shipment creation from the WooCommerce order `Отправления` block;
- shipment cancellation;
- shipment status polling and autosync through WDC shipment-status platform;
- labels and handover-act documents.

Out of scope:

- same-day delivery;
- express delivery;
- courier pickup from sender;
- warehouse handover flow and Yandex warehouse management as a business scenario;
- creating Yandex warehouse/pickup schedules from WDC;
- C2C flow;
- automatic shipment creation from checkout, hooks, cron or background jobs;
- COD / cash on delivery, unless a separate business decision adds it later;
- merchant-management UI, unless `merchant_id` becomes mandatory for the account.

## 2. Project documents reviewed

The architecture plan follows the current WDC platform documents and the completed DPD implementation pattern:

- `docs/development-workflow.md`
- `docs/project-status.md`
- `docs/wdc-current-code-map.md`
- `docs/wdc-core-platform.md`
- `docs/wdc-domain-model.md`
- `docs/wdc-shipments-foundation.md`
- `docs/wdc-shipment-statuses.md`
- `docs/wdc-order-delivery-recalculation.md`
- `docs/wdc-dpd-integration.md`
- `docs/wdc-dpd-checkout-runtime.md`
- `docs/wdc-dpd-checkout-pickup-selection.md`
- `docs/wdc-dpd-pickup-points.md`
- `docs/wdc-dpd-shipment-preparation.md`
- `docs/wdc-dpd-create-order.md`
- `docs/wdc-dpd-event-sync.md`
- `docs/wdc-dpd-status-mapping.md`
- `docs/wdc-dpd-autosync.md`
- `docs/wdc-dpd-documents.md`
- `docs/wdc-dpd-shipment-lifecycle.md`

DPD is used as the architectural reference, not as code to copy.

## 3. Yandex API sources reviewed

Primary Yandex documentation section:

- `https://yandex.ru/support/delivery-profile/ru/api/other-day/`

Relevant API areas:

- general introduction and environments;
- access/authentication;
- method list;
- pricing calculator;
- location detection;
- pickup points list;
- offers create;
- offers confirm;
- direct request create;
- request info;
- requests info;
- request history;
- request cancel;
- labels generation;
- handover act;
- status model;
- error reference.

The audited API section exposes polling/status-info methods. A webhook endpoint for the other-day API was not found in the checked method list.

## 4. API environment and credentials

### Hosts

Yandex documentation separates test and production environments:

- test host: `https://b2b.taxi.tst.yandex.net`
- production host: `https://b2b-authproxy.taxi.yandex.net`

The plugin must never hardcode production credentials. The public test token and test `platform_station_id` from Yandex docs may be used only for explicit test/probe tooling, not as hidden runtime defaults.

### Authentication

Yandex API uses Bearer authentication:

```text
Authorization: Bearer <OAuth-token>
```

Credential storage must reuse the existing WDC settings/encryption approach.

Recommended settings keys:

```text
yandex_delivery_environment
yandex_delivery_test_token_encrypted
yandex_delivery_production_token_encrypted
yandex_delivery_test_source_platform_station_id
yandex_delivery_production_source_platform_station_id
yandex_delivery_request_timeout
yandex_delivery_debug
yandex_delivery_autosync_enabled
yandex_delivery_last_sync_result
yandex_delivery_last_sync_at
yandex_delivery_pickup_brand_filter
yandex_delivery_default_payment_method
yandex_delivery_default_vat_code
yandex_delivery_default_item_country_code
yandex_delivery_default_source_ready_time
yandex_delivery_default_source_ready_offset_days
```

Optional account/business settings:

```text
yandex_delivery_merchant_id
yandex_delivery_use_handover_act
yandex_delivery_document_language
yandex_delivery_label_generate_type
```

`platform_station_id` is critical for the sender side. In this project scope it represents the Yandex point where the merchant self-delivers parcels. The configured point must be validated against `pickup-points/list` with `available_for_dropoff=true` before live creation is enabled.

## 5. API operation audit

### 5.1 Tariff calculation

Endpoint:

```text
POST /api/b2b/platform/pricing-calculator
```

Purpose: preliminary delivery price and delivery-days calculation.

Relevant request fields:

- source `platform_station_id`;
- destination address or destination `platform_station_id`;
- tariff:
  - `time_interval` for courier delivery;
  - `self_pickup` for delivery to pickup point;
- `total_weight`;
- `total_assessed_price` in kopecks;
- `client_price` in kopecks;
- `payment_method`;
- `places[]` with dimensions in centimeters and gross weight in grams.

WDC usage:

- checkout pricing;
- order-admin recalculation preview;
- no reservation, no Yandex order creation side effect;
- result is quote data only.

Important boundary: `pricing-calculator` should not be treated as final shipment creation. At shipment creation time WDC should request fresh offers and confirm a selected offer.

### 5.2 Pickup/dropoff points and locations

Endpoints:

```text
POST /api/b2b/platform/location/detect
POST /api/b2b/platform/pickup-points/list
```

`location/detect` returns `geo_id` for a location/address fragment.

`pickup-points/list` returns self-delivery, pickup and postamat points. The response includes point identifiers, type, operator, address, coordinates, schedule, supported payment methods and flags such as `available_for_dropoff`.

WDC usage:

- import/cache destination pickup points for checkout and order recalculation;
- find and validate sender dropoff points for merchant handover;
- expose points through the existing pickup REST/map architecture;
- store Yandex-specific point id aliases while preserving common WDC pickup meta.

Initial filters:

- destination pickup delivery: `type=pickup_point`;
- source sender dropoff: `available_for_dropoff=true` and initially `type=pickup_point`;
- postamats/terminals are not included in the first scope unless explicitly enabled later;
- Yandex-branded-only filtering should be a setting, because the API may return partner points that are valid for delivery.

### 5.3 Order creation

Primary endpoints:

```text
POST /api/b2b/platform/offers/create
POST /api/b2b/platform/offers/confirm
```

`offers/create` returns available delivery offers for a passed order payload. `offers/confirm` books the selected offer and returns the Yandex `request_id`.

Alternative endpoint:

```text
POST /api/b2b/platform/request/create
```

`request/create` creates an order for the nearest available time. It should not be the primary WDC flow because WDC needs a visible preview/selection boundary and should not bypass offer selection.

Recommended WDC creation flow:

1. Manager opens `Отправления` for a WooCommerce order.
2. `YandexDeliveryShipmentPayloadBuilder` builds a safe preview payload.
3. WDC validates local data without calling live creation.
4. On explicit create click, WDC saves a local pending shipment before external calls.
5. WDC calls `offers/create` with the exact order, places, items, declared value, source point and destination.
6. WDC selects the matching offer for the shipment delivery type and chosen pickup/courier interval.
7. WDC calls `offers/confirm` with `offer_id`.
8. WDC stores `request_id`, `operator_request_id`, selected offer snapshot, Yandex request state and sanitized payload snapshots.
9. WDC immediately runs the first status refresh through the same service used by manual refresh/autosync.

Idempotency:

- `operator_request_id` must be unique per Yandex shipment attempt;
- store it before external API submission;
- if a retry hits a duplicate external order condition, try to resolve by `request_code`/`operator_request_id` through `request/info` before allowing a new attempt;
- cancelled/deleted local shipments must not reuse the same external request code unless intentionally re-attaching.

### 5.4 Payload shape for orders

Yandex creation payload must be built from existing WDC order/shipment data, not from DPD/CDEK-specific meta.

Required WDC sources:

- WooCommerce order id and order number;
- recipient name, phone, email;
- delivery type: `pickup` or `courier`;
- selected Yandex pickup point for pickup delivery;
- normalized/usable courier address for courier delivery;
- shipment places from the shipment modal or package builder;
- WooCommerce order item list;
- declared value from item/order value;
- payment method.

Delivery mode mapping:

```text
WDC pickup  -> Yandex last_mile_policy=self_pickup, destination.type=platform_station
WDC courier -> Yandex last_mile_policy=time_interval, destination.type=custom_location
```

Source mapping:

```text
source.platform_station.platform_id = configured sender dropoff point id
source.interval_utc.start = configured/default parcel-ready datetime
```

Item mapping:

- WooCommerce line items become Yandex `items[]`;
- identical product quantities should use `count` when possible;
- SKU/article should come from product SKU, with order item id fallback;
- item price and assessed value must be converted to kopecks;
- item list must be connected to `places[]` in the way required by the API payload;
- missing dimensions should use existing WDC package/default settings and add a preview warning.

Payment mapping:

- initial default: `already_paid`;
- declared value is not the same as COD;
- `card_on_receipt` / `postpay` should be a separate business decision because the error reference shows delivery/payment combinations can be restricted.

### 5.5 Cancellation

Endpoint:

```text
POST /api/b2b/platform/request/cancel
```

WDC usage:

- expose `Отменить отправление в Яндекс.Доставке` only for non-terminal/non-return states where Yandex allows cancellation;
- use stored `request_id`;
- preserve local shipment state if Yandex returns a non-success or uncertain response;
- after successful cancellation, save universal status `cancelled` rather than silently deleting the shipment.

Local-only removal should remain a separate action and must not call Yandex.

### 5.6 Statuses and tracking

Polling endpoints:

```text
GET  /api/b2b/platform/request/info
POST /api/b2b/platform/requests/info
GET  /api/b2b/platform/request/history
```

WDC usage:

- manual per-order refresh: `request/info` plus optional `request/history`;
- autosync: existing `ShipmentStatusAutoSyncService`; initial implementation can call per shipment, with later optimization to batch known `request_id` values through `requests/info`;
- no Yandex-specific cron should be added;
- raw carrier status/reason/timestamp must be preserved in shipment state;
- universal status must be resolved through `YandexDeliveryStatusMapping`;
- WooCommerce order status changes must continue only through `ShipmentOrderStatusMappingService`.

Webhook status updates are not part of this plan because the checked other-day API method list exposes polling/status-info methods and no webhook endpoint was found during audit.

### 5.7 Documents

Endpoints:

```text
POST /api/b2b/platform/request/generate-labels
POST /api/b2b/platform/request/get-handover-act
```

`generate-labels` returns PDF labels for request ids. Request fields include `request_ids`, `generate_type` (`one` or `many`) and `language`.

`get-handover-act` returns a PDF or editable document depending on parameters. It can include orders by new-order filter, creation date range, `request_ids` or `request_code`.

WDC usage:

- `YandexDeliveryShipmentDocumentService` should be the only document boundary;
- order metabox action may download a ZIP containing:
  - `yandex-label-{request_id}.pdf`;
  - `yandex-handover-act-{request_id}.pdf` when enabled;
- documents should not be stored in order meta by default;
- document download must not mutate shipment status or `tracking_checked_at`;
- partial failures should stop the download and clean temporary files, matching DPD document behavior.

### 5.8 Errors and diagnostics

The Yandex error reference includes cases relevant to WDC:

- invalid destination;
- duplicate `operator_request_id`;
- token/auth failures;
- station/platform id not available;
- no delivery options;
- no dropoff options;
- payment method not supported;
- invalid dimensions or weight;
- point unavailable for dropoff.

Diagnostics must be sanitized:

- no Bearer token;
- no full phone/email in logs;
- no full recipient address in broad logs;
- request/response snapshots stored on shipment should be safe and redacted.

## 6. Platform integration plan

### 6.1 Carrier identity

Recommended carrier/service key:

```text
yandex_delivery
```

Use one carrier key for the Yandex other-day integration. Do not create separate carriers for pickup and courier; delivery type must remain a rate/shipment attribute.

Default service titles:

```text
Яндекс Доставка до ПВЗ
Яндекс Доставка курьером
```

The delivery service should be disabled by default until credentials and source dropoff point are configured.

### 6.2 Core classes

New API/settings layer:

```text
src/Carriers/YandexDelivery/YandexDeliveryApiClient.php
src/Carriers/YandexDelivery/YandexDeliveryApiException.php
src/Carriers/YandexDelivery/YandexDeliveryApiResponse.php
src/Carriers/YandexDelivery/YandexDeliveryCredentials.php
src/Carriers/YandexDelivery/YandexDeliveryEndpoints.php
src/Carriers/YandexDelivery/YandexDeliverySettings.php
src/Carriers/YandexDelivery/YandexDeliveryHttpClientInterface.php
src/Carriers/YandexDelivery/WpYandexDeliveryHttpClient.php
```

Checkout runtime:

```text
src/Carriers/Runtime/YandexDeliveryQuoteCarrier.php
```

Pickup/dropoff point support:

```text
src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointImportService.php
src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointNormalizer.php
src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointRepository.php
src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointService.php
src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointScheduleFormatter.php
```

Shipment preparation:

```text
src/Carriers/YandexDelivery/Shipments/YandexDeliveryShipmentPayloadBuilder.php
src/Carriers/YandexDelivery/Shipments/YandexDeliveryShipmentDateResolver.php
```

Shipment lifecycle:

```text
src/Shipments/YandexDelivery/YandexDeliveryShipmentAdapter.php
src/Shipments/YandexDelivery/YandexDeliveryOrderRegistrationService.php
src/Shipments/YandexDelivery/YandexDeliveryShipmentRepository.php
src/Shipments/YandexDelivery/YandexDeliveryEventSyncService.php
src/Shipments/YandexDelivery/YandexDeliveryEventNormalizer.php
src/Shipments/YandexDelivery/YandexDeliveryStatusMapping.php
src/Shipments/YandexDelivery/YandexDeliveryShipmentDocumentService.php
src/Shipments/YandexDelivery/YandexDeliveryShipmentButtonPolicy.php
```

### 6.3 Database

Recommended new migration:

```text
database/migrations/0032_create_yandex_delivery_pickup_points_table.php
```

Recommended table:

```text
wdc_yandex_delivery_pickup_points
```

Minimum fields:

```text
id
platform_station_id
operator_id
operator_name
name
type
address
geo_id
country_code
region_name
city_name
latitude
longitude
schedule
payment_methods
available_for_dropoff
available_for_c2c_dropoff
is_yandex_branded
raw_json
is_active
imported_at
updated_at
```

Indexes:

```text
unique(platform_station_id)
type
geo_id
city_name
coordinates
available_for_dropoff
is_active
```

The table should be separate from `wdc_location_delivery_codes`. Yandex pickup points are concrete service points, not settlement-code mappings.

### 6.4 Checkout runtime

`YandexDeliveryQuoteCarrier` must implement `CarrierAdapterInterface` and be registered in `CarrierRegistry`.

Availability requirements:

- delivery service row enabled;
- RU destination;
- active environment token present;
- source dropoff `platform_station_id` configured and validated;
- package/order value available;
- package weight and dimensions available or fallbacks configured;
- for pickup delivery: local active destination pickup point exists;
- for courier delivery: usable recipient/courier address exists;
- common delivery-service availability/rules do not reject the service.

Pickup rate behavior:

- returned rate uses `DeliveryType::PICKUP`;
- mark `requires_pickup_point=true`;
- set pickup family `yandex_delivery:pickup`;
- before buyer selection, quote may use an auto-selected active destination pickup point only for price display;
- after buyer selection, selected `platform_station_id` must be sent to the calculator and included in quote/cache keys;
- checkout/order save must still require explicit selected pickup point.

Courier rate behavior:

- returned rate uses `DeliveryType::COURIER`;
- set `requires_courier_address=true`;
- `requires_pickup_point=false`;
- use destination address payload and `time_interval` tariff.

Quote diagnostics should include:

```text
environment
delivery_type
source_platform_station_id
destination_platform_station_id when pickup
selected/auto-selected pickup point marker
request payload sanitized
pricing_total
delivery_days
raw Yandex status/error code when unavailable
```

### 6.5 Order delivery recalculation

Yandex must be connected to order-admin recalculation exactly like DPD:

```text
OrderQuoteRequestMapper
-> OrderDeliveryRecalculationService
-> CheckoutOrchestrator
-> YandexDeliveryQuoteCarrier
```

No separate order-admin Yandex calculator should be introduced.

Save behavior:

- pickup save writes common `_wdc_pickup_*` meta plus Yandex aliases;
- courier save clears common pickup meta and Yandex pickup aliases;
- `_wdc_delivery_calculation_data` stores the quote/API/rules snapshot;
- `OrderShipmentDraftFactory` reads saved Yandex rate meta for the `Отправления` draft.

Recommended Yandex aliases:

```text
_wdc_yandex_delivery_pickup_platform_station_id
_wdc_yandex_delivery_pickup_type
_wdc_yandex_delivery_pickup_name
_wdc_yandex_delivery_pickup_address
_wdc_yandex_delivery_pickup_geo_id
_wdc_yandex_delivery_pickup_latitude
_wdc_yandex_delivery_pickup_longitude
_wdc_yandex_delivery_pickup_operator_id
```

### 6.6 Shipment preparation and creation

Yandex shipment creation must stay manual in the first implementation, following the DPD lifecycle principle:

- no auto-create from checkout;
- no background creation;
- manager verifies preview first;
- create only after explicit click;
- save local pending state before external API submission.

Recommended stages:

1. Build draft from saved WDC order delivery meta.
2. Render payload preview with errors/warnings.
3. Validate selected delivery type, source point, recipient, item list, places, declared value and payment method.
4. Save pending local shipment with `pending_creation_in_carrier`.
5. Call `offers/create`.
6. Select matching offer.
7. Call `offers/confirm`.
8. Store `request_id` and Yandex state.
9. Run first status refresh.

Saved shipment state under `_wdc_shipments[yandex_delivery]` should include:

```text
carrier_key=yandex_delivery
service_key=yandex_delivery
status / universal_status_code
request_id
operator_request_id
last_mile_policy
source_platform_station_id
destination_platform_station_id when pickup
destination_address_snapshot when courier
offer_id
offer_snapshot
pricing_total_kopecks
payment_method
assessed_value_kopecks
items_snapshot
places_snapshot
request_snapshot
response_snapshot
created_at
updated_at
created_by
created_by_context=admin_manual
tracking_checked_at
carrier_status_code
carrier_status_title
carrier_status_description
carrier_status_reason
carrier_status_timestamp
```

### 6.7 Status mapping

Use a carrier-specific mapping class, but resolve only into existing universal WDC statuses.

Initial default mapping:

| Yandex status | WDC universal status | Notes |
| --- | --- | --- |
| `DRAFT`, `VALIDATING` | `pending_creation_in_carrier` | created/validating before confirmed carrier state |
| `VALIDATING_ERROR` | `rejected` | not confirmed by sorting center; preserve raw reason |
| `CREATED`, `DELIVERY_PROCESSING_STARTED`, `DELIVERY_TRACK_RECIEVED`, `SORTING_CENTER_PROCESSING_STARTED`, `SORTING_CENTER_TRACK_RECEIVED`, `SORTING_CENTER_TRACK_LOADED` | `created_in_carrier` | accepted/created but not yet active delivery |
| `DELIVERY_LOADED`, `SORTING_CENTER_LOADED`, `SORTING_CENTER_AT_START`, `SORTING_CENTER_PREPARED`, `SORTING_CENTER_TRANSMITTED`, `DELIVERY_AT_START`, `DELIVERY_AT_START_SORT`, `DELIVERY_TRANSPORTATION` | `in_transit` | movement through sort/linehaul/last-mile preparation |
| `DELIVERY_TRANSPORTATION_RECIPIENT` | `handed_to_courier` | courier delivery to recipient |
| `DELIVERY_ARRIVED_PICKUP_POINT`, `CONFIRMATION_CODE_RECEIVED` | `ready_for_pickup` | pickup/postamat destination flow |
| `DELIVERY_ATTEMPT_FAILED` | `in_transit` | not terminal in WDC model |
| `DELIVERY_TRANSMITTED_TO_RECIPIENT`, `DELIVERY_DELIVERED` | `delivered` | terminal success |
| `PARTICULARLY_DELIVERED` | `delivered` with warning marker | partial delivery must remain visible in raw carrier status |
| `CANCELLED` | `cancelled` | terminal cancel |
| `DELIVERY_STORAGE_PERIOD_EXPIRED`, `SORTING_CENTER_RETURN_PREPARING`, `SORTING_CENTER_RETURN_PREPARING_SENDER`, `RETURN_PREPARING`, `RETURN_TRANSPORTATION_STARTED`, `RETURN_ARRIVED_DELIVERY`, `RETURN_TRANSMITTED_FULFILMENT`, `RETURN_READY_FOR_PICKUP` | `returning_to_sender` | active return process |
| `SORTING_CENTER_RETURN_ARRIVED`, `SORTING_CENTER_RETURN_RETURNED`, `RETURN_RETURNED` | `returned_to_sender` | returned to sender/merchant |
| `DELIVERY_TIME_INTERVALS_UPDATED`, `DELIVERY_DATE_UPDATED_BY_SHOP`, `DELIVERY_DATE_UPDATED_BY_DELIVERY` | keep previous universal status, fallback `unknown` | date-change events should not downgrade delivery state |
| unknown / unmapped | `unknown` | safe fallback |

Admin mapping should follow the DPD model: status dictionary, editable universal-status select, defaults, save and reset.

WooCommerce status transitions must remain carrier-neutral:

```text
Yandex status -> YandexDeliveryStatusMapping -> DeliveryStatus -> ShipmentOrderStatusMappingService
```

No direct Yandex status to WooCommerce order status logic should be added.

### 6.8 Autosync

Do not add a separate Yandex cron.

Use the existing shared scheduler:

```text
ShipmentStatusAutoSyncCron
ShipmentStatusAutoSyncService
ShipmentOrderStatusMappingService
```

Initial implementation options:

1. Per-shipment polling through `request/info`, using the existing autosync dispatch pattern.
2. Later batch optimization through `requests/info` for known Yandex `request_id` values.

If batch support is implemented immediately, prefer a generic optional carrier bulk-sync interface rather than a Yandex-only scheduler branch.

Terminal universal statuses should follow the existing WDC autosync policy: terminal shipments skip carrier polling but still allow WooCommerce order-status mapping from saved state.

### 6.9 Documents

`YandexDeliveryShipmentDocumentService` should mirror DPD document safety:

- validate order id, nonce and capability;
- validate shipment carrier and `request_id`;
- request labels PDF;
- optionally request handover act PDF;
- validate PDF content;
- stream a temporary ZIP;
- delete temporary files;
- do not persist document bytes to order meta;
- do not mutate status fields.

Button policy:

- show documents only after `request_id` is present;
- if handover act is enabled, keep label and act failures explicit;
- do not show documents for local-only pending state without confirmed Yandex request id.

## 7. Development phases

### Phase 0 — documentation and plan

Current step. No PHP code.

Deliverable:

```text
docs/wdc-yandex-delivery-other-day-integration.md
```

### Phase 1 — foundation/API/settings

- credentials/settings/admin tab;
- HTTP client and endpoint selection;
- redacted diagnostics;
- fake-client smoke tests;
- optional explicit live probe using configured credentials only.


### 0.72.0 implementation note

Phase 1 is implemented as infrastructure only:

- built-in service `yandex_delivery` / `Яндекс Доставка`, RU-only, disabled by default and sorted after DPD;
- admin tab `Данные для входа` with active environment, encrypted test/production Bearer tokens, test/production source `platform_station_id`, timeout, debug and last connection-check fields;
- replaceable HTTP client plus JSON API client for `POST /api/b2b/platform/pickup-points/list`;
- explicit connection diagnostic that succeeds only for a found `pickup_point` with `available_for_dropoff=true`;
- sanitized diagnostics/exceptions/log-safe payloads without Bearer token, phones, email or full address;
- smoke coverage in `tests/yandex-delivery/run-yandex-delivery-foundation-smoke.php`.

Still intentionally not implemented in 0.72.0: pickup-point table/import, checkout rates, order-admin recalculation, shipment adapter/actions, `offers/create`, `offers/confirm`, cancellation, statuses/autosync, documents and any Yandex-specific cron.

### Phase 2 — pickup/dropoff points

- local table and migration;
- import/safe-replace service;
- sender dropoff validation;
- checkout/admin pickup REST integration;
- smoke tests for point normalization, active filtering and checkout payload shape.

### Phase 3 — checkout and order recalculation

- `YandexDeliveryQuoteCarrier`;
- pricing calculator integration;
- pickup/courier grouped rates;
- selected pickup point affects price and cache key;
- order-admin recalculation through `CheckoutOrchestrator`;
- smoke tests for checkout and recalculation.

### Phase 4 — shipment preparation and creation

- payload builder;
- manual preview;
- local pending state;
- `offers/create` + `offers/confirm`;
- duplicate/idempotency safeguards;
- immediate first status refresh;
- smoke tests for payload, validation, persistence and uncertain failures.

### Phase 5 — statuses and autosync

- status dictionary and admin mapping;
- manual refresh service;
- autosync integration;
- WooCommerce order status mapping via existing shared service;
- smoke tests for mapping, manual refresh, autosync and terminal skip behavior.

### Phase 6 — documents

- label PDF download;
- handover act PDF download when enabled;
- ZIP streaming;
- smoke tests for validation, PDF handling and cleanup.

### Phase 7 — stabilization

- admin button policy;
- diagnostics polish;
- no-secret/no-PII log audit;
- tree/docs/project-status update for implementation completion;
- full smoke suite for Yandex plus regression smoke for DPD/CDEK/Russian Post shared paths.

## 8. Acceptance criteria for implementation planning

Before writing PHP code, confirm:

- production Bearer token ownership and storage expectation;
- production sender `platform_station_id` for the Yandex dropoff point;
- whether only Yandex-branded pickup points are allowed or partner pickup points returned by the API are allowed;
- whether postamats/terminals are excluded in all flows;
- whether `merchant_id` is required for this account;
- whether all orders are prepaid (`already_paid`) or COD must be supported later;
- VAT/default fiscal fields for item payloads;
- default/fallback dimensions and package-place strategy;
- whether handover act must be downloaded from WDC or can remain in Yandex cabinet initially;
- exact smoke-test command list for the first implementation branch.

## 9. Recommended Codex prompt for implementation branch

Use only after this plan is approved.

```text
Ветка: feature/yandex-delivery-other-day

Перед началом прочитай:
- docs/development-workflow.md
- docs/project-status.md
- docs/wdc-current-code-map.md
- docs/wdc-core-platform.md
- docs/wdc-domain-model.md
- docs/wdc-shipments-foundation.md
- docs/wdc-shipment-statuses.md
- docs/wdc-order-delivery-recalculation.md
- docs/wdc-yandex-delivery-other-day-integration.md
- docs/wdc-dpd-integration.md
- docs/wdc-dpd-checkout-runtime.md
- docs/wdc-dpd-checkout-pickup-selection.md
- docs/wdc-dpd-pickup-points.md
- docs/wdc-dpd-shipment-preparation.md
- docs/wdc-dpd-create-order.md
- docs/wdc-dpd-event-sync.md
- docs/wdc-dpd-status-mapping.md
- docs/wdc-dpd-autosync.md
- docs/wdc-dpd-documents.md
- docs/wdc-dpd-shipment-lifecycle.md

Разрешено менять:
- src/Carriers/YandexDelivery/**
- src/Carriers/Runtime/YandexDeliveryQuoteCarrier.php
- src/Shipments/YandexDelivery/**
- database/migrations/0032_create_yandex_delivery_pickup_points_table.php
- assets/admin/* только если требуется UI Яндекса
- assets/frontend/pickup-map/* только если требуется carrier-family wiring
- docs/wdc-yandex-delivery-other-day-integration.md
- docs/project-status.md
- docs/wdc-current-code-map.md
- tree.txt
- tests/yandex-delivery/**

Нельзя менять:
- DPD business logic, кроме shared regression-safe wiring if unavoidable
- CDEK business logic
- Russian Post business logic
- legacy/includes runtime
- WooCommerce order status mapping rules outside the shared ShipmentOrderStatusMappingService path

Требования:
- не создавать отправления автоматически
- не добавлять отдельный cron для Яндекса
- не хранить Bearer token plaintext
- не логировать PII/secrets
- checkout и order-admin recalculation должны идти через CheckoutOrchestrator
- статусы должны идти через YandexDeliveryStatusMapping -> DeliveryStatus -> ShipmentOrderStatusMappingService
```
