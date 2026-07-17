# Checkout

Version: 0.124.1

Checkout code lives in `src/Checkout` and frontend assets in `assets/frontend`. It maps WooCommerce packages into `QuoteRequest`, runs carriers through `CheckoutOrchestrator`, applies rules, sorts rates, persists selected pickup/courier metadata, and validates checkout input.

## Canonical Requirements

- Rates are produced through WooCommerce shipping rates.
- The customer sees carrier, delivery type, delivery days/date, and final customer price.
- Pickup point UI appears only for pickup delivery methods.
- Courier address validation applies only when courier delivery is selected.
- Selected city, rate, tariff, pickup point, and courier address are preserved through checkout session/runtime state.
- Sorting can use price or delivery time.
- Manager recalculation in the order admin must save a clear order note with old/new delivery title and price.

Raw carrier quote responses are not order storage by default. Use diagnostics/logging for raw payloads and redact credentials.

Do not change checkout UX or tariff business logic while working on shipment framework docs unless a confirmed defect requires it.
