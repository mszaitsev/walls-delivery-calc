# Архитектурный аудит Walls Delivery Calc

Archived/not-runtime as of 0.20.0: this document describes the pre-removal legacy `includes/*` architecture for historical context only. Current runtime is `src/` only.

## Краткое резюме

Текущий плагин `walls-delivery-calc` - небольшой процедурно-классовый WooCommerce-плагин без Composer, namespaces и автозагрузчика. Основной реализованный сценарий: один WooCommerce shipping method `wdc_dynamic_delivery`, который рассчитывает международную доставку Почтой России через публичный tariff API, добавляет ставку в classic checkout и сохраняет диагностические данные расчета в meta заказа.

Код уже имеет несколько полезных зачатков будущей архитектуры: carrier interface, registry, quote normalizer, cache abstraction, settings object, order meta service и отдельный API-клиент Почты России. При этом текущая архитектура жестко завязана на международную Почту России, один shipping method, один формат тарифа и админку настроек. Для будущего модуля с несколькими ТК, ПВЗ, shipment lifecycle, rule engine, календарями и HPOS нужно изолировать текущий расчет как legacy/carrier adapter и постепенно вводить новые слои.

Функциональный код в рамках аудита не менялся.

## Структура файлов

```text
walls-delivery-calc.php
includes/
  class-wdc-plugin.php
  class-wdc-admin.php
  class-wdc-cache.php
  class-wdc-carrier-registry.php
  class-wdc-country-mapper.php
  class-wdc-location-mapper.php
  class-wdc-logger.php
  class-wdc-order-meta.php
  class-wdc-quote-normalizer.php
  class-wdc-settings.php
  class-wdc-weight-calculator.php
  carriers/
    interface-wdc-carrier.php
    russian-post/
      class-wdc-russian-post-api.php
      class-wdc-russian-post-carrier.php
      class-wdc-russian-post-countries.php
  shipping-methods/
    class-wdc-shipping-method.php
```

Ключевые роли:

- `walls-delivery-calc.php` - основной файл плагина, константы, версия, bootstrap singleton.
- `includes/class-wdc-plugin.php` - загрузка зависимостей, регистрация хуков WooCommerce и админки.
- `includes/shipping-methods/class-wdc-shipping-method.php` - WooCommerce shipping method.
- `includes/carriers/russian-post/class-wdc-russian-post-carrier.php` - текущий расчет тарифа Почты России.
- `includes/carriers/russian-post/class-wdc-russian-post-api.php` - HTTP-клиент tariff API Почты России.
- `includes/carriers/russian-post/class-wdc-russian-post-countries.php` - справочник стран Почты России и country mapping cache.
- `includes/class-wdc-order-meta.php` - перенос meta из shipping rate/item в заказ и metabox заказа.
- `includes/class-wdc-admin.php` - страница настроек в WooCommerce admin.

## Основной bootstrap

Основной файл: `walls-delivery-calc.php`.

Bootstrap:

- проверка `defined( 'ABSPATH' ) || exit`;
- объявление констант `WDC_PLUGIN_FILE`, `WDC_PLUGIN_DIR`, `WDC_PLUGIN_URL`, `WDC_VERSION`;
- `require_once includes/class-wdc-plugin.php`;
- функция `wdc_plugin(): WDC_Plugin`;
- немедленный вызов `wdc_plugin()`.

`WDC_Plugin` реализован как singleton. В конструкторе он загружает зависимости, создает `WDC_Logger`, `WDC_Settings`, `WDC_Order_Meta` и вешает `plugins_loaded -> init`.

## Текущая версия

Версия объявлена в `walls-delivery-calc.php`:

```php
define( 'WDC_VERSION', '0.3.0' );
```

Версия в рамках аудита не менялась.

## Подключение WooCommerce

Плагин не делает жесткой проверки активного WooCommerce при старте. Интеграция идет через хуки:

- `woocommerce_shipping_init` - подключает файл shipping method, если существует `WC_Shipping_Method`;
- `woocommerce_shipping_methods` - регистрирует `wdc_dynamic_delivery`;
- в админке выводится статус `class_exists( 'WooCommerce' )`;
- расчет и order meta используют классы/функции WooCommerce: `WC_Shipping_Method`, `WC_Order`, `WC_Order_Item_Shipping`, `WC()`, `wc_get_logger()`, `wc_get_order()`.

