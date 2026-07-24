# Walls Delivery Calc

Version: 0.128.1

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

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
