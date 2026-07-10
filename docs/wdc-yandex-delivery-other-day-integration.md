0.88.0 Yandex Geo Manual Review Queue

Manual review is available in Маппинг geo_id: grouped needs_review rows can be filtered, approved as mapped primary, rejected to normal not_found, or bulk rejected. These actions are unavailable while the runner status is running. PVZ import, checkout and pricing remain out of scope.


## 0.87.0 Yandex Geo Mapping Runner

The `Маппинг geo_id` tab now contains a production runner for full automatic mapping of the WDC locations base. It processes active RU locations with non-empty `display_name` in fixed batches of 30, stores state in `wdc_yandex_delivery_geo_mapping_runner_state`, reserves batches with `next_location_id` before processing, and the admin JS runs three bounded browser workers in parallel. A full run always rebuilds mappings for every selected location: old rows for that location are deleted before remapping, and existing primary/manual/error mappings are not skipped.

Technical `location/detect` failures use marker `999999999`. This marker is not a working geo_id, is never primary, and exists only to make failed locations searchable for retry. The retry-errors mode processes only marker rows and replaces or clears the marker when a normal mapped/needs_review/not_found result is produced.

Manual mapping actions are blocked while the runner is `running`. Coverage batch, PVZ import, checkout and pricing remain out of scope for this stage.
# WDC Yandex Delivery Other-Day Integration

Status: foundation/API/settings, pickup diagnostics, geo_v2 import/enrichment/mapping pipeline, checkout rates, the admin source platform station selector and checkout pricing-calculator integration are implemented through 0.104.5. Yandex pricing-calculator uses the shared generic PackagingBuilder for multi-place request payloads, and checkout buyer PVZ selection for `yandex_pickup` is implemented through the common pickup picker. Order recalculation and shipments remain planned.

Date: 2026-06-30.

## 0.85.0 Admin UX consolidation

The Yandex Delivery admin surface now follows the intended working model:

```text
Данные для входа
Маппинг geo_id
Покрытие Яндекса
Яндекс ПВЗ
```

`Маппинг geo_id` contains manual geo_id search, the browser-driven full runner, mapping analytics and a working manual `needs_review` queue for approving a candidate geo_id or rejecting a WDC location as `not_found`. `Покрытие Яндекса` stays a selective/manual coverage check, not a mass import. `Яндекс ПВЗ` is the future pickup-point workspace; the current Moscow `geo_id=213` import remains a test diagnostic.

Architecture decision: coverage batch as a separate mass stage is not needed. The future PVZ import should run over confirmed mapped geo_id values and update `covered`/`not_covered` while importing real points.



## 0.104.5 Checkout pickup map presentation

Yandex destination pickup rows are still loaded from `wp_wdc_yandex_delivery_pickup_points_v2` by selected checkout `location_id` and all mapped/manual `yandex_geo_id` values, without server-side limits for the Yandex checkout picker. The formatter now maps operator/type/name to buyer-facing titles: 5Post, Yandex.Market terminal, partner terminal, generic Yandex terminal and generic Yandex Delivery fallback. `platform_station_id` remains available only as `id`/`point_code`/`platform_station_id`/`snapshot.platform_station_id`; `display_code` is empty for Yandex points.

Warnings such as the 5Post price note and terminal storage note are carried as `presentation_comment`, kept separate from pickup instructions in `description`, and rendered under the title in the map popup/list. The frontend keeps all loaded Yandex city points for map markers and clustering but filters the side list client-side by the current map bbox, preserving committed selection if the selected point leaves the viewport. Leaflet `clusterCellSize` and Yandex `gridSize` are now 128. Pricing-calculator requests, representative fallback, selected-station priority, source station, imports and geo/mapping pipelines were not changed.

## 0.104.4 Checkout pickup chain fix

The checkout PVZ button chain now covers real WooCommerce rate meta. WooCommerceRateMapper writes pickup_family next to requires_pickup_point, delivery_type, carrier_key and rate_id. CheckoutRateRenderer accepts both associative test meta and real WooCommerce meta-data entries with key/value payloads, then renders the shared pickup container for yandex_pickup.