Риск: нет раннего guard/dependency notice для случая, когда WooCommerce выключен. Большая часть кода загружается всегда, но shipping class подключается только после проверки `WC_Shipping_Method`.

## Текущие WooCommerce hooks

- `woocommerce_shipping_init` -> `WDC_Plugin::load_shipping_method()`.
- `woocommerce_shipping_methods` -> `WDC_Plugin::register_shipping_method()`.
- `woocommerce_checkout_create_order_shipping_item` -> `WDC_Order_Meta::save_shipping_item_meta()`.

Также используется экран HPOS:

- `add_meta_boxes_woocommerce_page_wc-orders` -> `WDC_Order_Meta::add_hpos_meta_box()`.

## Текущие WordPress hooks

- `plugins_loaded` -> `WDC_Plugin::init()`.
- `admin_menu` -> `WDC_Admin::add_menu_page()`.
- `admin_init` -> `WDC_Admin::handle_save()`.
- `add_meta_boxes_shop_order` -> `WDC_Order_Meta::add_classic_meta_box()`.

AJAX-хуков (`wp_ajax_*`), REST routes, cron hooks, activation/deactivation hooks не найдено.

## Текущий shipping flow

1. WordPress загружает основной файл и singleton `WDC_Plugin`.
2. На `plugins_loaded` плагин регистрирует shipping hooks и инициализирует `WDC_Order_Meta`.
3. На `woocommerce_shipping_init` подключается `WDC_Shipping_Method`.
4. Через `woocommerce_shipping_methods` WooCommerce получает метод `wdc_dynamic_delivery`.
5. WooCommerce вызывает `WDC_Shipping_Method::calculate_shipping( $package )`.
6. Метод создает `WDC_Russian_Post_Carrier` и вызывает `get_quote()`.
7. Carrier возвращает normalized quote с одной calculated rate или fallback rate.
8. Shipping method вызывает `add_rate()` и кладет в `meta_data` три блока: `wdc_quote_summary`, `wdc_rate_summary`, `wdc_raw_meta`.
9. При создании заказа `WDC_Order_Meta::save_shipping_item_meta()` переносит эти данные в order meta и добавляет видимые meta к shipping item.

Checkout Blocks не поддерживаются явно. Текущий flow соответствует classic shortcode checkout.

## Текущий расчет доставки

Основной расчет находится в `WDC_Russian_Post_Carrier::get_quote()`.

Алгоритм:

- читает настройки `WDC_Settings::get()`;
- определяет destination country из `$package['destination']['country']`;
- мапит страну WooCommerce в ID страны Почты России через `WDC_Location_Mapper` и `WDC_Russian_Post_Countries`;
- считает вес товаров через `WDC_Weight_Calculator`;
- добавляет вес упаковки по tiers из настроек;
- проверяет max package weight;
- строит параметры API: `object`, `from`, `country-to`, `weight`, `date`, `date-discount`, `isavia`;
- строит cache key `rp_worldwide_` + md5 JSON params;
- получает тариф из transient cache или через `WDC_Russian_Post_API::calculate_tariff()`;
- извлекает цену API из `paynds` или `paymoneynds` в копейках;
- считает базовую цену в рублях;
- применяет формулу `ceil( api_price_rub / formula_divider + formula_add_rub )`;
- считает скидку от суммы товаров `floor( items_net_total_rub * discount_percent / 100 )`;
- итоговая цена: `max( 1, shipping_price_before_items_discount - discount_amount )`;
- определяет transport type: `transtype == 2` => `air`, иначе `ground`;
- возвращает normalized quote.

Fallback:

- используется при выключенной услуге, неподдержанной стране, превышении веса, ошибке API, отсутствующей цене;
- если fallback включен, возвращает ставку с ценой `0` и label из настроек;
- если fallback выключен, quote возвращается без ставок.

## Текущий расчет Почты России

Есть только международный экспорт Почты России:

