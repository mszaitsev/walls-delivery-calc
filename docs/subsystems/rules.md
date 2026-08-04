# Rules

Version: 0.133.4

Rules live under `src/Rules`. The rule engine evaluates delivery conditions and operations used by checkout and delivery services.

Repositories store rule data. Application logic belongs in services such as `RuleEngine`, `RuleEvaluator`, `ConditionEvaluator`, and `RuleSimulator`.

Rules may change price, delivery days/date, availability, labels/comments, or delivery-service behavior. Rule evaluation should leave an audit trail sufficient for admin review and order snapshots.

Delivery-day rules run after checkout normalizes raw carrier lead time into calendar days. The canonical order is carrier raw lead time -> shop processing calendar -> carrier working-day conversion -> delivery date rules -> planned date. Because the global `shop_processing_working_days` setting now applies automatically, older manual processing-day additions in rules should be removed manually by an administrator to avoid double-increasing delivery time.

## Service Rule Simulation

The delivery-service rules tab uses a carrier-agnostic simulation extension point: `RulesAdminPage` renders the common form and calls the configured service simulation runner. Carrier-aware services must reuse the production quote path (`QuoteRequest` -> runtime carrier -> `DeliveryQuote` -> `RuleAppliedRateBuilder`) so the test calculation uses API/base price, product value, product weight, packaging settings, final package weight, dimensions, destination mapping, delivery type, tariff/offer, comments, disabled state, formula visualization, and lead-time audit where available.

DPD and Yandex Delivery participate through the same service runner mechanism as Russian Post service simulations. Do not add carrier branches to `RulesAdminPage`; wire carrier-specific runtime dependencies at the delivery-services/application boundary.
