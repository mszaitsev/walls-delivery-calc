# WDC background execution optimization audit

Audit date: 2026-09-12

Branch: `perf/ozon-pickup-bounded-worker`

Baseline HEAD: `e036ef67e2733d9fe077d93ebbfd522117b65ee0`

Plugin version: `1.0.17`; schema version: `1.0.0`

Russian Post, DPD Geography, the Yandex full geography pipeline, and Ozon pickup synchronization now use carrier-owned bounded worker slices. Ozon keeps Action Scheduler and its existing API units while removing both the one-unit-per-callback boundary and per-row Stage N+1 persistence.

This document records both the original audit and the implemented runtime contracts. The Ozon implementation changes no schema, global schedule, API batch size, or server configuration.

## 1. Executive summary

The production cron configuration is correct and should remain the baseline:

- `DISABLE_WP_CRON=true`;
- the site user starts `wp-cron.php` once per minute through `flock`;
- PHP is 8.4.12;
- top-level daily/weekly/monthly schedules may legitimately have minute-level start latency.

The performance defect is not the one-minute system cron itself. It is the use of a new `+1` or `+5` second scheduled action for every small unit of three long-running pipelines:

1. Russian Post pickup import: one 500-row action per batch;
2. Ozon pickup import: one discovery page or one 100-ID enrichment batch per action;
3. Yandex full geography pipeline: one orchestration/stage step per WP-Cron event, including 500-row, 10-row, and 100-row local stages.

With external cron once per minute, these actions become effectively one unit per minute. The recommended application-level fix is a bounded worker slice inside each callback: process and checkpoint multiple ordinary continuation units until a soft time/memory/unit budget is reached, then schedule exactly one continuation. A real API retry/backoff must end the slice and preserve its delay.

Do not re-enable traffic-driven WP-Cron. It would add nondeterministic duplicate wake-up attempts and tie throughput to checkout/admin traffic without repairing the pipeline contract. The recommended server option is **system cron every minute + bounded WDC workers**. A dedicated Action Scheduler WP-CLI runner is a valid later operational accelerator, but it does not cover the Yandex WP-Cron pipeline and should not be required for correctness.

A small shared `BackgroundExecutionBudget` is justified because three independent scheduled pipelines need the same monotonic wall-time and memory decision. It must not become a new queue framework: checkpoints, leases, cancellation, idempotency, and retry classification remain owned by each subsystem.

## 2. Current production cron model

The system scheduler serializes invocations using `flock` and starts `wp-cron.php` once per minute. `DISABLE_WP_CRON=true` prevents request traffic from spawning additional WP-Cron runs. Due WP-Cron events execute on the next system wake-up. Action Scheduler's default WP-Cron runner is also awakened through that process.

This is predictable and operationally sound. A requested timestamp of `time() + 1` or `time() + 5` is only an eligibility time, not a promise that a worker will run at that second. Under the production model the next opportunity is normally the next minute tick.

