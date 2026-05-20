# Карта текущего кода Walls Delivery Calc

## Сводная таблица

| Путь | Назначение | Ключевые классы/функции | Хуки | Связи | Решение |
|---|---|---|---|---|---|
| `walls-delivery-calc.php` | Основной файл плагина, константы, запуск singleton | `wdc_plugin()`, `WDC_VERSION` | нет прямых hooks | подключает `includes/class-wdc-plugin.php` | оставить, позже адаптировать bootstrap |
| `includes/class-wdc-plugin.php` | Bootstrap, загрузка зависимостей, регистрация WooCommerce/admin hooks | `WDC_Plugin` | `plugins_loaded`, `woocommerce_shipping_init`, `woocommerce_shipping_methods` | подключает все классы через `require_once`; создает logger/settings/order_meta/admin | перенести в core platform |
| `includes/shipping-methods/class-wdc-shipping-method.php` | WooCommerce shipping method для classic checkout | `WDC_Shipping_Method`, `calculate_shipping()`, `add_quote_rates()` | methods вызываются WooCommerce после регистрации | напрямую создает `WDC_Russian_Post_Carrier`; использует `WDC_Settings`, `WDC_Logger` | изолировать, позже заменить adapter-aware shipping method |
| `includes/carriers/interface-wdc-carrier.php` | Контракт carrier adapter | `WDC_Carrier_Interface` | нет | используется `WDC_Russian_Post_Carrier` | оставить как прототип, перенести и расширить |
| `includes/class-wdc-carrier-registry.php` | Реестр carrier/service definitions | `WDC_Carrier_Registry` | нет | используется carrier, settings, order meta, quote normalizer | перенести, но расширить модель |
| `includes/carriers/russian-post/class-wdc-russian-post-carrier.php` | Текущий расчет международной Почты России | `WDC_Russian_Post_Carrier::get_quote()` | нет | использует settings, normalizer, weight calculator, cache, API, location mapper, logger | сохранить как legacy adapter, изолировать |
| `includes/carriers/russian-post/class-wdc-russian-post-api.php` | HTTP-клиент tariff API Почты России | `WDC_Russian_Post_API::calculate_tariff()` | нет | вызывается carrier и countries checker | перенести в adapter layer, добавить redaction/retry позже |
| `includes/carriers/russian-post/class-wdc-russian-post-countries.php` | Справочник стран Почты России, mapping и cache | `WDC_Russian_Post_Countries` | нет | использует `WDC_Cache`, `WDC_Settings`, `WDC_Logger`, `WDC_Russian_Post_API` | сохранить частично, изолировать от admin UI |
| `includes/class-wdc-location-mapper.php` | Маппинг локаций WooCommerce -> carrier IDs | `WDC_Location_Mapper::map_country()`, `map_city()` | нет | использует countries client и registry | перенести как adapter-facing service |
| `includes/class-wdc-country-mapper.php` | Пустой placeholder | `WDC_Country_Mapper` | нет | нет фактической логики | заменить или удалить позже |
| `includes/class-wdc-weight-calculator.php` | Расчет веса товаров и упаковки | `WDC_Weight_Calculator::calculate_package_weight()` | нет | используется Russian Post carrier | сохранить, перенести в domain/calculation layer |
| `includes/class-wdc-quote-normalizer.php` | Нормализованный формат quote/rate и fallback quote | `WDC_Quote_Normalizer` | нет | используется carrier и indirectly shipping method | сохранить идею, заменить hardcoded Russian Post defaults |
| `includes/class-wdc-settings.php` | Настройки, дефолты, sanitization, legacy migration | `WDC_Settings` | нет | используется почти всеми сервисами | сохранить частично, переработать schema |
| `includes/class-wdc-order-meta.php` | Сохранение расчета в заказ, metabox заказа | `WDC_Order_Meta` | `woocommerce_checkout_create_order_shipping_item`, `add_meta_boxes_woocommerce_page_wc-orders`, `add_meta_boxes_shop_order` | читает shipping item meta, пишет order meta через WooCommerce CRUD | перенести, разделить persistence/schema/rendering |
| `includes/class-wdc-admin.php` | Админская страница настроек и country overrides | `WDC_Admin` | `admin_menu`, `admin_init` | использует settings, logger, Russian Post countries | изолировать, позже разбить по страницам/controllers |
| `includes/class-wdc-cache.php` | Transient cache abstraction | `WDC_Cache` | нет | используется carrier/countries | оставить, расширить versioning/invalidation |
| `includes/class-wdc-logger.php` | Обертка над WooCommerce logger/error_log | `WDC_Logger` | нет | используется сервисами | оставить, добавить redaction |

