# Walls Delivery Calc

Version: 0.155.15

0.155.15 adds an on-demand paired Locations + aliases database backup on the Locations admin page. Verified shadow copies are restored together with an atomic table swap; the backup remains available. Administrative writers share a fail-fast database lock, and unfinished import jobs prevent backup/restore. The GAR/ФИАС section offers a protected download of `src/Export-GarPlaces.ps1` and its PowerShell command. See [Locations](docs/subsystems/locations.md) for scope and operational requirements.

The platform setting `checkout_sort_selector_enabled` defaults to true, including upgrades with a missing key. When enabled, customers can use the checkout sorting selector; when disabled, no selector markup is rendered and `checkout_sort_mode` from admin is authoritative, ignoring posted/customer modes. Checkout synchronizes the session to the forced mode so re-enabling starts from the last admin-synchronized value. Actual effective-mode transitions, whether customer-driven or forced by admin, reset tariffs and WDC method choices once; stable-mode refreshes preserve manual tariff/method selections. Runtime/selection smokes cover checkbox persistence, visibility, stale POST/session, forced sorting and re-enabling.

0.155.14 unifies both RateSorter stages on final checkout price and delivery days. "По цене": non-zero price ASC, zero LAST, then min days and max days ASC. "По сроку": min days ASC, max days ASC, then the same non-zero-first price order. Null bounds independently sort after known bounds; title, tariff key, rate ID and input index break exact ties. Rates are sorted within each method first; methods are then compared by their active rate. Grouping is unchanged and JavaScript does not sort rates.

0.155.13 replaces the checkout address modal with an inline autocomplete attached to `billing_address_1`. It is enabled only for a canonical RU WDC location with a FIAS identity; manual, unresolved and non-RU destinations keep a plain editable address. `AddressSuggestionAjax` verifies the checkout nonce and active DB location, then uses the dedicated `address_inline` DaData request with a fixed city boundary (street through house, maximum 8 results). It never retries without that boundary. Suggestions cannot change city, region, postcode or WDC location metadata. Manual address text is always allowed.

WooCommerce delivery calculation and shipment management plugin.

Canonical documentation starts at [docs/README.md](docs/README.md).

0.155.12 adjusts the WooCommerce checkout billing field presentation inside the WDC checkout runtime. Billing fields now render as name/patronymic, surname, country, city, region, postcode, address, phone, and email; billing address line 2 is removed from the checkout. Billing address line 1 stays visible with the `Улица, дом, корпус, квартира` placeholder, is optional by default, and becomes required only when the selected WDC shipping rate is classified as courier by generic delivery metadata. Phone is required with the `+7-ххх-ххх-хххх (или формат вашей страны)` placeholder, email keeps WooCommerce's existing requirement, and order comments use the approved multiline placeholder for special handling notes.

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
