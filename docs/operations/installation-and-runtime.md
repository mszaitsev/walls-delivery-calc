# Installation And Runtime

Version: 0.132.1

The plugin requires WordPress 6.8+, PHP 8.4+, WooCommerce 9.0+, and the main plugin file `walls-delivery-calc.php`.

Runtime boot:

1. plugin constants and `WDC_VERSION`;
2. core bootstrap and autoloader;
3. `Plugin` service registration;
4. WordPress hooks, AJAX, REST, cron, and admin pages;
5. migrations and startup module checks on `plugins_loaded`.

No production data migration is required for pre-0.122 internal wire aliases because the plugin has not been deployed to production.

Shipment cost analytics creates `{$wpdb->prefix}wdc_shipment_cost_analytics` through the normal migration manager. It is a materialized read-model table rebuilt one order at a time after canonical order/shipment changes. No historical analytics import is installed because new deployments start without old orders.

PEK foundation creates its carrier-owned geography and destination terminal tables through migrations `0048` and `0049`. Migration history is not considered a complete proof of physical schema integrity, because an installer can fail silently at the WordPress `dbDelta()` boundary if postconditions are not checked. Migration `0050_repair_pek_foundation_schema.php` performs controlled PEK schema integrity recovery during the migration lifecycle: it checks `wdc_pek_location_mappings` and `wdc_pek_terminals` with the active `$wpdb->prefix`, invokes the existing repository installer only for each missing table, verifies both tables exist afterward, and throws before migration state advances if repair is incomplete. The PEK location mapping schema uses physical `mapping_precision` rather than reserved MySQL identifier `precision`; repository read/write methods translate this to the domain `precision` key. Migration `0051_migrate_pek_mapping_precision_column.php` supports legacy mapping tables that already contain physical `` `precision` `` by adding `mapping_precision` and backfilling only missing new values.

PEK schema recovery is idempotent and non-destructive. It does not drop, truncate, delete, import rows, edit canonical `wdc_locations`, or call PEK APIs. Runtime repository reads/writes and PEK admin diagnostics still fail closed on SQL errors and do not create tables themselves; schema repair belongs only to installation/update control flow. PEK installers check unavailable `dbDelta()` and `$wpdb->last_error` immediately, and plugin boot logs migration failures plus an admin notice instead of allowing an unhandled site-wide fatal.