WordPress explicitly recommends disabling page-load cron after connecting WP-Cron to a system task scheduler: [Hooking WP-Cron into the system scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). WordPress also documents that native WP-Cron is traffic-driven rather than continuous: [Cron handbook](https://developer.wordpress.org/plugins/cron/).

## 3. Audit scope and counts

The repository has:

- 6 user-visible scheduled task owners in `ScheduledTaskCatalog`;
- 3 scheduler-driven resumable continuation pipelines;
- 14 browser-driven resumable/remote-continuation flows;
- 17 continuation flows in total;
- 20 distinct owner/mechanism rows in the inventory below. Yandex's four lower-level state owners are intentionally listed separately because they can run directly from the browser and are also composed by the scheduled full pipeline.

No WDC production code uses `as_enqueue_async_action()`, a custom Action Scheduler runner, a shutdown worker, a WDC loopback worker, or a REST endpoint that drains a persistent batch queue. REST checkout/pickup endpoints are request-response APIs, not continuation workers.

## 4. Complete background inventory

Abbreviations: AS = Action Scheduler; WP-Cron = WordPress cron; AJAX = authenticated admin AJAX; A/B/C/D/E are defined in the next section. “Overview” means the WDC Scheduled Tasks overview.

| # | Class | Subsystem / hook or action | Scheduler and cadence | Owner | Work per callback / batch | Continuation and possible count | Lock / lease and idempotency | Retry / backoff | Cron-wake dependency | Browser behavior | Overview |
|---:|---|---|---|---|---|---|---|---|---|---|---|
| 1 | A+B | Russian Post pickup: `wdc_russian_post_pickup_import`, `..._init`, `..._batch`, `..._finalize`; `RussianPostPickupImporter` | Weekly WP-Cron start; AS single actions with WP-Cron fallback | A+B | Init downloads/extracts; batch reads at most 500 passport objects; finalize swaps staging | `+5s` after init and each batch; for 184,442 rows about 369 batch actions plus init/finalize | Unique `import_id`; option lock with token, 10,800s TTL, owner compare/replace renew at every callback; staging and byte offset checkpoints | Pipeline failures are terminal; no intentional inter-batch backoff | **Yes**, every `+5s` action normally waits up to the next minute | 3s status polling is read-only | Yes, top-level weekly task |
| 2 | A+B+C | Ozon pickup: `wdc_ozon_delivery_pickup_daily`, `..._step`; `OzonDeliveryPickupScheduler` + `OzonDeliveryPickupImportService` | Daily AS recurring start; AS single worker slices | A+B+C | Up to 10 independently committed discovery pages/100-ID enrichment units within 18s and 80% memory; publish when pending is empty | One owner-scoped continuation between slices; pages up to 50,000 and rows up to 5,000,000 by safety caps | Random owner option lock, 900s TTL, CAS renew/release/takeover plus a per-callback execution token in the same option; generation/job state and atomic phase-aware commits | Retryable API errors end the slice: 2s, 5s, 10s, max 3; 404 info salvage remains inside one unit | **Yes**, between bounded slices only; intentional retry delay is preserved | 3s single-flight status polling only; start/stop are explicit controls | Yes, daily task |
| 3 | A+B | Yandex full geo/pickup: `wdc_yandex_delivery_geo_pipeline_v2_scheduled_start`, `..._run_step`; `YandexDeliveryGeoPipelineV2Runner` | Configured day/time WP-Cron single start; WP-Cron single continuation | A+B | One orchestrator step: download/start or one lower-level batch/stage transition | `+1s` after every running step; count is sum of pickup, geo-build, enrichment, mapping batches and transitions | Persistent session/state option, but no dedicated owner lease on the outer pipeline; lower stages use staging/promotion where applicable | No explicit delayed retry in the outer runner | **Yes**, every ordinary step waits for the next minute | Full-pipeline UI polls status every 2s and does no work | Yes, configured task |
| 4 | D | DPD pickup autosync: `wdc_dpd_pickup_points_autosync`; `DpdPickupPointAutoSync` | WP-Cron daily, once for each configured local time | D | `import_all('auto_cron')` fetches/imports OPS and PVZ in one callback | None | Token option lock, 30-minute TTL, owner-checked release; importer upserts by stable point identity | No scheduled continuation/backoff | No; cadence only affects start | No worker polling | Yes |
| 5 | A+D | Shipment status autosync: `wdc_shipment_status_autosync`; `ShipmentStatusAutoSyncCron` + service | WP-Cron recurring, configurable 15–1440 minutes | A+D | Whole run in one callback: DPD global sync then all configured-status orders and shipments | None | Transient lock, 30-minute TTL, `finally` release; carrier updates operate on persisted shipment identity | Carrier HTTP behavior and CDEK in-process throttle; no continuation schedule | No minute gap after start | Manual run is a separate whole callback | Yes |
| 6 | A+D | Calendar: `wdc_calendar_generate_next_year`; `CalendarScheduler` | Monthly AS single action, first Monday 09:00; self-schedules next month | A+D | Whole `generate_next_year_if_needed()` run | One next-month schedule, not a pipeline continuation | Idempotent “generate if needed”; no lease | None | No throughput issue | None | Yes |
| 7 | E | GAR CSV full import; `LocationsAdminPage` + `GarPlacesCsvImporter` | Admin AJAX loop | E | CSV staging 1,000 rows; processing 500 rows | 250ms browser timer until terminal; proportional to file rows | Persistent job option and stage offsets; no process-owner token | Transport failure stops browser loop; no server retry | No | Browser performs work | No |
| 8 | E+C | Locations incremental update; `LocationIncrementalUpdateService` | Admin AJAX loop | E+C | Usually 1,000-row local batches; enrichment can be one external lookup unit | 250ms normal continuation; pauses at DaData limit or cache-clear failure | Persistent job ID, owner check on step/resume/cancel, staged candidate tables, per-phase checkpoints | `waiting_dadata_limit` and `waiting_cache_clear` require explicit resume | No | Browser performs work; status restores state only | No |
| 9 | E | Locations snapshot export; `LocationsSnapshotExporter` | Admin AJAX loop | E | 1,000 rows/page across exported tables | 250ms browser timer, pages proportional to data | Persistent job option, table/offset checkpoint; output file | None | No | Browser performs work | No |
| 10 | E | Locations snapshot import; `LocationsSnapshotImporter` | Admin AJAX loop | E | 1,000 JSONL lines/step | 250ms browser timer, proportional to lines | Persistent job option and byte/line checkpoint; staged replacement contract | None | No | Browser performs work | No |
| 11 | E | Location display/search rebuild; `LocationsAdminPage::step_display_name_rebuild_job()` | Admin AJAX loop | E | 500 active locations by ID | 250ms browser timer, `ceil(active/500)` | Job option with `last_id`; idempotent recalculation | Early EOF becomes failed | No | Browser performs work | No |
| 12 | E+C | DaData postcode fill; `LocationsAdminPage::step_dadata_postcode_job()` | Admin AJAX loop | E+C | Random 10–20 locations, external API | Random 2–4s browser delay | Job option, ID/priority checkpoint; values persisted per location | Daily token exhaustion stops; 30 consecutive failures fail | No | Browser performs work; delay protects external service | No |
| 13 | E+C | DaData coordinate fill; `LocationCoordinatesDadataBatchUpdater` | Admin AJAX loop | E+C | Requested 20–30, clamped to at most 20; external API | Random 2–4s browser delay | Job option, ID/priority checkpoint; values persisted per location | Daily limit is a terminal waiting condition resumable later | No | Browser performs work; delay protects external service | No |
| 14 | E+C | Russian Post courier-calc postcode fill; `RussianPostCourierCalcPostcodeFillStateService` | Admin AJAX loop | E+C | One location, at most 18 probes and 3 seconds; target 6 probes/s | 75ms between browser steps; potentially one step/location | Job option with current location/candidate offset; writes each resolved value | Up to 5 technical attempts per candidate; in-step pacing is intentional | No | Browser performs work | No |
| 15 | B | DPD geography import; `DpdGeographyImportService` | Action Scheduler one-shot worker | B | 500 rows/atomic step; up to 10 steps/18-second slice | 5s scheduled continuation, subject to queue wake-up | Token lock per step, 1,800s start lock, WDC location write lock per step, job/revision/byte-offset stale detection | Busy exits the slice; duplicate continuation is suppressed | Yes, between slices only | Browser only polls state | Yes |
| 16 | E | Standalone Yandex pickup V2 runner; `YandexDeliveryPickupPointV2RunnerService` | Admin AJAX loop when full pipeline is inactive | E | Download is one heavy request; streamed import 500 objects | 50ms between local steps | Persistent session/offset; staging repository promoted only on completion | No scheduled API retry | No | Browser performs work only without an active full-pipeline owner | No; also composed by #3 |
| 17 | E | Standalone Yandex geo V2 builder; `YandexDeliveryGeoV2BuilderRunnerService` | Admin AJAX loop when full pipeline is inactive | E | 500 unique geo IDs | 50ms browser loop | Persistent offset; deterministic aggregate upsert | None | No | Browser performs work only without an active full-pipeline owner | No; also composed by #3 |
| 18 | E | Standalone Yandex region enrichment; `YandexGeoV2RegionEnrichmentRunner` | Admin AJAX loop when full pipeline is inactive | E | 10 rows; local WDC DB matching only | 50ms browser loop | Attempt status persisted per geo row; remaining set is re-queried | None | No | Browser performs work only without an active full-pipeline owner | No; also composed by #3 |
| 19 | E | Standalone Yandex location mapping; `YandexLocationMappingV2Runner` | Admin AJAX loop when full pipeline is inactive | E | 100 geo IDs | 50ms browser loop | Persistent offset; staging table promoted on completion | None | No | Browser performs work only without an active full-pipeline owner | No; also composed by #3 |
| 20 | C+E | Shipment lifecycle continuation/status/document polling; `ShipmentLifecycleAjaxController` and carrier adapters | Admin browser AJAX | C+E | One remote submit/status/document operation | Usually 5s and max 14 attempts (Ozon/Yandex); DPD registration 10s with carrier-owned terminal rules | Order shipment state and continuation token prevent unrelated continuation | Delay waits for remote asynchronous carrier state and must remain | No | Browser performs remote continuation/polling work | No |

The inline locations AJAX loop treats `finished`, `failed`, and `canceled` as terminal. It is therefore browser-dependent: closing the tab stops progress, while the persisted checkpoint remains. The Yandex full-pipeline browser is different: it calls the status endpoint only; scheduled WP-Cron callbacks do the work.

## 5. Classification

### A. Top-level schedules

- Russian Post weekly import start;
- Ozon daily import start;
- Yandex configured day/time start;
- DPD configured daily pickup sync times;
- shipment status interval (15–1440 minutes);
- calendar first Monday of the month.

Minute-level wake-up latency is acceptable for these starts.

### B. Continuation pipelines

Scheduler-driven: Russian Post, Ozon, and Yandex full geography. These are the only WDC pipelines whose throughput is directly degraded by the one-minute system wake-up.

Browser-driven: GAR, incremental locations, snapshot export/import, display rebuild, DaData postcode/coordinates, Russian Post courier postcode fill, DPD geography, four standalone Yandex runners, and shipment lifecycle continuation. Their latency depends on an open admin browser rather than system cron.

### C. Intentional backoff or pacing

- Ozon retryable API failures: 2/5/10 seconds;
- Ozon 404 salvage occurs inside one bounded enrichment step and must retain its request cap;
- DaData 2–4 second browser pacing and daily-limit stops;
- Russian Post courier probes at 6 requests/s, max 18 probes/3 seconds, with bounded technical attempts;
- DPD geography lock-busy/status retry delays;
- shipment registration/cancellation/status/document polling at carrier-defined intervals;
- CDEK status auto-sync throttle inside the single callback.

These are API/business/concurrency delays, not technical cron latency. A worker slice must stop when it receives a retry/backoff result rather than immediately looping.

### D. Single heavy callbacks

DPD pickup autosync, shipment status autosync, calendar generation, and the Yandex full JSON download each run as one callback. Cron frequency does not slow them after they start. Shipment status autosync is potentially unbounded because it calls `wc_get_orders(limit=-1)` and processes every matching order; that is a scalability risk for a separate change, not justification to alter its configured sync interval.

### E. Browser-driven work

The 14 flows identified above require an admin tab to keep issuing work requests. This audit does not recommend migrating all of them automatically. Local file import/export and explicit maintenance jobs are acceptable browser tools today. API-heavy DaData and carrier polling deliberately benefit from client pacing. Any future migration must preserve their existing pause/resume and quota semantics.

## 6. Russian Post deep dive

Current lifecycle:

`weekly/manual start -> acquire import_id lock -> init (+5s) -> download/extract -> batch (+5s) -> batch... -> finalize (+5s) -> staging swap -> release`.

Each batch reads at most 500 passport objects, normalizes them, persists a staging batch, saves `payload_offset` and counters, renews the 10,800-second owner lock, and schedules one next action. Version 1.0.4 therefore has appropriate request-boundary checkpoints and lease renewal, but too many scheduler boundaries.

For 184,442 objects, at least `ceil(184442/500) = 369` batch callbacks are required. With one effective callback/minute the continuation portion alone is about 369 minutes (6.15 hours). At the measured production rate of about one second per batch, a conservative 20-second slice with a 15-batch cap would need about 25 slices instead of 369. Depending on the first wake-up and server load, this reduces scheduler waiting from roughly six hours to tens of minutes; a 20-batch cap is about an 18–20x reduction in wake boundaries.

Proposed change:

- init remains a separate heavy download/extract callback;
- one batch callback loops over atomic 500-row batches;
- after every batch it persists the offset/counters and renews the owner lease;
- it checks cancel/ownership, elapsed time, memory, and unit cap between batches;
- EOF can finalize in the same slice only if budget remains; otherwise schedule one finalize action;
- crash safety remains the existing byte-offset + staging upsert contract;
- no transaction spans multiple batches.

The 1.0.15 admin status contract is presentation-safe across slow/out-of-order AJAX responses. The persisted Russian Post option carries a globally monotonic `state_revision`: every successful state transition stores the expected persisted revision plus one, including queue/start of a new `import_id`, worker metrics, terminal CAS, cancellation, and stale reset. Browser polling is single-flight, ignores lower positive revisions after a newer render, retries after transport errors, and remains status-only. This changes neither worker execution nor its batches, schedule, lease, or staging lifecycle.

## 7. Ozon deep dive

Current phases are discovery, enrichment, then ready/activation:

- discovery performs one remote list-page request and atomically commits IDs and cursor;
- enrichment takes at most 100 pending IDs, performs one info request in the normal case, and atomically commits points/rejections;
- a not-found response may split the ID set recursively, capped at 199 info requests;
- empty pending set marks the generation ready and activates it;
- the owner lock has a 900-second TTL and is renewed at each scheduled step.

### Ozon implementation result (1.0.16)

Action Scheduler remains the owner of the daily start and `wdc_ozon_delivery_pickup_step`. One step callback now runs a conservative bounded slice of at most 18 seconds, 10 API atomic units, and 80% of a finite PHP memory limit. Unknown/unlimited memory remains bounded by time and units. Each discovery page and each enrichment batch of at most 100 IDs retains its own repository transaction and checkpoint. A retry result ends the slice immediately and creates one exact `(job_id, owner)` continuation with the existing 2/5/10-second backoff.

The scheduler revalidates the SQL building generation, immutable job/owner pair, and option lease between units. The existing 900-second lease now uses compare-and-replace renew and compare-and-delete release/expired takeover, so a stale callback cannot overwrite or delete a newer owner. Exact continuation checks suppress duplicates, and Action Scheduler initialization repairs a missing continuation only when the building generation and current lease owner match; bootstrap never executes import work.

Discovery persists IDs through `INSERT IGNORE` chunks of 250 rows and derives counters from the unchanged unique generation count. Enrichment keeps `INFO_BATCH_SIZE=100`, inserts accepted point rows with one prepared multi-row statement, marks accepted IDs with one set-based update, and marks rejected IDs with one prepared `CASE` update. Affected-row checks require every expected point/ID transition; any short or failed write rolls back the current atomic transaction. No table, index, parser, API request, activation, or snapshot contract changed.

The browser remains status-only and single-flight, so no SQL `state_revision` was added. Terminal generations stop polling. Temporary transport failures no longer permanently stop the panel after three errors; polling resumes on the existing bounded cadence without mutating worker state.

Every normal step schedules `time()+1`, so it suffers minute quantization. The 2/5/10-second retry schedule is intentional backoff and must not be consumed in a tight loop.

Proposed slice:

- loop ordinary successful discovery/enrichment units while budget remains;
- checkpoint and renew after every unit;
- stop immediately on `{retry: true}` and schedule exactly the returned delayed retry;
- count remote requests as units, not only rows; consider a lower per-slice unit cap for enrichment because the 404 salvage path can make many requests;
- preserve generation phase checks, atomic commits, cancel detection, and owner token.

Acceleration depends on API latency and page count. If normal requests take about 0.5–1.5 seconds, a 20-second slice might complete roughly 8–20 normal units instead of one, an order-of-magnitude reduction in minute wake boundaries. No numeric production duration is currently instrumented, so this estimate must be validated before choosing caps.

## 8. Yandex deep dive

The full pipeline has five stages:

1. `import_pvz`: one full JSON download, then streamed 500-object import batches;
2. `build_geo_v2`: local aggregation in batches of 500 geo IDs;
3. `region_enrichment`: local WDC location/coordinate matching in batches of 10;
4. `region_mapping`: one local synchronization callback;
5. `location_mapping`: local mapping in batches of 100 geo IDs followed by staging promotion.

The outer runner performs exactly one stage operation and schedules `time()+1` through WP-Cron. All normal transitions therefore suffer minute latency. The browser for the full pipeline polls `..._status` every two seconds and does not advance it. Four lower-level tools can also be run independently by a browser with 50ms continuations.

The full JSON download remains one heavy/API unit. After it succeeds, the streamed import and all local stages are good bounded-loop candidates. No provider-required delay was found in the outer pipeline. Nevertheless, a slice should treat the download as one unit, re-check state after every lower-level call, and stop if a stage reports `error`, `paused`, or a future explicit retry state.

Before 1.0.15 the outer runner had a session ID but no dedicated owner lease. The implementation added a minimal carrier-owned lease before increasing work per callback; WP-Cron event uniqueness and server `flock` are not treated as the application ownership contract.

Acceleration depends on table sizes. For local stages, replacing one 500/10/100-row unit per minute with a 20-second slice should reduce wait boundaries by approximately the number of units completed per slice (often 10–100x for fast SQL batches). The download itself will not become faster.

### Yandex implementation result (1.0.15)

The outer WP-Cron callback now processes a bounded slice instead of one local unit per minute-level wake-up. One slice allows at most 18 seconds, 25 atomic units, and 80% of a finite PHP `memory_limit`; time and unit caps remain mandatory when the memory limit is unlimited or unknown. The existing 500-object pickup import, 500-geo-ID build, 10-row enrichment, one region sync, and 100-geo-ID location mapping units and their lower-runner checkpoints are unchanged.

The full JSON download remains one remote atomic unit, counts toward the slice, and always stops that slice before staging initialization or SQL import. Continuations remain direct WP-Cron but now carry the immutable outer `session_id`. A carrier-owned 300-second option lease stores session, random token, and expiry; acquire uses atomic `add_option`, renew/release compare the complete stored value, and expired takeover first compare-deletes the old value. Stale callbacks and owners cannot work on, renew, release, or overwrite a new session.

Pause persists the outer terminal-for-worker state before pausing the lower runner, so a callback finishing its current atomic unit cannot overwrite the pause. Reset invalidates the old session before clearing continuations; initialization of the lower pickup runner occurs only inside the new owner's first locked unit. Bootstrap schedule ensure performs only an idempotent continuation repair. A legacy no-argument callback performs no work and can only establish the current owner-scoped continuation.

Production acceptance exposed a second ownership boundary: the legacy standalone browser loops could mutate the same persisted lower-runner state while the new outer worker was active. This caused visible counter rollback when stale and current checkpoints alternated. On the final pickup batch, the outer-owned successful promotion correctly deleted the downloaded JSON; a stale browser step then tried to reopen that already-cleaned file and overwrote the newer lower state with a not-readable error. The correction keeps JSON cleanup unchanged and makes the full pipeline the sole lower-stage executor while its outer status is `running` or `paused`. Every standalone pickup, geo builder, region enrichment, region mapping/manual override, and location mapping mutation is re-checked server-side; browser loops and controls are also suppressed. Terminal `done`, `error`, and `idle` states release this boundary and preserve standalone operation.

## 9. DPD

DPD pickup autosync performs OPS and PVZ import in one locked callback. It has no continuation gap and needs no cron-throughput optimization.

DPD geography import was moved from its browser-driven loop to the shared bounded-worker policy in 1.0.13. Manual/SFTP source acquisition creates a durable job and one Action Scheduler worker. Each callback performs multiple 500-row checkpointed steps while its 18-second, 10-unit, and 80%-memory limits permit. The browser performs read-only, single-flight polling and already rejects responses whose persisted `state_revision` is lower than the last rendered revision; no DPD production change was needed for the 1.0.15 status-freshness audit. Stage N+1 was removed with bounded existing-row prefetch and prepared multi-row writes; the already batched RU matcher and set-based transactional finalization were retained.

### DPD foreign-location performance follow-up (1.0.14)

The accepted 1.0.13 production import read 261,592 rows, including 24,209 supported foreign rows (20,810 BY), in about 5h30m; its slowest 500-row step was 132,024ms. Code-level profiling and a deterministic 500-row smoke reproduced the foreign hot path as 500 DPD mapping reads, 500 non-sargable identity reads, 500 redundant `find_by_id()` reads, and 500 unconditional location updates. The identity SQL constrained `active,country_code`, but each remaining predicate used `REPLACE(LOWER(searchable_text)) LIKE '%token%'`, so the compound index only narrowed the country and every row repeated the broad scan.

Version 1.0.14 keeps the exact normalization, mapped-ID preference, lowest-ID duplicate choice, legacy empty-district fallback, semantic counters, staging behavior, worker limits, and finalization. Per 500-row step it now deduplicates identities, prefetches DPD mappings in 250-ID chunks, prefetches foreign candidates per country in 100-identity chunks, replays sequential identity mutations in memory, and writes only unique changed/new locations in 100-row prepared statements. A failed bulk statement falls back to the established per-row save path so row-level warning behavior remains available. `foreign_locations_updated` still means “an existing canonical row was processed”; it is intentionally not redefined as “an SQL UPDATE occurred.”

Structural fixtures record the before/after query model. For 500 existing BY rows the path changes from 500 mapping + 500 identity + 500 `find_by_id` + 500 updates to 2 mapping queries + 5 identity prefetches + zero `find_by_id` + zero no-op writes. For 500 almost-unique AM/BY/KZ/KG rows it uses 2 mapping queries + 7 country/chunk identity prefetches + 5 location inserts. These are query-count assertions rather than machine-specific wall-time thresholds. No persistent column, index, migration, RU matcher, worker, or finalization change is involved; production duration and max-step acceptance remain required.

## 10. Shipment status autosync

The configured 15–1440 minute interval is a top-level business schedule and must not be confused with internal continuation cadence. The entire run executes in one callback with a 30-minute transient lock and `finally` cleanup; it does not suffer a one-minute gap.

The service loads all matching WooCommerce orders using `limit=-1`. If volume grows enough to approach PHP/lock limits, a separate design should add ID/keyset pagination, per-page checkpoints, and an owner lease. That work is independent of Russian Post/Ozon/Yandex continuation optimization and should not be bundled into the first phase.

## 11. Calendar

Calendar generation is one idempotent callback and schedules only the next month. There is no continuation pipeline and no optimization opportunity related to minute wake-ups.

## 12. Exact 60-second latency sites

| Pipeline | Exact production scheduling site | Requested delay | Nature |
|---|---|---:|---|
| Russian Post | `RussianPostPickupImporter::schedule_single()` | 5s | Technical continuation latency; remove between ordinary batches by slicing |
| Ozon | `OzonDeliveryPickupScheduler::run_step()` after a bounded slice | 1s | Technical continuation latency remains only between slices; 2/5/10s API retry branch is retained |
| Yandex | `YandexDeliveryGeoPipelineV2Runner::schedule_next_step()` | 1s | Technical continuation latency across normal stages |

No other production scheduler call creates second-scale WDC continuation actions.

## 13. Action Scheduler capabilities and limits

Action Scheduler exposes the public `as_enqueue_async_action()` API, which schedules an action “as soon as possible”; it does not synchronously execute it. See the [Action Scheduler API reference](https://actionscheduler.org/api/).

By default, Action Scheduler attempts to run its queue every minute through WP-Cron and also checks on admin-request shutdown whether a pending queue should be started through an asynchronous loopback. Its default runner stops around 90% memory or when another three actions are predicted to exceed 30 seconds, and defaults to one concurrent batch. See [How Action Scheduler works](https://actionscheduler.org/) and [Background processing at scale](https://actionscheduler.org/perf/).

Consequences for WDC:

- replacing `as_schedule_single_action(time()+1)` with `as_enqueue_async_action()` does not guarantee immediate execution under `DISABLE_WP_CRON=true`;
- the admin-shutdown loopback is opportunistic and is absent from a pure CLI `wp-cron.php` callback context; it must not be a correctness dependency;
- a loopback may run concurrently with another queue runner, so importer owner locks remain mandatory;
- Action Scheduler documents `ActionScheduler::runner()->run()` and `do_action('action_scheduler_run_queue', ...)` as ways to initiate a runner without WP-Cron in its [FAQ](https://actionscheduler.org/faq/), but invoking a nested general queue runner from a WDC callback would drain unrelated actions and complicate concurrency. WDC should not do this;
- there is no public WDC-scoped “execute this new action inline now” API.

The official WP-CLI runner is appropriate for large/long queues and supports batch count, hook, group, and concurrency controls: [Action Scheduler WP-CLI](https://actionscheduler.org/wp-cli/). It can reduce queue latency operationally, but it is optional infrastructure and only covers AS actions. The current Yandex continuation uses WP-Cron directly.

## 14. Infrastructure alternatives

| Option | Reliability / latency | Load and duplicate risk | Operations / supportability | Verdict for this VPS |
|---|---|---|---|---|
| A. Current: system cron each minute | Predictable starts; poor multi-action pipeline latency | Low; `flock` serializes launcher | Simple and WordPress-supported | Keep as baseline, but insufficient alone for long continuations |
| B. Re-enable native WP-Cron | Traffic may wake due actions earlier, but latency varies with traffic | More loopback attempts on checkout/admin traffic; WP cron locks usually prevent duplicate execution but add contention/load | Standard, but less predictable | **Do not enable** merely for throughput |
| C. Minute cron + bounded WDC workers | Predictable start; rapid progress within each callback | Controlled by per-job leases, time/memory/unit budgets | Small application change; no new daemon | **Recommended** |
| D. More frequent system wake-ups | Reduces all due-event latency | Repeated full WordPress bootstrap; overlap/cron-lock interactions; traditional cron has minute granularity unless wrapped in a loop | Easy to misuse and site-wide | Optional temporary mitigation only, not primary design |
| E. Persistent/repeated WP-CLI Action Scheduler worker | Highest AS throughput; official tooling | Concurrency must be configured; does not process direct Yandex WP-Cron continuation | Requires WP-CLI supervision and monitoring | Good later accelerator after staging test; not required for WDC correctness |
| F. systemd timer/loop | Fine-grained and supervised | Can create overlapping bootstraps without strict lock; affects all WP-Cron work | More VPS-specific operational code | Not preferred while C solves the bottleneck |

The existing `flock` should remain. If option E is piloted, use a separate lock, run as the site user, start with one runner, avoid `--force`, and do not filter hooks/groups until ordering dependencies have been audited. WordPress documents `wp cron event run --due-now` for explicit due-event execution: [WP-CLI cron event commands](https://developer.wordpress.org/cli/commands/cron/event/).

## 15. Native WP-Cron recommendation

Keep `DISABLE_WP_CRON=true`.

Re-enabling native WP-Cron would allow page loads to attempt a cron loopback when actions are due. On a busy shop this may occasionally reduce a continuation gap, but it makes execution traffic-dependent, adds loopback work to customer/admin request shutdown, and creates extra runner contention with the existing system cron. WordPress's own system-scheduler guidance says page-load cron is no longer necessary once an external scheduler exists.

It also does not solve the fundamental problem: a 369-action import is still 369 separately claimed/executed actions. Throughput should be bounded by explicit worker budgets and carrier limits, not by storefront traffic.

## 16. Proposed common worker-slice contract

Pseudo-contract:

```text
enter callback with job id and owner token
validate persisted state and ownership
start monotonic execution budget

while work remains:
    check cancellation and owner lease
    process one existing atomic unit
    persist checkpoint and diagnostics
    renew owner lease using compare-and-replace ownership

    if terminal:
        finalize and release owner lock
        return

    if retry/backoff required:
        schedule exactly one delayed continuation
        return

    if elapsed, memory, or unit budget is exhausted:
        schedule exactly one ordinary continuation
        return
```

Required properties:

- checkpoint after every atomic batch/page;
- owner-safe lease renewal after each successful checkpoint;
- cancellation check between units;
- monotonic soft wall-time, not wall-clock timestamps alone;
- hard unit cap even when the clock is mocked or a unit is extremely fast;
- memory guard based on configured PHP memory limit and current/peak usage;
- no database transaction spanning the whole slice;
- exactly one pending continuation for the job;
- idempotent resume after process death between checkpoint and schedule;
- stale callback cannot release or renew a newer owner's lease;
- retry/backoff is an explicit result that stops the loop;
- finalization is owner-checked and not run concurrently.

### Budget recommendation

Do not hard-code 20/25 seconds without measurements. Recommended initial defaults for web/Action Scheduler callbacks:

- soft elapsed budget: 18–20 seconds;
- reserve before likely 30-second host/AS limit: at least 5 seconds;
- per-pipeline max units: Russian Post 15; Ozon 8 normal remote units; Yandex local 25, with download counting as a terminal slice unit;
- memory stop: before 80% of an enforceable `memory_limit`, with a conservative fixed reserve when unlimited/unknown;
- if `max_execution_time` is positive and lower, derive soft budget from it; CLI `0` must not mean unbounded.

These are rollout starting points, not final constants. Production batch duration percentiles and API timing should determine them.

### Shared helper boundary

A shared, dependency-light `BackgroundExecutionBudget` is justified for elapsed/memory/unit decisions in Russian Post, Ozon, and Yandex. It may expose `can_continue()`, `record_unit()`, elapsed time, and the stop reason. It must not schedule actions, access job state, renew locks, or know carrier semantics.

## 17. Proposed per-subsystem changes

### Russian Post

Add the first bounded slice around the existing 500-object atomic batch. Reuse current offset, staging, guard diagnostics, lease renew, and owner-safe terminal cleanup. This is the lowest-risk and highest-confidence pilot because production duration is known.

### Ozon

Loop successful `run_step()` calls under a stricter remote-request/unit cap. Preserve 2/5/10-second retry actions exactly. Add cancellation/owner re-read between steps and test 404 salvage budget separately.

### Yandex

Add or prove an outer owner lease before multi-step execution. Loop local stage units; treat JSON download as one unit and stop if any lower runner pauses/errors. Preserve staging promotions. Consider moving the direct WP-Cron continuation to the existing AS wrapper only for observability consistency, not as the performance fix.

### DPD, shipment statuses, calendar

No change in this project. Record shipment status run size/duration in production; open a separate pagination design only if it approaches timeout or lock TTL.

### Browser workers

No migration in this phase. Document that closing the tab pauses work. A later product decision may move purely local GAR/snapshot/display jobs to bounded background workers, but API-paced browser jobs require provider-specific analysis.

## 18. Risks and concurrency

Main risks of multi-unit callbacks:

- longer lock hold time and slower cancellation response;
- more remote requests per PHP process;
- timeout during a unit;
- checkpoint/schedule race leaving no pending action;
- two runners claiming old/new continuation actions;
- stale owner cleanup affecting a new job;
- final staging promotion occurring twice;
- Action Scheduler queue fairness if one action runs too long.

Mitigations are the soft budget, hard unit cap, per-unit checkpoint/renew/cancel checks, unique continuation scheduling, owner compare-and-delete, phase-aware atomic commits, and conservative first rollout. The worker must never “catch up” an intentional retry within the same slice.

## 19. Testing strategy

Shared budget tests:

- monotonic elapsed stop, max-unit stop, memory stop, CLI unlimited-time behavior;
- enough reserve under a smaller positive `max_execution_time`;
- deterministic fake clock and memory provider.

Each pipeline:

- multiple batches in one callback and checkpoint after every batch;
- process death after checkpoint and before continuation schedule;
- process death before checkpoint;
- cancellation between batches;
- lease renewed while active and released only at terminal state;
- old owner cannot renew/release new owner;
- one continuation scheduled when budget ends;
- no continuation on terminal state;
- duplicate callback is harmless or explicitly rejected;
- staging promotion/finalize executes once;
- persisted progress is visible to status polling during a slice.

Ozon-specific:

- ordinary step loops;
- retryable error stops the slice and retains exact 2/5/10-second delay;
- 404 salvage respects request cap;
- API/nonretryable failure is terminal.

Yandex-specific:

- download does not immediately start an unbounded local loop;
- each of the 500/10/100-row stages resumes across request boundaries;
- scheduled and manual start cannot overlap;
- pause during a slice takes effect by the next atomic unit.

Integration/production-like:

- `DISABLE_WP_CRON=true`, one system wake per minute;
- fresh service/container objects per callback;
- Action Scheduler and WP-Cron stores, not only test doubles;
- verify number of scheduler actions falls substantially;
- verify checkout/admin response latency while workers run;
- capture batch duration p50/p95, slice duration, memory peak, actions/job, and lock renew failures.

## 20. Phased implementation plan

1. **Measurement only:** add bounded state diagnostics for unit duration, slice duration, units/slice, memory peak, continuation reason, and action count. Confirm p50/p95 on production-like data.
2. **Shared primitive:** introduce and unit-test the small execution-budget helper; no scheduler abstraction.
3. **Russian Post pilot — implemented in 1.0.5:** the existing 500-object atomic batch is wrapped by a 15-unit/18-second worker slice with an 80% finite-memory-limit guard. Checkpoints, activity timestamps, and owner renewals remain per batch; the callback schedules exactly one continuation on budget exhaustion or one finalize action on EOF. Production tuning should record slice duration, batches, objects, and stop reason on the 184k snapshot.
4. **Ozon — implemented in 1.0.16:** the existing API pages/100-ID batches run in 18-second/10-unit/80%-memory slices; retry ends the slice, ownership is revalidated between units, a same-option CAS execution token excludes same-owner callback overlap, and Stage N+1 writes are replaced by bounded bulk SQL without schema changes.
5. **Yandex — implemented in 1.0.15:** the outer pipeline has a session-and-token option lease and runs local work in 18-second/25-unit/80%-memory slices; the download remains an isolated terminal slice unit and continuations are owner-scoped.
6. **Operational option:** only after application changes, evaluate one Action Scheduler WP-CLI runner as an optional accelerator for the whole WooCommerce queue. It must not be a WDC installation requirement.
7. **Deferred review:** use shipment status duration/order-count diagnostics to decide whether it needs its own paginated worker project. Keep browser maintenance flows unchanged unless a separate UX requirement asks for tab-independent execution.

## 21. Final recommendation

- Keep system cron once per minute, `flock`, site user, PHP 8.4, and `DISABLE_WP_CRON=true`.
- Do not use traffic as an execution accelerator.
- Implement bounded in-callback slices for Russian Post first, then Ozon, then Yandex.
- Share only the execution-budget decision; keep carrier state, locking, retries, and finalization local.
- Preserve every intentional API/rate-limit/remote-poll delay.
- Treat WP-CLI Action Scheduler as optional infrastructure after the application contract is safe, observable, and tested.
