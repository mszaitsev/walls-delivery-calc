# Project Status

Version: 1.0.25

The current Walls Delivery Calc release source is version 1.0.25. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. Shipment production acceptance now uses the WooCommerce display order number in the modal heading, keeps postcode out of the manager-editable normalization source while retaining the separate shipment postcode, and labels the Ozon pickup scenario explicitly. Ozon shipment mutations, status reads, and documents use bounded operation-specific timeouts and retry only transient failures; order creation keeps one idempotency key, checkpoints known carrier references before approval, and reconciles ambiguous approval results before another mutation. Carrier status eligibility owns Ozon label availability, protected document failures remain inside the shipment block, and every successful AJAX refresh clears stale errors and renders one fresh canonical status payload including universal status and document actions. Shipment Framework boundaries and schema remain unchanged.

Current production scope includes:

The Shipment package editor now treats its current modal draft as authoritative for summaries, warnings, preview, and create. Package overweight remains non-blocking by default; CDEK alone hard-blocks incomplete order-item allocation and any place whose current allocated item weight exceeds its manager-entered weight. Each eligible package has a deterministic **Подогнать вес товаров** action; edits remain ephemeral and do not mutate WooCommerce order/product data.

CDEK destination pickup now supports carrier-specific child-city coverage for eligible canonical RU cities without importing that carrier geography into WDC. Primary versus selected effective CDEK destinations are explicit, and the lazy daily region directory participates in the unified delivery-cache clear.

- WooCommerce checkout delivery calculation, sorting, pickup selection, and recalculation;
- shared delivery services, rules, calendars, locations, and shipment lifecycle infrastructure;
- carrier integrations registered by the composition root in `src/Core/Plugin.php`;
- HPOS-compatible WooCommerce order access;
- Action Scheduler and WP-Cron jobs documented in [cron-and-background-jobs.md](cron-and-background-jobs.md);
- a self-contained release ZIP built by `tools/build-release.ps1`.

The authoritative release decision is the hardening report produced after the full regression suite, fresh database activation, ZIP installation, and representative `WP_DEBUG` acceptance flow. A source checkout passing smoke tests is not by itself production acceptance.

Known environment-dependent and baseline allowances are tracked in [technical-debt.md](technical-debt.md). They must not be silently treated as passing release gates.
