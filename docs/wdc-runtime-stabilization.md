# WDC Runtime Stabilization 0.12.1

## Что исправлено

- Устранен fatal при создании `NewShippingMethod`: внутренний репозиторий настроек больше не объявляется как `$settings` и не конфликтует с публичным свойством WooCommerce `WC_Settings_API::$settings`.
- Новые страницы WDC перенесены из меню WooCommerce в отдельный верхнеуровневый пункт «Калькулятор доставок» со slug `wdc-platform`.
- Видимые строки новой админки и checkout-интерфейса переведены на русский.
- Новая checkout UI, нормализация адреса, валидация, отладочная панель и frontend CSS подключаются только при включенной новой системе доставки.
- Добавлена страница «Калькулятор доставок → Настройки» для включения новой системы без ручного редактирования `FeatureFlags.php`.

## Как включить новую доставку

1. Откройте в админке WordPress: «Калькулятор доставок → Настройки».
2. Включите «Включить новую систему доставки».
3. При необходимости выберите сортировку: «По цене» или «По сроку».
4. Оставьте «Включить тестовую ТК Demo» включенной для smoke-теста на сайте.
5. Сохраните настройки.

`FeatureFlags::new_shipping_method_enabled()` остается dev override, но обычный тестовый сценарий должен использовать настройку `enable_new_checkout_shipping`.

## Меню

Верхнеуровневый пункт: «Калькулятор доставок».

Подменю:

- «Обзор»
- «Настройки»
- «Календари»
- «Населенные пункты»
- «Правила»
- «ПВЗ»
- «Симулятор checkout»

## Почему frontend hooks gated

При выключенной новой системе доставки legacy checkout должен выглядеть как раньше. Поэтому хуки новой системы регистрируются только через общий `CheckoutFeatureGate`:

- `CheckoutRateRenderer`
- `CheckoutDeliveryTypeSelector`
- `CheckoutAddressRuntime`
- `CheckoutAddressRenderer`
- `CheckoutValidation`
- `CheckoutDebugPanel`
- `OrderShippingMetaPersister`
- frontend CSS `checkout-rates.css`, `pickup-foundation.css`, `address-normalization.css`

Если gate выключен, блок «Проверка адреса» и другие элементы новой checkout-системы не появляются.

## DemoCarrier

Тестовая ТК Demo включается отдельной настройкой `enable_demo_carrier`. По умолчанию она включена, чтобы можно было проверять новую систему на тестовом сайте.

Если новая доставка включена, но активных carriers нет, checkout orchestration возвращает резервный тариф «Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина» вместо fatal.

## Debug panel

Отладочный блок checkout скрыт по умолчанию. Он показывается только когда одновременно выполнены условия:

- новая система доставки включена;
- пользователь имеет право `manage_options`;
- настройка `show_checkout_debug_panel` включена.

## Как тестировать на сайте

1. Убедитесь, что новая система выключена: checkout не должен показывать «Проверка адреса», выбор ПВЗ, debug panel или CSS-оформление WDC Platform.
2. Включите новую систему в «Калькулятор доставок → Настройки».
3. Оставьте DemoCarrier включенным.
4. Проверьте checkout с российским городом: должны появиться тестовые варианты доставки.
5. Для проверки fallback выключите DemoCarrier и повторите расчет: должен появиться резервный тариф, без fatal.
6. Для отладки включите «Показывать отладочный блок checkout администраторам» и откройте checkout под администратором.

## Smoke tests

Проверки runtime stabilization:

- `php tests/checkout/run-runtime-stabilization-smoke.php`
- `php tests/checkout/run-woocommerce-checkout-smoke.php`
- `php tests/pickup/run-pickup-smoke.php`
- `php tests/address/run-address-smoke.php`
