# Shipment Framework

Version: 0.130.4

The Shipment Framework lets carriers share admin creation, persistence, lifecycle, documents, modal UI, status presentation, polling, and regression coverage. It is not one linear pipeline; each runtime flow has its own owner.

## Architecture Map

| Area | Owner | Extension point | Tests |
| --- | --- | --- | --- |
| Adapter contract | `CarrierShipmentAdapterInterface` | one adapter per carrier | `framework.adapter-registry` |
| Adapter lookup | `CarrierShipmentAdapterRegistry` | register in `Plugin.php` | `framework.adapter-registry` |
| Creation orchestration | `ShipmentCreationService` | no carrier switches | `framework.persistence-mappers` |
| Persistence | `CarrierShipmentPersistenceMapperInterface` + `OrderShipmentRepository` | one mapper per carrier | `framework.persistence-mappers` |
| Allocation | `ShipmentAllocationBuilder` | shared | `framework.allocation` |
| Lifecycle continuation | `CarrierShipmentLifecycleContinuationInterface`, `ShipmentLifecycleResult` | optional per carrier | `framework.lifecycle-contract` |
| Status | adapter `status_payload()`, status services, autosync services | per-carrier mapping, shared payload | `framework.status`, carrier/status groups |
| Actual cost | `ShipmentActualCostResolver`, `ShipmentActualCostService`, `ShipmentActualCostComparisonService`, `ShipmentBaseApiCostResolver` | shared canonical fields, optional carrier extraction | `framework.actual-cost-presentation` |
| Documents | provider registry, document providers, download service | optional provider per carrier | `framework.document-actions` |
| Modal | `ShipmentModalExtensionRegistry` | optional extension per carrier | `framework.modal-extensions` |
| Admin AJAX | controllers in `src/Shipments/Admin/Ajax` | shared endpoints | `framework.admin-ajax` |
| Admin UI | `OrderShipmentsMetabox`, generic JS, carrier JS extensions | shared shell + carrier hooks | `framework.admin-js-structure` |
| Regression | manifest runner | group entries | full profile |

## Create Flow

1. Admin modal builds a `ShipmentCreateRequest`.
2. Preview calls `ShipmentCreationService::safe_preview()`.
3. Create calls `ShipmentCreationService::create()`.
4. Service resolves adapter through `CarrierShipmentAdapterRegistry`.
5. Service resolves mapper from registered `CarrierShipmentPersistenceMapperInterface` instances.
6. Adapter creates shipment or returns a failed `ShipmentCreateResult`.
7. Mapper builds carrier-specific stored fields.
8. `OrderShipmentRepository` saves common envelope plus mapper fields.
9. Optional lifecycle continuation returns `ShipmentLifecycleResult` to shared JS polling/continuation code.

Forbidden in create flow: carrier switch in `ShipmentCreationService`, persistence in adapter, persistence fallback for missing mapper.

## Status Flow

1. Admin status AJAX resolves order and carrier key.
2. Adapter or status service loads current carrier status.
3. Carrier status mapping converts external status into universal `DeliveryStatus`.
4. Status payload includes carrier key, button flags, status fields, tracking presentation, and actual-cost presentation.
5. Generic JS renders shared rows and dispatches carrier hooks.
6. Carrier JS extension renders carrier-only rows.

Autosync uses shared status services plus carrier-specific sync services where needed.

## Actual Cost Flow

Actual shipment cost is the real carrier cost for a specific shipment. It is separate from base API cost saved in delivery calculation data and from the customer shipping price in WooCommerce totals.

The canonical storage fields are `actual_cost_kopecks`, `actual_cost_currency`, `actual_cost_source`, `actual_cost_source_detail`, and `actual_cost_updated_at`. Carrier-specific code may extract an amount from carrier payloads, but common application code owns merge policy and storage keys. A strictly positive carrier amount overwrites any existing actual cost, including `actual_cost_source=manual`; missing, null, zero, negative, or invalid carrier amounts leave the current value untouched. The shared metabox AJAX controller owns manual set and clear for all carriers, and clear removes actual cost regardless of source.

Shipment cost analytics reads a materialized read model from `{$wpdb->prefix}wdc_shipment_cost_analytics`: order ID, selected carrier key, selected service key, shipment identifier, order creation time, `actual_cost_kopecks`, `actual_cost_currency`, `actual_cost_source`, `actual_cost_updated_at`, base API cost, difference, percentage in basis points, and threshold status. Canonical data remains in WooCommerce order/shipment metadata. Because base API cost is order-level data for the selected delivery service, analytics compares at most one created shipment per order.

