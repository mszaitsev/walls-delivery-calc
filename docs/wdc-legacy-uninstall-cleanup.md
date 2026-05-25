# WDC Legacy Uninstall Cleanup

Archived/not-runtime as of 0.20.0: this document describes cleanup behavior from the removed legacy runtime and is retained only as historical context.

This document describes the cleanup performed by the legacy `walls-delivery-calc` plugin when it is deleted through the standard WordPress plugin deletion flow.

## What uninstall.php removes

- The plugin settings option:
  - `wdc_settings`
- The matching network option:
  - `wdc_settings`
- WordPress transients in the options table with WDC-only names:
  - `_transient_wdc_%`
  - `_transient_timeout_wdc_%`
  - `_site_transient_wdc_%`
  - `_site_transient_timeout_wdc_%`
- On multisite, if the `sitemeta` table is available through `$wpdb->sitemeta`, matching WDC site transient rows are also removed from the current network context.

These patterns cover legacy WDC cache entries, including the Russian Post country directory cache, API cache entries created through `WDC_Cache`, and temporary admin transients such as:

- `wdc_russian_post_countries_refresh_error_{user_id}`
- `wdc_bulk_country_preview_{user_id}`
- `wdc_bulk_country_apply_result_{user_id}`

## What uninstall.php intentionally does not remove

- Order meta matching `_wdc_*`.
- WooCommerce logs.
- Orders or order data.
- WooCommerce shipping methods.
- Custom database tables.

The legacy plugin did not create custom database tables, so there are no WDC tables to drop.

## Why order meta is preserved

Order meta matching `_wdc_*` may be part of historical order records. Removing it automatically during plugin deletion could make past orders harder to audit, troubleshoot, or reconcile. For that reason, order meta is preserved unless a site owner performs a separate, deliberate data cleanup.

## Why WooCommerce logs are preserved

WooCommerce logs can be useful for diagnosing historical issues and confirming past behavior during migration. They may also contain context shared with other operational logs. The uninstall routine does not delete logs automatically so that site owners can decide whether and when to remove them.

## How to verify cleanup

1. Activate the plugin cleanup build.
2. Delete the plugin through **WordPress Plugins -> Delete**.
3. Check that the `wdc_settings` option no longer exists.
4. Check that transients with the `wdc_` prefix no longer exist.
