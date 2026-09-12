# Project Status

Version: 1.0.15

The current Walls Delivery Calc release source is version 1.0.15. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. The Yandex full pickup/geography pipeline now processes bounded multi-unit slices inside each WP-Cron continuation while retaining its existing atomic batches, stage order, staging promotion, and poll-only browser. Its outer session is protected by a carrier-owned renewable option lease, and missing owner-scoped continuations self-heal during schedule ensure. DPD Geography continues through the bounded server-side Action Scheduler worker introduced in 1.0.13; the browser only polls progress. Its 500-row RU matching and set-based finalization are unchanged. Foreign AM/BY/KZ/KG rows resolve DPD mappings and canonical location identities through bounded batch prefetches, persist changed/new canonical locations through bounded bulk statements, and avoid no-op updates while preserving the historical outcome counters and duplicate/legacy identity decisions. PHP `soap`/`SoapClient` is required for DPD SOAP APIs and PHP `ssh2` for automatic SFTP geography download; manual CSV remains the fallback. Russian Post 1.0.9–1.0.12 optimizations, scheduling, status mapping, and lifecycle semantics remain unchanged.

Current production scope includes:

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
