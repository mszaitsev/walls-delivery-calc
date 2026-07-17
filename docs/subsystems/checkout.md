# Checkout

Version: 0.122.0

Checkout code lives in `src/Checkout` and frontend assets in `assets/frontend`. It maps WooCommerce packages into `QuoteRequest`, runs carriers through `CheckoutOrchestrator`, applies rules, sorts rates, persists selected pickup/courier metadata, and validates checkout input.

Do not change checkout UX or tariff business logic while working on shipment framework docs unless a confirmed defect requires it.
