# DPD Shipment Lifecycle 0.67.0

This lifecycle is rebuilt from `develop` 0.66.2 and does not reuse experimental DPD status/debug branches or old 0.67.x adapter code.

## Manual create

Manual creation is page-controlled and two-stage internally, but the common UI sees a neutral lifecycle contract. The first AJAX request validates the modal draft and saves a local DPD shipment before any SOAP call: `carrier_key=dpd`, Woo order id, internal order number, `datePickup`, service code, delivery type, terminals, places, sanitized request snapshot, `registration_started_at`, `dpd_registration_state=submitting`, `status=pending_creation_in_carrier`, `universal_status_code=pending_creation_in_carrier`, `status_title=Ждём регистрацию`, current admin id and `created_by_context=admin_manual`. The response contains `lifecycle.phase=submission_required`; the modal closes and the shared shipment block shows the pending state immediately.

The second AJAX request is the shared `wdc_continue_shipment_lifecycle` endpoint with order/carrier/attempt context. It resolves the adapter capability and then DPD calls `order2/createOrder2` using wrapper `orders`. The timeout is fixed at 10 seconds. `OK` stores `orderNum` as `dpd_order_number`, tracking fields and `_wdc_dpd_order_number`. `OrderPending` keeps the shipment pending and returns `lifecycle.phase=polling_required`. `OrderDuplicate`, `OrderError` and `OrderCancelled` become terminal registration states. Timeout / `Error Fetching http headers` is treated as uncertain and continues through `getOrderStatus`; explicit transport errors stop automatic polling but leave manual update available.

No Action Scheduler, background queue or DPD cron is registered.

## Registration polling

While the current page is open, DPD pending registration uses non-overlapping frontend polling every 10 seconds. The backend calls `order2/getOrderStatus` with wrapper `orderStatus`, `auth` inside `orderStatus`, and `order[]` rows containing `orderNumberInternal` and `datePickup`.

`OK` stores the DPD number, marks `dpd_registration_state=ok`, sets `created_in_carrier`, runs one global DPD event sync and refreshes the block. `OrderPending` and empty/no-data responses keep polling. Terminal DPD creation statuses stop polling and show the saved message. Transport/SOAP errors stop automatic polling but leave the update button visible so the manager can restart polling.

## Manual attach, cancel and remove

Manual attach is available only when no DPD shipment or pending registration exists. It saves the DPD number into shipment tracking fields and `_wdc_dpd_order_number`, marks the shipment as created and does not run `getEvents` automatically.

Cancel uses `order2/cancelOrder` with wrapper `orders` and `cancel[]`. `Canceled` and `CanceledPreviously` delete the local shipment and `_wdc_dpd_order_number`. `CallDPD`, `NotFound`, `Error` and transport failures keep the local shipment; the remove button may be shown only in the current DOM after the failed cancel response and that temporary state is not persisted.

Local remove deletes only the local DPD shipment, clears `_wdc_dpd_order_number` and does not call DPD.

## Button policy

No local DPD shipment shows `Создать отправление DPD` and `Внести номер DPD вручную`. Pending registration without DPD number shows `Обновить статус` and `Удалить из заказа`. A shipment with DPD number shows `Обновить статус`; cancel is shown only for EventCode `1001`, `1101`, `1201`, `1401`, `1501`. EventCode `1301` and all other non-cancellable states show local remove instead. Initial PHP render and AJAX refresh both use the same DPD adapter status payload for button visibility. DPD `Скачать документы` is shown only for shipments with saved `dpd_order_number` and strict EventCode `1401`. Other DPD statuses, including `1001`, `1101`, `1201`, `1301`, `1501`, delivered, cancelled and unknown states, do not show the documents action. See `docs/wdc-dpd-documents.md`.

## Price/date enrichment

Manual update with a DPD number runs global `getEvents`, then calls `tracing1-1/getStatesByDPDOrder` for the current order with wrapper `request`, `dpdOrderNr` and `pickupYear` from `datePickup`. It stores `dpd_actual_cost_kopecks`, `planned_delivery_date` and `dpd_enrichment_checked_at` only when both `orderCost` and `planDeliveryDate` are present. This API never changes universal status. Actual price is compared with saved base API cost using the existing 3% tolerance: within threshold is green/ok, above threshold is warning/red, missing base cost is neutral.

## 0.67.2 create/status QA

After `createOrder2` returns `OK` with `orderNum`, `DpdOrderRegistrationService` saves the DPD number and immediately calls the same refresh path used by manual `Обновить статус`: `DpdEventSyncService::sync()`, current-order `DpdShipmentEnrichmentService`, then a `tracking_checked_at` / `updated_at` touch. A missing matching event is not fatal; the check time still changes so the manager can see that DPD was queried.

Manual DPD status refresh also touches `tracking_checked_at` and `updated_at` after sync/enrichment even when the latest saved event remains unchanged. The future scheduled sync can keep its own policy; no DPD cron is registered in this release.

DPD button visibility is based only on persisted shipment data. Saved `dpd_event_code` or generic `carrier_operation_code=1401` keeps `Отменить отправление в DPD` visible after reload; `1301` and non-cancellable operation codes hide cancel and show `Удалить из заказа`.

The DPD shipment record stores the actual places sent in the `createOrder2` request as `dpd_sent_places` plus `dpd_cargo_num_pack`, `dpd_cargo_weight` and `dpd_cargo_volume`. These values are extracted from the already-built request payload / `request_snapshot`, not recalculated after the SOAP call. They are shown in the `Отправления` technical block because managers create shipment places in the shipment modal, not in `Калькулятор доставок`. If the DPD cabinet does not display dimensions, WDC still treats the request snapshot and `dpd_sent_places` as the local source of truth.

## 0.67.4 pending re-registration events

When a WooCommerce order previously had a DPD shipment and a new DPD registration is pending without a saved `dpd_order_number`, `getEvents` may still contain old unconfirmed events for the same `clientOrderNr`. The event sync keeps the client-order fallback only for this pending state, but filters it by `registration_started_at - 300 seconds` and ignores cancellation/return events with a DPD number so an old `RUOLD` cannot become the active number for the new attempt.

Among valid pending events for the same `clientOrderNr`, the latest `eventDate` wins. A fresh `1401 / OrderCreate` with the new `dpdOrderNr` is saved as the active shipment number. After that point, all later events must match the saved `dpd_order_number`; `clientOrderNr` alone cannot update the shipment.
