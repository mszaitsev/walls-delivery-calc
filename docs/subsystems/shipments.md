# Shipments

Version: 0.125.2

Shipment code lives under `src/Shipments` and `src/Carriers/*/Shipment*` where carrier APIs require it.

Carrier-specific shipment implementations currently exist for CDEK, DPD, Russian Post, and Yandex Delivery. Shared behavior is documented in [shipment-framework.md](../architecture/shipment-framework.md). Use that document and [new-carrier-guide.md](../development/new-carrier-guide.md) before changing carrier shipment code.

## Canonical Requirements

- Shipment creation uses the common adapter/mapper/repository flow.
- Manual admin shipment creation supports multiple places and item allocation.
- Carrier documents are exposed through provider-owned document actions and downloaded through the protected document service.
- Carrier status updates map into universal delivery statuses and may update WooCommerce order status through configured mapping.
- Order shipment data should be compact but sufficient: carrier key, service key/title, delivery type, places, request/response snapshots when relevant, tracking/external IDs, status, canonical actual shipment cost when available, and timestamps.

## Actual Shipment Cost

`actual_cost_kopecks` is the canonical actual shipment cost owner for every carrier. It is an integer amount in kopecks; companion fields are `actual_cost_currency`, `actual_cost_source`, `actual_cost_source_detail`, and `actual_cost_updated_at`.

Supported source values include `carrier_api`, `carrier_status`, `carrier_reconciliation`, `manual`, and `legacy_import`. Manual cost edits in the shared shipment card set `actual_cost_source=manual`, but they are a fallback/correction value, not a lock: a later strictly positive carrier/API update overwrites any existing source. Missing, null, zero, negative, or invalid carrier amounts must not remove or overwrite an existing actual cost. Clearing the actual cost removes canonical actual-cost fields, allowing a later carrier update to populate them again.

Russian Post legacy fields (`russian_post_actual_cost_kopecks`, `russian_post_actual_cost_rub`, `russian_post_actual_cost_source`) remain readable through the common resolver. New writes should populate canonical fields through `ShipmentActualCostService`; legacy fields are compatibility data, not the analytics owner. Explicit admin clear removes legacy actual-cost fields too, otherwise the resolver would immediately display the old legacy value again.