The JS aliases yandex_pickup to yandex_delivery:pickup in shippingMethodFamily() and marks yandex_pickup as a pickup rate in isPickupRateValue(). With those two checks aligned, containerMatchesActivePickup() succeeds and toggleForMethod() leaves the Yandex pickup container visible after boot and updated_checkout. Saving a Yandex pickup point still triggers update_checkout for selected-station repricing.
## 0.104.3 Checkout pickup renderer fix

The checkout pickup button is now rendered by CheckoutRateRenderer itself for rates with requires_pickup_point=true and delivery_type=pickup. yandex_pickup therefore receives the shared data-wdc-pickup-checkout container, data-shipping-method-id=yandex_pickup, the standard hidden fields and wdc_pickup_family=yandex_delivery:pickup in the same rate meta block that renders tariffs, courier address summaries and comments. yandex_courier remains without pickup UI.

CheckoutDeliveryTypeSelector no longer registers a duplicate shipping-rate HTML renderer, while its session capture compatibility stays in place. wdc-pickup-checkout.js now treats yandex_delivery:pickup like DPD pickup for post-save recalculation and triggers update_checkout after a Yandex PVZ is saved, allowing the existing selected-station pricing flow to refresh the shown rate.
## 0.104.2 Checkout pickup button init fix

The checkout picker assets are now loaded on checkout before pickup rates exist in session, so a later WooCommerce updated_checkout response that includes yandex_pickup has the required JS/CSS already present. The script still reboots on updated_checkout and now uses delegated clicks for data-wdc-pickup-open, keeping the Yandex pickup button clickable after WooCommerce replaces the shipping methods HTML.

The rendered yandex_pickup block uses the common pickup container and hidden fields with carrier_key=yandex_delivery and pickup_family=yandex_delivery:pickup. Pricing-calculator payloads, selected platform_station_id priority, representative PVZ fallback, source station, import and mapping pipelines are unchanged.
## 0.104.1 Checkout PVZ review fixes

For buyer checkout selection, Yandex destination PVZ loading now returns all points for the selected city instead of truncating at the REST limit. The endpoint still starts from checkout location_id, resolves all mapped/manual yandex_geo_id values, filters active rows with non-empty platform_station_id and type pickup_point or terminal, and does not require available_for_dropoff. The repository query used by the checkout picker has no SQL LIMIT and the test path no longer slices the result.

Checkout pricing now receives the full family-scoped pickup_selections dictionary in QuoteRequest customer_context. yandex_pickup restores pickup_selections['yandex_delivery:pickup'] before considering the global pickup_selection, so switching to another carrier and back keeps the saved Yandex platform_station_id for pricing-calculator. Representative PVZ remains the fallback when no selected Yandex point exists.
## 0.104.0 Checkout buyer PVZ selection

yandex_pickup now uses the common checkout pickup picker instead of only the representative destination PVZ. The endpoint reads active destination candidates from wp_wdc_yandex_delivery_pickup_points_v2 by all mapped/manual yandex_geo_id values for checkout location_id, requires non-empty platform_station_id, accepts 	ype=pickup_point and 	ype=terminal, and does not require vailable_for_dropoff because that flag describes sender dropoff availability. The frontend receives the shared pickup point format with carrier_key=yandex_delivery, point_code/platform_station_id, display address/name and safe snapshot.

When no Yandex PVZ is selected, pricing still uses the representative PVZ fallback and rate meta records pickup_source=representative. When a buyer selects a Yandex PVZ, YandexDeliveryCarrier reads the selected common pickup session payload, uses the selected destination platform_station_id in pricing-calculator, and records pickup_source=selected plus destination_platform_station_id. Validation requires a selected Yandex PVZ only for yandex_pickup; yandex_courier remains courier-address based. Order creation stores the common _wdc_pickup_* meta and Yandex aliases including _wdc_yandex_delivery_pickup_platform_station_id, without saving full raw Yandex JSON.

