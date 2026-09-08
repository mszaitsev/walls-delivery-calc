# Walls Delivery Calc

Version: 0.155.12

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

Current stage: 0.155.12 adjusts the WooCommerce checkout address field presentation inside the WDC checkout runtime. Billing fields now render as name/patronymic, surname, country, city, region, postcode, address, phone, and email; address line 2 is removed from billing/shipping checkout fields. Address line 1 stays visible but optional by default and becomes required only when the selected WDC shipping rate is classified as courier by generic delivery metadata. Phone is required, email keeps WooCommerce's existing requirement, and order comments use the multiline "Здесь пишем:" placeholder for special handling notes.

0.155.11 improves checkout country/region/city handling for countries present in the local location database. Legacy WooCommerce city/state values are reconciled by a conservative PHP matcher with no fuzzy matching: country must match exactly, normalized settlement names must match exactly, and normalized region must match when present; ambiguous or missing matches clear checkout city/state/postcode and WDC location metadata so the customer selects a supported location. Canonical database selections fill city/state/postcode and lock the region field without disabling POST; manual fallback is available only after a successful zero-result search, marks `wdc_platform_location_selected_source=manual`, clears canonical identifiers, unlocks region entry, and requires city plus region at validation. Manual trust is transient to the current WooCommerce checkout session and is cleared after successful order processing, so later checkout loads re-check saved Woo profile text.

0.155.10 adds global checkout delivery messages on the platform settings page. Administrators can enable an informational WYSIWYG text and an optional promo WYSIWYG text before the delivery sort selector; both are off by default for upgrades. Promo rendering compares an integer kopeck threshold against either the existing full-cart post-discount total or the new minimal WooCommerce-boundary shippable-items total, replaces `{s}` and `{d}` with plain numeric ruble amounts, sanitizes stored/rendered HTML, and refreshes through the normal WooCommerce checkout review lifecycle. The server emits a valid source table row and the existing checkout DOM enhancement moves messages into the shipping cell before the sort control.

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
