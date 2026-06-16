# DPD Checkout Runtime

Version: 0.58.0

## Scope

DPD checkout runtime is enabled as a quote-only carrier. It produces WooCommerce checkout delivery rates through the existing `CarrierRegistry` / `CheckoutOrchestrator` architecture and reuses the DPD tariff foundation built on `calculator2/getServiceCostByParcels3`.

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
- `getServiceCostByParcels3` returns tariff options with numeric positive `cost`;
- common delivery-service availability/rules do not reject the service.

If one of these conditions fails, DPD returns no rates and checkout continues without a fatal error.

## Request Payload

The runtime calls `DpdTariffCalculationService`, which builds the same business payload as the admin tariff calculator. Auth is still added centrally by `DpdSoapRequest` using the calculator `request` wrapper strategy.

The first checkout stage sends only aggregate package data:

- `pickup.cityId`: sender DPD city ID from settings/override;
- `delivery.cityId`: receiver DPD city ID resolved from `wdc_location_delivery_codes`;
- `selfPickup`: `false` by default, or `true` when sender-side runtime pickup mode is `terminal`;
- `selfDelivery`: `false`;
- `parcel[]`: total package weight and dimensions, with DPD defaults as fallback;
- `declaredValue`: package/order total, with DPD default declared value as fallback;
- optional service code is not forced by checkout; returned options are filtered after normalization.

Cart line composition, `unitLoad`, COD/НПП and fiscal receipts are intentionally not sent.

## Rate Mapping

DPD SOAP response options are normalized by `DpdTariffOptionNormalizer`.

`DpdQuoteCarrier` maps every allowed option with numeric positive `cost` to a courier `DeliveryRate`:

- service code is stored in rate meta as `dpd_service_code`;
- service name is stored in rate meta as `dpd_service_name`;
- checkout title is `{method_title_prefix} {service_name}` when service name exists;
- if service name is absent, checkout title is `{method_title_prefix} {service_code}`;
- `preserve_rate_title` keeps distinct labels such as `DPD Максимум` and `DPD Экспресс`;
- common delivery-service rounding, minimum price and rules are applied by the existing checkout orchestrator.

## Allowed Service Codes

`DPD Расчет` stores `allowed_service_codes`.

- Default: `MAX,NDY`.
- Empty value: show every returned service option with numeric positive cost.
- Non-empty value: compare normalized uppercase DPD service codes and skip everything else.

Live tariff validation confirmed the expected DPD services:

- `MAX` / `DPD Максимум`;
- `NDY` / `DPD Экспресс`.

## Cache

The generic checkout quote cache key now includes selected receiver location, package dimensions and declared value in addition to the existing carrier/service/country/city/weight/order-total dimensions. This keeps DPD quotes separated by the receiver and parcel parameters that affect `getServiceCostByParcels3`.

## Out Of Scope

The 0.58.0 stage does not implement:

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

Regression tests cover tariff calculation, DPD foundation/geography, delivery services, shipment adapter registry, CDEK runtime/order flows and Russian Post domestic rates.