## 0.103.6 Shared packaging for pricing-calculator

Yandex pricing-calculator requests now use src/Packaging/PackagingBuilder with generic PackagingBuilderConfig::defaults(). YandexDeliveryPricingRequestBuilder converts every PackagingParcel to a Yandex places[] entry with physical_dims.weight_gross, dx, dy and dz; PackagingParcel::quantity is expanded into repeated places. Request 	otal_weight is the sum of all sent weight_gross values. If the packaging result is empty or invalid, the builder falls back to the previous single-place model with generic 500 g and 20x15x10 cm defaults. Yandex rate meta records safe diagnostics: package_builder_source, packing_strategy, parcels_count, 	otal_weight_g, places_count and sanitized package dimensions/weights, without addresses or tokens. DPD legacy packaging config is not used by Yandex.

## 0.103.3 Checkout sorting

Yandex Delivery checkout rates use the shared deterministic checkout sorter together with every other carrier. Sorting reads neutral original carrier values from `DeliveryRate::original_cost` and `DeliveryRate::original_delivery_days`; pricing-calculator payloads, parsed prices, delivery-day labels, source station selection and representative destination PVZ logic are unchanged.
## 0.103.2 Shared packaging readiness

The DPD parcel packing engine has been extracted to `src/Packaging/PackagingBuilder.php` with neutral `PackagingResult` and `PackagingParcel` DTOs. `PackagingBuilderConfig::defaults()` is defined inside Packaging, so Yandex uses this builder without depending on `Carriers/Dpd` or DPD settings. DPD wires a separate legacy-configured builder for its runtime fallback payload, while the shared Packaging defaults remain generic.
## 0.102.1 Checkout pricing-calculator

Yandex checkout now calls `POST /api/b2b/platform/pricing-calculator` for both rates. Pickup uses `tariff=self_pickup`, `source.platform_station_id` from `YandexDeliverySettings::source_platform_station_id()`, and a representative destination `platform_station_id` selected locally from imported PVZ rows across every mapped/manual `yandex_geo_id` for the destination WDC `location_id`. Courier uses `tariff=time_interval` and `destination.address` assembled from checkout fields. The request builder sends `total_weight`, `total_assessed_price`, `client_price=0`, `payment_method=already_paid`, and shared packaging `places[]`. Each `PackagingParcel` becomes a place with gross weight and dimensions; parcel quantity is expanded into repeated places. `total_weight` equals the sum of sent `weight_gross` values. Empty or invalid packaging falls back to the previous single-place payload with generic `500 g` and `20x15x10 cm` defaults.

`pricing_total` is parsed to kopecks (`237.9 RUB` -> `23790`), and `delivery_days` becomes the checkout title suffix through the shared Russian formatter (`1 день`, `2 дня`, `5 дней`). A failure in one Yandex rate returns a disabled reason and does not break the other rate. Buyer PVZ selection/map, shipment offers, order recalculation, import and geo pipeline code were not changed.

## 0.101.3 Source platform station admin setting

The first checkout-preparation setting for Yandex Delivery is available on `Службы доставки -> Яндекс Доставка -> Расчет`. Admins search/select a WDC local `location_id`, WDC resolves it through location mapping v2 to all mapped/manual `yandex_geo_id` values, and then shows all locally imported Yandex PVZ rows that are active, `available_for_dropoff=true`, have any matching `yandex_geo_id`, and have a non-empty `platform_station_id`, without limiting the list inside that geo id set. The PVZ selector has a client-side `full_address` filter that starts from 3 characters and shows an empty-result message when nothing matches. WDC stores the selected `platform_station_id` as `source_platform_station_id`; `source_location_id` is stored only to restore the admin selector. The full address is restored from the local PVZ table and shown as a read-only verification field.

