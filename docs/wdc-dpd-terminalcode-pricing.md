# WDC DPD TerminalCode Runtime Pricing

Version: 0.62.0.

DPD terminalCode pricing is now part of checkout runtime. The previous 0.61.0 admin-only diagnostic matched the DPD
personal cabinet, so the temporary terminalCode diagnostic UI/classes were removed.

## Calculator Method

Runtime uses `calculator2/getServiceCostByParcels3` through `DpdApiClient::getServiceCostByParcels3()`.

`DpdTerminalCodeTariffRequestBuilder` builds a business-only payload; `DpdSoapRequest` adds `request.auth` centrally.
The payload still uses `parcel[]` as packaging places and does not send `extraService`, `unitLoad`, COD/NPP or fiscal
receipt data.

Pickup payload:

- `pickup.cityId`;
- `pickup.terminalCode`;
- `delivery.cityId`;
- `delivery.terminalCode`;
- `selfPickup=true`;
- `selfDelivery=true`;
- `declaredValue`;
- `parcel[]`;
- optional `serviceCode` / `pickupDate`.

Courier payload:

- `pickup.cityId`;
- `pickup.terminalCode`;
- `delivery.cityId`;
- `selfPickup=true`;
- `selfDelivery=false`;
- `declaredValue`;
- `parcel[]`.

Courier payload intentionally omits `delivery.terminalCode` because the delivery leg is courier-to-door.

## Sender TerminalCode

The sender always drops parcels at a DPD pickup point. Runtime always selects a sender `pickup.terminalCode` from active
local `parcel_shop` rows in the sender DPD city.

Selection order:

- prefer active `parcel_shop`;
- prefer a `parcel_shop` without a same-city active `terminal_self_delivery` duplicate for the same `terminal_code`;
- if no unambiguous parcel shop exists, fall back to the first deterministic active `parcel_shop`;
- never select standalone `terminal_self_delivery`;
- if no sender `parcel_shop` exists, DPD quote returns empty.

## Receiver TerminalCode

For pickup delivery, `delivery.terminalCode` is required.

Before the buyer selects a point, runtime auto-selects an active receiver-city `parcel_shop` using the same preference
rules as sender terminal selection. This lets DPD pickup rates appear immediately after a checkout city/location is
chosen.

After the buyer selects a DPD point, checkout saves the selected `terminal_code` and the frontend triggers
`update_checkout`. Runtime validates that the selected code belongs to an active `parcel_shop` in the receiver DPD city,
then uses it instead of the auto-selected code. A standalone `terminal_self_delivery` code is rejected for runtime
pricing.

## Cache And Quote Id

DPD `quote_id` includes city IDs, selected terminalCode values, parcel signature, declared value, enabled services,
environment and calculation date.

The generic checkout quote cache key also includes `dpd_selected_terminal_code`, so changing the selected DPD pickup
point recalculates rates instead of reusing a stale quote.

## Removed Diagnostic UI

Removed from `DPD Расчет`:

- terminalCode diagnostic form;
- manual diagnostic terminal selectors;
- Parcels2/Parcels3 comparison result block;
- diagnostic service/result/request classes.

Kept:

- low-level `getServiceCostByParcels3` wrapper;
- reusable runtime request builder;
- local DPD pickup point import/storage/admin diagnostics.

## Boundaries

Still out of scope:

- DPD shipment adapter/metabox;
- DPD shipment creation;
- labels;
- COD/NPP;
- unitLoad;
- CDEK runtime changes;
- Russian Post runtime changes.
