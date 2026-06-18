# DPD Checkout Runtime

Version: 0.64.0

## Scope

DPD checkout runtime is a quote-only carrier. It produces WooCommerce checkout delivery rates through the existing
`CarrierRegistry` / `CheckoutOrchestrator` / grouped tariff-selector architecture and uses
`calculator2/getServiceCostByParcels3` with terminalCode.

DPD remains disabled by default as the built-in delivery service `dpd`.

0.62.0 update: DPD pickup and courier checkout pricing now use Parcels3. Pickup rates send sender
`pickup.terminalCode` and receiver `delivery.terminalCode`; courier rates send sender `pickup.terminalCode` and omit
`delivery.terminalCode`.

0.63.0 update: DPD shipment preparation is available only as a manual dry-run preview from the order `Отправления` block.
Checkout runtime pricing is unchanged. Checkout `parcel[]` remains pricing diagnostics/input only and is not reused for
shipment creation.

0.64.0 update: the WooCommerce order admin `Калькулятор доставок` recalculation flow now reuses this same DPD checkout
runtime. Order preview goes through `CheckoutOrchestrator` and `DpdQuoteCarrier`; admin DPD pickup selection passes the
selected receiver `terminal_code` into a fresh preview before save, so DPD quote IDs/cache keys vary by selected point just
like checkout. No separate DPD order calculator was added.

## Runtime Registration

- `src/Carriers/Runtime/DpdQuoteCarrier.php` implements `CarrierAdapterInterface`.
- `src/Core/Plugin.php` registers `DpdQuoteCarrier` in the checkout `CarrierRegistry`.
- `src/Orders/Application/OrderDeliveryRecalculationService.php` reuses the same `CheckoutOrchestrator` for order-admin recalculation.
- `src/Core/Plugin.php` registers `DpdShipmentAdapter` in `CarrierShipmentAdapterRegistry` only so DPD orders can open the manual preparation modal and dry-run preview.
- `ShipmentCreationService` live-create adapters remain Russian Post and CDEK only.
- There is no live DPD shipment creation, auto creation, status sync, cancellation or label flow.

## Availability

DPD rates are returned only when all runtime prerequisites pass:

- the built-in delivery service `dpd` is enabled;
- the active DPD environment has both `clientNumber` and `clientKey`;
- sender DPD city ID is configured directly or resolved from the sender `location_id`;
- checkout has a receiver `location_id`;
- receiver `location_id` has `wdc_location_delivery_codes.dpd_city_id`;
- at least one DPD service code is enabled on the DPD `Тарифы` tab;
- `getServiceCostByParcels3` returns enabled tariff options with numeric positive `cost`;
- active DPD `parcel_shop` rows exist for sender terminalCode and, for pickup delivery, receiver terminalCode;
- common delivery-service availability/rules do not reject the service.

If one of these conditions fails, DPD returns no rates and checkout continues without a fatal error.

## Request Payload

The runtime calls `DpdTariffCalculationService`, which builds the same business payload as the admin tariff calculator. Auth is still added centrally by `DpdSoapRequest` using the calculator `request` wrapper strategy.

The checkout stage sends only aggregate package data:

- `pickup.cityId`: sender DPD city ID from settings/override;
- `pickup.terminalCode`: active sender-city DPD `parcel_shop`;
- `delivery.cityId`: receiver DPD city ID resolved from `wdc_location_delivery_codes`;
- `delivery.terminalCode`: active receiver-city DPD `parcel_shop` for pickup delivery only;
- `selfPickup`: always `true` in checkout runtime; DPD checkout shipment is calculated from a DPD terminal;
- `selfDelivery`: `true` for the pickup/terminal delivery entry, `false` for the courier delivery entry;
- `parcel[]`: packaging places, not cart items;
- `declaredValue`: package/order total, with DPD default declared value as fallback.

Current pricing mode is terminalCode-aware:

- pickup group: `selfPickup=true`, `selfDelivery=true`, sender `pickup.terminalCode`, receiver `delivery.terminalCode`;
- courier group: `selfPickup=true`, `selfDelivery=false`, sender `pickup.terminalCode`; `delivery.terminalCode` is not sent.

`DpdParcelBuilder` builds runtime `parcel[]` as packaging places with fast deterministic 3D shelf/bin packing:

