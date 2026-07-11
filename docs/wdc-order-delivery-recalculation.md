# WDC Order Delivery Recalculation

Version: 0.105.9.

## Статус 0.105.9

- `OrderDeliveryRecalculationService` возвращает в preview `location.id` и `location.location_id` из read-only resolved `customer_context.location_id` исходного заказа, если explicit override не выбран. `is_override` по-прежнему выставляется только от явного `location_override`; найденный id не записывается в order meta.
- Admin JS после `renderPreview()` синхронизирует `payload.data.location` в `selectedLocations`: полный current/selected payload не затирается, но missing `id/location_id` дополняется resolved значением. Поэтому первый preview исходного города сразу дает последующему Yandex pickup search numeric location id.
- `ajax_pickup_search()` для Яндекса имеет безопасный fallback: если старый/неполный JS payload пришел без numeric id, controller запрашивает preview-compatible location через `OrderDeliveryRecalculationService::resolved_location_payload()` и затем ищет ПВЗ по тому же Yandex mapping. Полный pricing preview для этого не запускается.
- Admin popup и боковые карточки Яндекс ПВЗ используют checkout presentation formatter fields: `point_title`/`card_title`/`display_title`, `presentation_comment`, address, description и storage notice. `platform_station_id`/`point_code` остаются в payload для identity/save/pricing, но station id и строка `Код/индекс:` для Яндекса визуально не выводятся.

## Статус 0.105.8

- Admin replacement сохраняет `api_base_price_rub` как исходную цену API до правил, а `result.final_price_rub` как итог после Rule Engine. Для Яндекса `pricing_total_kopecks / 100` используется как fallback, если явного `api_base_price_rub` нет; `original_cost` в форме `Money::to_array()` разбирается безопасно.
- `rules.formula_visualization` строится от исходной API-цены и включает `change_delivery_days`: например, формула для 535 -> 662 начинается с `Базовая цена API: 535 руб.`, содержит строку правила срока и заканчивается `Итог: 662 руб.`.
- `OrderDeliveryReplacementService` заменяет исходный suffix срока в title на финальный: `Яндекс до двери - 8 дней` сохраняется как `Яндекс до двери - 10 дней`; уже финальный title не дублируется, grouped/tariff-selector сценарии других служб не меняются.

## Статус 0.105.7

- `OrderQuoteRequestMapper` для admin preview восстанавливает numeric `location_id` исходного заказа read-only через внедренный `LocationRepository`, если explicit selected location и сохраненный numeric id отсутствуют.
- Приоритет lookup: explicit selected location id, сохраненный numeric id, exact `fias_id`, затем `city_fias_id` только при единственном активном совпадении, затем exact positive GAR (`gar_object_id`). Неоднозначный `city_fias_id` не выбирает первую случайную строку.
- Первый preview исходного города для Яндекса теперь получает numeric `location_id` без ручной смены населенного пункта, поэтому `Яндекс до ПВЗ` доступен сразу и использует representative ПВЗ. Preview не записывает найденный id в order meta.

## Статус 0.105.0

Яндекс.Доставка подключена к существующему блоку WooCommerce заказа `Калькулятор доставок` без отдельного order calculator или API client.

- Preview идет через общий `OrderQuoteRequestMapper -> CheckoutOrchestrator -> YandexDeliveryCarrier -> YandexDeliveryPricingRequestBuilder -> pricing-calculator` runtime и показывает варианты `Яндекс до ПВЗ` и `Яндекс до двери`.
- Общий admin picker ПВЗ получает активные destination rows из локальной Yandex v2 базы по рабочим `yandex_geo_id` выбранного населенного пункта и форматирует их тем же `YandexDeliveryCheckoutPickupPointFormatter`, что checkout.
- После явного выбора ПВЗ admin JS запускает fresh preview; mapper передает checkout-compatible `pickup_selection` и `pickup_selections['yandex_delivery:pickup']`, поэтому pickup pricing использует конкретный `platform_station_id`.
- Save переиспользует общий replacement flow, пишет canonical `_wdc_pickup_*`, `_wdc_pickup_platform_station_id` и Yandex aliases `_wdc_yandex_delivery_pickup_*`; для courier pickup meta очищаются.

Version: 0.69.3.

## Статус 0.69.3

Уточнен DPD pickup flow в order-admin блоке `Калькулятор доставок`.

- DPD pickup рассчитывается тем же checkout runtime path: `CheckoutOrchestrator -> DpdQuoteCarrier -> DpdTariffCalculationService -> getServiceCostByParcels3`.
- Для pickup payload содержит `selfDelivery=true` и `delivery.terminalCode`; если менеджер еще не выбрал ПВЗ, backend выбирает активный receiver `parcel_shop` по `receiver_city_id` только для расчета.
- `terminal_self_delivery` не используется как receiver pickup point. Если у `terminal_self_delivery` и `parcel_shop` совпадает `terminalCode`, auto-selection выбирает другой `parcel_shop`; fallback на duplicate parcel_shop остается допустимым только с warning.
- Если в receiver cityId нет активного `parcel_shop`, DPD pickup не показывается, DPD courier не ломается, а diagnostics содержит `DPD pickup tariff unavailable: no active parcel_shop for receiver cityId {cityId}`.
- Smoke покрывает receiver location/city diagnostics, terminal selection/code/source, raw/skipped/filter counters, `request_payload_sanitized.delivery.terminalCode`, grouped pickup result и no-parcel-shop negative case.
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
