# WDC Order Delivery Recalculation

Version: 0.69.2.

## Статус 0.69.2

Восстановлен показ DPD pickup/courier rates в order-admin блоке `Калькулятор доставок` при preview после выбора населенного пункта.

- Причина была в mapper-слое пересчета: admin selected-location payload может приходить с `location_id`, а `OrderQuoteRequestMapper` принимал только `id`. В таком случае checkout-compatible request терял `location_id`, и DPD runtime не получал корректный receiver location context.
- Mapper теперь принимает `id`, `location_id` и `selected_location_id`, сохраняет DPD cityId aliases (`dpd_city_id` / `dpd_receiver_city_id`) и передает `dpd_receiver_city_id` в `customer_context`.
- Preview по-прежнему идет через `CheckoutOrchestrator -> DpdQuoteCarrier`; отдельного DPD calculator для order recalculation нет.
- `OrderDeliveryRecalculationService` добавил DPD fallback titles для grouped tariffs: `DPD до пункта выдачи` и `DPD курьером`; Russian Post/CDEK fallbacks не изменялись.
- DPD pickup save по-прежнему требует явно выбранный `selected_pickup_point`; auto-selected receiver terminalCode остается quote-only. DPD courier save по-прежнему очищает shared pickup meta и DPD receiver terminal aliases.

## Статус 0.64.2

Уточнен источник `current_pickup` для DPD prefill в order-admin пересчете.

- `OrderDeliveryMetabox::current_pickup_payload()` теперь отдает DPD `terminal_code` только если сохраненный контекст заказа явно DPD pickup: carrier `dpd`, delivery type `pickup` и непустой DPD terminal/point code.
- DPD courier, пустой заказ, CDEK, Почта России и любые другие non-DPD варианты не получают DPD `terminal_code` из shipping address или общего pickup snapshot.
- Для non-DPD pickup текущий payload остается carrier-specific: CDEK/Почта могут prefill-иться своим carrier, но не превращаются в DPD pickup.
- `requestPreview()` отправляет `selected_pickup_point` только после явного выбора ПВЗ менеджером. Если его нет, backend DPD quote может использовать auto-selected receiver terminalCode только для расчета.
- Auto-selected terminalCode из DPD quote не считается selected pickup point в UI и не может попасть в save payload без выбора ПВЗ на карте.

## Статус 0.64.1

Исправлен DPD pickup picker в order-admin блоке `Калькулятор доставок`.

- DPD popup/list card теперь показывает `Пункт выдачи DPD {terminal_code}` и label `Код пункта`, а не Russian Post `Отделение Почты России` / `Код/индекс`.
- `normalizePickupPoint()` сохраняет DPD `terminal_code`, а `pickupPointDisplayCode()` для DPD берет `terminal_code` или `point_code`.
- `prefillCurrentPickupIfAvailable()` стал carrier-aware: выбранный pickup prefill используется только если текущий сохраненный pickup соответствует выбранному carrier/pickup family.
- Для DPD prefill разрешен только когда заказ уже был DPD pickup: есть `carrier_key=dpd`/`pickup_family=dpd:pickup` или DPD terminal alias и непустой `terminal_code`/`point_code`.
- Если заказ был courier, CDEK, Почта России или другой не-DPD вариант, выбор DPD pickup оставляет UI в состоянии `ПВЗ не выбран`; shipping address не считается выбранным DPD ПВЗ.
- Auto-selected receiver terminalCode, который backend использует для quote preview, остается quote-only диагностикой. Он не попадает в `selected_pickup_point` UI и не сохраняется как менеджерский выбор без явного выбора ПВЗ.
- Save DPD pickup без выбранного `selected_pickup_point` блокируется сообщением `Для pickup-варианта выберите ПВЗ.`.

## Статус 0.64.0

DPD подключен к существующему order-admin пересчету доставки по тому же паттерну, что CDEK и Почта России.

