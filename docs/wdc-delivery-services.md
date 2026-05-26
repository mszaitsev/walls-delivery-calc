# WDC Delivery Services

Version 0.21.0 adds the permanent delivery-service foundation. Version 0.21.1 adds reusable service-specific rules admin on top of the same storage model. Version 0.21.3 turns the service edit screen into real per-tab administration. Version 0.21.8 adds delivery-type customer comments. Version 0.21.9 makes checkout comments render as separate lines and translates service admin select labels. Version 0.21.12 moves technical order calculation data into `_wdc_delivery_calculation_data` and keeps Russian Post shipping item meta visually clean. The model is intentionally table-based, not a single serialized option, so it can grow to API carriers, fixed-rate services, weight-based services, user-created services, service settings, pickup logic, credentials references, statistics, debug data, and history.

## Tables

`{$wpdb->prefix}wdc_delivery_services` stores one row per service:

- `service_key` is the stable unique runtime key.
- `carrier_key` links API-backed services to a carrier adapter.
- `service_type` supports `api`, `fixed`, and `weight_based`.
- `availability_mode` supports `carrier_directory`, `selected_countries`, `all_countries`, and `all_except_selected`.
- `use_default_rules_when_no_service_rules` controls service rule fallback.
- `round_up_to_ruble` and `minimum_price_rub` are service-level post-processing controls.
- `pickup_customer_comment` and `courier_customer_comment` store service customer-facing comments per delivery type.
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

The service edit page is `admin.php?page=wdc-delivery-services&service=<service_key>` and exposes real tabs:

- `Основное`: service key, title, carrier key, service type, enabled state, sort order, and default-rule fallback.
- `Доступность`: availability mode plus the selected/all-except country list. The admin shows Russian labels (`Справочник перевозчика`, `Только выбранные страны`, `Все страны`, `Все страны, кроме выбранных`) while storing the technical values. `carrier_directory` services show that availability is driven by the carrier directory. Russian Post also links to its countries tab.
- `Расчет`: service post-processing (`round_up_to_ruble`, `minimum_price_rub`) and service-specific calculation settings. The visible labels use Russian wording such as `Минимальная цена, руб.` and `Ставка НДС`.
- `Правила`: embedded reusable rules admin for `target_type=service`.
- `Страны Почты России`: only for `russian_post_worldwide_parcel`; embeds the Russian Post country mapping admin.

Russian Post international service settings now live on the service `Расчет` tab and are saved to `wdc_delivery_service_settings`: API endpoint, country endpoint, origin postcode, object code, ISAVIA flag, timeout, VAT rate, max package weight, fallback controls, cache-until-end-of-day, auto-refresh-countries-if-empty, and debug flag. The service `enabled` flag is authoritative and is not duplicated as a Russian Post setting.

As of 0.21.6, packaging tiers are no longer Russian Post service settings. They are global settings on `Правила расчета -> Упаковка`. Each service controls `include_packaging_weight` and `packaging_weight_mode` on its `Расчет` tab. The database stores `total_weight` or `package_item`, while the admin select shows Russian labels: `Прибавлять к общему весу посылки` and `Добавлять отдельной строкой «Упаковка»`.

As of 0.21.8, the service `Расчет` tab also stores `pickup_customer_comment` and `courier_customer_comment` in `wdc_delivery_services`. Empty values are allowed. At checkout, a normal pickup/courier rate receives the matching service comment first, then rule-added comments are appended. As of 0.21.9, each checkout comment is rendered as a separate block line rather than relying on inline spans.

## Service Rules

The `Правила` tab embeds the same reusable rules admin controller used by the default `Правила расчета` page, but with a service context:

- default rules use `target_type=default`, `target_value=''`;
- service rules use `target_type=service`, `target_value=<service_key>`;
- list, create, edit, duplicate, delete, toggle, move, and drag-sort operations all check the current target context;
- drag-sort changes only rules belonging to that target.

If a service has no own rules, the tab shows an empty-state message, a link to default rules, and `Скопировать дефолтные правила`. Copying default rules appends new service-targeted copies to the end of the service list. It preserves names, enabled state, order, action, operation, conditions, group logic/expression, promo flag, stop-processing flag, and comment text, but does not reuse ids or timestamps and does not delete existing service rules.

Service simulation uses only rules from the current service target. It does not automatically mix in default fallback; if the service has no own rules, the simulation reports that own rules are not configured.

For Russian Post the service simulation performs a real service quote first, using destination country, package weight, order total, and calculation date. The screen then shows API/base price, final price after service rules, audit details, source/fallback/cache metadata, and delivery days when available. Default rules page simulation remains a pure rule simulation over the administrator-entered base delivery price and does not call carrier APIs.

## Order Admin Data

Delivery services can save a structured order calculation payload under `_wdc_delivery_calculation_data`. It contains stable technical data for future carrier support: service/carrier ids, rate id, delivery type, pickup selection when applicable, destination, package weights, sanitized API fields, rule audit/formula, final price, fallback state, and delivery days when non-empty.

For `russian_post_worldwide_parcel`, the normal WooCommerce shipping item meta shows only `Способ доставки: международная доставка Почтой России`. The order metabox `Калькулятор доставок` is the admin-facing place for calculation details: destination country, products/packaging/final API weight, API base price, readable rules formula, and final result. Terminal fallback rates save fallback reason/text and final price `0`, but do not show rules because fallback bypasses rules and service post-processing.
# Delivery services update

Domestic Russian Post foundation adds built-in services for `russian_post_domestic_pickup` and `russian_post_domestic_courier`. Bootstrapping pins both to `RU`; the availability UI is informational for this carrier family.

The service calculation settings continue to own comments, packaging weight inclusion, rounding, minimum price and default-rule fallback. Domestic-specific tariff variants are exposed on a Tariffs foundation tab and resolved at runtime per service.

Russian Post domestic service simulation calls every enabled variant for the service and shows active tariffs plus skipped API variants. Skipped rows include `object_code`, sanitized request params, HTTP status, and API `errorcode`/`errormsg`, which is useful when one tariff is rejected while the rest of the service still calculates.
