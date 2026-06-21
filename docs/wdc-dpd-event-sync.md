# DPD Event Sync 0.67.0

DPD `event-tracking/getEvents` is treated as a global client inbox, not as a single-order status endpoint. One DPD `clientNumber` is used only by this plugin, so confirming processed packages is safe when the setting is enabled.

## API shape

`DpdApiClient::getEvents()` calls service `event-tracking`, method `getEvents`, wrapper `request`, adds auth and forces `maxRowCount=500`. Nullable event lookback days are configured in `WDC → Службы доставки → DPD → DPD расчёт`: empty means no `dateFrom/dateTo`; `N=1` means today 00:00 to now; `N=2` means yesterday 00:00 to now. Dates use WordPress timezone and ISO 8601 offset. PHP payloads do not send .NET `*Specified` flags.

`confirm` uses service `event-tracking`, method `confirm`, wrapper `request` and `docId` from the package.

## Processing loop

`DpdEventSyncService` acquires an atomic option lock with a token and TTL. If another request is active, sync returns a non-fatal message that DPD events are already being processed. Stale locks can be recovered after TTL, and the lock is released in `finally`.

Each package reads `docId`, `resultComplete` and `event[]`. Events are normalized to `clientOrderNr`, `dpdOrderNr`, `dpdParcelNr`, `eventNumber`, `eventCode`, `eventName`, `eventDate` and timestamp. They are grouped first by `dpdOrderNr`, then by `clientOrderNr`; the latest eventDate wins, equal dates keep the later response row, and invalid-date rows do not overwrite valid saved events.

Matching priority is `_wdc_dpd_order_number`, then `wc_get_order(clientOrderNr)`, then shipment validation. If DPD number and client order point to different WooCommerce orders, neither is updated and a sanitized conflict is logged. Pending shipments without DPD number may match by client order number, but events materially older than `registration_started_at` are ignored.

For a new latest event, the service stores only the last event fields in `_wdc_shipments[dpd]`: numeric `dpd_event_code`, marker `dpd_event_marker`, title `dpd_event_name`, raw event time, normalized timestamp, DPD/client order numbers, tracking checked time and universal status from `DpdStatusMapping::resolve(eventNumber)`. It then calls `ShipmentOrderStatusMappingService::apply()` so all matched WooCommerce orders in the package can move through the configured status mapping. Event parameters and full raw SOAP bodies are not persisted or logged.

## Confirm and safety

After a package is fully parsed, matched updates are saved and unmatched summaries are logged, `confirm(docId)` is called only when the admin setting is enabled. If confirm is disabled, one package is processed and no confirm or next getEvents call is made. If confirm succeeds and `resultComplete=false`, the next package is fetched; `resultComplete=true` finishes the run. A 20-package safety limit returns a warning without rolling back already saved and confirmed packages.

Unmatched events are still considered processed and do not block confirm. Confirm errors stop the loop; saved changes remain, and the next manual run can safely receive the same unconfirmed package because event application is idempotent.

## 0.67.4 pending re-registration guard

For a pending DPD shipment that does not yet have `dpd_order_number`, `clientOrderNr` fallback is allowed only after extra guards. The event must belong to the Woo order, have `eventDate` at or after `registration_started_at - 300 seconds`, and must not be an old negative event with a DPD number. `1301`, `2901`, `2904`, mapped `cancelled`, `returning_to_sender` and `returned_to_sender` events are logged as unmatched with `reason=stale_or_cancelled_pending_event` and do not attach their `dpdOrderNr` to the current pending registration.

If one package contains several valid events for the same pending `clientOrderNr`, the service picks the latest valid `eventDate`, using the response order as the tie-breaker. This lets a fresh `1401 / OrderCreate` for `RUNEW` win over older ignored events for `RUOLD`. Once a shipment has a saved `dpd_order_number`, the fallback is no longer relevant: incoming events must match that exact DPD number after trim and case-insensitive normalization.
## 0.67.3 multiple shipments per Woo order

When one WooCommerce order has had multiple DPD shipments over time, `dpdOrderNr` is the primary event identity. `DpdEventSyncService::match_order()` first looks up `_wdc_dpd_order_number`; if that finds an order, the event belongs only to that shipment. `clientOrderNr` is used only as fallback when no DPD-number match exists, which keeps pending registration without a DPD number working.

Before applying an event, the service checks the active shipment: if `dpd_order_number` is already saved, incoming `event.dpdOrderNr` must match it after trim and case-insensitive normalization. Mismatched events are logged as unmatched with `saved_dpd_order_number`, increase the unmatched counter and still allow confirm after the package is otherwise processed.
