# WDC Delivery Services

Version 0.21.0 adds the permanent delivery-service foundation. Version 0.21.1 adds reusable service-specific rules admin on top of the same storage model. The model is intentionally table-based, not a single serialized option, so it can grow to API carriers, fixed-rate services, weight-based services, user-created services, service settings, pickup logic, credentials references, statistics, debug data, and history.

## Tables

`{$wpdb->prefix}wdc_delivery_services` stores one row per service:

- `service_key` is the stable unique runtime key.
- `carrier_key` links API-backed services to a carrier adapter.
- `service_type` supports `api`, `fixed`, and `weight_based`.
- `availability_mode` supports `carrier_directory`, `selected_countries`, `all_countries`, and `all_except_selected`.
- `use_default_rules_when_no_service_rules` controls service rule fallback.
- `round_up_to_ruble` and `minimum_price_rub` are service-level post-processing controls.
- `sort_order` and `deleted` support admin ordering and soft deletion.

`{$wpdb->prefix}wdc_delivery_service_settings` stores service-specific settings as individual key/value rows with `value_format` (`json`, `string`, `number`, `bool`). It is for endpoints, credentials references, limits, future pickup settings, tariffs, and UI state. Real secrets must not be stored as plaintext here; future encrypted storage can be referenced from this table.

`{$wpdb->prefix}wdc_delivery_service_countries` stores country availability for `selected_countries` and `all_except_selected`. `carrier_directory` does not use this table.

`minimum_price_rub` accepts comma or dot decimals in admin input and is normalized to a non-negative decimal. Negative values are clamped to `0`.

## Built-In Service

The bootstrap creates `russian_post_worldwide_parcel` if it does not exist:

- `carrier_key`: `russian_post`
- `service_type`: `api`
- `availability_mode`: `carrier_directory`
- default rule fallback enabled
- ruble rounding enabled
- minimum price `1`

No rules or built-in markups are created automatically.

## Admin

The admin page is `Калькулятор доставок → Службы доставки` (`wdc-delivery-services`). It lists services, availability, countries, rule fallback, rounding, minimum price, sort order, and actions for enable/disable, edit, delete, and future service creation.

The service edit page is `admin.php?page=wdc-delivery-services&service=<service_key>` and exposes the foundation tabs: `Основное`, `Доступность`, `Расчет`, `Правила`.

## Service Rules

The `Правила` tab embeds the same reusable rules admin controller used by the default `Правила расчета` page, but with a service context:

- default rules use `target_type=default`, `target_value=''`;
- service rules use `target_type=service`, `target_value=<service_key>`;
- list, create, edit, duplicate, delete, toggle, move, and drag-sort operations all check the current target context;
- drag-sort changes only rules belonging to that target.

If a service has no own rules, the tab shows an empty-state message, a link to default rules, and `Скопировать дефолтные правила`. Copying default rules appends new service-targeted copies to the end of the service list. It preserves names, enabled state, order, action, operation, conditions, group logic/expression, promo flag, stop-processing flag, and comment text, but does not reuse ids or timestamps and does not delete existing service rules.

Service simulation uses only rules from the current service target. It does not automatically mix in default fallback; if the service has no own rules, the simulation reports that own rules are not configured.
