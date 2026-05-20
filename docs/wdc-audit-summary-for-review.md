# Summary for Review

## Что сделано

В рамках аудита изучена текущая база WordPress/WooCommerce-плагина `walls-delivery-calc` и добавлены технические документы. Функциональный PHP/JS/CSS код не изменялся, версия плагина не менялась.

## Ключевые найденные файлы

- `walls-delivery-calc.php` - основной файл плагина, `WDC_VERSION = 0.3.0`.
- `includes/class-wdc-plugin.php` - bootstrap и регистрация hooks.
- `includes/shipping-methods/class-wdc-shipping-method.php` - WooCommerce shipping method `wdc_dynamic_delivery`.
- `includes/carriers/russian-post/class-wdc-russian-post-carrier.php` - текущий расчет международной Почты России.
- `includes/carriers/russian-post/class-wdc-russian-post-api.php` - tariff API client.
- `includes/carriers/russian-post/class-wdc-russian-post-countries.php` - справочник стран Почты России.
- `includes/class-wdc-order-meta.php` - сохранение meta расчета в заказ и metabox.
- `includes/class-wdc-admin.php` - страница настроек.

## Ключевые риски

- Текущий расчет жестко привязан к международной Почте России.
- Нет Composer/autoload/namespaces.
- Нет тестов вокруг расчета тарифа, fallback и order meta.
- HPOS-friendly CRUD используется, но совместимость HPOS не объявлена через WooCommerce FeaturesUtil.
- Нет AJAX/REST сейчас, но будущие операции потребуют отдельной nonce/capability модели.
- Debug logging может писать API URL, параметры, body response и части checkout destination.
- Order meta schema `_wdc_*` hardcoded под один carrier/service.
- Inline admin CSS/JS встроены прямо в render method.

## Вопросы для решения

- Какой формат новой quote/rate/shipment schema считаем стабильным?
- Нужно ли сохранять все текущие `_wdc_*` meta keys для обратной совместимости?
- Когда объявляем HPOS compatibility: до или после добавления тестов/ручной проверки?
- Где и как будут храниться секреты будущих carriers: options, encrypted storage, external secrets?
- Должен ли текущий Russian Post adapter остаться legacy-only или стать первым adapter новой архитектуры?
- Какой минимальный набор golden tests нужен перед изменением формулы расчета?

## Добавленные/измененные файлы аудита

- `docs/wdc-architecture-audit.md`
- `docs/wdc-current-code-map.md`
- `docs/wdc-migration-plan.md`
- `docs/wdc-audit-summary-for-review.md`

Существующие файлы плагина не менялись.