## Подробности по значимым файлам

### `walls-delivery-calc.php`

- Назначение: WordPress plugin header, constants, bootstrap.
- Ключевое: `define( 'WDC_VERSION', '0.3.0' )`.
- Связи: требует `includes/class-wdc-plugin.php`.
- Комментарий: оставить как минимальный entrypoint. При новой архитектуре добавить autoload/bootstrap container, но не менять версию без релизного решения.

### `includes/class-wdc-plugin.php`

- Назначение: главный singleton и ручная загрузка всех зависимостей.
- Ключевые методы: `instance()`, `init()`, `load_shipping_method()`, `register_shipping_method()`, `load_dependencies()`.
- Хуки:
  - `plugins_loaded`;
  - `woocommerce_shipping_init`;
  - `woocommerce_shipping_methods`.
- Связи: создает `WDC_Logger`, `WDC_Settings`, `WDC_Order_Meta`, `WDC_Admin`.
- Комментарий: перенести в слой core platform. Сейчас это одновременно bootstrap и service wiring.

### `includes/shipping-methods/class-wdc-shipping-method.php`

- Назначение: WooCommerce shipping method.
- Ключевые методы: `calculate_shipping()`, `add_quote_rates()`, `build_rate_meta_data()`, `build_compact_raw_meta()`.
- Хуки: напрямую не регистрирует, вызывается WooCommerce как registered shipping method.
- Связи: жестко создает `WDC_Russian_Post_Carrier`; строит shipping rate meta для `WDC_Order_Meta`.
- Комментарий: требует изоляции. В новой архитектуре shipping method должен получать rates от orchestrator/rule engine/carrier adapters, а не знать конкретную Почту России.

### `includes/carriers/interface-wdc-carrier.php`

- Назначение: минимальный contract adapter.
- Ключевые методы: `get_id()`, `get_title()`, `get_services()`, `get_quote()`.
- Комментарий: можно сохранить как основу, но будущий контракт должен учитывать capabilities, pickup points, shipment creation, calendars, statuses, errors и idempotency.

### `includes/class-wdc-carrier-registry.php`

- Назначение: hardcoded registry Почты России.
- Ключевое: carrier `russian_post`, service `russian_post_worldwide_parcel`, direction `international_export`.
- Комментарий: сохранить идею registry, но заменить на расширяемую schema/config provider.

### `includes/carriers/russian-post/class-wdc-russian-post-carrier.php`

- Назначение: расчет тарифа международной Почты России.
- Ключевые методы: `get_quote()`, `fallback()`, `build_request_params()`, `get_cached_api_result()`, `extract_price_kop()`.
- Связи: `WDC_Russian_Post_API`, `WDC_Russian_Post_Countries` через mapper, `WDC_Cache`, `WDC_Weight_Calculator`, `WDC_Quote_Normalizer`.
- Комментарий: оставить как legacy behavior. Любые изменения опасны без golden tests по текущим тарифам/fallback.

### `includes/carriers/russian-post/class-wdc-russian-post-api.php`

- Назначение: вызов `https://tariff.pochta.ru/v2/calculate/tariff`.
- Ключевые методы: `calculate_tariff()`, `extract_api_error_message()`.
- Комментарий: перенести в adapter infrastructure; добавить retry/timeout config/redacted logging позже.

