# WDC Order Delivery Recalculation

Version: 0.41.2.

## Цель этапа

Этот документ описывает foundation для будущего пересчета доставки внутри WooCommerce order admin. Итоговая бизнес-цель всей feature-ветки: дать администратору возможность пересчитать доставку заказа, выбрать новый метод, при необходимости выбрать ПВЗ, безопасно заменить WooCommerce shipping item, обновить WDC delivery meta и пересчитать totals.

## Статус 0.41.2

Реализован только preview-only foundation.

Что уже есть:

- в order metabox `Калькулятор доставок` добавлена кнопка `Пересчитать доставку`;
- preview результатов открывается в custom admin modal, а не в постоянном inline-блоке внутри metabox;
- modal содержит header/body/footer, заголовок `Пересчет доставки`, status/loading/error area, content area для rates, close controls и disabled save placeholder `Сохранение будет добавлено следующим шагом`;
- modal закрывается через close button, overlay и Escape; повторный preview request не запускается, пока текущий request активен;
- если `_wdc_shipments` содержит созданное/зарегистрированное отправление, tracking/barcode или `backlog_order_id`, пересчет блокируется в UI и AJAX endpoint;
- `OrderQuoteRequestMapper` строит `QuoteRequest` из текущего WooCommerce заказа, товаров, веса, shipping address и WDC location/calculation meta fallback;
- `OrderDeliveryRecalculationService` вызывает существующий `CheckoutOrchestrator`, поэтому preview использует активные службы доставки, carrier adapters, правила, упаковку и service post-processing текущего checkout runtime;
- admin preview показывает pickup/courier groups и Russian Post domestic tariffs;
- ни один rate и ни один tariff не выбран по умолчанию;
- при выборе pickup rate JS показывает только заглушку `Выбор ПВЗ будет добавлен следующим шагом.`;
- endpoint возвращает HTML preview, normalized rates и request payload для диагностики/следующих patch.

## Ограничения

В 0.41.2 намеренно не реализованы:

- сохранение выбранного метода доставки;
- выбор ПВЗ;
- смена/override населенного пункта;
- замена WooCommerce shipping item;
- пересчет WooCommerce order totals;
- изменение shipping address;
- order note;
- обновление `_wdc_delivery_calculation_data`;
- изменение hidden `_wdc_platform_*` order meta.

## Основные классы

- `src/Orders/Application/OrderQuoteRequestMapper.php`
- `src/Orders/Application/OrderDeliveryRecalculationService.php`
- `src/Orders/Admin/OrderDeliveryRecalculationAdminController.php`
- `src/Orders/Admin/OrderDeliveryRateRenderer.php`
- `src/Orders/Admin/OrderDeliveryMetabox.php`
- `assets/admin/order-delivery-recalculation.js`
- `assets/admin/order-delivery-recalculation.css`

## Следующие patch

Рекомендуемый порядок:

1. Добавить location selector/override в admin preview и пересчет по выбранному населенному пункту.
2. Добавить полноценный выбор ПВЗ для pickup rates, переиспользуя существующий map provider stack.
3. Добавить save/replacement service: безопасная замена shipping item через WooCommerce CRUD, обновление hidden WDC meta и `_wdc_delivery_calculation_data`, пересчет totals, приватный order note и reload страницы.

## Проверки

Smoke coverage:

```powershell
php tests/orders/run-order-delivery-recalculation-smoke.php
```

Тест проверяет shipment block, построение `QuoteRequest`, возврат всех доступных rates, Russian Post domestic pickup/courier groups, отсутствие selected state, nonce/capability checks и отсутствие изменений shipping item/totals/calculation meta.
