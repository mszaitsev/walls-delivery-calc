# Shipment Framework

Version: 0.122.0

The Shipment Framework lets carriers share admin creation, persistence, lifecycle, documents, modal UI, status presentation, polling, and regression coverage.

## End-To-End Flow

Carrier API -> Adapter -> Lifecycle -> Persistence Mapper -> Repository -> Status -> Presentation -> Document Provider -> Modal Extension -> Admin AJAX -> JS Extension -> Regression

## Components

| Component | Responsibility | Extension point | Tests |
| --- | --- | --- | --- |
| `CarrierShipmentAdapterInterface` | carrier support, preview, create, status payload, manual attach/remove/cancel, document actions | implement per carrier | `framework.adapter-registry`, carrier group |
| `CarrierShipmentAdapterRegistry` | lookup adapter by carrier key | register adapter in `Plugin.php` | `framework.adapter-registry` |
| `ShipmentCreationService` | generic create orchestration, duplicate guard, mapper persistence | no carrier switches | `framework.persistence-mappers`, carrier create tests |
| `CarrierShipmentPersistenceMapperInterface` | map carrier result into stored shipment fields | implement per carrier | `framework.persistence-mappers` |
| `ShipmentAllocationBuilder` | allocate order items into shipment places | shared service | `framework.allocation` |
| `ShipmentLifecycleResult` | shared lifecycle continuation result shape | adapter continuation | `framework.lifecycle-contract` |
| `CarrierShipmentLifecycleContinuationInterface` | optional continuation after initial create/preview | implement when required | `framework.lifecycle-contract` |
| `ShipmentStatusUpdateService` | Russian Post tracking update and shared presentation fields | service path | `framework.status`, `status-core` |
| `ShipmentStatusAutoSyncService` | status autosync across supported carriers | service path | `status-core`, carrier autosync |
| `ShipmentActualCostPresentation` | actual/base API cost presentation | shared presentation | `framework.actual-cost-presentation` |
| `ShipmentDocumentProviderRegistry` | carrier document provider lookup | register provider | `framework.document-actions` |
| `ShipmentDocumentDownloadService` | protected admin document download | provider registry | `framework.document-actions` |
| `ShipmentDocumentAction` | normalized document action DTO | adapter/document provider | `framework.document-actions` |
| `ShipmentModalExtensionRegistry` | modal extension lookup | register extension | `framework.modal-extensions` |
| Admin AJAX controllers | create, preview, lifecycle, manual attach, status, remove/cancel, documents, address/product helpers | shared controllers | `framework.admin-ajax` |
| `ShipmentAdminCarrierUiPayloadBuilder` | shared UI payload and document action payload | registry lookup | `framework.admin-ajax` |
| `OrderShipmentsMetabox` | render metabox and register AJAX controllers | no business logic | framework/carrier smokes |
| Generic JS modules | shared state, buttons, polling, document actions | carrier hooks | `framework.admin-js-structure` |
| Carrier JS extensions | carrier-specific UI rendering/clicks | one extension per carrier | carrier smokes |
| Regression runner | mandatory group execution | manifest entries | `framework`, carrier groups |

## Canonical Payloads

Document actions use:

- PHP adapter method: `document_actions()`;
- JS normalized state: `documentActions`;
- AJAX/status payload key: `document_actions`.

There is no production compatibility requirement for older internal wire aliases.

## New Carrier Expectations

Adding a new carrier must not require:

- editing `ShipmentCreationService` logic;
- adding a carrier switch in `OrderShipmentsMetabox`;
- adding carrier branches in generic JS;
- saving carrier data outside a persistence mapper;
- adding document download code outside the document provider/download service;
- adding a separate lifecycle endpoint outside the common lifecycle continuation contract.

Registration in `Plugin.php` is expected because it is the composition root.