If a later import removes the saved station from the local database, marks it inactive, or marks it unavailable for dropoff, the setting remains saved, the `platform_station_id` is still displayed, and the admin page shows a warning. Checkout remains tolerant of an empty source station by returning disabled Yandex rates with a readable reason instead of sending pricing requests.
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
- manual admin pickup-point import/search tab;
- smoke tests for point normalization, active filtering, sender validation and import statistics.

Checkout/admin pickup REST integration and checkout payload shape move to Phase 3 with pickup selection/pricing.


### 0.73.0 implementation note

Phase 2 is implemented as a local pickup/dropoff points foundation only:

- migration `0032_create_yandex_delivery_pickup_points_table.php` creates `wdc_yandex_delivery_pickup_points`;
- repository, normalizer, manual import service and service layer live in `src/Carriers/YandexDelivery/Pickup/`;
- import source is `POST /api/b2b/platform/pickup-points/list` with `type=pickup_point`;
- importer stores pickup points, dropoff points and partner points, including rows where `available_for_dropoff=false`;
- admin tab `ПВЗ / точки сдачи` shows stats, active-environment sender point validation, manual import and basic search;
- smoke coverage is in `tests/yandex-delivery/run-yandex-delivery-pickup-smoke.php`.

Still intentionally not implemented in 0.73.0: checkout rates, pickup map/selection, `YandexDeliveryQuoteCarrier`, pricing-calculator integration, order-admin recalculation, shipment adapter/actions, `offers/create`, `offers/confirm`, cancellation, statuses/autosync, documents, cron or pickup autosync. Import is manual only.



### 0.75.0 geo_id mapping foundation note

This stage adds only the WDC `location_id` -> Yandex `geo_id` mapping layer needed before pricing, pickup filtering and checkout pickup selection.

Implemented:

- migration `0033_create_yandex_delivery_geo_mappings_table.php` creates `wdc_yandex_delivery_geo_mappings`;
- storage supports one WDC `location_id` with multiple Yandex `geo_id` rows and an explicit primary row;
- statuses are `mapped`, `multiple_matches`, `not_found`, `manual` and `error`;
- `YandexDeliveryGeoMappingService` builds location search strings from existing WDC `LocationRepository`/`Location` models, calls `POST /api/b2b/platform/location/detect`, normalizes variants and saves mappings;
- admin tab order for Yandex Delivery is now `Данные для входа`, `ПВЗ / точки сдачи`, `Yandex geo_id`;
- the geo tab can search/select a WDC location, run one `location/detect`, display `geo_id`, locality, region, confidence and status, and mark a mapping as primary.

The mapping intentionally does not use `wp_wdc_location_delivery_codes`; that table remains DPD-specific in shape (`location_id`, `dpd_city_id`, `updated_at`) and cannot represent one WDC location mapped to several Yandex `geo_id` values.

Confidence is deterministic and intentionally simple for this foundation:

- `100.00` when Yandex locality and region match the WDC location;
- `70.00` when only locality matches;
- `40.00` for multiple matches or weak matches.

Still intentionally not implemented in 0.75.0: full Russia pickup import, import of all WDC locations, checkout, pricing calculator, pickup selection, maps, shipments, statuses, documents, cron or autosync.

### Контрольное число ПВЗ Яндекс.Доставки

Live API checks for the full Russia `pickup-points/list` response showed:

- `35222` pickup points;
- `12605` dropoff points.

After a later full Russia import is implemented, the local database should be close to these values. If the final count is substantially lower, return to the analysis of multiple Yandex `geo_id` values for large cities.
### 0.74.2 fixed geo_id import note

Live testing showed `pickup-points/list` ignores `limit` and does not provide usable page tokens, returning the full Russia dataset in one response. The current manual AJAX import is therefore temporarily constrained to `type=pickup_point` with integer `geo_id=213` (`Москва`) and stores `mode=geo_id_fixed`, `geo_id` and `geo_label` in state/report. Full Russia import remains a later phase after WDC location records can be mapped to Yandex `geo_id` values.

### 0.74.0 AJAX import note