- Preview по-прежнему строится через `OrderQuoteRequestMapper`, `OrderDeliveryRecalculationService` и общий `CheckoutOrchestrator`.
- DPD не имеет отдельной "почти checkout" калькуляции: rates приходят из `DpdQuoteCarrier` и текущего `getServiceCostByParcels3` runtime.
- Для DPD pickup preview отправляет sender `pickup.terminalCode` и receiver `delivery.terminalCode`; до выбора ПВЗ receiver terminalCode авто-выбирается из активного `parcel_shop`, после выбора DPD ПВЗ admin JS запускает fresh preview с выбранным `terminal_code`.
- Для DPD courier preview отправляет sender `pickup.terminalCode` и не отправляет receiver `delivery.terminalCode`.
- Admin pickup search endpoint теперь поддерживает `carrier_key=dpd` через `DpdPickupPointService`, возвращая тот же map/list payload shape: `point_code`, `terminal_code`, `pickup_family=dpd:pickup`, title/type/address/city/coordinates/source/snapshot.
- Save DPD pickup пишет `_wdc_platform_carrier_key=dpd`, `_wdc_platform_service_key=dpd`, `_wdc_platform_delivery_type=pickup`, selected serviceCode/title, shipping cost, `_wdc_platform_rate_meta`, `_wdc_delivery_calculation_data`, canonical `_wdc_pickup_*` meta and DPD aliases.
- Save DPD courier пишет `delivery_type=courier`, обновляет DPD tariff/rate data and clears shared pickup meta plus `_wdc_dpd_pickup_*` receiver aliases.
- WooCommerce shipping item is still created/replaced by `OrderDeliveryReplacementService`: method id `wdc_platform_delivery`, compact method title, total = selected rate cost, visible meta only `Срок доставки`.
- `OrderShipmentDraftFactory` reads the recalculated DPD serviceCode, delivery type, sender pickup terminalCode, receiver delivery terminalCode for pickup and selected pickup snapshot from the same order meta used by checkout-created DPD orders.
- Live DPD shipment creation remains disabled; no labels/statuses/cancellation/COD/unitLoad were added.

## Цель этапа

Сценарий пересчета доставки внутри WooCommerce order admin завершен: администратор может открыть модалку из блока `Калькулятор доставок`, пересчитать rates для текущего или выбранного населенного пункта, выбрать courier/pickup вариант, выбрать ПВЗ для pickup и сохранить новый вариант доставки.

## Статус 0.42.0

Этап завершен и HPOS-аудирован. Следующий рекомендуемый крупный этап разработки: `feature/cdek-carrier-foundation`.

Реализовано:

- preview/recalculation в custom admin modal остается доступен всегда и не блокируется shipping items или shipment state;
- save блокируется отдельно, если в заказе больше одного shipping item или есть зарегистрированное отправление;
- зарегистрированным отправлением считается `_wdc_shipments` state с `tracking_number`, `barcode`, `backlog_order_id`, `status=created`, `status=registered`, `universal_status_code`, `carrier_status_title` или `tracking_checked_at`;
- если shipping item отсутствует, save создает новый shipping item;
- если shipping item ровно один, save заменяет его method title, method id, total и WDC meta;
- после save обновляются `_wdc_delivery_calculation_data`, `_wdc_platform_*` meta и pickup meta;
- `_wdc_delivery_calculation_data` сохраняет checkout-like структуру `package`, `api`, `rules`, `result`; базовая API стоимость берется из `api_base_price_rub`, а не из итоговой цены;
- saved shipping method title формируется как на checkout: service/method + selected tariff + delivery text без дублирования срока;
- для courier в модалке показывается блок `Адрес доставки`; подсказки улицы/дома/квартиры/помещения/офиса идут автоматически через общий checkout stack `AddressSuggestionService` + `AddressSuggestionNormalizer` + `AddressLineParser`, без отдельной кнопки `Проверить адрес`;
- explicit manual fallback `Использовать этот адрес` остается доступен, когда подсказки не дали usable result;
- courier поддерживает выбор lower-level suggestion, ввод номера после выбранного дома для фильтрации, и завершение нормализованного адреса на уровне дома через ссылку `если номера нет - нажмите здесь`;
- courier save UI показывает не блокирующий warning, если населенный пункт нормализованного/manual адреса не удалось уверенно сопоставить с населенным пунктом расчета тарифа;
- для pickup требуется выбранный ПВЗ, но не требуется нормализованный адрес менеджера;
- если населенный пункт не менялся, modal prefill выбирает текущий ПВЗ заказа из WDC pickup meta только когда этот ПВЗ соответствует выбранному carrier/pickup family;
- для pickup WooCommerce shipping address заполняется выбранным ПВЗ: address_1 = адрес ПВЗ, address_2 очищается, city/state/postcode/country берутся из ПВЗ/selected location;
- WooCommerce shipping city/state при admin save записываются через checkout-compatible selected location payload/formatter values (`city_value`, `state_value` и fallback через `LocationDisplayNameFormatter`), а не через полный `display_name`;
- ПВЗ сохраняется в WDC pickup meta и не попадает в order note;
- ручной поиск адреса на карте ПВЗ геокодируется через admin wrapper над существующей DaData/address-normalization инфраструктурой; временная булавка ставится только по координатам введенного адреса, не по первому ПВЗ fallback;
- totals пересчитываются через WooCommerce order API, затем order сохраняется;
- после успешного save добавляется приватное примечание на русском языке со старым/новым методом, ценой, базовой API стоимостью, total и old/new city только при фактической смене населенного пункта;
- JS после успешного save перезагружает страницу, чтобы администратор видел актуальные totals, shipping item и блок `Калькулятор доставок`.

