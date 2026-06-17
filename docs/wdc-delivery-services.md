# WDC Delivery Services

## CDEK Tariff Runtime 0.44.5

The built-in CDEK service still uses service key `cdek` and carrier key `cdek`, and remains disabled by default. When enabled, CDEK rates require complete active environment credentials, sender CDEK city code, and a confident destination city mapping.

Runtime uses CDEK API v2:

- OAuth: `POST /v2/oauth/token`.
- Tariffs: `POST /v2/calculator/tarifflist`.
- Destination city resolution: `/v2/location/cities`.

Checkout/admin labels:

- `pickup`: default `СДЭК до пункта выдачи`.
- `courier`: default `СДЭК курьер`.

The CDEK `Основное` tab stores service-specific method titles in `wdc_delivery_service_settings` with the same generic keys used by grouped checkout/admin rates:

- `pickup_method_title`
- `courier_method_title`

If an administrator leaves a title empty, CDEK falls back to the defaults above. These titles affect checkout grouped rate labels, admin recalculation preview labels, saved shipping-item metadata, and calculation data. Russian Post title settings remain isolated on `russian_post_domestic`.

CDEK `delivery_mode` is classified by the destination side of the mode: door destinations are `courier`, warehouse/PVZ destinations are `pickup`. Numeric modes follow the CDEK docs: `1` door-door -> courier, `2` door-warehouse -> pickup, `3` warehouse-door -> courier, `4` warehouse-warehouse -> pickup.

CDEK pickup points and pickup selection were added in 0.45.0 through `GET /v2/deliverypoints`. The current stage still does not implement CDEK orders/shipments, statuses, webhooks or print forms. The next stage is `feature/cdek-order-creation`.

## CDEK Foundation 0.43.1

The built-in CDEK delivery service uses the single service key `cdek` and carrier key `cdek`. It is created disabled by default so administrators can configure credentials without changing checkout runtime behavior.

The `Данные для входа` admin tab stores:

- `cdek_environment`
- `cdek_test_account`
- encrypted test Secure password in `cdek_test_secure_password_encrypted`
- `cdek_production_account`
- encrypted production Secure password in `cdek_production_secure_password_encrypted`
- last connection check diagnostics

The active environment selects the matching base URL and credentials. Switching environments does not copy or clear credentials for the other environment. CDEK service enablement is controlled only by the common `Основное` tab. The foundation includes OAuth for `POST /v2/oauth/token`, token cache, and a protected "Проверить подключение" action. Tariff calculation was added in 0.44.0; pickup points via `GET /v2/deliverypoints` were added in 0.45.0. Orders/shipments, statuses, print documents and webhooks are still not implemented. See `docs/wdc-cdek-foundation.md`, `docs/wdc-cdek-tariff-calculation.md`, and `docs/wdc-cdek-pickup-points.md`.

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

As of 0.54.0, the bootstrap also creates the DPD foundation service:

- `service_key`: `dpd`
- `carrier_key`: `dpd`
- `service_type`: `api`
- title: `DPD`
- default state: disabled
- RU availability row is created for future use

As of 0.58.0, DPD is registered as a checkout quote carrier in `CarrierRegistry`. It is still disabled by default through the built-in delivery service row, so DPD rates appear only after the administrator enables the `dpd` service and completes the active DPD environment credentials, sender city settings and receiver city mapping. There is still no DPD shipment adapter in `CarrierShipmentAdapterRegistry`, so DPD does not appear in shipment creation/metabox actions.

The DPD `Данные для входа` tab stores test/production `clientNumber`, encrypted `clientKey`, environment, timeout and optional debug flag through the existing settings/encryption layer. The connection check is a dry diagnostic and does not execute a DPD API call.

As of 0.56.3, DPD geography lives in a separate `DPD География` tab. This tab stores SFTP settings for `GeographyNewDPD_*.csv` (`host`, `port`, `username`, encrypted password and remote directory), starts SFTP or manual CSV import jobs, shows AJAX progress for the current job, allows reset of a stale/failed/running job, diagnoses one `location_id`, saves manual `dpd_city_id`, runs the single-location DaData delivery fallback, and shows the last import report. The import builds an indexed lookup from active RU `wdc_locations`, does not query SQL per CSV row, and writes import rows only to a per-job DPD staging table until EOF. Finalization refreshes only `wdc_location_delivery_codes.dpd_city_id`/`updated_at`; reset leaves the working table untouched. These actions do not enable DPD checkout rates or shipment actions.

As of 0.58.4, DPD also has a `DPD Расчет` tab. It stores sender/default parcel settings and provides an admin-only `getServiceCostByParcels2` test calculator. Results are visible once after redirect with normalized service code/name/cost/period fields and optional raw debug output. The calculator does not write delivery rate tables and does not add shipment actions.

As of 0.58.2, DPD checkout titles and runtime tariffs follow the same grouped model as CDEK/Russian Post, with DPD-specific terminal-origin logic. The DPD `Основное` tab stores `dpd_runtime_pickup_title` and `dpd_runtime_courier_title` with defaults `DPD до пункта выдачи` and `DPD курьером`. The DPD `Тарифы` tab stores known DPD service-code checkboxes, custom checkout tariff titles, and `dpd_runtime_enable_courier_rates`. Default enabled service codes are `ECN,CSM,MXO`; if all checkboxes are off, DPD rates are not shown. Checkout DPD always sends `selfPickup=true`; pickup/terminal delivery sends `selfDelivery=true`, while courier delivery sends a separate request with `selfDelivery=false` only when courier rates are enabled.

DPD checkout rates reuse `calculator2/getServiceCostByParcels2`, receiver `dpd_city_id` from `wdc_location_delivery_codes` through `DpdCityResolver`, sender city ID from tariff settings/override, DPD parcel-builder packaging places, declared value, and the common delivery-service post-processing for rounding, minimum price and rules. Returned DPD service options are grouped into one checkout method per delivery type with `tariff_variants`. Terminal delivery is calculation-only until DPD pickup point selection is implemented. DPD pickup points, maps, postamats, order creation, statuses, labels, COD/НПП, `unitLoad` and fiscal receipts are not implemented.

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
