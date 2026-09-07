# Walls Delivery Calc

Version: 0.155.8

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current stage: 0.155.8 adds fixed/dynamic shop processing working days on Delivery Services. Existing installs stay in fixed mode and keep `shop_processing_working_days` semantics. Dynamic mode uses `extra_processing_days + ceil(active_orders / orders_per_day)`, counts selected WooCommerce order statuses through the HPOS-compatible `wc_get_orders()` paginated total boundary, caches the aggregate for 5 minutes, and invalidates it on order status changes and processing-settings saves.

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