HPOS audit:

- order loading goes through `wc_get_order()`;
- order meta is read/written through `$order->get_meta()` and `$order->update_meta_data()`;
- shipping item create/replace uses `WC_Order_Item_Shipping` and WooCommerce item CRUD;
- order totals are recalculated through `$order->calculate_totals(false)`;
- private notes are written through `$order->add_order_note()`;
- no direct order `get_post_meta()`, `update_post_meta()`, `WP_Query` over `shop_order`, or direct `wp_posts`/`wp_postmeta` access was found in the audited order delivery recalculation code.

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
- `wdc_order_delivery_recalculate_pickup_search`: поиск ПВЗ для карты, initial `mode=location` грузит все ПВЗ выбранного населенного пункта, manual `mode=search` ищет по введенному адресу/индексу/коду. Supports Russian Post, CDEK and DPD; DPD rows come from active local `parcel_shop` points through `DpdPickupPointService`.
- `wdc_order_delivery_recalculate_address_suggest`: thin admin wrapper над checkout `AddressSuggestionService` для courier address suggestions; возвращает shared suggestion items plus normalized save payload.
- `wdc_order_delivery_recalculate_normalize_address`: legacy/thin wrapper нормализации courier delivery address через существующий checkout address runtime; основной admin courier UI использует suggestion-driven flow, pickup save его не требует.
- `wdc_order_delivery_recalculate_geocode_address`: thin wrapper геокодинга ручного адреса на карте ПВЗ через существующий address-normalization/DaData runtime и лимиты.
- `wdc_order_delivery_recalculate_save`: сохранение выбранного rate, создание/замена shipping item, meta rewrite, address update, totals, note.

Все admin AJAX endpoints проверяют nonce, `manage_woocommerce` и загружают order через `wc_get_order()`.

## Ограничения

- Несколько shipping items в заказе остаются save-blocker; автоматического выбора одного shipping item нет.
- Для courier требуется выбранный normalized suggestion payload или explicit `admin_manual` fallback; mismatch warning по населенному пункту не блокирует save.
- Реальный выбор/валидация налогов зависит от WooCommerce `calculate_totals(false)` и текущей конфигурации магазина.
- Save intentionally remains blocked for ambiguous orders with multiple shipping items or already registered shipments.

## Проверки

Smoke coverage:

```powershell
php tests/orders/run-order-delivery-recalculation-smoke.php
php tests/dpd/run-dpd-order-recalculation-smoke.php
```

Тесты проверяют modal markup, current pickup/current shipping address payload, duplicate-free location labels, order-to-quote mapping, location override, all-rates preview, Russian Post/CDEK/DPD pickup/courier groups, pickup map and geocode endpoints, courier address suggest endpoint/shared stack, save blockers, shipping item create/replace, pickup save без normalized address, courier save with required normalized/manual address, checkout-compatible city/state save, WDC meta rewrite with package/API/rules/result, DPD Parcels3 terminalCode payloads, DPD pickup alias meta, DPD courier pickup-meta cleanup, shipment draft visibility, totals recalculation, private notes, endpoint security, JS prefill/map-sync/geocode/save-warning hooks and no mutation during ordinary preview/pickup search.