The Phase 2 pickup/dropoff import is manual AJAX only. The admin tab starts a session, stores progress in `wdc_yandex_delivery_pickup_import_state`, processes exactly one `pickup-points/list` page per step, updates counters after every step and saves the final report on success/error. Reset removes both `wdc_yandex_delivery_pickup_import_lock` and the import state so an admin can recover from a failed request. Cron/autosync, checkout rates, pickup map/selection, shipments, statuses and documents remain out of scope.

### 0.73.3 production import memory note

Yandex pickup/dropoff import remains manual-only and page-streamed. Production imports use a conservative default `yandex_delivery_pickup_import_page_size=100`; the admin pickup tab can set 20..500, and the import report records `page_size`, `pages` and `memory_peak_mb`. Use 50 or 100 if a large Yandex page exhausts PHP memory during response decoding.

### 0.84.0 coverage discovery foundation note

This stage answers a narrower operational question: which already mapped WDC locations actually have Yandex pickup/dropoff coverage. It is manual/selective and intentionally does not load or store the full Russia pickup-point database.

Implemented:

- migration `0034_create_yandex_delivery_geo_coverage_table.php` creates `wdc_yandex_delivery_geo_coverage`;
- statuses are `covered`, `not_covered`, `no_geo_id`, `error` and `unknown`;
- `YandexDeliveryGeoCoverageService::check_location()` uses only `YandexDeliveryGeoMappingRepository::find_primary_geo_id($location_id)` as the working geo_id source;
- if no primary geo_id exists, the result is `no_geo_id` and Yandex API is not called;
- if a primary geo_id exists, the service calls `POST /api/b2b/platform/pickup-points/list` with exactly `{"type":"pickup_point","geo_id":<int>}` for this coverage check, with no `limit` and no pagination;
- response points can come from `points`, `pickup_points`, `items`, `result` or a root array;
- `operators_json` stores aggregate counts by `operator_id`;
- `sample_points_json` stores only the first 5 compact points with `id`, `operator_id`, `dropoff` and `address`;
- `raw_stats_json` stores compact stats only, not the full raw response;
- the Yandex admin tab order is now `Данные для входа`, `ПВЗ / точки сдачи`, `Yandex geo_id`, `Yandex geo batch`, `Yandex geo analysis`, `Yandex coverage`;
- smoke coverage is in `tests/yandex-delivery/run-yandex-delivery-geo-coverage-smoke.php`.

Coverage status semantics:

- `covered`: successful response contains at least one pickup/dropoff point;
- `not_covered`: primary geo_id exists, request succeeds and returns zero points;
- `no_geo_id`: no working primary geo_id exists for the WDC location;
- `error`: API/transport/normalization exception;
- `unknown`: fallback/default for invalid stored values.

Still intentionally not implemented in 0.84.0: checkout, pricing, PVZ map, PVZ selection, shipment creation, full Russia PVZ import, coordinate fallback or autosync.

Required Yandex smoke list for this stage:

```bash
php tests/yandex-delivery/run-yandex-delivery-foundation-smoke.php
php tests/yandex-delivery/run-yandex-delivery-pickup-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-mapping-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-batch-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-analysis-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-resolution-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-coverage-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-runner-smoke.php
php tests/yandex-delivery/run-yandex-delivery-geo-manual-review-smoke.php
```
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

## Yandex geo_id scoring

`location/detect` can return many candidates that are not valid geo_id values for the same WDC location. Examples include nearby settlements, cottage settlements, garden associations, foreign names that share the same text, and other weak textual matches.

Multiple geo_id for a large city is a working hypothesis from Yandex FAQ. It must be rechecked later against real pickup-points/list results and the completed mapping database. Do not treat every additional location/detect variant as a valid geo_id for the same WDC location.

Practical scoring rules for the current stage:

