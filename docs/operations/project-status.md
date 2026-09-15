# Project Status

Version: 1.0.24

The current Walls Delivery Calc release source is version 1.0.24. It uses one fresh-install schema migration and does not promise upgrade compatibility from arbitrary unpublished 0.x development databases. Checkout order creation now fails closed when the current persisted WDC rate requires a customer-selected pickup point but the authoritative family/carrier/current-destination selection is absent; representative quote stations remain non-authoritative. Yandex pickup-map selection is reconciled only by namespaced provider station/id/code identity, and side-list preview explicitly focuses the map without committing the point or allowing programmatic bounds refresh to transfer selection. The standalone WDC configuration console requires `manage_options`; WooCommerce order operational tools retain their explicit `manage_woocommerce` contract. Order-admin delivery recalculation preserves canonical selected-location state/city values when saving pickup delivery, while pickup address and point postcode remain carrier-owned. Yandex manual location overrides may retain a truthful empty source region and bind that incomplete identity to the exact geo id and locality. Calendar admin alignment, background workers, imports, and Shipment Framework remain unchanged.

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
