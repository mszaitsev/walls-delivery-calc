# Installation And Runtime

Version: 0.128.11

The plugin requires WordPress 6.8+, PHP 8.4+, WooCommerce 9.0+, and the main plugin file `walls-delivery-calc.php`.

Runtime boot:

1. plugin constants and `WDC_VERSION`;
2. core bootstrap and autoloader;
3. `Plugin` service registration;
4. WordPress hooks, AJAX, REST, cron, and admin pages;
5. migrations and startup module checks on `plugins_loaded`.

No production data migration is required for pre-0.122 internal wire aliases because the plugin has not been deployed to production.

Shipment cost analytics creates `{$wpdb->prefix}wdc_shipment_cost_analytics` through the normal migration manager. It is a materialized read-model table rebuilt one order at a time after canonical order/shipment changes. No historical analytics import is installed because new deployments start without old orders.