- product quantities are expanded before packaging;
- items with any side over 49 cm are long items and become separate DPD parcels with quantity `1`;
- regular small items with volume <=50 cm3 are aggregated into one synthetic 3:1:1 volume block;
- identical item groups can become one synthetic `identical_grid` block;
- regular units are packed into `box_50_50_30` or `box_40_40_40`;
- actual occupied dimensions are sent to DPD, not the full box format;
- one box is attempted first, then bounded two-box distribution;
- if regular items do not fit one or two boxes, stacked rows are used with a 45 cm row width threshold;
- packaging weight is added to each parcel from the existing admin packaging-weight tiers;
- package-level dimensions are used only when item dimensions are missing;
- if no reliable dimensions can be calculated, DPD default weight/dimensions from settings are used.

Regular cart items are not sent as individual parcels. Long items and actual two-box splits are the intentional exceptions. `unitLoad`, COD/НПП and fiscal receipts are intentionally not sent. This is a bounded practical packer, not full optimal bin packing.

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

DPD `DPD Расчет` stores sender/default package settings and can run a test calculation through the same Parcels3 runtime
service. The old terminalCode diagnostic comparison UI was removed.

## Pickup And Courier Entries

The checkout orchestrator builds DPD delivery-type entries from the built-in DPD service:

- pickup entry: created whenever the DPD delivery service is active;
- courier entry: created only when `dpd_runtime_enable_courier_rates` is enabled.

`DpdQuoteCarrier` reads `QuoteRequest::$customer_context['delivery_type']` and defaults to pickup when it is absent.

Pickup/terminal delivery now requires local DPD pickup-point selection in checkout:

- request payload sends `selfPickup=true`;
- request payload sends `selfDelivery=true`;
- returned rates use `DeliveryType::PICKUP`;
- `requires_pickup_point=true`;
- meta includes `dpd_pickup_point_selection_enabled=true`;
- the shared checkout pickup UI loads active local DPD points from `wdc_dpd_pickup_points`;
- the selected `terminal_code` is saved to checkout/session/order meta.

Before a buyer selects a DPD point, runtime auto-selects a receiver-city `parcel_shop` terminalCode so pickup prices can
appear immediately. After the buyer selects or changes a DPD point, the checkout frontend saves the selected
`terminal_code`, triggers `update_checkout`, and runtime uses the selected terminalCode instead of the auto-selected one.

Courier delivery:

- is skipped with reason `courier_rates_disabled` and no SOAP call when `dpd_runtime_enable_courier_rates` is disabled;
- request payload sends `selfPickup=true`;
- request payload sends sender `pickup.terminalCode`;
- request payload sends `selfDelivery=false`;
- request payload does not send `delivery.terminalCode`;
- returned rates use `DeliveryType::COURIER`;
- `requires_courier_address=true`;
- `requires_pickup_point=false`.

## TerminalCode Selection

Sender terminalCode is always selected from active sender-city `parcel_shop` rows. Receiver terminalCode for pickup
delivery is selected from active receiver-city `parcel_shop` rows until the buyer picks a concrete DPD point.

Selection rules:

- standalone `terminal_self_delivery` rows are not used as runtime terminals;
- `parcel_shop` is preferred;
- `parcel_shop` without a same-code `terminal_self_delivery` duplicate is preferred;
- if every parcel shop is duplicated, runtime falls back to the first deterministic `parcel_shop`;
- if no `parcel_shop` exists, DPD returns no quote.

## Quote Id

DPD `quote_id` is diagnostic and includes:

- selected receiver `location_id`;
- sender city ID;
- receiver city ID when available;
- sender pickup terminalCode;
- receiver delivery terminalCode for pickup delivery;
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

The generic checkout quote cache key includes selected receiver location, selected DPD terminalCode, package dimensions
and declared value in addition to the existing carrier/service/country/city/weight/order-total dimensions. DPD
`quote_id` diagnostics also include the normalized parcel signature, terminalCode values, parcel count, long-item parcel
count, regular item count, total weight, dimensions, declared value, `parcel_dimensions`, goods/packaging/final weights,
tried/selected box formats, small-item and identical-grid diagnostics, `box_limit`, packing limit reason and
`package_builder_source`.

## Out Of Scope

The 0.62.0 terminalCode runtime pricing stage still does not implement:
- DPD-specific shipment creation;
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
