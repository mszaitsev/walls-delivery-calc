# WDC Runtime Stabilization 0.12.8

## Что исправлено

- Устранен fatal при создании `NewShippingMethod`: внутренний репозиторий настроек не конфликтует с публичным свойством WooCommerce `WC_Settings_API::$settings`.
- Новые страницы WDC находятся в отдельном верхнеуровневом меню «Калькулятор доставок» со slug `wdc-platform`.
- Новая checkout UI, нормализация адреса, валидация, отладочная панель и frontend assets подключаются только при включенной новой системе доставки.
- Pickup и courier больше не фильтруются через session `selected_delivery_type`.
- Pickup и courier являются отдельными WooCommerce shipping rates: `demo:pickup` и `demo:courier`.
- Radio-переключатель `wdc_platform_delivery_type` под тарифами убран.
- Демо-населенные пункты и демо-ПВЗ русифицированы.
- Добавлена простая сортировка вариантов доставки на checkout.
- Добавлен рабочий checkout city selector/autocomplete v1 для классического WooCommerce checkout.
- City selector устойчив к WooCommerce `updated_checkout`: повторно инициализируется после refresh и не дублирует обработчики.
- AJAX endpoint `wdc_platform_search_locations` регистрируется для logged-in и guest checkout независимо от `is_admin()`.
- Нормализованный адрес теперь привязан к fingerprint текущего checkout address, поэтому старый город не остается в session после смены города.
- Сохраненный выбор ПВЗ очищается при смене города и не показывается, если пункт не относится к текущему городу.
- City selector теперь открывается как overlay/popup и не сдвигает checkout вниз.
- Backend search ограничивает количество результатов настройкой `location_search_limit`, по умолчанию 100.
- Поиск поддерживает исправление ошибочной EN/RU раскладки клавиатуры, например `yjdjc` → `новос`.

## Как включить новую доставку

1. Откройте «Калькулятор доставок → Настройки».
2. Включите «Включить новую систему доставки».
3. Оставьте «Включить тестовую ТК Demo» включенной для проверки pickup/courier.
4. Сохраните настройки.

`FeatureFlags::new_shipping_method_enabled()` остается dev override, но тестирование на сайте должно идти через настройку `enable_new_checkout_shipping`.

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

## Checkout rates

Новая система больше не использует session `selected_delivery_type` для фильтрации списка тарифов. DemoCarrier всегда возвращает оба доступных варианта:

- `demo:pickup` — «Тестовый пункт выдачи»
- `demo:courier` — «Тестовая курьерская доставка»

Выбранный тип доставки определяется выбранным WooCommerce shipping rate. Для pickup rate показывается selector ПВЗ. Для courier rate показывается подсказка: «Для курьерской доставки будет использован адрес, указанный в checkout.»

## City picker overlay

На checkout подключается popup выбора населенного пункта. Он работает только когда новая система доставки включена через `CheckoutFeatureGate`.

Что делает selector:

- использует `#shipping_city` / `input[name="shipping_city"]`, а если их нет — `#billing_city` / `input[name="billing_city"]`;
- при focus/click по стандартному полю города открывает overlay с отдельным полем поиска;
- после 3 символов вызывает AJAX action `wdc_platform_search_locations`;
- показывает результаты группами по регионам внутри scrollable popup;
- при выборе населенного пункта заполняет city, postcode и state/region, если поля доступны;
- сохраняет выбранный населенный пункт в hidden fields:
  `wdc_platform_location_id`, `wdc_platform_location_fias_id`, `wdc_platform_location_gar_id`, `wdc_platform_location_display_name`, `wdc_platform_location_postcode`, `wdc_platform_location_region_name`;
- запускает штатный WooCommerce `update_checkout`.

Frontend не загружает полный справочник городов. Он отправляет только введенный query, а backend возвращает найденные варианты в пределах `location_search_limit`.

Desktop popup: `max-width: 1300px`, `width: min(1300px, calc(100vw - 48px))`, результаты могут идти в 2 колонки. Mobile popup: почти full-screen, `width: calc(100vw - 24px)`, результаты строго в 1 колонку и занимают всю ширину.

При простом вводе picker делает только AJAX search и не запускает `update_checkout` на каждый символ. Checkout обновляется после выбора города из списка или при закрытии popup с ручным fallback-вводом.

Если населенный пункт не найден, в popup показывается «Населенный пункт не найден. Будет использовано введенное значение.» и кнопка «Выбрать введенный населенный пункт». При выборе fallback city hidden location fields остаются пустыми, адрес считается manual fallback: `normalized=false`, `fallback=true`.

Если backend достиг лимита результатов, frontend показывает «Показаны первые 100 результатов. Уточните запрос.».

