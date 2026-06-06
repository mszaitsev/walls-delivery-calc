# WDC Core Platform

Version `0.20.0` makes the platform core the only runtime.

## Architecture

Runtime code lives under `src/` and uses the `WallsShop\WDC` namespace. Legacy `WDC_*` classes, the old shipping method, and `includes/*` bootstrap have been removed.

Primary areas:

- `Core` for bootstrap, environment, constants access, feature flags, requirements, and the service container.
- `Infrastructure` for logging, settings, encryption, database migration skeletons, and queue abstraction.
- `WooCommerce` for WooCommerce-specific integration such as HPOS compatibility.
- `Admin` for the new platform status page and notices.
- `Domain`, `Carriers`, `Rules`, `Calendar`, `Locations`, `Pickup`, `Checkout`, and `Orders` hold the active platform modules.

## Namespaces

The new namespace prefix is `WallsShop\WDC\`.

Examples:

- `WallsShop\WDC\Core`
- `WallsShop\WDC\Infrastructure`
- `WallsShop\WDC\Domain`

There are no runtime `WDC_*` class dependencies.

## Autoloader

`src/Core/Autoloader.php` provides a lightweight PSR-4 style autoloader for the new namespace only. It maps `WallsShop\WDC\` to `src/` and uses `require_once`.

`src/Core/bootstrap.php` registers the autoloader and creates the new `WallsShop\WDC\Core\Plugin` bootstrap.

## Container

`src/Core/Container.php` is a small lazy service container with:

- `register(id, factory)`
- singleton service instances
- lazy initialization
- `get(id)`
- `has(id)`

It is intentionally not a full DI framework.

## Logger

`src/Infrastructure/Logging/Logger.php` wraps `wc_get_logger()` with source `walls-delivery-calc`.

Supported levels:

- `debug`
- `info`
- `warning`
- `error`

`LogRedactor` recursively redacts sensitive context keys including password, token, secret, api_key, authorization, phone, and email.

## Settings Repository

`src/Infrastructure/Settings/SettingsRepository.php` stores new platform settings in the `wdc_core_settings` option.

It supports reading and writing settings plus typed getters:

- `get_string`
- `get_bool`
- `get_int`
- `get_array`

Legacy settings are not migrated; this runtime generation is fresh-install-only.

## Encryption Service

`src/Infrastructure/Security/EncryptionService.php` uses OpenSSL AES-256-GCM.

Key material comes from `WDC_SECRET_KEY` when defined. Otherwise it falls back to WordPress salts.

This service is reserved for later API credentials.

## Migration System

`src/Infrastructure/Database/MigrationManager.php` is a skeleton only. It tracks the migration option name `wdc_db_version` and code version `0.4.0`.

No tables are created and no migrations are executed in this stage.

## Action Scheduler Abstraction

`src/Infrastructure/Queue/ActionScheduler.php` wraps Action Scheduler functions behind:

- `schedule_single`
- `schedule_recurring`
- `unschedule`
- `has_scheduled`

When Action Scheduler is unavailable, it logs a warning and returns safely.

## HPOS Declaration

`src/WooCommerce/HPOSCompatibility.php` declares compatibility with `custom_order_tables` via WooCommerce `FeaturesUtil` on `before_woocommerce_init`.

Older WooCommerce versions are handled with a class-exists fallback.

## Feature Flags

`src/Core/FeatureFlags.php` currently stores flags in PHP:

- `new_core_enabled => true`
- `new_checkout_flow_enabled => false`
- `new_carriers_enabled => false`
- `new_shipping_method_enabled => false`

They will later move to settings.

## Runtime Bootstrap

The main plugin file now loads only the platform bootstrap:

- `src/Core/bootstrap.php`

`Plugin` registers the service container, WooCommerce shipping method registrar, checkout runtime, admin pages, activation migrations, and scheduled platform jobs.
