# Walls Delivery Calc

Version: 0.141.6

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current Ozon Delivery scope: pickup checkout pricing for `Ozon до ПВЗ` is implemented through the carrier-owned quote layer and official Ozon Delivery checkout contract, but remains fail-closed until a safe admin live diagnostic succeeds for the configured `shipment_method_id`. Checkout uses the standard WooCommerce `billing_phone`, including AJAX `post_data`, and Ozon has an optional carrier-owned fallback phone for missing or locally invalid customer numbers. The Ozon rate carries the trusted `pickup_provider_query.destination_fingerprint` required by the generic pickup REST resolver, and the buyer map receives every active cargo-compatible local Ozon point inside the trusted 60 km destination radius without arbitrary first-100/500 truncation. Ozon pickup points declare generic `requires_rate_refresh`, so saving a point triggers selected-point authoritative repricing through the shared checkout lifecycle. Ozon Shipment Framework features are intentionally not included in this version.

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