- exact locality plus matching region is high confidence and may be auto-primary;
- exact locality without verifiable region is high but slightly less certain;
- noisy variants that only contain the WDC locality as a token or substring stay low confidence;
- foreign country hints are low confidence even when locality text matches;
- additional candidates such as Парголово/Шушары/Колпино for Санкт-Петербург are stored as candidates, but are treated as separate localities/territories unless they match the WDC location.

The scorer is intended to prepare future automation for 160k locations, but ambiguous cases still require manual confirmation. This stage does not add checkout, pricing calculator integration, pickup-point selection, full Russia pickup import, or mass mapping.
### 0.77.0 region-aware geo scoring note

Yandex location/detect query source: WDC uses `wp_wdc_locations.display_name` as the primary query source. `display_name` already contains region, district and settlement from WDC location normalization, for example `Краснодарский край, Анапский р-н, хутор Воскресенский`. Country is not added because Yandex Delivery Russia API works only for Russia. Manual query assembly remains only as a fallback for legacy/incomplete locations without `display_name`.

The scorer is region/district-aware: it uses WDC `region_name`, `district_name`, `city_name`, `resolved_place_name()` and `resolved_place_type()`. For Yandex string addresses, the first comma-separated part is treated as candidate locality and the remaining parts as region/district/city context. Settlement type is stripped for name comparison, but a matching type such as `хутор` to `хутор` adds confidence. Wrong-region exact names, foreign hints, administrative units and weak substring matches are capped low.

Multiple geo_id for a large city is still a working hypothesis from Yandex FAQ, not a proven property of the WDC database. It must be rechecked later against real pickup-points/list results and the completed mapping database. Additional `location/detect` variants are candidates, not automatically valid geo_id values for the same WDC location.

The current scorer stores diagnostics in `raw_json.scoring`: `confidence`, `matched_by`, `reason` and component scores for base, region, district, city/context, type and penalty.

### 0.80.0 scorer synonym note

The scorer now canonicalizes common administrative context aliases before comparison: `респ`/`республика`, `р-н`/`район`, `МО`/`муниципальный округ`, `ГО`/`городской округ`, `АО`/`автономный округ`, plus existing region words such as `обл`/`область` and `край`. Settlement type comparison uses canonical type codes, so `пгт` and `поселок городского типа` are equivalent, alongside `г`/`город`, `пос`/`поселок`, `ст`/`станица`, `х`/`хутор`, `с`/`село` and `д`/`деревня`.

When a candidate matches through equivalent but differently written settlement types, `raw_json.scoring.matched_by` includes `type_equivalent` in addition to `type_match`. The Тлюстенхабль case (`респ Адыгея, Теучежский р-н, пгт Тлюстенхабль` vs `поселок городского типа Тлюстенхабль, Теучежский район, Республика Адыгея`) is expected to score as a high-confidence exact match. This stage does not add checkout, pricing, pickup-point changes, distance fallback or batch-builder changes.

Future distance-based fallback: around 155000 of 160000 WDC locations have coordinates, and Yandex `pickup-points/list` returns coordinates for pickup points. If text scoring cannot reach the auto-primary threshold for an unresolved/ambiguous location, a later expensive fallback may call `pickup-points/list(geo_id)` for candidate geo IDs, compare pickup point coordinates with WDC location coordinates and prefer the geo_id whose nearest or median pickup-point set is geographically closest. This must stay a last resort for unresolved/ambiguous locations, not a full Russia import, checkout flow or pickup map.
## Yandex geo_id candidate storage policy

Candidates returned by Yandex `location/detect` are diagnostic candidates, not working geo_id mappings. The working mapping for checkout/pricing/pickup selection is only the row with `is_primary=1`; future runtime code must continue to use `find_primary_geo_id()`.

Default production policy is `ambiguous_only`: when auto-primary is confident (`best confidence >= 95` and the second candidate is absent or at least 15 points lower), only the primary row is stored. When the result is ambiguous and no primary is assigned, all candidates are stored for manual review.

`primary_only` is reserved for stricter storage where only the primary or best diagnostic row is kept. `all` is a debug/manual-analysis policy that stores every candidate even when a confident primary exists. The `all` mode is not intended for future 160000-location mass mapping because unrelated candidates would inflate `wdc_yandex_delivery_geo_mappings`.

