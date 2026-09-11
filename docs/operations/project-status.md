# Project Status

Version: 1.0.4

The current Walls Delivery Calc release source is version 1.0.4. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. Patch 1.0.4 preserves the WordPress File API bootstrap from 1.0.3 and hardens the Russian Post pickup background pipeline: status polling cannot release a valid owner lease, callbacks renew that lease, invalid callback invariants become explicit failures, and native cURL streams only into its temporary file. The schema baseline remains 1.0.0.

Current production scope includes:

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
