# Installation And Runtime

Version: 1.0.18

## Requirements

- WordPress 6.8+
- WooCommerce 9.0+
- PHP 8.4+

## Production installation

Build the package from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-release.ps1
```

This creates `dist/walls-delivery-calc-1.0.18.zip` with one top-level `walls-delivery-calc/` directory. Install it through **Plugins → Add Plugin → Upload Plugin**, then activate **Walls Delivery Calc**. No dependency installation or asset compilation is required on the server.

The package contains only runtime files: the main plugin entry, `uninstall.php`, `src/`, `assets/`, and `database/`. It intentionally excludes tests, documentation, VCS files, local output, and development configuration. `src/Export-GarPlaces.ps1` is included because the Locations admin UI offers it as a protected download.

## Activation and schema

Runtime boot order is:

1. plugin constants and autoloader;
2. service registration in `Plugin`;
3. WordPress, WooCommerce, AJAX, REST, cron, and admin hooks;
4. `database/migrations/0001_initial_schema.php` through `MigrationManager`;
5. module boot and scheduled-task registration.

An empty database receives the complete 1.0 schema directly. The migration is idempotent and can normalize an already-correct development database without dropping or truncating business tables. Retired pre-1.0 tables are not created; an existing development database may retain such inert tables until an operator removes them separately.

Deactivation removes no business data. Reactivation reruns safe lifecycle checks. `uninstall.php` clears only ephemeral plugin caches and does not destructively remove business tables or settings.