- carrier id: `russian_post`;
- service id: `russian_post_worldwide_parcel`;
- legacy service alias в настройках: `russian_post_international_parcel`;
- object code по умолчанию: `4031`;
- endpoint тарифа: `https://tariff.pochta.ru/v2/calculate/tariff`;
- endpoint справочника стран: `https://tariff.pochta.ru/v2/dictionary/country`;
- РФ исключается из международной доставки.

Внутрироссийского расчета Почты России не найдено. Создания отправлений, трекинга, статусов, ПВЗ/отделений как сущностей нет.

## Текущая работа с заказом WooCommerce

`WDC_Order_Meta` сохраняет данные через WooCommerce CRUD:

- `$order->update_meta_data( $key, $value )`;
- `$order->get_meta( $key, true )`;
- `wc_get_order()` для classic metabox object fallback.

Прямых вызовов `get_post_meta`, `update_post_meta`, `$wpdb`, `wp_posts`, `wp_postmeta` не найдено.

Meta, сохраняемые в заказ:

- `_wdc_delivery_provider`
- `_wdc_delivery_service`
- `_wdc_delivery_rate_id`
- `_wdc_delivery_rate_title`
- `_wdc_delivery_method`
- `_wdc_transport_type`
- `_wdc_tariff_type`
- `_wdc_currency`
- `_wdc_wc_country`
- `_wdc_carrier_country_id`
- `_wdc_carrier_country_name`
- `_wdc_cart_weight_g`
- `_wdc_packaging_weight_g`
- `_wdc_total_weight_g`
- `_wdc_post_price_kop`
- `_wdc_post_price_rub`
- `_wdc_api_original_price_kop`
- `_wdc_api_original_price_rub`
- `_wdc_formula_divider`
- `_wdc_formula_add_rub`
- `_wdc_items_net_total_rub`
- `_wdc_shipping_discount_percent_from_items_total`
- `_wdc_shipping_discount_amount_rub`
- `_wdc_shipping_price_before_items_discount_rub`
- `_wdc_final_shipping_price_rub`
- `_wdc_origin_postcode`
- `_wdc_object_code`
- `_wdc_isavia_requested`
- `_wdc_transtype_result`
- `_wdc_transtype_name`
- `_wdc_api_date`
- `_wdc_api_cache_hit`
- `_wdc_api_error`
- `_wdc_is_fallback`
- `_wdc_api_paynds`
- `_wdc_api_paymoneynds`
- `_wdc_api_url`
- `_wdc_api_http_code`

Shipping item visible meta:

- `Расчёт доставки`
- `Страна расчёта`
- `Вес расчёта`
- `Итог расчёта`

## Текущая работа с настройками

Настройки хранятся одним option:

- option name: `wdc_settings`;
- чтение: `get_option()`;
- запись: `update_option()`;
- дефолты и sanitization в `WDC_Settings`.

Настройки включают:

- debug/fallback/currency;
- настройки `russian_post_worldwide_parcel`;
- country overrides;
- packaging tiers.

Секретов/API-ключей в настройках не найдено.

## Текущая работа с фронтовыми скриптами

Отдельных frontend CSS/JS файлов и `wp_enqueue_script()` / `wp_enqueue_style()` не найдено.

В `WDC_Admin::render_country_tables_assets()` есть inline `<style>` и `<script>` для таблиц стран в админке. Это не storefront frontend, а admin UI, встроенный прямо в render method.

## Текущая работа с админкой

Админка реализована в `WDC_Admin`:

- submenu page под `woocommerce`;
- capability: `manage_options`;
- tabs: general, Russian Post international, packaging, countries, logs;
- формы сохраняются через `admin_init` и POST flags;
- используется `check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' )`;
- bulk preview/apply country overrides используют transients на пользователя;
- логи как отдельный UI фактически не реализованы: tab сообщает, что логи будут добавлены позже.

## Текущая работа с AJAX

AJAX отсутствует. `wp_ajax_*`, `admin-ajax.php`, REST API routes не найдены.

Все admin operations идут через POST на admin page и redirect.

## Текущая работа с API-запросами

API-запросы:

- `WDC_Russian_Post_API::calculate_tariff()` использует `wp_remote_get()`;
- `WDC_Russian_Post_Countries::refresh_countries()` использует `wp_remote_get()`;
- `WDC_Russian_Post_Countries::check_country_tariff_availability()` вызывает tariff API для проверки страны.

