# WDC Order Delivery Recalculation

Version: 0.41.12.

## Цель этапа

Сценарий пересчета доставки внутри WooCommerce order admin завершен: администратор может открыть модалку из блока `Калькулятор доставок`, пересчитать rates для текущего или выбранного населенного пункта, выбрать courier/pickup вариант, выбрать ПВЗ для pickup и сохранить новый вариант доставки.

## Статус 0.41.12

Реализовано:

- preview/recalculation в custom admin modal остается доступен всегда и не блокируется shipping items или shipment state;
- save блокируется отдельно, если в заказе больше одного shipping item или есть зарегистрированное отправление;
- зарегистрированным отправлением считается `_wdc_shipments` state с `tracking_number`, `barcode`, `backlog_order_id`, `status=created`, `status=registered`, `universal_status_code`, `carrier_status_title` или `tracking_checked_at`;
- если shipping item отсутствует, save создает новый shipping item;
- если shipping item ровно один, save заменяет его method title, method id, total и WDC meta;
- после save обновляются `_wdc_delivery_calculation_data`, `_wdc_platform_*` meta и pickup meta;
- `_wdc_delivery_calculation_data` сохраняет checkout-like структуру `package`, `api`, `rules`, `result`; базовая API стоимость берется из `api_base_price_rub`, а не из итоговой цены;
- saved shipping method title формируется как на checkout: service/method + selected tariff + delivery text без дублирования срока;
- для courier в модалке показывается блок `Адрес доставки`, менеджер нормализует адрес через существующий DaData/checkout address runtime, и save блокируется без успешного normalized payload;
- для pickup требуется выбранный ПВЗ, но не требуется нормализованный адрес менеджера;
- если населенный пункт не менялся, modal prefill выбирает текущий ПВЗ заказа из WDC pickup meta;
- для pickup WooCommerce shipping address заполняется выбранным ПВЗ: address_1 = адрес ПВЗ, address_2 очищается, city/state/postcode/country берутся из ПВЗ/selected location;
- ПВЗ сохраняется в WDC pickup meta и не попадает в order note;
- ручной поиск адреса на карте ПВЗ геокодируется через admin wrapper над существующей DaData/address-normalization инфраструктурой; временная булавка ставится только по координатам введенного адреса, не по первому ПВЗ fallback;
- totals пересчитываются через WooCommerce order API, затем order сохраняется;
- после успешного save добавляется приватное примечание на русском языке со старым/новым методом, ценой, базовой API стоимостью, total и old/new city только при фактической смене населенного пункта;
- JS после успешного save перезагружает страницу, чтобы администратор видел актуальные totals, shipping item и блок `Калькулятор доставок`.

## Основные классы

- `src/Orders/Application/OrderQuoteRequestMapper.php`
- `src/Orders/Application/OrderDeliveryRecalculationService.php`
- `src/Orders/Application/OrderDeliveryAddressNormalizationService.php`
- `src/Orders/Application/OrderDeliveryReplacementService.php`
- `src/Orders/Admin/OrderDeliveryRecalculationAdminController.php`
- `src/Orders/Admin/OrderDeliveryRateRenderer.php`
- `src/Orders/Admin/OrderDeliveryMetabox.php`
- `assets/admin/order-delivery-recalculation.js`
- `assets/admin/order-delivery-recalculation.css`

## AJAX

- `wdc_order_delivery_recalculate_preview`: пересчет rates, не мутирует заказ.
- `wdc_order_delivery_recalculate_location_search`: thin wrapper над существующим checkout location search payload.
- `wdc_order_delivery_recalculate_pickup_search`: поиск ПВЗ для карты, initial `mode=location` грузит все ПВЗ выбранного населенного пункта, manual `mode=search` ищет по введенному адресу/индексу/коду.
- `wdc_order_delivery_recalculate_normalize_address`: thin wrapper нормализации courier delivery address через существующий checkout address runtime; pickup save его не требует.
- `wdc_order_delivery_recalculate_geocode_address`: thin wrapper геокодинга ручного адреса на карте ПВЗ через существующий address-normalization/DaData runtime и лимиты.
- `wdc_order_delivery_recalculate_save`: сохранение выбранного rate, создание/замена shipping item, meta rewrite, address update, totals, note.

Все admin AJAX endpoints проверяют nonce, `manage_woocommerce` и загружают order через `wc_get_order()`.

## Ограничения

- Несколько shipping items в заказе остаются save-blocker; автоматического выбора одного shipping item нет.
- Для courier требуется успешная нормализация адреса менеджером в модалке; без normalized payload frontend держит save disabled, а backend возвращает ошибку.
- Реальный выбор/валидация налогов зависит от WooCommerce `calculate_totals(false)` и текущей конфигурации магазина.
- Сценарий требует ручной QA на реальном HPOS order admin screen после smoke-тестов.

## Проверки

Smoke coverage:

```powershell
php tests/orders/run-order-delivery-recalculation-smoke.php
```

Тест проверяет modal markup, current pickup/current shipping address payload, order-to-quote mapping, location override, all-rates preview, Russian Post pickup/courier groups, pickup map and geocode endpoints, save blockers, shipping item create/replace, pickup save без normalized address, courier save with required normalized address, WDC meta rewrite with package/API/rules/result, totals recalculation, private notes, endpoint security, JS prefill/map-sync/geocode/save hooks and no mutation during preview/pickup search.