Поиск исправляет только клавиатурную раскладку, не транслитерирует названия. Пример: `yjdjc` ищется как `новос` и находит Новосибирск. Запрос вроде `Berlin` не должен случайно находить русские города.

Как проверить:

1. Включите новую доставку и DemoCarrier.
2. Кликните поле города на checkout.
3. Должен открыться popup «Выберите населенный пункт».
4. Введите `Новос`.
5. Выберите «Новосибирск — Новосибирская область».
6. Popup должен закрыться.
7. Убедитесь, что город, индекс и регион заполнены, а checkout обновился.
8. Блок проверки адреса должен показать Новосибирск.

Troubleshooting:

- В Network проверьте запрос `admin-ajax.php?action=wdc_platform_search_locations`.
- Для администратора можно включить debug panel в настройках. Тогда city picker пишет в Console: `city picker opened`, `search input query`, `ajax request start`, `ajax success groups count`, `limit reached`, `city picker closed`, `location selected`, `manual fallback city`.

## Normalized vs fallback city

Normalized city выбран из справочника через popup. Для него сохраняются hidden location fields, postcode, FIAS/GAR ids, а блок проверки адреса показывает «Населенный пункт определен».

Manual fallback city введен вручную через кнопку «Выбрать введенный населенный пункт» или закрытие popup с новым текстом. Для него hidden location fields пустые, FIAS/GAR не показывается, а блок проверки адреса пишет «Используется введенный вручную населенный пункт».

## Search limit

Настройка «Калькулятор доставок → Настройки → Лимит результатов поиска населенных пунктов» управляет максимальным числом результатов AJAX-поиска.

- option key: `location_search_limit`;
- default: 100;
- min: 10;
- max: 300.

AJAX response содержит `limit` и `limit_reached`. Если `limit_reached=true`, frontend просит уточнить запрос.

## Address fingerprint

Checkout address runtime строит fingerprint из страны, города, индекса, адреса и hidden selected location fields. Если fingerprint изменился, session очищает:

- старый normalized address result;
- selected city / fallback city;
- pickup selection;
- cached WooCommerce shipping rates.

Это нужно, чтобы ПВЗ и блок проверки адреса всегда соответствовали текущему checkout city, а не прошлому значению из session.

## Сортировка

На checkout выводится select «Сортировка доставки»:

- «По цене»
- «По сроку»

Выбор сохраняется в WooCommerce session через стандартный `woocommerce_checkout_update_order_review`. Минимальный JS `assets/frontend/checkout-sort.js` только вызывает штатный `update_checkout` при изменении select. Если JS не сработает, checkout не ломается и использует настройку по умолчанию.

Session sort mode имеет приоритет над настройкой `checkout_sort_mode`. При смене select очищается WooCommerce shipping cache, чтобы порядок методов пересчитался сразу.

Как проверить:

1. Включите новую доставку и DemoCarrier.
2. Откройте checkout.
3. Выберите «По цене»: первым должен быть pickup за 350.
4. Выберите «По сроку»: первым должен быть courier с меньшим сроком.

## Проверка ПВЗ для Новосибирска

Демо-данные содержат русские ПВЗ:

- `demo-nsk-001` — Новосибирск, Красный проспект, 25
- `demo-nsk-002` — Новосибирск, ул. Фрунзе, 80
- `demo-nsk-003` — Новосибирск, ул. Кирова, 113

Поиск ПВЗ tolerant к русскому городу и частичному вводу:

- «Новосибирск»
- «новосибирск»
- «Новосиб»

Если сохраненных ПВЗ в таблице нет, DemoPickupProvider может вернуть demo points по fallback city.

Проверка смены города:

1. Выберите Новосибирск и убедитесь, что доступны `demo-nsk-001`, `demo-nsk-002`, `demo-nsk-003`.
2. Выберите один из новосибирских ПВЗ.
3. Смените город на Москву.
4. Старый `demo-nsk-*` должен исчезнуть, selection должен сброситься, в списке должен остаться московский demo point.

## Проверка fallback

1. Откройте «Калькулятор доставок → Настройки».
2. Включите «Включить новую систему доставки».
3. Выключите «Включить тестовую ТК Demo».
4. Сохраните настройки и откройте checkout.

Ожидаемый результат: появляется fallback rate «Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина» с ценой 0, без fatal.

## Debug panel

Отладочный блок checkout скрыт по умолчанию. Он показывается только когда одновременно выполнены условия:

- новая система доставки включена;
- пользователь имеет право `manage_options`;
- настройка `show_checkout_debug_panel` включена.

## Smoke tests

- `php tests/checkout/run-runtime-stabilization-smoke.php`
- `php tests/checkout/run-woocommerce-checkout-smoke.php`
- `php tests/pickup/run-pickup-smoke.php`
- `php tests/address/run-address-smoke.php`
