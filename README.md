# Walls Delivery Calc

Version: 0.155.9

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current stage: 0.155.9 moves manual Delivery Service creation from the inline list-page form to `admin.php?page=wdc-delivery-services&action=create`. The list page now keeps the services table, global shop-processing settings, and a primary `Создать новую службу` button. Successful creates redirect to the existing edit screen for the new `service_key`; validation/storage errors stay on the create screen with submitted values preserved. Manual service keys are validated after `sanitize_key()` normalization against all persisted services, including soft-deleted rows, and against reserved predefined service keys.

## Quick Start

New ChatGPT chat:

1. Read [docs/development/chat-start.md](docs/development/chat-start.md).
2. Prepare the Codex task with [docs/development/codex-prompt-template.md](docs/development/codex-prompt-template.md).

New Codex task:

1. Read [docs/README.md](docs/README.md) and the task-specific docs before editing.
2. Follow [docs/development/development-workflow.md](docs/development/development-workflow.md).

New developer:

1. Start with [docs/architecture/plugin-architecture.md](docs/architecture/plugin-architecture.md).
2. For shipment/carrier work, read [docs/architecture/shipment-framework.md](docs/architecture/shipment-framework.md) and [docs/development/new-carrier-guide.md](docs/development/new-carrier-guide.md).

Primary local regression command:

```bash
php tests/shipments/run-shipment-regression-profile.php
```

For adding a transport company, use [docs/development/new-carrier-guide.md](docs/development/new-carrier-guide.md).
