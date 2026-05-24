# WDC Rule Engine Foundation

## Architecture

The rule engine foundation lives under `src/Rules` and is split into domain objects, storage, services, admin UI, and value object constants. Domain classes do not depend on WordPress runtime APIs, while storage and admin code are isolated behind repository and page classes.

## Conditions And Groups

Each rule contains zero or more `RuleCondition` objects. Conditions belong to one of three groups (`1`, `2`, or `3`). The rule stores per-group logic in `condition_group_logic`: `and` requires every condition in that group, and `or` requires at least one condition in that group. Empty groups are ignored. Groups are always combined with `OR`, so a rule applies when at least one non-empty group matches. Rules without conditions apply.

Supported condition types cover order totals, item counts, payment method, destination, delivery type, price, weight, volume, and date parts. Operators include numeric comparison, string equality, `IN` and `NOT_IN`, and containment checks.

## Evaluation Pipeline

`ConditionEvaluator` evaluates one condition against a `RuleEvaluationContext`. `RuleEvaluator` evaluates a single rule and returns a `RuleEvaluationResult`. `RuleEngine` applies an ordered rule list and produces a `RuleEngineResult`.

The repository returns enabled rules ordered by the internal sort order (`priority ASC`, then `id ASC`). Admin users manage that order visually from top to bottom; the evaluator intentionally does not handle global ordering.

Condition values are type-aware. Numeric conditions use `value_number`; select/text identifiers use `value_text`; city stores FIAS ID in `value_text` and display metadata in `value_json`; dimensions store `length_cm`, `width_cm`, and `height_cm` in `value_json`. Weight is compared in grams. Volume is compared in cubic meters after converting package `cm3` to `m3`. City matching is FIAS-only: if the context or condition FIAS ID is empty, the condition is false, and display names or city text are not used as a fallback.

## Audit Trail

Every evaluated rule emits `RuleAuditEntry` records. Applied entries include the changed value where relevant. Non-applied entries include the reason, such as disabled rules or unmatched conditions.

## Promo Shipping

Promo shipping rules are foundation-ready for crossed-price behavior. When a promo rule changes delivery price, the engine stores `crossed_price` before the promo operation and clamps the final price to at least 1 RUB.

Example: `450 RUB`, promo `-500 RUB` gives `crossed_price = 450 RUB` and `final_price = 1 RUB`.

## Stop Processing

Rules can set `stop_processing`. When an applied rule has this flag, `RuleEngine` stops evaluating subsequent rules and returns the accumulated result.

## Admin Builder

The rules admin page is a CRUD interface for default rules. It uses the same type-aware condition matrix as the evaluator, shows unit labels beside numeric fields, limits condition groups to `Условие 1` through `Условие 3`, and saves the per-group AND/OR logic with the rule.

## Future Checkout Integration

No checkout runtime integration is included in this foundation. Future orchestration can build `RuleEvaluationContext` from WooCommerce checkout state, fetch rules from `RuleRepository`, apply `RuleEngine`, and map the result back to delivery rates.
