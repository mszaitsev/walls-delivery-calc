# Cron And Background Jobs

Version: 1.0.8

WDC business clock times use `Asia/Novosibirsk`; scheduler APIs receive Unix timestamps. Owners register callbacks during plugin bootstrap and defer Action Scheduler inspection/creation until `action_scheduler_init`. Registration after that hook ensures the schedule immediately. Expected pre-initialization and disabled/no-work states are silent.

Current recurring owners:

- shipment status autosync, with a configurable 15–1440 minute interval and a 30-minute overlap lock;
- DPD pickup-point synchronization;
- Yandex geography pipeline jobs;
- Russian Post pickup-point import;
- Ozon Delivery two-phase pickup catalog discovery/enrichment with bounded batches, a renewable lease, retry delay, and atomic snapshot publication;
- calendar generation, self-scheduled for 09:00 Asia/Novosibirsk on the first Monday of each month.

Russian Post pickup API imports validate complete Otpravka credentials before acquiring their import lock. Queued/running jobs use one atomic option lease owned by the unique import job ID; init, batch, and finalize callbacks renew it, while terminal success, failure, cancellation, and stale-state recovery release only that owner's lease. Status polling treats a valid, unexpired owner lease as authoritative and therefore cannot mistake an Action Scheduler inter-request pause for an orphaned pipeline. If an active callback has lost its lease, the job becomes explicitly failed with bounded state diagnostics; a callback for a different active job throws without touching that job or its lease. A terminal pre-1.0.2 job may leave the former scalar transient behind; status refresh or the next manual start removes that legacy lock only while the persisted job state is terminal. Background download and ZIP extraction explicitly load `wp-admin/includes/file.php` before using `wp_tempnam()` because Action Scheduler and WP-Cron do not guarantee the admin File API include.

Since 1.0.5, the ordinary Russian Post parse/upsert continuation uses a bounded worker slice. One callback processes up to 15 batches of 500 objects while its 18-second soft wall-time and 80%-of-finite-`memory_limit` guard allow it. Every batch persists counters and the byte offset, updates activity, and renews the owner lease before the next batch. EOF schedules one finalize action; a budget stop schedules one batch continuation; cancellation, lock loss, or failure schedules none. The once-per-minute system cron with `DISABLE_WP_CRON=true` remains the production baseline.

Manual Russian Post cancellation makes the persisted terminal state authoritative before cleanup. Late checkpoints cannot change a terminal state, and a worker rereads status and ownership before renewing its lease or starting another batch. Lock release uses owner-checked compare-delete; if a concurrent renew changed the same job's lease after an option-cache read, it invalidates only the precise option cache, rereads once, verifies the job ID again, and retries. Terminal status refresh applies the same cleanup, while a lock belonging to a newer job is never removed.

Since 1.0.7, `init`, `batch`, and `finalize` use one foreign-callback policy: a callback whose immutable argument `import_id` differs from the persisted active job fails without recording diagnostics into, cleaning, failing, renewing, scheduling for, or unlocking that job. Unexpected-failure handling rechecks state ownership before building a result and before cleanup, and never lets mutable current state replace the callback owner ID.

Version 1.0.8 adds diagnostic-only profiling around each Russian Post atomic batch. Timings use a monotonic clock and cover payload reading, parsing, normalization, location matching, staging preparation/write, checkpoint, and lock renewal. State retains only the last profile, ten slow profiles at the existing 10-second threshold, aggregate counters, and at most 200 integer duration samples. Lookup values, SQL text, payload data, and credentials are never persisted. The 500-row batch, 18-second/15-unit worker slice, scheduling, lease, and retry contracts are unchanged.

Ozon browser polling reads local progress only and never executes background work. Checkout reads published local pickup snapshots and never initiates imports.

Handlers call application services rather than controllers/renderers. API jobs must be idempotent where possible and protect overlapping execution. There are no prepared-FIAS or automatic GAR/SPAS background jobs in the 1.0 runtime.
