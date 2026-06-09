# WDC Delivery Services

## CDEK Tariff Runtime 0.44.0

The built-in CDEK service still uses service key `cdek` and carrier key `cdek`, and remains disabled by default. When enabled, CDEK rates require complete active environment credentials, sender CDEK city code, and a confident destination city mapping.

Runtime uses CDEK API v2:

- OAuth: `POST /v2/oauth/token`.
- Tariffs: `POST /v2/calculator/tarifflist`.
- Destination city resolution: `/v2/location/cities`.

Checkout/admin labels:

- `pickup`: `СДЭК до пункта выдачи`.
- `courier`: `СДЭК курьер`.

The current stage does not implement CDEK pickup points, pickup point selection, CDEK orders/shipments, statuses, webhooks or print forms. The next stage is `feature/cdek-pickup-points`.

## CDEK Foundation 0.43.1

The built-in CDEK delivery service uses the single service key `cdek` and carrier key `cdek`. It is created disabled by default so administrators can configure credentials without changing checkout runtime behavior.

The `Данные для входа` admin tab stores:

- `cdek_environment`
- `cdek_test_account`
- encrypted test Secure password in `cdek_test_secure_password_encrypted`
- `cdek_production_account`
- encrypted production Secure password in `cdek_production_secure_password_encrypted`
- last connection check diagnostics

The active environment selects the matching base URL and credentials. Switching environments does not copy or clear credentials for the other environment. CDEK service enablement is controlled only by the common `Основное` tab. The foundation includes OAuth for `POST /v2/oauth/token`, token cache, and a protected "Проверить подключение" action. Tariff calculation was added in 0.44.0; pickup points, orders/shipments, statuses, print documents and webhooks are still not implemented. See `docs/wdc-cdek-foundation.md` and `docs/wdc-cdek-tariff-calculation.md`.

## Russian Post Tracking Statuses 0.36.2

The unified service `russian_post_domestic` owns Russian Post Tracking API credentials on `Данные для входа`:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

These credentials are separate from Otpravka AccessToken/login/password and from the Tariff API token. Manual shipment status refresh reads only the Tracking credentials and does not store credentials in order meta. The `Статусы / Mapping` tab may keep diagnostic JSON/future override UI, but the main Russian Post status mapping is fixed in code from `status pocha.xlsx`.

