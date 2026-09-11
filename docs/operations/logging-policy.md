# Production Logging Policy

Version: 1.0.0

WDC uses WooCommerce logging with a stable `source` context. Logs must contain identifiers, carrier keys, order IDs, safe endpoint names, status/error codes, and aggregate counters only when those fields help an operator diagnose a failure.

- `ERROR` means an operation failed, integrity was compromised, a database/API failure requires attention, a shipment lifecycle operation failed, or a carrier error is unrecoverable.
- `WARNING` means a real technical failure or significant degradation requires attention: transport, API, parse, contract, timeout, retry exhaustion, or an unexpected recovered condition with operational value.
- `INFO` is reserved for rare operational events with business value, such as completion of a controlled catalog refresh with compact counters.
- `DEBUG` is allowed only behind an existing subsystem or carrier debug setting. Do not add a debug setting only to preserve a diagnostic message.

Expected no-match, empty-result, local precondition, normal fallback, cache hit, successful quote, routine checkout recalculation, pickup selection, successful HTTP request, and cron no-op events are not production logs. A recoverable secondary failure may be logged at `DEBUG` only when the subsystem already has an explicit debug mode. The final technical failure may remain `WARNING` or `ERROR` according to impact.

Never log API tokens, authorization headers, passwords, cookies, sessions, payment data, raw customer payloads, full phone/email values, or full personal addresses. Carrier-provided messages must be reduced to allowlisted, redacted diagnostic fields before logging.

## Production Inventory

Counts below are explicit logger sink sites in production PHP. Repeated carrier debug messages call one of the four listed debug sinks and are enabled only by the existing Russian Post debug setting. Ten `error_log()` calls are logger-unavailable fallbacks or bounded admin/AJAX audit fallbacks; they do not define additional normal-flow events.

| Source | Level / sites | Message class | Frequency | Context | PII? | Production value | Recommendation |
| --- | ---: | --- | --- | --- | --- | --- | --- |
| Plugin bootstrap / migration | ERROR / 1 | activation or migration failure | activation/upgrade failure only | safe exception text, migration identity | no | prevents incomplete runtime registration | keep |
| Checkout shipping boundary | ERROR / 1 | unhandled WDC calculation failure | failed calculation only | safe exception text | no customer payload | checkout failure diagnosis | keep |
| Carrier quote boundaries: CDEK, DPD, Jet, Ozon, PEK, Yandex | WARNING / 6 | final technical transport/API/parse/contract failure | failed carrier quote only | carrier key, status/error code, redacted error | no | identifies real carrier degradation | keep; never log expected empty/no-match |
| PEK quote and destination diagnostics | ERROR / 2 | failed explicit quote/admin diagnostic | failed operation only | safe PEK error/status fields | no | operator-requested or unrecoverable failure | keep |
| Carrier geography/catalog imports | ERROR / 1; WARNING / 4 | import/sync/lookup technical failure | manual or scheduled failure only | carrier key, counters, safe error | no | catalog/geography integrity | keep |
| Russian Post country mapping refresh | WARNING / 1; INFO / 1 | refresh failed / refresh completed | rare controlled refresh | aggregate counters only | no | actionable failure and useful completion audit | keep; start event remains removed |
| FIAS client | WARNING / 2 | timeout / response parse failure | failed external request only | operation, endpoint class, safe error | no | real external degradation | keep; local limiter/precondition remains silent |
| Action Scheduler wrapper | WARNING / 1 | scheduler unavailable | attempted scheduled operation while unavailable | method/owner | no | integration degradation | keep |
| Carrier execution guard | WARNING / 2 | timeout threshold exceeded / thrown quote failure | technical failure only | carrier key, duration or safe error | no | detects slow or failed carrier execution | keep |
| Checkout session fallback | WARNING / 1 | session state write/reconciliation failure | exceptional checkout state only | bounded identifiers/status | no raw session | detects lost checkout state | keep |
| Location backup/restore/admin | ERROR / 4; WARNING / 1 | DB operation, restore/cache invalidation, cleanup failure | explicit maintenance failure only | table identifier, safe DB/exception text | no | protects recoverability and integrity | keep |
| Location incremental update | ERROR / 1 | apply succeeded but cache invalidation failed | rare maintenance degradation | safe exception text | no | requires retry/operator attention | keep |
| Russian Post postcode enrichment | WARNING / 2 | technical probe/update degradation | failed background step only | counts, status/error code | no | incomplete enrichment requires attention | keep |
| Order queue counter | WARNING / 1 | HPOS/Woo order-count query failure | cache refresh failure only | safe exception/status | no | dynamic lead-time degradation | keep |
| Pickup provider | WARNING / 1 | CDEK pickup technical request failure | failed request only | safe carrier/status fields | no | map/catalog degradation | keep |
| Shipment creation/lifecycle | ERROR / 2; WARNING / 2 | create/status/cancel technical failure | failed mutation/status operation only | order/shipment/carrier IDs, safe error | no customer payload | core fulfillment lifecycle | keep |
| Shipment documents | WARNING / 1 | document retrieval failure | failed manager action only | order, carrier, action, safe error | no | actionable admin failure | keep |
| Shipment cost analytics | ERROR / 2 | analytics persistence/index failure | failed indexing only | order/shipment IDs, safe error | no | detects incomplete operational analytics | keep |
| Ozon shipment approval | INFO / 1 | shipment created and approved | once per successful shipment | order/shipment/posting identifiers | no | rare business event | keep |
| Russian Post HTTP/runtime debug | DEBUG / 4 sinks | sanitized request/response, cache/fallback detail | only when existing carrier debug is enabled | endpoint/status/sanitized params; bounded carrier response | no secrets/customer payload | opt-in troubleshooting | keep behind existing switch |
| Logger/admin fallback paths | `error_log` / 10 | logger unavailable or bounded admin/AJAX failure audit | exceptional path only | safe identifiers/status/error | no | preserves failure evidence without Woo logger | keep; no normal-flow output |
