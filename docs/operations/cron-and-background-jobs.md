# Cron And Background Jobs

Version: 1.0.3

WDC business clock times use `Asia/Novosibirsk`; scheduler APIs receive Unix timestamps. Owners register callbacks during plugin bootstrap and defer Action Scheduler inspection/creation until `action_scheduler_init`. Registration after that hook ensures the schedule immediately. Expected pre-initialization and disabled/no-work states are silent.

Current recurring owners:

- shipment status autosync, with a configurable 15–1440 minute interval and a 30-minute overlap lock;
- DPD pickup-point synchronization;
- Yandex geography pipeline jobs;
- Russian Post pickup-point import;
- Ozon Delivery two-phase pickup catalog discovery/enrichment with bounded batches, a renewable lease, retry delay, and atomic snapshot publication;
- calendar generation, self-scheduled for 09:00 Asia/Novosibirsk on the first Monday of each month.

Russian Post pickup API imports validate complete Otpravka credentials before acquiring their import lock. Queued/running jobs use one atomic option lease owned by the import job ID; terminal success, failure, cancellation, and stale-state recovery release only that owner's lease. A terminal pre-1.0.2 job may leave the former scalar transient behind; status refresh or the next manual start removes that legacy lock only while the persisted job state is terminal. A queued/running state remains authoritative and cannot be displaced by another manual or cron start. Background download and ZIP extraction explicitly load `wp-admin/includes/file.php` before using `wp_tempnam()` because Action Scheduler and WP-Cron do not guarantee the admin File API include.

Ozon browser polling reads local progress only and never executes background work. Checkout reads published local pickup snapshots and never initiates imports.

Handlers call application services rather than controllers/renderers. API jobs must be idempotent where possible and protect overlapping execution. There are no prepared-FIAS or automatic GAR/SPAS background jobs in the 1.0 runtime.
