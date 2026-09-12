# Rules

Version: 1.0.13

The Rule Engine distinguishes the current shipping package item total (`order_total`) from the full cart item total (`cart_total`). Both use post-discount product totals and exclude shipping, fees, and taxes; outside WooCommerce checkout, the typed full-cart value falls back to the package total.

Price-change operations expose package and full-cart percentage bases, with optional inclusion of the current delivery price. Persisted package-based identifiers keep their established semantics.

Rules live under `src/Rules`. The rule engine evaluates delivery conditions and operations used by checkout and delivery services. Repositories own persistence; application behavior belongs in `RuleEngine`, `RuleEvaluator`, `ConditionEvaluator`, and `RuleSimulator`.

Rule Engine domain/services do not call WooCommerce globals. WooCommerce totals enter through `WooCommercePackageMapper`, `QuoteRequest`, and `RuleEvaluationContext`.

Rules may change price, delivery days/date, availability, labels/comments, or delivery-service behavior. Evaluation leaves an audit trail suitable for admin review and order snapshots. Manual delivery pricing is calculated before rules; generic minimum-price and ruble-rounding policy remains post-processing.

## Service Rule Simulation

The delivery-service rules tab uses a carrier-agnostic simulation extension point: `RulesAdminPage` renders the common form and calls the configured service simulation runner. Carrier-aware services must reuse the production quote path (`QuoteRequest` -> runtime carrier -> `DeliveryQuote` -> `RuleAppliedRateBuilder`) so the test calculation uses API/base price, product value, product weight, packaging settings, final package weight, dimensions, destination mapping, delivery type, tariff/offer, comments, disabled state, formula visualization, and lead-time audit where available.

DPD and Yandex Delivery participate through the same service runner mechanism as Russian Post service simulations. Do not add carrier branches to `RulesAdminPage`; wire carrier-specific runtime dependencies at the delivery-services/application boundary.
