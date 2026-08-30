# Walls Delivery Calc

Version: 0.141.19

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current Ozon Delivery scope: pickup checkout pricing for `Ozon до ПВЗ` is implemented through the carrier-owned quote layer and official Ozon Delivery checkout contract, but remains fail-closed until a safe admin live diagnostic succeeds for the configured `shipment_method_id`. Version 0.141.19 adds one INFO record after each successful Ozon checkout quote. Its carrier-owned allowlist includes package counts/weights, packing strategy, safe parcel dimensions, normalized declared values, normalized posting delivery/insurance totals, and final totals; it excludes buyer, address, raw API, product, pickup-row, and credential data. Packaging, pricing, pickup filtering, map behavior, and Shipment Framework remain unchanged.

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
