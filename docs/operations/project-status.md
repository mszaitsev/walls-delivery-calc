# Project Status

Version: 1.0.11

The current Walls Delivery Calc release source is version 1.0.11. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. The accepted 1.0.9 Russian Post exact-FIAS prefetch and bulk staging writes remain in production; temporary performance and lock-forensic diagnostics are removed. Russian Post weekly pickup refresh keeps its explicit weekday and quarter-hour time in `Asia/Novosibirsk`. Russian Post shipment tracking now exposes the complete 490-row native status catalog and configurable universal mapping in Delivery Services while preserving every 1.0.10 mapping and terminal lifecycle decision. Tracking cadence remains owned exclusively by the common shipment status autosync. Matching decisions, worker-slice limits, lock lifecycle, and schema baseline remain unchanged.

Current production scope includes:

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
