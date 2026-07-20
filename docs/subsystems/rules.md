# Rules

Version: 0.124.23

Rules live under `src/Rules`. The rule engine evaluates delivery conditions and operations used by checkout and delivery services.

Repositories store rule data. Application logic belongs in services such as `RuleEngine`, `RuleEvaluator`, `ConditionEvaluator`, and `RuleSimulator`.

Rules may change price, delivery days/date, availability, labels/comments, or delivery-service behavior. Rule evaluation should leave an audit trail sufficient for admin review and order snapshots.

Delivery-day rules run after checkout normalizes raw carrier lead time into calendar days. The canonical order is carrier raw lead time -> shop processing calendar -> carrier working-day conversion -> delivery date rules -> planned date. Because the global `shop_processing_working_days` setting now applies automatically, older manual processing-day additions in rules should be removed manually by an administrator to avoid double-increasing delivery time.