Version 0.36.1 corrects that code mapping: selected pickup operations such as `8:2`, ranges `12:1..12:31` and `42:1..42:30` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`; `8:15` and `8:18` map to `handed_to_courier` / `передан курьеру`; unknown pairs remain `unknown` / `не определён`.

Version 0.37.0 keeps tracking barcode-based: Tracking API uses barcode/ШПИ. Otpravka `result-id` is saved separately as hidden `backlog_order_id` for internal backlog operations such as cancellation, but it is not shown in the metabox, customer UI, emails, or toasts.

Version 0.22.00 adds the Russian Post pickup-point passport import foundation on top of the existing pickup table. Version 0.21.0 adds the permanent delivery-service foundation. Version 0.21.1 adds reusable service-specific rules admin on top of the same storage model. Version 0.21.3 turns the service edit screen into real per-tab administration. Version 0.21.8 adds delivery-type customer comments. Version 0.21.9 makes checkout comments render as separate lines and translates service admin select labels. Version 0.21.12 moves technical order calculation data into `_wdc_delivery_calculation_data` and keeps Russian Post shipping item meta visually clean. The model is intentionally table-based, not a single serialized option, so it can grow to API carriers, fixed-rate services, weight-based services, user-created services, service settings, pickup logic, credentials references, statistics, debug data, and history.

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

- `Основное`: service key, title, carrier key, service type, enabled state, sort order, default-rule fallback, availability mode and selected/all-except country list. The admin shows Russian labels (`Справочник перевозчика`, `Только выбранные страны`, `Все страны`, `Все страны, кроме выбранных`) while storing the technical values. `carrier_directory` services show that availability is driven by the carrier directory. Russian Post also links to its countries tab.
- `Расчет`: service post-processing (`round_up_to_ruble`, `minimum_price_rub`) and service-specific calculation settings. The visible labels use Russian wording such as `Минимальная цена, руб.` and `Ставка НДС`.
- `Правила`: embedded reusable rules admin for `target_type=service`.
- `Страны Почты России`: only for `russian_post_worldwide_parcel`; embeds the Russian Post country mapping admin.

Russian Post international service settings now live on the service `Расчет` tab and are saved to `wdc_delivery_service_settings`: API endpoint, country endpoint, origin postcode, object code, ISAVIA flag, timeout, VAT rate, max package weight, fallback controls, cache-until-end-of-day, auto-refresh-countries-if-empty, and debug flag. The service `enabled` flag is authoritative and is not duplicated as a Russian Post setting.

As of 0.21.6, packaging tiers are no longer Russian Post service settings. They are global settings on `Правила расчета -> Упаковка`. Each service controls `include_packaging_weight` and `packaging_weight_mode` on its `Расчет` tab. The database stores `total_weight` or `package_item`, while the admin select shows Russian labels: `Прибавлять к общему весу посылки` and `Добавлять отдельной строкой «Упаковка»`.

As of 0.21.8, the service `Расчет` tab also stores `pickup_customer_comment` and `courier_customer_comment` in `wdc_delivery_services`. Empty values are allowed. At checkout, a normal pickup/courier rate receives the matching service comment first, then rule-added comments are appended. As of 0.21.9, each checkout comment is rendered as a separate block line rather than relying on inline spans.

The unified Russian Post domestic service exposes shipment settings on the `Отправления` tab. System services show `service_key` and `carrier_key` as read-only technical fields. `shelf_life_days_default` is clamped to 15..60 and defaults to 30. The service supports `send_goods_items`, `combine_goods_items_default`, and `combined_goods_name_template`; `goods` is omitted from the shipment payload unless `send_goods_items=true`.

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

As of 0.35.2, domestic Russian Post has one built-in delivery service:

- `carrier_key=russian_post_domestic`
- `service_key=russian_post_domestic`

Bootstrapping pins it to `RU` and no longer creates pickup/courier service rows. Pickup and courier remain separate checkout groups through `delivery_type`, not separate service settings contexts.

Historical migration note: migration `0026_unify_russian_post_domestic_service.php` is a one-way cleanup migration for the old domestic Russian Post model. It copies service settings, tariff variants, countries, point type settings, Otpravka/tracking credentials and shipment settings into `service_key=russian_post_domestic`, then physically deletes the old `russian_post_domestic_pickup` and `russian_post_domestic_courier` service rows plus their service settings, country rows and service-rule bindings. The source of truth after the migration is only the unified service; backward compatibility with the old domestic service keys is intentionally not supported.

The domestic service has these tabs: `Основные`, `Расчет`, `Тарифы`, `ПВЗ / ОПС`, `Данные для входа`, `Отправления`, `Статусы / Mapping`, `Диагностика`. The former carrier credentials page is removed from the menu and the UI.

As of 0.35.1, the old separate `Доступность` tab is folded into `Основные`. The main tab saves enabled/title/system keys, `availability_mode`, country availability, and the customer-facing domestic method titles `pickup_method_title` and `courier_method_title`. For `russian_post_domestic`, availability is pinned to `RU`, and the configurable method-title defaults are `Почта России до отделения` and `Почта России до двери`.

The domestic `Расчет` tab keeps calculation behavior: `from_postcodes` labeled as `Индекс отправки для расчета доставки`, `return_postcode` labeled as `Индекс возврата для расчета доставки`, insurance, timeout/cache/debug, packaging weight, rounding, minimum price and fallback settings. Tariff API endpoint and token live on `Данные для входа`, next to Otpravka and Tracking credentials. The token field remains visible because `RussianPostDomesticApiClient` sends it as `Authorization: Bearer ...` when configured. `russian_post_otpravka_postoffice_codes` remains separate from tariff `from_postcodes`; it is the list of acceptance postoffice indices used by the shipment modal as `postoffice-code`. `default_from_postcode` is edited next to those postoffice codes, remains the same service setting, and is also used by tariff calculation as the fallback origin index.

Checkout labels for grouped domestic rates use `{pickup_method_title|courier_method_title}, {tariff title - delivery days}`. If delivery days are absent, only the method title and tariff title are shown; if the tariff title is absent, only the method title is shown. Visible domestic WooCommerce shipping item meta contains only `Срок доставки`. Technical and operational data, including service key, selected tariff, delivery type and pickup point code/type/postcode/address, is stored in hidden WDC order meta and `_wdc_delivery_calculation_data`; shipment creation reads that data and does not rely on visible shipping item meta or `shipping_address_2`.

Domestic tariff variants are exposed on the unified `Тарифы` tab. One list stores pickup and courier rows with `delivery_type`, `enabled`, `is_ecom`, declared-value flag, weight limits, custom titles and sort order. Shipment creation uses `is_ecom` to decide whether pickup payloads use `ecom-data.delivery-point-index` or the normal OPS `DEMAND` address schema.

Russian Post domestic service simulation calls every enabled variant for the selected delivery type context and shows active tariffs plus skipped API variants. Skipped rows include `object_code`, sanitized request params, HTTP status, and API `errorcode`/`errormsg`, which is useful when one tariff is rejected while the rest of the service still calculates.
