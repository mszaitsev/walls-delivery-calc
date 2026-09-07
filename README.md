# Walls Delivery Calc

Version: 0.155.10

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current stage: 0.155.10 adds global checkout delivery messages on the platform settings page. Administrators can enable an informational WYSIWYG text and an optional promo WYSIWYG text before the delivery sort selector; both are off by default for upgrades. Promo rendering compares an integer kopeck threshold against either the existing full-cart post-discount total or the new minimal WooCommerce-boundary shippable-items total, replaces `{s}` and `{d}` with plain numeric ruble amounts, sanitizes stored/rendered HTML, and refreshes through the normal WooCommerce checkout review lifecycle.

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
