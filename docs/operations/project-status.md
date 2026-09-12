# Project Status

Version: 1.0.13

The current Walls Delivery Calc release source is version 1.0.13. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. DPD Geography now continues through a bounded server-side Action Scheduler worker after manual CSV or SFTP source acquisition; the browser only polls progress. Existing 500-row matching semantics and set-based finalization are unchanged, while staging candidate persistence uses bounded prefetch and bulk writes instead of a SELECT plus write per row. PHP `soap`/`SoapClient` is required for DPD SOAP APIs and PHP `ssh2` for automatic SFTP geography download; manual CSV remains the fallback. Russian Post 1.0.9–1.0.12 optimizations, scheduling, status mapping, and lifecycle semantics remain unchanged.

Current production scope includes:

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