Аутентификация не используется. API-ключей нет.

Риски:

- debug log может записать полный URL, параметры и тело ответа;
- retry/backoff/circuit breaker нет;
- HTTP timeout фиксирован 20 секунд;
- ошибки API превращаются в fallback, что хорошо для checkout, но усложняет диагностику качества тарифа.

## Текущая работа с кешем

`WDC_Cache` - тонкая обертка над WordPress transients:

- prefix: `wdc_`;
- default TTL: `DAY_IN_SECONDS`;
- normalize key через `sanitize_key()` и truncation.

Кешируется:

- tariff API result до конца дня, если `cache_until_end_of_day = yes`;
- справочник стран Почты России на 7 дней;
- временные admin diagnostics/preview/apply results на пользователя.

Отдельного persistent cache layer, invalidation strategy, cache versioning или cache warmup нет.

## Текущая работа с логами

`WDC_Logger` пишет через `wc_get_logger()` с source `walls-delivery-calc`, если WooCommerce logger доступен. Fallback - `error_log()`.

Логирование включается настройкой `debug_enabled`. В debug context могут попадать:

- URL и параметры API;
- полные body responses;
- quote/rate diagnostic arrays;
- destination country/city/postcode из package;
- order id;
- ошибки API.

Персональные данные минимальны, но postcode/city и диагностические API body могут считаться чувствительными в зависимости от контекста.

## Текущие риски HPOS

Плюсы:

- order meta пишется через `$order->update_meta_data()`;
- чтение идет через `$order->get_meta()`;
- есть metabox hook для HPOS screen `add_meta_boxes_woocommerce_page_wc-orders`;
- classic screen также поддержан.

Риски:

- в коде нет объявления совместимости HPOS через `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`;
- нет тестов на HPOS enabled/disabled;
- metabox registration для HPOS использует `wc_get_page_screen_id()` fallback, но требует проверки в реальном WooCommerce admin;
- нет отдельного слоя repository/order service, вся meta schema зашита в `WDC_Order_Meta`;
- shipping item meta и order meta schema пока рассчитаны на один carrier/service.

Итог: текущий код в основном использует HPOS-friendly CRUD, но официально не объявляет совместимость и имеет непроверенные admin-screen риски.

## Текущие риски безопасности

Найденные положительные места:

- admin save operations проверяют `current_user_can( 'manage_options' )`;
- admin POST operations используют `check_admin_referer()`;
- большинство выводов в admin page экранируются через `esc_html`, `esc_attr`, `esc_url`;
- настройки проходят sanitization в `WDC_Settings`;
- прямых SQL-запросов нет;
- секретов/API-ключей нет.

Риски и ограничения:

- inline admin JS/CSS не подключается через enqueue и не изолирован;
- `WDC_Logger` может логировать полный body API response и URL с параметрами;
- в checkout debug log может содержать destination/city/postcode из package;
- API URL сохраняется в order meta (`_wdc_api_url`) и показывается в metabox;
- нет capability/nonce проблем в найденных admin POST paths, но при будущих AJAX/REST операциях это нужно проектировать отдельно;
- нет централизованной политики redaction для логов и order diagnostics;
- строки в некоторых файлах отображаются как mojibake в консоли, нужно проверить фактическую кодировку/локализацию перед i18n-работами.

## Текущие технические ограничения

- Нет Composer, PSR-4, namespaces, autoload.
- Все классы в глобальном namespace с префиксом `WDC_`.
- Нет тестов.
- Нет migrations/activation hooks/custom tables.
- Нет Blocks checkout integration.
- Нет абстракции multi-carrier orchestration.
- Нет rule engine.
- Нет календарей.
- Нет ФИАС/ГАР.
- Нет ПВЗ/map flow.
- Нет shipment creation/status lifecycle.
- Нет админского перерасчета доставки.
- Нет domain model для carrier/rate/shipment/pickup point.
- Текущий shipping method напрямую создает `WDC_Russian_Post_Carrier`.
- Meta schema заказа жестко привязана к Почте России и международному сценарию.
