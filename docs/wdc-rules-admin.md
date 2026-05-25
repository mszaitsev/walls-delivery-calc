# WDC Rules Admin

Version: 0.18.6.

## Default rules

The admin page `admin.php?page=wdc-rules` manages default checkout delivery calculation rules.

Rules created on this page always use:

- `target_type = default`
- `target_value = ''`

The admin UI does not expose target fields. Fresh installs create the current rules schema directly; old empty-target legacy normalization is no longer part of runtime or the active migration list.

## CRUD

The page supports regular WordPress admin POST forms with nonce and `AdminMenu::CAPABILITY` checks:

- create rule
- edit rule
- duplicate rule
- enable or disable rule
- delete rule
- move up or down by swapping internal sort order with the neighboring default rule
- drag-and-drop table sorting, saved through the `reorder_rules` POST action

Duplicated rules are disabled by default for safety.

Rules are shown and applied from top to bottom. The database `priority` column is still used internally as a sort order value, but the admin UI does not expose it as a user-facing priority field.

## Conditions

Rules can have multiple conditions. The UI is type-specific: managers choose a condition type, then see only the valid operator and value control for that type. The old universal "text plus number" editor is no longer shown.

Conditions are assigned to one of three groups shown as `Условие 1`, `Условие 2`, and `Условие 3`. The edit form renders those groups as separate blocks, and each block has its own `Добавить условие в Условие N` button. Rows keep `condition_group` as a hidden value so saved rules reopen with conditions under the same group.

There are two separate logic settings:

- `condition_group_logic` controls logic inside each group. It is stored as JSON, for example `{"1":"and","2":"and","3":"or"}`. `and` means every condition in that group must match; `or` means at least one condition in that group must match.
- `condition_group_expression` controls how the three group results are combined. It is stored on `wdc_rules.condition_group_expression`.

Default expression is `condition_1_or_2_or_3`, which preserves the old behavior: group 1 OR group 2 OR group 3. If an expression references an empty group, that group is false. Rules without any conditions still apply.

Supported group expressions:

- `condition_1`: Условие 1
- `condition_2`: Условие 2
- `condition_3`: Условие 3
- `condition_1_or_2`: Условие 1 ИЛИ Условие 2
- `condition_1_and_2`: Условие 1 И Условие 2
- `condition_1_or_3`: Условие 1 ИЛИ Условие 3
- `condition_1_and_3`: Условие 1 И Условие 3
- `condition_2_or_3`: Условие 2 ИЛИ Условие 3
- `condition_2_and_3`: Условие 2 И Условие 3
- `condition_1_or_2_or_3`: Условие 1 ИЛИ Условие 2 ИЛИ Условие 3
- `condition_1_and_2_and_3`: Условие 1 И Условие 2 И Условие 3
- `condition_1_and_2_or_3`: (Условие 1 И Условие 2) ИЛИ Условие 3
- `condition_1_or_2_and_3`: Условие 1 ИЛИ (Условие 2 И Условие 3)

Condition matrix:

| Type | Label | Operators | UI value | Storage | Unit/source |
| --- | --- | --- | --- | --- | --- |
| `order_total` | Сумма заказа | `>=`, `=`, `!=`, `>`, `<`, `<=` | number | `value_number` | руб. |
| `items_count` | Количество товаров | numeric | integer | `value_number` | шт. |
| `payment_method` | Способ оплаты | `=`, `!=` | select | `value_text` | WooCommerce gateway id |
| `city` | Населенный пункт | `=`, `!=` | FIAS ID input | `value_text`, `value_json` | existing `wdc_locations.fias_id` |
| `country` | Страна | `=`, `!=` | select | `value_text` | WooCommerce country code |
| `delivery_type` | Тип доставки | `=`, `!=` | select | `value_text` | `pickup` or `courier` |
| `delivery_price` | Рассчитанная стоимость доставки | numeric | number | `value_number` | руб. |
| `weight` | Вес | numeric | number | `value_number` | грамм |
| `dimensions` | Габариты | numeric | length/width/height | `value_json` | cm |
| `volume` | Объем | numeric | number | `value_number` | куб.м. |
| `day_of_week` | День недели | `=`, `!=` | select | `value_number` | 1..7 |
| `day_of_month` | День месяца | numeric | select | `value_number` | 1..31 |
| `month` | Месяц | `=`, `!=` | select | `value_number` | 1..12 |
| `date` | Дата | numeric | `dd.mm.yyyy` | `value_text` | normalized `YYYY-MM-DD` |

The city condition is FIAS-only. The admin enters a FIAS ID, the UI checks it against the local `wdc_locations` table, and the condition is invalid when the ID is not found. It stores `value_text=fias_id` and `value_json={fias_id, display_name}` for display. Runtime compares only the selected location FIAS ID from context or destination address with `value_text`; city names and display names are not used as a fallback.

Weight is stored and compared in grams. Volume is entered and compared in cubic meters; package volume in `cm3` is converted by the evaluator. Dimensions compare every filled field and ignore empty dimension fields.

The condition form shows unit labels next to value fields where units matter: `руб.` for order total and delivery price, `шт.` for item count, `грамм` for weight, and `куб.м.` for volume.

Decimal fields accept both comma and dot input. Admin sanitization trims spaces, converts `,` to `.`, and stores normalized float values. This applies to operation values, numeric conditions, dimensions, and simulation numeric fields. Integer-only fields such as day numbers and condition group ids remain integers.

## Actions

Supported action types are the domain action types:

- `change_price`
- `change_delivery_days`
- `add_comment`
- `disable_rate`

`disable_rate` saves safe operation defaults. `add_comment` uses `wdc_rules.operation_text`, added by migration `0014_add_rule_operation_text.php`. In the admin form it switches the operation to `Установить`, hides numeric operation fields, and shows a required comment textarea. The evaluator appends `operation_text` to `RuleEngineResult::comments`; `RuleAppliedRateBuilder` merges those comments into the delivery rate comments, so the existing frontend/order-meta comment flow receives them.

For `change_delivery_days`, operation bases are limited to:

- `calendar_days`
- `business_days`

If an invalid base is posted for a delivery-days rule, the admin handler normalizes it to `calendar_days`.

Operation summaries are formatted for the Russian admin table:

- `increase`: `увеличить на 12.4% от заказа`
- `decrease`: `уменьшить на 10% от доставки`
- `equals`: `установить 500 руб.`

Percent bases are joined without a space before `%`; ruble and day bases keep normal spacing.

## Simulation

The "Проверить правила" section builds a real `RuleEvaluationContext` from the form:

- original delivery price
- original delivery days
- order total
- weight
- dimensions
- volume in cubic meters
- country
- city
- location FIAS ID
- delivery type
- payment method
- calculation date

Simulation uses `RuleRepository::get_default_rules()` and displays original price, crossed price, final price, original delivery days, final delivery days, disabled state, comments, and rule audit entries.

## Future Carrier Rules

Carrier-specific UI is intentionally out of scope for this step. The repository is prepared with:

- `get_default_rules()`
- `get_all_default_rules()`
- `get_rules_for_carrier_with_default_fallback(string $carrierKey)`
- `get_rules_for_target_or_default(string $target_type, string $target_value)`

When carrier-specific rules exist for a target, the repository returns them. Otherwise it falls back to enabled default rules.
