# Cron And Background Jobs

Version: 1.0.18

WDC business clock times use `Asia/Novosibirsk`; scheduler APIs receive Unix timestamps. Owners register callbacks during plugin bootstrap and defer Action Scheduler inspection/creation until `action_scheduler_init`. Registration after that hook ensures the schedule immediately. Expected pre-initialization and disabled/no-work states are silent.

Current recurring owners:

- shipment status autosync, with a configurable 15–1440 minute interval and a 30-minute overlap lock;
- DPD pickup-point synchronization;
- Yandex geography pipeline jobs;
- Russian Post pickup-point import;
- Ozon Delivery two-phase pickup catalog discovery/enrichment with bounded batches, a renewable lease, retry delay, and atomic snapshot publication;
- calendar generation, self-scheduled for 09:00 Asia/Novosibirsk on the first Monday of each month.

Russian Post shipment tracking is a participant in the single shared `wdc_shipment_status_autosync` job. Its enablement, eligible WooCommerce order statuses, and 15–1440 minute cadence are controlled only by the global shipment-status settings page. There is no Russian Post-specific status recurrence. The separate `wdc_russian_post_pickup_import` weekly schedule controls only the pickup/OPS catalog import.

Russian Post pickup API imports validate complete Otpravka credentials before acquiring their import lock. Queued/running jobs use one atomic option lease owned by the unique import job ID; init, batch, and finalize callbacks renew it, while terminal success, failure, cancellation, and stale-state recovery release only that owner's lease. Status polling treats a valid, unexpired owner lease as authoritative and therefore cannot mistake an Action Scheduler inter-request pause for an orphaned pipeline. If an active callback has lost its lease, the job becomes explicitly failed with bounded state diagnostics; a callback for a different active job throws without touching that job or its lease. A terminal pre-1.0.2 job may leave the former scalar transient behind; status refresh or the next manual start removes that legacy lock only while the persisted job state is terminal. Background download and ZIP extraction explicitly load `wp-admin/includes/file.php` before using `wp_tempnam()` because Action Scheduler and WP-Cron do not guarantee the admin File API include.

Since 1.0.5, the ordinary Russian Post parse/upsert continuation uses a bounded worker slice. One callback processes up to 15 batches of 500 objects while its 18-second soft wall-time and 80%-of-finite-`memory_limit` guard allow it. Every batch persists counters and the byte offset, updates activity, and renews the owner lease before the next batch. EOF schedules one finalize action; a budget stop schedules one batch continuation; cancellation, lock loss, or failure schedules none. The once-per-minute system cron with `DISABLE_WP_CRON=true` remains the production baseline.

Manual Russian Post cancellation makes the persisted terminal state authoritative before cleanup. Late checkpoints cannot change a terminal state, and a worker rereads status and ownership before renewing its lease or starting another batch. Lock release uses owner-checked compare-delete; if a concurrent renew changed the same job's lease after an option-cache read, it invalidates only the precise option cache, rereads once, verifies the job ID again, and retries. Terminal status refresh applies the same cleanup, while a lock belonging to a newer job is never removed.

Since 1.0.7, `init`, `batch`, and `finalize` use one foreign-callback policy: a callback whose immutable argument `import_id` differs from the persisted active job fails without recording diagnostics into, cleaning, failing, renewing, scheduling for, or unlocking that job. Unexpected-failure handling rechecks state ownership before building a result and before cleanup, and never lets mutable current state replace the callback owner ID.

Version 1.0.10 retains the accepted 1.0.9 exact-FIAS prefetch and bounded 100-row staging inserts. The temporary batch profiler and lock forensic journal have been removed; operational state keeps only lifecycle, progress, guard, memory, and worker-slice fields.

DPD Geography manual CSV and SFTP starts now queue the one-shot `wdc_dpd_geography_import_worker`; they do not add a recurring schedule. One callback runs up to ten independently checkpointed 500-row steps within an 18-second soft budget and the shared 80%-of-finite-memory limit. A budget stop creates one continuation for the current job and byte offset. The admin browser only polls status. The once-per-minute system cron with `DISABLE_WP_CRON=true` remains the recommended trigger model; it may still leave a minute between slices, but no longer leaves that gap between every 500-row step.

The Yandex full pickup/geography continuation remains direct WP-Cron. Its callback runs up to 25 independently checkpointed stage units within an 18-second soft budget and the shared 80%-of-finite-memory guard; unlimited or unknown memory still remains bounded by time and unit caps. Existing atomic sizes remain 500 pickup objects, 500 geo IDs, 10 enrichment rows, one region sync, and 100 location-mapping geo IDs. The full JSON download counts as one heavy unit and ends the slice before local import work. Each continuation carries the outer `session_id`; a 300-second carrier-owned option lease with a random token prevents overlap and uses owner-safe renew/release and expired takeover. A legacy no-argument callback performs no work and may only repair one current owner-scoped continuation. Bootstrap schedule ensure repairs a missing continuation without running work inline.

DPD SOAP calls require the PHP `soap` extension and `SoapClient`. Automatic DPD SFTP geography acquisition requires the PHP `ssh2` extension; manual CSV upload remains available without `ssh2`.

The Russian Post weekly start is configured by ISO weekday (`1` Monday through `7` Sunday) and a quarter-hour `HH:MM` value. Its only business timezone is `Asia/Novosibirsk`. A fresh install defaults to Monday 09:00. An upgraded installation with weekly scheduling enabled but without the new explicit fields keeps its existing WP-Cron timestamp and derives the displayed weekday/time from that event until the administrator explicitly saves the form. Changing enabled state, weekday, or time synchronizes the event immediately; bootstrap `sync_schedule()` remains a duplicate-free self-healing check.

Ozon browser polling reads local progress only and never executes background work. Checkout reads published local pickup snapshots and never initiates imports.

Since 1.0.16, one Ozon pickup Action Scheduler callback processes up to ten existing discovery/enrichment API units within an 18-second soft wall-time and 80%-of-finite-memory guard. Each unit keeps its own transaction; a retry ends the slice and retains the existing 2/5/10-second delay. The 900-second owner lease is revalidated between units, and a CAS execution token in that same lease excludes overlapping callbacks even when they carry the same `(job_id, owner)`. Exact continuation checks prevent duplicates, and Action Scheduler initialization repairs a missing continuation without running work inline.

Handlers call application services rather than controllers/renderers. API jobs must be idempotent where possible and protect overlapping execution. There are no prepared-FIAS or automatic GAR/SPAS background jobs in the 1.0 runtime.
