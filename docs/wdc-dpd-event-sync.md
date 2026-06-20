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
