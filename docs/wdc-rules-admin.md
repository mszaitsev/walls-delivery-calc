# WDC Rules Admin

Version: 0.18.3.

## Default rules

The admin page `admin.php?page=wdc-rules` manages default checkout delivery calculation rules.

Rules created on this page always use:

- `target_type = default`
- `target_value = ''`

The admin UI does not expose target fields. Existing legacy rules with an empty `target_type` are normalized to default rules by migration `0012_normalize_default_rules.php` and by `RuleRepository::normalize_legacy_default_rules()`.

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

Conditions inside the same `condition_group` are evaluated as AND. Different groups are evaluated as OR.

Condition matrix:

| Type | Label | Operators | UI value | Storage | Unit/source |
| --- | --- | --- | --- | --- | --- |
| `order_total` | Сумма заказа | `>=`, `=`, `!=`, `>`, `<`, `<=` | number | `value_number` | руб. |
| `items_count` | Количество товаров | numeric | integer | `value_number` | items |
| `payment_method` | Способ оплаты | `=`, `!=` | select | `value_text` | WooCommerce gateway id |
| `city` | Населенный пункт | `=`, `!=` | autocomplete | `value_text`, `value_json` | FIAS ID plus display name |
| `country` | Страна | `=`, `!=` | select | `value_text` | WooCommerce country code |
| `delivery_type` | Тип доставки | `=`, `!=` | select | `value_text` | `pickup` or `courier` |
| `delivery_price` | Рассчитанная стоимость доставки | numeric | number | `value_number` | руб. |
| `weight` | Вес | numeric | number | `value_number` | grams |
| `dimensions` | Габариты | numeric | length/width/height | `value_json` | cm |
| `volume` | Объем | numeric | number | `value_number` | cubic meters |
| `day_of_week` | День недели | `=`, `!=` | select | `value_number` | 1..7 |
| `day_of_month` | День месяца | numeric | select | `value_number` | 1..31 |
| `month` | Месяц | `=`, `!=` | select | `value_number` | 1..12 |
| `date` | Дата | numeric | `dd.mm.yyyy` | `value_text` | normalized `YYYY-MM-DD` |

The city condition searches the local WDC locations table. It stores `value_text=fias_id` and `value_json={fias_id, display_name}`. Runtime compares FIAS ID first, then falls back to normalized city text when no FIAS ID is available.

Weight is stored and compared in grams. Volume is entered and compared in cubic meters; package volume in `cm3` is converted by the evaluator. Dimensions compare every filled field and ignore empty dimension fields.

## Actions

Supported action types are the domain action types:

- `change_price`
- `change_delivery_days`
- `add_comment`
- `disable_rate`

`disable_rate` saves safe operation defaults. `add_comment` currently uses the existing rule model behavior in `RuleEvaluator`: because default rules keep `target_value` empty, the rule name is used as the comment text unless the domain model is expanded later.

For `change_delivery_days`, operation bases are limited to:

- `calendar_days`
- `business_days`

If an invalid base is posted for a delivery-days rule, the admin handler normalizes it to `calendar_days`.

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
