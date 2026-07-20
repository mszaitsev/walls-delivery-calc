# Checkout

Version: 0.124.22

Checkout code lives in `src/Checkout` and frontend assets in `assets/frontend`. It maps WooCommerce packages into `QuoteRequest`, runs carriers through `CheckoutOrchestrator`, applies rules, sorts rates, persists selected pickup/courier metadata, and validates checkout input.

`CheckoutFeatureGate` is the single runtime policy for enabling the new WooCommerce checkout shipping method and frontend runtime. Its source of truth is the `enable_new_checkout_shipping` setting from the platform settings page. The checkout debug panel uses the same gate and additionally requires `show_checkout_debug_panel`.

## Delivery Lead Time Pipeline

Checkout and order-admin recalculation use the same runtime order:

1. carrier raw lead time;
2. shop processing calendar;
3. carrier working-day conversion;
4. delivery date rules;
5. planned date.

Carrier adapters return the raw carrier `DateRange`. `DeliveryLeadTimeNormalizer` adds the global `shop_processing_working_days` setting, default `2`, using `CalendarTypes::SHOP`, then converts carrier working days through `CalendarTypes::CARRIER_RU` only when the service-level `delivery_days_are_working` checkbox is enabled. That checkbox defaults to `false`.

The current calculation day is not counted for shop processing, and the handoff day is not counted when carrier working days are converted. Rules run only after the base duration is normalized into calendar days. The planned date is calculated after rules from the final minimum delivery-days boundary, so checkout comments and order metadata stay aligned with rule changes.

## Canonical Requirements

- Rates are produced through WooCommerce shipping rates.
- The customer sees carrier, delivery type, delivery days/date, and final customer price.
- Pickup point UI appears only for pickup delivery methods.
- Courier address validation applies only when courier delivery is selected.
- Selected city, rate, tariff, pickup point, and courier address are preserved through checkout session/runtime state.
- Sorting can use price or delivery time.
- Manager recalculation in the order admin must save a clear order note with old/new delivery title and price.
- Planned checkout comments use `DeliveryRate::planned_delivery_comment` and the format `Доставка планируется* с 12 августа (среда).`.

Raw carrier quote responses are not order storage by default. Use diagnostics/logging for raw payloads and redact credentials.

Do not change checkout UX or tariff business logic while working on shipment framework docs unless a confirmed defect requires it.
