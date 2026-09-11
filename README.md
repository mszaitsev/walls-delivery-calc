# Walls Delivery Calc

Walls Delivery Calc is a WooCommerce delivery-calculation and shipment-management plugin.

Current release: **1.0.7**.

Requirements:

- WordPress 6.8 or newer;
- WooCommerce 9.0 or newer;
- PHP 8.4 or newer.

The production package is self-contained: installing it does not require Composer, Node.js, pnpm, or a build step on the WordPress host.

## Build a release package

From the repository root, run:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-release.ps1
```

The command creates `dist/walls-delivery-calc-1.0.7.zip`. Install that archive through **WordPress → Plugins → Add Plugin → Upload Plugin**.

## Development

Start with [docs/README.md](docs/README.md), then follow [docs/development/development-workflow.md](docs/development/development-workflow.md). The primary shipment regression command is:

```bash
php tests/shipments/run-shipment-regression-profile.php
```
