# DPD Checkout Runtime

Version: 0.58.5

## Scope

DPD checkout runtime is a quote-only carrier. It produces WooCommerce checkout delivery rates through the existing `CarrierRegistry` / `CheckoutOrchestrator` / grouped tariff-selector architecture and reuses the DPD tariff foundation built on `calculator2/getServiceCostByParcels2`.

DPD remains disabled by default as the built-in delivery service `dpd`.

## Runtime Registration

- `src/Carriers/Runtime/DpdQuoteCarrier.php` implements `CarrierAdapterInterface`.
- `src/Core/Plugin.php` registers `DpdQuoteCarrier` in the checkout `CarrierRegistry`.
- DPD is not registered in `CarrierShipmentAdapterRegistry`.
- There is no DPD shipment adapter, shipment creation action, metabox button, status sync or label flow.

## Availability

DPD rates are returned only when all runtime prerequisites pass:

- the built-in delivery service `dpd` is enabled;
- the active DPD environment has both `clientNumber` and `clientKey`;
- sender DPD city ID is configured directly or resolved from the sender `location_id`;
- checkout has a receiver `location_id`;
- receiver `location_id` has `wdc_location_delivery_codes.dpd_city_id`;
- at least one DPD service code is enabled on the DPD `Тарифы` tab;
- `getServiceCostByParcels2` returns enabled tariff options with numeric positive `cost`;
- common delivery-service availability/rules do not reject the service.

If one of these conditions fails, DPD returns no rates and checkout continues without a fatal error.

## Request Payload

The runtime calls `DpdTariffCalculationService`, which builds the same business payload as the admin tariff calculator. Auth is still added centrally by `DpdSoapRequest` using the calculator `request` wrapper strategy.

The checkout stage sends only aggregate package data:

- `pickup.cityId`: sender DPD city ID from settings/override;
- `delivery.cityId`: receiver DPD city ID resolved from `wdc_location_delivery_codes`;
- `selfPickup`: always `true` in checkout runtime; DPD checkout shipment is calculated from a DPD terminal;
- `selfDelivery`: `true` for the pickup/terminal delivery entry, `false` for the courier delivery entry;
- `parcel[]`: packaging places, not cart items;
- `declaredValue`: package/order total, with DPD default declared value as fallback.

`DpdParcelBuilder` builds runtime `parcel[]` as packaging places:

- product quantities are expanded before packaging;
- items with any side over 49 cm are long items and become separate DPD parcels with quantity `1`;
- remaining regular items are packed into one common parcel by `single_box_fit()` when possible;
- if regular items do not fit the temporary 50x50x30 cm single-box model, stacked rows are used with a 45 cm row width threshold;
- package-level dimensions are used only when item dimensions are missing;
- if no reliable dimensions can be calculated, DPD default weight/dimensions from settings are used.

Regular cart items are not sent as individual parcels. Long items are the intentional exception because they cannot be packed into the temporary standard box. `unitLoad`, COD/НПП and fiscal receipts are intentionally not sent. Advanced multi-box/bin-packing is a separate future stage.

## Grouped Rate Mapping

DPD SOAP response options are normalized by `DpdTariffOptionNormalizer`.

`DpdQuoteCarrier` maps every enabled option with numeric positive `cost` to a tariff-selector candidate:

- candidates are marked with `tariff_selector_group=true`;
- courier calculation uses checkout group `dpd:courier`;
- terminal delivery calculation uses checkout group `dpd:pickup`;
- `selected_tariff_object` stores the DPD `serviceCode`;
- `selected_tariff_title` stores the configured DPD tariff title;
- `tariff_variants` are built by the shared WooCommerce tariff-selector flow;
- checkout labels follow the common format `{method title}, {tariff title} - {delivery days}`.

DPD uses separate DPD API requests for separate checkout delivery types. The same `serviceCode` can exist in both terminal and courier delivery responses, so the pickup group must not be reused for courier rates.

Examples:

- `DPD курьером, DPD Максимум - 5 дней`
- `DPD курьером, DPD Экспресс - 1 день`
- `DPD до пункта выдачи, DPD Максимум - 5 дней`

The old `dpd_runtime_method_title_prefix`, `dpd_runtime_pickup_mode` and `dpd_runtime_delivery_mode` settings are no longer rendered in admin and are not used by runtime titles or payload mode selection.