## Cost Analytics Flow

1. `ShipmentCostAnalyticsFilter` normalizes GET filters: period, custom dates, carrier, actual-cost mode, order search, sort, page, and page size.
2. `ShipmentCostAnalyticsRecordBuilder` reads one WooCommerce order, uses `OrderAnalyticsShipmentSelector` to select exactly one matching created shipment, resolves base API cost through `ShipmentBaseApiCostResolver`, and computes difference/threshold fields.
3. `ShipmentCostAnalyticsIndexer` rebuilds one order's read-model row after canonical mutations. If the order is not eligible, it deletes the row.
4. `ShipmentCostAnalyticsRepository` owns atomic upsert/delete in `wdc_shipment_cost_analytics`.
5. `ShipmentCostAnalyticsQuery` filters, counts, sorts, paginates, and aggregates with SQL against the read-model table only.
6. `ShipmentCostThresholdPolicy` owns the 3% threshold and compares integer kopecks without float arithmetic.
7. `ShipmentCostAnalyticsAdminSection` renders filters, table, legend, pagination, empty states, and summary on the existing overview page.

The analytics page is read-only: it must not call carrier APIs, recalculate delivery, update order meta, save shipment records, or scan WooCommerce orders. The indexer writes only the materialized analytics table. Carrier titles and filter options come from `CarrierRegistry`; analytics code must not branch by concrete carrier key. No historical analytics import is implemented for this deployment model.

## Document Flow

Provider actions are the single canonical source of truth:

- `CarrierShipmentDocumentProviderInterface::actions()` owns available actions, visibility, action keys, labels, action type, and action metadata.
- `ShipmentDocumentProviderRegistry` resolves the provider by carrier key.
- `ShipmentAdminCarrierUiPayloadBuilder` and `OrderShipmentsMetabox` call the registry, filter visible provider actions, normalize `ShipmentDocumentAction::to_array()`, add protected `download_url`, and include the result in the canonical `document_actions` payload.
- Generic JS maps `document_actions` into `documentActions` state and toggles generic document buttons.
- `ShipmentDocumentDownloadService` builds protected `download_url` values.
- `ShipmentDocumentDownloadService::admin_post_download()` owns capability, nonce, order existence, carrier/action sanitization, and HTTP response authorization.
- `ShipmentDocumentDownloadService::download_for_order()` re-checks persisted shipment existence and verifies the requested action is still visible in provider `actions()`.
- Carrier document provider `download()` owns binary bytes.

Adapters must not generate document action metadata, add `document_actions` to status payloads, generate download URLs, or stream binary documents.

## UI Flow

1. `OrderShipmentsMetabox` renders carrier tabs, modal shell, and initial payload.
2. Admin AJAX controllers return normalized payloads.
3. Generic JS modules own state, preview, status rendering, polling, allocation, picker, and event wiring.
4. Carrier JS extensions own carrier-only selectors and presentation.
5. Generic JS must not contain carrier selectors or branches.

## Test Flow

1. Add focused smoke for the changed contract.
2. Register shipment/carrier critical smokes in `tests/shipments/regression/shipment-regression-manifest.php`.
3. Run targeted smoke.
4. Run framework group.
5. Run full default regression profile.

## Canonical Payloads

Document actions use:

- provider action source for UI payload: `CarrierShipmentDocumentProviderInterface::actions()`;
- AJAX/status payload key: `document_actions`;
- JS normalized state: `documentActions`.

There is no production compatibility requirement for older internal wire aliases.

## New Carrier Expectations

Adding a new carrier must not require:

- editing `ShipmentCreationService` logic;
- adding a carrier switch in `OrderShipmentsMetabox`;
- adding carrier branches in generic JS;
- saving carrier data outside a persistence mapper;
- adding document download code outside the document provider/download service;
- adding a separate lifecycle endpoint outside the common lifecycle continuation contract.
- adding carrier-prefixed actual cost fields or manual actual-cost UI.

Registration in `Plugin.php` is expected because it is the composition root.

Jet Logistic participates in the framework without API shipment creation. The adapter supports presentation, manual attach through the shared AJAX endpoint, status payload/update, local remove, tracking identifier, and shared autosync. `preview/create` and carrier cancellation return unsupported results. Jet persistence for manual attach/status/local remove is owned by its shipment service, not by generic creation flow or a fake persistence mapper.
