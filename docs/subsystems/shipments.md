# Shipments

Version: 0.133.4

Shipment code lives under `src/Shipments` and `src/Carriers/*/Shipment*` where carrier APIs require it.

Carrier-specific shipment implementations currently exist for CDEK, DPD, Russian Post, Yandex Delivery, and Jet Logistic. Shared behavior is documented in [shipment-framework.md](../architecture/shipment-framework.md). Use that document and [new-carrier-guide.md](../development/new-carrier-guide.md) before changing carrier shipment code.

## CDEK EAEU Shipments

CDEK domestic and EAEU shipments share the existing `CdekShipmentAdapter`, request builder, persistence mapper, status service, barcode print service, document provider, and modal extension. Shipment Framework contracts, registries, lifecycle endpoints, status polling, manual attach, cancel, local remove, actual cost, and barcode PDF behavior remain shared.

Russian CDEK courier preparation keeps the DaData/FIAS plus CDEK location flow. For `AM`, `BY`, `KZ`, and `KG`, courier preparation uses the resolved CDEK city code from calculation or rate metadata plus the raw WooCommerce shipping address and postcode; it does not require Russian DaData or FIAS. International pickup uses the same `/v2/orders` endpoint and the selected CDEK delivery point.

The CDEK modal extension owns the `cdek_recipient_document` field. It is server-rendered hidden and cleared for `RU`, visible and optional for `AM`, `BY`, `KZ`, and `KG`, then re-synchronized by the CDEK admin JS hook during form initialization and later country changes; this visibility does not depend on preview responses. When present it maps to `recipient.tin` for `KZ`/`KG` and to `recipient.passport_number` for `AM`/`BY`. The value is sanitized, kept only in the current admin page memory, redacted from diagnostics, and never stored in order meta, shipment persistence, snapshots, transients, options, notes, analytics, or status payloads.

## Canonical Requirements

- Shipment creation uses the common adapter/mapper/repository flow.
- Manual admin shipment creation supports multiple places and item allocation.
- Carrier documents are exposed through provider-owned document actions and downloaded through the protected document service.
- Carrier status updates map into universal delivery statuses and may update WooCommerce order status through configured mapping.
- Jet Logistic status messages are free text. Carrier-owned mappings convert known text into existing universal statuses; unknown messages are observed and saved for admin mapping but do not reset the current universal status. Jet stores only the latest status fields and up to five deduplicated recent events, not the full API log.
- Order shipment data should be compact but sufficient: carrier key, service key/title, delivery type, places, request/response snapshots when relevant, tracking/external IDs, status, canonical actual shipment cost when available, and timestamps.

## Actual Shipment Cost

`actual_cost_kopecks` is the canonical actual shipment cost owner for every carrier. It is an integer amount in kopecks; companion fields are `actual_cost_currency`, `actual_cost_source`, `actual_cost_source_detail`, and `actual_cost_updated_at`.

Supported source values include `carrier_api`, `carrier_status`, `carrier_reconciliation`, and `manual`. Manual cost edits in the shared shipment card set `actual_cost_source=manual`, but they are a fallback/correction value, not a lock: a later strictly positive carrier/API update overwrites any existing source. Missing, null, zero, negative, or invalid carrier amounts must not remove or overwrite an existing actual cost. Clearing the actual cost removes canonical actual-cost fields, allowing a later carrier update to populate them again.

## Shipment Cost Analytics

The overview page (`admin.php?page=wdc-platform`) includes a read-only shipment cost analytics section. One analytics row represents at most one selected created shipment for an order. The plan price is order-level data and belongs to the delivery service selected during calculation/checkout, so other shipment records on the same order are excluded from comparison. A shipment qualifies when the selected `_wdc_shipments` record has a real carrier identifier such as `tracking_number`, `barcode`, `external_id`, `carrier_shipment_id`, `shipment_id`, `cdek_number`, `dpd_order_number`, `yandex_request_id`, or `request_id`; drafts, previews, failed records without identifiers, and removed records are excluded.

Planned cost comes from the same base API cost contract used by the order delivery calculator through `ShipmentBaseApiCostResolver`. It means carrier API cost before delivery rules, markup, discounts, or customer-paid shipping total. Actual cost comes only from canonical `actual_cost_*` shipment fields.

Carrier filters are registry-driven through `CarrierRegistry::all()`. Adding a carrier to the composition root makes it available to the analytics filter and rows without hardcoded carrier arrays. The analytics layer does not call carrier APIs and does not write order meta.

The threshold policy is owned by `ShipmentCostThresholdPolicy`: actual cost is within plan when `actual_cost_kopecks * 100 <= base_api_cost_kopecks * 103`; otherwise it is over threshold. Comparisons use integer kopecks. The summary reports all filtered shipments, rows with/without actual cost, planned and actual totals, comparable difference total, arithmetic average percentage over comparable rows, and count/share of shipments over the 3% threshold.

Shipment cost analytics uses a materialized read-model table named `{$wpdb->prefix}wdc_shipment_cost_analytics`. Canonical data remains in WooCommerce order metadata: `_wdc_delivery_calculation_data` and `_wdc_shipments`. The analytics row is rebuilt synchronously for one order after delivery calculation changes, shipment save/delete, actual-cost changes, and order deletion/restore hooks. The overview page queries only the read-model table; it does not scan WooCommerce orders or call carrier APIs.

There is no historical analytics import in this version because deployments start without old orders. If an order is not eligible for analytics, its read-model row is deleted.
