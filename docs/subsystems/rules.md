# Rules

Version: 0.151.2

Rules live under `src/Rules`. The rule engine evaluates delivery conditions and operations used by checkout and delivery services.

Repositories store rule data. Application logic belongs in services such as `RuleEngine`, `RuleEvaluator`, `ConditionEvaluator`, and `RuleSimulator`.

Rules may change price, delivery days/date, availability, labels/comments, or delivery-service behavior. Rule evaluation should leave an audit trail sufficient for admin review and order snapshots.

Manual delivery pricing is calculated before Rule Engine evaluation. The manual tariff minimum for `per_kg` is part of the manual base formula, while the DeliveryService minimum price and round-up-to-ruble settings remain generic post-processing after rules. Rule Engine must not contain manual-specific pricing branches.

Delivery-day rules run after checkout normalizes raw carrier lead time into calendar days. The canonical order is carrier raw lead time -> shop processing calendar -> carrier working-day conversion -> delivery date rules -> planned date. Because the global `shop_processing_working_days` setting now applies automatically, older manual processing-day additions in rules should be removed manually by an administrator to avoid double-increasing delivery time.

PEK light-cargo bag/plombing surcharges are store-owned base-price adjustments, not Rule Engine rules. For PEK, `api_base_price_rub` already includes the configured non-zero bag and/or plombing surcharge before rules run, while the pure carrier `costTotal` is stored separately as `pek_carrier_base_price_rub`/`pek_carrier_price_kopecks`. Formula visualization may add `Добавлен мешок и пломбировка`, `Добавлен мешок`, or `Добавлена пломбировка` before rule operations, including when no regular rule applies. These comments are not added to `applied_rules`, and `price_delta_rub` is calculated from the adjusted base so PEK store surcharges are not counted as rule effects.

## Service Rule Simulation

The delivery-service rules tab uses a carrier-agnostic simulation extension point: `RulesAdminPage` renders the common form and calls the configured service simulation runner. Carrier-aware services must reuse the production quote path (`QuoteRequest` -> runtime carrier -> `DeliveryQuote` -> `RuleAppliedRateBuilder`) so the test calculation uses API/base price, product value, product weight, packaging settings, final package weight, dimensions, destination mapping, delivery type, tariff/offer, comments, disabled state, formula visualization, and lead-time audit where available.

DPD and Yandex Delivery participate through the same service runner mechanism as Russian Post service simulations. Do not add carrier branches to `RulesAdminPage`; wire carrier-specific runtime dependencies at the delivery-services/application boundary.
