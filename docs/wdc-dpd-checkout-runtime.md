# DPD Checkout Runtime

Version: 0.58.1

## Scope

DPD checkout runtime is a quote-only carrier. It produces WooCommerce checkout delivery rates through the existing `CarrierRegistry` / `CheckoutOrchestrator` / grouped tariff-selector architecture and reuses the DPD tariff foundation built on `calculator2/getServiceCostByParcels3`.

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
- `getServiceCostByParcels3` returns enabled tariff options with numeric positive `cost`;
- common delivery-service availability/rules do not reject the service.

If one of these conditions fails, DPD returns no rates and checkout continues without a fatal error.

## Request Payload

The runtime calls `DpdTariffCalculationService`, which builds the same business payload as the admin tariff calculator. Auth is still added centrally by `DpdSoapRequest` using the calculator `request` wrapper strategy.

The checkout stage sends only aggregate package data:

- `pickup.cityId`: sender DPD city ID from settings/override;
- `delivery.cityId`: receiver DPD city ID resolved from `wdc_location_delivery_codes`;
- `selfPickup`: `false` for pickup mode `door`, `true` for pickup mode `terminal`;
- `selfDelivery`: `false` for delivery mode `door`, `true` for delivery mode `terminal`;
- `parcel[]`: total package weight and dimensions, with DPD defaults as fallback;
- `declaredValue`: package/order total, with DPD default declared value as fallback.

Cart line composition, `unitLoad`, COD/НПП and fiscal receipts are intentionally not sent.

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

Examples:

- `DPD курьером, DPD Максимум - 5 дней`
- `DPD курьером, DPD Экспресс - 1 день`
- `DPD до пункта выдачи, DPD Максимум - 5 дней`

The old `dpd_runtime_method_title_prefix` setting is no longer rendered in admin and is not used by runtime titles.

## Admin Settings

DPD `Основное` stores method titles:

- `dpd_runtime_pickup_title`, default `DPD до пункта выдачи`;
- `dpd_runtime_courier_title`, default `DPD курьером`.

DPD `Тарифы` stores runtime tariff controls:

- fixed known service codes with enabled checkboxes;
- custom checkout tariff titles;
- `dpd_runtime_pickup_mode`: `door` or `terminal`;
- `dpd_runtime_delivery_mode`: `door` or `terminal`.

Default enabled service codes are `ECN,CSM,MXO`. If all checkboxes are off, DPD returns no checkout rates. Unknown returned DPD service codes are skipped unless they become explicitly enabled in settings.

DPD `DPD Расчет` remains only for admin diagnostics and sender/default package settings.

## Terminal Delivery

`runtime_delivery_mode=terminal` is calculation-only in 0.58.1:

- request payload sends `selfDelivery=true`;
- returned rates use `DeliveryType::PICKUP`;
- `requires_pickup_point=false`;
- meta includes `dpd_pickup_points_not_implemented=true`.

This keeps checkout from requiring a DPD pickup point before DPD parcel shops, map and selection are implemented.

## Quote Id

DPD `quote_id` is diagnostic and includes:

- selected receiver `location_id`;
- sender city ID;
- receiver city ID when available;
- weight;
- length, width and height;
- declared value;
- pickup and delivery modes;
- enabled service codes;
- calculation date;
- active DPD environment.

## Cache

The generic checkout quote cache key includes selected receiver location, package dimensions and declared value in addition to the existing carrier/service/country/city/weight/order-total dimensions. This keeps DPD quotes separated by the receiver and parcel parameters that affect `getServiceCostByParcels3`.

## Out Of Scope

The 0.58.1 stage does not implement:

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
- new global carrier branching.

## Tests

Primary smoke test:

```bash
php tests/dpd/run-dpd-checkout-runtime-smoke.php
```

Regression tests cover tariff calculation, DPD foundation, delivery services, shipment adapter registry, CDEK runtime/order flows and Russian Post domestic rates.
