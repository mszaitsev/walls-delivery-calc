# WDC Rule Engine Foundation

## Architecture

The rule engine foundation lives under `src/Rules` and is split into domain objects, storage, services, admin UI, and value object constants. Domain classes do not depend on WordPress runtime APIs, while storage and admin code are isolated behind repository and page classes.

## Conditions And Groups

Each rule contains zero or more `RuleCondition` objects. Conditions with the same `condition_group` are evaluated with `AND`. Separate groups are evaluated with `OR`, so a rule applies when at least one group matches completely.

Supported condition types cover order totals, item counts, payment method, destination, delivery type, price, weight, volume, and date parts. Operators include numeric comparison, string equality, `IN` and `NOT_IN`, and containment checks.

## Evaluation Pipeline

`ConditionEvaluator` evaluates one condition against a `RuleEvaluationContext`. `RuleEvaluator` evaluates a single rule and returns a `RuleEvaluationResult`. `RuleEngine` applies an ordered rule list and produces a `RuleEngineResult`.

The repository returns enabled rules ordered by the internal sort order (`priority ASC`, then `id ASC`). Admin users manage that order visually from top to bottom; the evaluator intentionally does not handle global ordering.

## Audit Trail

Every evaluated rule emits `RuleAuditEntry` records. Applied entries include the changed value where relevant. Non-applied entries include the reason, such as disabled rules or unmatched conditions.

## Promo Shipping

Promo shipping rules are foundation-ready for crossed-price behavior. When a promo rule changes delivery price, the engine stores `crossed_price` before the promo operation and clamps the final price to at least 1 RUB.

Example: `450 RUB`, promo `-500 RUB` gives `crossed_price = 450 RUB` and `final_price = 1 RUB`.

## Stop Processing

Rules can set `stop_processing`. When an applied rule has this flag, `RuleEngine` stops evaluating subsequent rules and returns the accumulated result.

## Future Visual Builder

The current admin page is only a skeleton: list, create demo rules, delete demo rules, and POST-based simulation. A future visual builder can use the same domain model and repository without changing checkout behavior.

## Future Checkout Integration

No checkout runtime integration is included in this foundation. Future orchestration can build `RuleEvaluationContext` from WooCommerce checkout state, fetch rules from `RuleRepository`, apply `RuleEngine`, and map the result back to delivery rates.
