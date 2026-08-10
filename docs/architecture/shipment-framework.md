# Shipment Framework

Version 0.135.11 keeps the PEK read-only verification results, the 0.135.3 generic lock cache fix, the 0.135.4 diagnostics, the 0.135.5 preregistration services-object fix, the 0.135.7 cancellation reconciliation flow, the 0.135.8 cancelled-status integer `-3` compatibility, and the 0.135.9 CANCELLED terminal cleanup. The cancelled live shipment returned `cargoStatus="Аннулировано до приемки груза"` with integer `cargoStatusId=-3`; official PEK docs document the status text and Number type but do not document negative sentinel IDs. The status normalizer therefore accepts integer `-3` only when the same response carries that exact cancelled-before-acceptance status after bounded text normalization. This is not general negative-ID support: `-2`, `-4`, `"-2"`, `"-3"`, padded sentinels, zero, floats, bools and arrays remain malformed. Accepted live sentinels (`-1`, `"-1"`, and cancelled-status `-3`) preserve the valid `cargoStatus` string and omit/clear `pek_cargo_status_id`. 0.135.9 changes status-update reconciliation for `CANCELLED` only: fresh carrier status that maps to `cancelled` terminalizes the generic creation attempt and removes the active PEK shipment from order storage, matching the already-confirmed cancellation flow. 0.135.10 makes the attempt lifecycle collaborator required, so terminalization failure prevents deletion instead of orphaning an active attempt. This is backend canonical cleanup, not frontend hiding, and it does not add delivered/returned archival policy. Cancellation itself remains one-shot: ambiguous mutation responses are reconciled through exactly one fresh read-only status call and are never retried automatically. PEK creation remains RU-only physical-recipient SMS release with first-name/last-name-only recipient identity, LTL `type=3`, `orderType=0`, sender self-delivery, sender-paid services, mandatory insurance, pickup/courier destination modes, safe preview only, and fail-closed SMS validation. First controlled courier create evidence showed an 8800 g shipment with `sealing=false` passed preparation but PEK rejected missing `cargos[0].services.sealing`; 0.135.10 therefore always serializes `services.sealing`, using `enabled=false` without payer for non-sealed cargo. 0.135.11 adds safe bounded diagnostics for unsupported PEK status DateTime fields after the first successful courier create reached `/cargos/status/` and failed only on `deliveryPlanDate`; no additional date format is accepted until the exact production wire value is captured.

Version: 0.135.11

0.135.4 keeps the 0.134.17 actual-address `/branches/findzonebyaddress/` courier authority, the 0.134.18 rate-meta `location_id` fix, the 0.134.19 historical city-FIAS fallback, the 0.135.0 generic creation attempt owner, and the 0.135.3 conservative CAS cache cleanup. `ShipmentCreationAttemptService` reserves a server-generated UUID v4 per order plus carrier/service scope on explicit shipment preview/create, persists the current generation in `_wdc_shipment_creation_attempts`, and injects `creation_attempt_id` into trusted `ShipmentCreateRequest::meta`. The canonical shipment/pending record stores the same `creation_attempt_id`; uncertain pending marks the attempt `pending`, successful manual reconciliation moves the same attempt back to `active`, and application-level terminal workflows mark the old attempt `terminal` before deleting a confirmed/cancelled/removed/pending shipment so the next explicit create receives a new UUID/generation.

The Shipment Framework lets carriers share admin creation, persistence, lifecycle, documents, modal UI, status presentation, polling, and regression coverage. It is not one linear pipeline; each runtime flow has its own owner.

PEK `customerCorrelation` is carrier-specific derived evidence, not the lifecycle owner. It first trusts persisted `pek_creation_correlation` for historical/pending reconciliation, otherwise derives from generic `creation_attempt_id`; new PEK mutation create fails before `/preregistration/submit/` if the generic attempt is missing. Preview reservation is internal framework metadata only: safe preview still performs no carrier mutation, but repeated preview and the following create share the same attempt and correlation when inputs are unchanged. Validation failures before outbound submit keep the same attempt for retry. The create mutation lock is scoped to the actual repository uniqueness boundary, order plus carrier, while attempt identity remains order plus carrier/service; lock ordering is create-lock first, then the short attempt-meta lock, then carrier mutation. Lock release and stale takeover use DB-level token ownership/CAS semantics so an expired request cannot delete a successor lock; after a successful direct SQL CAS delete, 0.135.3 invalidates the individual WordPress `options` cache entry and removes the lock key from `notoptions` so the next lookup can consult DB instead of trusting stale positive or negative cache.

For definite PEK create rejection, 0.135.4 does not create a shipment or pending record and does not terminalize the generic attempt. The create AJAX response may include a safe diagnostic block projected from `PekApiException::context()`: `failure_stage`, endpoint, method, HTTP status, error code, normalized PEK `api_error_message`, normalized `field_errors`, and `response_shape`. The projection is closed and redacted against the built payload's sender/receiver phones, emails, names, INN/KPP/card-like values, credentials, and credential-like key/value patterns. Raw response, raw request, receiver object, sender object, credentials, private token, phone, email, INN, KPP, and client card are never persisted in canonical shipment storage for definite rejection. Sender warehouse read-only validation distinguishes authoritative fresh PEK results from access/availability failures: a successful `/branches/nearestdepartments/` response without the exact warehouse remains fail-closed, but HTTP 403/transport/5xx does not prove the warehouse invalid. In that case only an exact matching, previously persisted server-derived `free` warehouse snapshot may be used, after local cargo limits and stored availability still pass; preview exposes `sender_warehouse_validation_source=persisted_snapshot_access_fallback`, `sender_warehouse_fallback_used=true`, and a warning, while preserving the original `checked_at`. Pickup destination read-only fallback follows the same narrow idea but a different trust source: it is limited to an already selected PEK checkout terminal persisted on the order and never to a stale discovery list. A successful fallback exposes `pickup_destination_source=persisted_checkout_selection_access_fallback`, `pickup_destination_fallback_used=true`, `pickup_destination_fresh_check=false`, and a warning, then continues to SMS validation. SMS release diagnostics are preview-only and are not persisted as shipment evidence before creation. They never expose the private token, counterpart GUID, raw connected-services response, request body, sender/receiver PII, or credentials, and they do not add a cached SMS authorization fallback: shipment creation remains blocked until the SMS contract is confirmed.

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
