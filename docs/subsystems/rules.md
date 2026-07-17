# Rules

Version: 0.124.1

Rules live under `src/Rules`. The rule engine evaluates delivery conditions and operations used by checkout and delivery services.

Repositories store rule data. Application logic belongs in services such as `RuleEngine`, `RuleEvaluator`, `ConditionEvaluator`, and `RuleSimulator`.

Rules may change price, delivery days/date, availability, labels/comments, or delivery-service behavior. Rule evaluation should leave an audit trail sufficient for admin review and order snapshots.