## Price And Delivery-Days Filtering

DPD tariff candidates are filtered inside the current delivery-type group after enabled service-code filtering and numeric-cost validation, before the `DeliveryQuote` is returned.

The filter compares only:

- final DPD candidate price;
- normalized delivery period min/max days.

The filter does not compare tariff names, service names, configured checkout titles or service-code semantics.

Rules:

- if two tariffs have the same known min/max delivery period, only the cheaper tariff survives;
- a tariff is hidden when another tariff has known min/max days that are no worse, a price that is no higher, and at least one strictly better value among min days, max days or price;
- tariffs with unknown min/max days are kept and are not removed by delivery-speed dominance.

Filtering is scoped to the current quote. Pickup candidates and courier candidates are filtered independently because DPD uses separate API requests for those delivery types.

`DeliveryQuote::raw_reference` stores non-customer-facing diagnostics:

- `dpd_filter_removed_count`;
- `dpd_filter_removed_tariffs`.

## Admin Settings

DPD `Основное` stores method titles:

- `dpd_runtime_pickup_title`, default `DPD до пункта выдачи`;
- `dpd_runtime_courier_title`, default `DPD курьером`.

DPD `Тарифы` stores runtime tariff controls:

- fixed known service codes with enabled checkboxes;
- custom checkout tariff titles;
- `dpd_runtime_enable_courier_rates`: checkbox `Использовать курьерские тарифы`.

Default enabled service codes are `ECN,CSM,MXO`. If all checkboxes are off, DPD returns no checkout rates. Unknown returned DPD service codes are skipped unless they become explicitly enabled in settings.

DPD `DPD Расчет` remains only for admin diagnostics and sender/default package settings.

## Pickup And Courier Entries

The checkout orchestrator builds DPD delivery-type entries from the built-in DPD service:

- pickup entry: created whenever the DPD delivery service is active;
- courier entry: created only when `dpd_runtime_enable_courier_rates` is enabled.

`DpdQuoteCarrier` reads `QuoteRequest::$customer_context['delivery_type']` and defaults to pickup when it is absent.

Pickup/terminal delivery is calculation-only in the current runtime:

- request payload sends `selfPickup=true`;
- request payload sends `selfDelivery=true`;
- returned rates use `DeliveryType::PICKUP`;
- `requires_pickup_point=false`;
- meta includes `dpd_pickup_points_not_implemented=true`.

This keeps checkout from requiring a DPD pickup point before DPD parcel shops, map and selection are implemented.

Courier delivery:

- is skipped with reason `courier_rates_disabled` and no SOAP call when `dpd_runtime_enable_courier_rates` is disabled;
- request payload sends `selfPickup=true`;
- request payload sends `selfDelivery=false`;
- returned rates use `DeliveryType::COURIER`;
- `requires_courier_address=true`;
- `requires_pickup_point=false`.

## Quote Id

DPD `quote_id` is diagnostic and includes:

- selected receiver `location_id`;
- sender city ID;
- receiver city ID when available;
- weight;
- length, width and height;
- declared value;
- `delivery_type`;
- fixed `selfPickup=true`;
- delivery-type-derived `selfDelivery`;
- courier-rates enablement;
- enabled service codes;
- calculation date;
- active DPD environment.

## Cache And Parcel Diagnostics

The generic checkout quote cache key includes selected receiver location, package dimensions and declared value in addition to the existing carrier/service/country/city/weight/order-total dimensions. DPD `quote_id` diagnostics also include the normalized parcel signature, parcel count, long-item parcel count, regular item count, total weight, dimensions, declared value, `parcel_dimensions`, `box_limit` and `package_builder_source`.

## Out Of Scope

The 0.58.5 stage does not implement:

- DPD pickup points;
- parcel shop selection;
- parcel shop map;
- postamats;
- shipment creation;
- cancellation;
- statuses/events;
- labels;
- COD/НПП;
- `unitLoad`;
- fiscal receipts;
- complex multi-box/bin packing;
- new global carrier branching.

## Tests

Primary smoke test:

```bash
php tests/dpd/run-dpd-checkout-runtime-smoke.php
```

Regression tests cover tariff calculation, DPD foundation, delivery services, shipment adapter registry, CDEK runtime/order flows and Russian Post domestic rates.