This policy keeps `multiple_matches` rows diagnostic: they are useful for admin review, but they are not valid delivery mappings unless a human later marks one as primary/manual.

## Yandex Geo Mapping Batch Builder

0.79.0 adds only an experimental batch builder for quality analysis of the existing WDC `location_id` -> Yandex `geo_id` mapping flow.

The batch service stores state in option `wdc_yandex_delivery_geo_mapping_batch_state`, not in a new table. It processes short batches of RU locations with non-empty `display_name`, sorted by `id ASC` and `id > last_location_id`. Each location is handled through `YandexDeliveryGeoMappingService::detect_for_location_id()`, so query construction, `location/detect`, region-aware scoring, auto-primary and candidate storage policy stay in one existing path.

Statistics are interpreted as:

- `mapped`: saved mappings contain `is_primary=1`;
- `ambiguous`: saved mappings exist without primary and status is `multiple_matches`;
- `not_found`: status is `not_found`;
- `errors`: status is `error` or an exception was caught;
- `skipped_existing`: a primary mapping already existed before processing.

Recommended first live run: `limit=1000`, `batch_size=25`. Evaluate `mapped / ambiguous / not_found / errors`, confidence buckets and `errors_last` before increasing scope. Do not run the full 160000 WDC locations yet.

Still intentionally not implemented in this stage: checkout, pricing-calculator, pickup-point selection, map, full Russia PVZ import, coordinate fallback through `pickup-points/list`, shipments, statuses, documents, cron or autosync.
## Yandex Low Confidence Analysis

0.81.0 adds a read-only dashboard for analyzing saved Yandex geo mapping results after the first batch runs. `YandexDeliveryGeoAnalysisService` reads existing `wdc_yandex_delivery_geo_mappings` rows and related WDC locations only; it does not call Yandex, does not run `location/detect`, does not execute the scorer again and does not create or update mappings.

The admin tab `Yandex geo analysis` sits after `Yandex geo batch` and uses a GET `max_confidence` filter defaulting to `59.99`. It shows confidence buckets from real mapping rows, status counts, top low-confidence regions, top low-confidence settlement types, top `matched_by` patterns parsed from `raw_json.scoring.matched_by`, and the lowest-confidence rows with `location_id`, `display_name`, `geo_id`, `confidence`, `status`, `matched_by` and `reason`.

The goal is to understand which settlements landed in low-confidence buckets such as `40-59`, `1-39` and `0`, and why, before deciding whether any scorer changes are justified later. This stage does not change scorer logic, batch builder logic, checkout, pricing-calculator, pickup-point import/selection, coordinate fallback, shipments, statuses or documents.

## Yandex Mapping Resolution Engine

0.82.0 adds a resolution layer between Yandex `location/detect` scoring and saved mapping rows. The layer is intentionally narrow: it does not change scorer logic, does not call pickup-points/list and does not implement coordinate fallback yet.

Resolution outcomes:

- `mapped`: one safe working primary, currently confidence `>=95` with at least a 15-point gap from the second candidate;
- `needs_review`: candidates exist, but WDC cannot safely decide mapped/not_found automatically;
- `not_found`: Yandex returned no meaningful locality/title/substring/region-district context candidate.

Important rule: `locality_exact` with a wrong or foreign region is `needs_review`, not `not_found`. Such rows are not working geo_id mappings, but they should remain visible for manual review and future coordinate checks because region data may differ between WDC and Yandex or a candidate may still be geographically close.

`needs_review` rows always keep `is_primary=0`. Runtime code must continue to use only primary rows (`is_primary=1`, normally via `find_primary_geo_id()`). The batch builder does not get a new counter; `needs_review` contributes to the existing `ambiguous` count. Checkout, pricing-calculator, PVZ import/selection, pickup map, shipments, statuses, documents and autosync remain out of scope.
