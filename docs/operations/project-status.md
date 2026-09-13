# Project Status

Version: 1.0.16

The current Walls Delivery Calc release source is version 1.0.16. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. Ozon pickup discovery/enrichment remains Action Scheduler-owned, but one callback now processes a bounded 18-second/10-unit/80%-memory slice and keeps each API page or 100-ID enrichment batch as its own transaction. Discovery and enrichment persistence use bounded multi-row/set-based SQL instead of per-ID writes, with no schema change. Yandex and DPD bounded workers, Russian Post imports, Shipment Framework, and checkout semantics remain unchanged.

Current production scope includes:

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
