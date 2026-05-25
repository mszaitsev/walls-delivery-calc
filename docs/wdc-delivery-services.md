# WDC Delivery Services

Version 0.21.0 adds the permanent delivery-service foundation. The model is intentionally table-based, not a single serialized option, so it can grow to API carriers, fixed-rate services, weight-based services, user-created services, service settings, pickup logic, credentials references, statistics, debug data, and history.

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