### `includes/carriers/russian-post/class-wdc-russian-post-countries.php`

- Назначение: загрузка, нормализация и кеширование справочника стран Почты России.
- Ключевые методы: `get_countries()`, `refresh_countries()`, `get_country_by_wc_code()`, `rebuild_cached_effective_countries()`, `check_country_tariff_availability()`.
- Связи: settings country overrides, WooCommerce country list, transient cache, tariff API probe.
- Комментарий: полезная логика, но слишком много responsibilities: API client, normalization, diagnostics, override application. Разделить позже.

### `includes/class-wdc-location-mapper.php`

- Назначение: country/city mapper facade.
- Ключевые методы: `map_country()`, `map_city()`.
- Комментарий: city mapping placeholder. Для ФИАС/ГАР и ПВЗ понадобится отдельная модель адреса и нормализации.

### `includes/class-wdc-country-mapper.php`

- Назначение: placeholder без логики.
- Комментарий: требует решения: либо удалить после переноса, либо наполнить как часть location domain.

### `includes/class-wdc-weight-calculator.php`

- Назначение: расчет веса корзины и упаковки.
- Ключевые методы: `calculate_package_weight()`, `find_packaging_weight()`.
- Связи: WooCommerce product object via `get_weight()`.
- Комментарий: переиспользовать, но учесть единицы веса WooCommerce и будущие правила упаковки.

### `includes/class-wdc-quote-normalizer.php`

- Назначение: нормальная shape quote/rate и fallback.
- Ключевые методы: `get_default_quote()`, `get_default_rate()`, `normalize_quote()`, `create_fallback_quote()`.
- Комментарий: идея полезная, но текущие defaults жестко Russian Post-specific.

### `includes/class-wdc-settings.php`

- Назначение: option storage, defaults, sanitization.
- Ключевые методы: `get_defaults()`, `get()`, `update()`, `sanitize()`.
- Хранилище: option `wdc_settings`.
- Комментарий: сохранить как compatibility layer; новую config schema лучше версионировать.

### `includes/class-wdc-order-meta.php`

- Назначение: persist shipping calculation to order meta and render metabox.
- Ключевые методы: `save_shipping_item_meta()`, `build_order_meta()`, `render_meta_box()`.
- Хуки:
  - `woocommerce_checkout_create_order_shipping_item`;
  - `add_meta_boxes_woocommerce_page_wc-orders`;
  - `add_meta_boxes_shop_order`.
- Комментарий: HPOS-friendly по CRUD, но schema hardcoded. Нужно разделить writer, reader, renderer, schema definitions.

### `includes/class-wdc-admin.php`

- Назначение: WooCommerce submenu settings page.
- Ключевые методы: `handle_save()`, `render_page()`, tab render methods, bulk country override methods.
- Хуки:
  - `admin_menu`;
  - `admin_init`.
- Безопасность: capability `manage_options`, nonce `wdc_save_settings`.
- Комментарий: сохранить admin use cases, но разбить controller/view/assets.

### `includes/class-wdc-cache.php`

- Назначение: transient wrapper.
- Ключевые методы: `get()`, `set()`, `delete()`, `get_seconds_until_end_of_day()`.
- Комментарий: оставить, добавить namespaces/versioned keys при миграции.

### `includes/class-wdc-logger.php`

- Назначение: логирование через WooCommerce logger.
- Ключевые методы: `log()`.
- Комментарий: оставить, но добавить redaction PII/API payload и уровни событий.

## Отдельно: отсутствующие элементы

- Composer/autoload: отсутствует.
- Namespaces: отсутствуют.
- PSR-4 структура: отсутствует.
- Frontend enqueue: отсутствует.
- AJAX/REST: отсутствует.
- Migrations/custom tables: отсутствуют.
- Activation/deactivation hooks: отсутствуют.
- WooCommerce Blocks checkout support: отсутствует.
- HPOS compatibility declaration: отсутствует.
