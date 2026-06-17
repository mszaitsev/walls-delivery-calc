# WDC DPD Checkout Pickup Selection

Version: 0.60.4.

0.60.4 update: the shared checkout pickup map recalculates and resorts visible point distances after DPD address search
or browser geolocation changes the active origin, so side-card distances and `Ближайший ПВЗ` use the current origin.
No DPD pricing, import or backend carrier behavior changed.

0.60.3 update: checkout-facing DPD pickup data is consumer-deduplicated by `terminal_code` and prefers `parcel_shop`
over a duplicate `terminal_self_delivery` row. DPD `raw_json` stays in local storage for diagnostics, but checkout REST
responses, checkout session snapshots and order meta do not expose it.

0.60.2 update: DPD pickup schedules are formatted for checkout output. REST point payloads and saved checkout pickup
snapshots expose readable `work_time`/`schedule` values, including JSON schedules already stored in
`wdc_dpd_pickup_points`. Raw DPD `raw_json` remains untouched, and the selected `terminal_code` still does not affect
tariff calculation.

0.60.1 review fix: checkout resolve-location now extracts the selected point payload or `point_id` before applying the
DPD/CDEK skip checks. DPD and CDEK pickup points return `requires_location_change=false` without invoking the Russian
Post location resolver; Russian Post behavior remains on the resolver path. Pricing remains unchanged and selected DPD
`terminal_code` is still not sent to tariff calculation.

## Scope

DPD pickup-point selection is connected to checkout as a foundation step. Buyers can select a local DPD parcel shop or
self-delivery terminal for DPD pickup rates. The selected point is stored in checkout/session/order meta for future DPD
terminalCode-aware pricing and order/shipment work.

This stage intentionally does not change DPD pricing. DPD checkout rates still use `calculator2/getServiceCostByParcels2`
with sender/receiver cityId and `selfPickup`/`selfDelivery` mode flags. The selected `terminal_code` is not sent to DPD
tariff calculation yet.

## Reused Pattern

DPD reuses the existing checkout pickup architecture used by CDEK and Russian Post:

- `src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php` renders the shared pickup selector block after rates with `requires_pickup_point=true`.
- `src/Checkout/WooCommerce/PickupMapCheckout.php` enqueues the shared pickup map/list assets and provides `pickup_family` presentation config.
- `assets/frontend/pickup-map/wdc-pickup-api.js` calls the shared REST endpoints.
- `assets/frontend/pickup-map/wdc-pickup-checkout.js` writes the selected point into common hidden checkout fields.
- `src/Pickup/Rest/PickupPointsRestController.php` provides point lists/search.
- `src/Pickup/Rest/CheckoutPickupPointRestController.php` saves the selected point into checkout session buckets.
- `src/Checkout/WooCommerce/CheckoutValidation.php` validates required pickup selections.
- `src/Checkout/WooCommerce/OrderShippingMetaPersister.php` saves selected pickup meta to the order.

No DPD-only frontend component or parallel checkout architecture is added.

## Endpoint

The shared read endpoint now supports DPD:

```text
GET /wp-json/wdc/v1/points?carrier=dpd&location_id=<location_id>&limit=<limit>
GET /wp-json/wdc/v1/points/search?carrier=dpd&location_id=<location_id>&q=<query>&limit=<limit>
```

`PickupPointsRestController` calls `DpdPickupPointService::get_points_for_location_id()`. The service resolves:

```text
location_id -> wdc_location_delivery_codes.dpd_city_id -> wdc_dpd_pickup_points.city_id
```

Only active points are returned. If the location has no DPD cityId mapping, the endpoint returns an empty list.
Before returning points to checkout, the DPD service deduplicates rows by `terminal_code`. If a `parcel_shop` and a
`terminal_self_delivery` row share the same code, checkout receives only the `parcel_shop`; a terminal row is returned
only when no parcel-shop row exists for that code.

DPD response fields follow the shared pickup shape:

- `id`;
- `carrier` / `carrier_key`: `dpd`;
- `service_key`: `dpd`;
- `pickup_family`: `dpd:pickup`;
- `point_code` / `terminal_code`;
- `point_type`;
- `point_type_label`;
- `point_title`;
- `point_name`;
- `address` / `point_address`;
- `city_name`;
- `region_name`;
- `lat`, `lng`;
- `work_time` / `schedule`;
- `source` / `dpd_source`;
- `snapshot`.

DPD `raw_json` is not included in checkout REST responses.

## Checkout UI

`DpdQuoteCarrier` now marks pickup rates with:

```text
requires_pickup_point=true
dpd_pickup_point_selection_enabled=true
```

The existing checkout selector renders the shared pickup map/list block only for the DPD pickup group (`dpd:pickup`).
DPD courier rates keep `requires_pickup_point=false` and do not show the block.

## Validation

For DPD pickup rates, `CheckoutValidation` requires a selected active terminal:

```text
Выберите пункт выдачи DPD.
```

The posted `terminal_code`/`point_code` is checked server-side through `DpdPickupPointService::get_point_by_terminal_code()`.
For duplicate local DPD rows this lookup also prefers the active `parcel_shop`.
Inactive or missing DPD points fail validation even if hidden frontend fields were posted.

DPD courier rates do not require a terminal. Non-DPD rates keep their existing CDEK/Russian Post validation behavior.

## Saving

`CheckoutPickupPointRestController` supports `carrier=dpd` on the shared save endpoint:

```text
POST /wp-json/wdc/v1/checkout/pickup-point
```

The controller resolves the posted `point_code`/`terminal_code` against the local active DPD table before saving the
selection into the checkout session bucket `dpd:pickup`.
Saved DPD checkout snapshots do not include `raw_json`.

Orders store the common pickup meta:

- `_wdc_pickup_point_code`;
- `_wdc_pickup_point_type`;
- `_wdc_pickup_carrier_key`;
- `_wdc_pickup_service_key`;
- `_wdc_pickup_family`;
- `_wdc_pickup_point_title`;
- `_wdc_pickup_point_address`;
- `_wdc_pickup_point_snapshot`.

Orders also store DPD aliases:

- `_wdc_dpd_pickup_terminal_code`;
- `_wdc_dpd_pickup_type`;
- `_wdc_dpd_pickup_name`;
- `_wdc_dpd_pickup_address`;
- `_wdc_dpd_pickup_city_name`;
- `_wdc_dpd_pickup_latitude`;
- `_wdc_dpd_pickup_longitude`;
- `_wdc_dpd_pickup_source`.

The existing pickup order display can render the common pickup summary. No DPD shipment adapter or shipment metabox action
is added in this stage.

## Pricing Boundary

The selected DPD pickup point currently affects only checkout validation and saved order metadata.

It does not affect DPD price calculation yet:

- no `terminalCode` is added to DPD tariff requests;
- runtime does not call `getServiceCostByParcels3`;
- runtime does not call `getServiceCost3`;
- `parcel[]` remains the packaging-place model.

Next step: test `getServiceCostByParcels3` with `parcel[]`, `pickup.terminalCode` and `delivery.terminalCode`, compare
prices with the DPD cabinet, and only then consider terminalCode-aware runtime pricing.
