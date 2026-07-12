# Регистрация отправлений Яндекс.Доставки

## Статус 0.108.3

Общая shipment modal получила canonical контракт для товарного allocation: `shipment_items[]`. Это теперь основной submit-формат для распределения товаров по грузоместам; `cdek_items[]` оставлен только как временный fallback для CDEK/common parser migration и зафиксирован как технический долг. Яндекс не создаёт свой `yandex_items` контракт для модалки и пишет в `ShipmentCreateRequest::meta` canonical `shipment_item_rows`.

Разбор данных модалки вынесен в `ShipmentModalRequestMapper`, который возвращает `ShipmentPreparationData` с двумя нейтральными полями: `places` и `item_rows`. Его используют CDEK и Яндекс admin submit paths. Mapper не исправляет `amount`, `place_number`, `weight`, `item_key` или стоимость: после базовой sanitization результат проходит через существующий `CdekShipmentAllocationAdapter` / `ShipmentAllocation` validator. Поэтому повреждённые rows отклоняются до Yandex HTTP, а split quantity и одинаковые SKU с разными `item_key` остаются различимыми.

Yandex-specific persistence вынесен из общего `ShipmentCreationService` в `YandexShipmentPersistenceMapper`. Общий сервис сохраняет common shipment envelope и вызывает mapper для Yandex fields: canonical request/info snapshot, selected offer, offer expiration, request ids, barcodes, reconciliation pending fields and lookup meta sync через `YandexShipmentRepository`. Reconciliation после successful confirm по-прежнему сохраняет pending shipment и не повторяет confirm.

Пустой alias `ShipmentCarrierAdapterInterface` удалён; canonical interface теперь `CarrierShipmentAdapterInterface`. Общая JS-логика allocation переименована с CDEK-specific names на shipment-neutral names и использует generic `data-wdc-shipment-*` selectors. Carrier-specific CDEK hooks for labels/polling/status remain untouched.

## Статус 0.108.2

Проведён повторный аудит источника allocation для существующей shipment modal. Канонический production flow для CDEK использует не order meta, а отправленные из общей модалки поля `places[]` и `cdek_items[]`; `OrderShipmentDraftFactory::create_cdek_request_from_admin_data()` превращает их в `ShipmentCreateRequest::places` и `meta['cdek_item_rows']`. Для Яндекса используется тот же источник: `create_yandex_request_from_admin_data()` читает `places[]` и `cdek_items[]`, сохраняет split quantity, `place_number`, `item_key` и одинаковые SKU разных order items как разные строки.

Поиски `_wdc_shipment_places`, `_wdc_cdek_shipment_places`, `_wdc_prepared_shipment_places` и аналогичных item-row meta удалены: существующий modal workflow их не пишет, поэтому они не являются каноническим источником. Начальный draft из заказа остаётся одногрузоместным, как у CDEK, и нужен для открытия модалки; многоместное распределение становится каноническим после submit общей shipment modal.

Yandex parser allocation rows больше не исправляет повреждённые значения. `amount=0`, отсутствующий `place_number`, пустой `item_key` или нулевой `weight` проходят в rows как есть и отклоняются существующим `CdekShipmentAllocationAdapter` / `ShipmentAllocation` validator до HTTP. Отдельный validator не добавлялся.

Cancellation lifecycle теперь использует общий helper terminal statuses из `YandexShipmentButtonPolicy`. Если shipment находится в `cancellation_started`, а `request/info` возвращает `CANCELLED`, сохраняется прежняя семантика успешной отмены. Если возвращается другой terminal status (`DELIVERED`, `RETURNED`, `RETURNED_TO_SENDER`, `REJECTED`), active cancel ожидание завершается, `yandex_cancel_requested` сбрасывается, повторный cancel скрывается через существующую button policy, но note не говорит «отправление отменено».

## Статус 0.108.1

Этот patch закрывает lifecycle/persistence-блокеры внутри уже существующего Shipment Framework. Новая архитектура, metabox, modal, AJAX flow, retry policy и `request/create` не добавлялись.

Если `offers/confirm` уже вернул `request_id`, но обязательный `request/info` упал, framework больше не теряет связь с реальным заказом Яндекса. `YandexShipmentRegistrationService` возвращает failed `ShipmentCreateResult` с `raw_reference['yandex_reconciliation']`, а `ShipmentCreationService` сохраняет pending shipment со статусом `reconciliation_required`, `yandex_request_id`, lookup meta `_wdc_yandex_delivery_request_id`, selected offer id/expires_at и безопасными diagnostics. Повторный create блокируется существующим duplicate guard; recovery выполняется только через кнопку обновления статуса, которая снова вызывает `request/info` и переводит запись в canonical created state.

Отмена Яндекса теперь обрабатывается как асинхронная операция. Ответ `request/cancel` с `status=CREATED` и `reason=cancellation_started` сохраняется сразу как `yandex_cancel_state`, local `status=cancellation_started` и `yandex_cancel_requested=true`. Пока `request/info` не подтвердил terminal `CANCELLED`, повторная отмена и локальное удаление скрыты, а доступно только обновление статуса. Если follow-up `request/info` после cancel временно падает, принятая отмена остаётся сохранённой и не повторяется автоматически.

Исходный Yandex draft теперь сначала переиспользует существующее подготовленное распределение shipment places/item rows, включая shared/CDEK meta/calculation rows. Это сохраняет dimensions/weight мест, `item_key` как identity order item, `place_number` и quantity внутри каждого места; split quantity между местами не агрегируется обратно. Только при отсутствии такого allocation используется fallback с одним местом и всеми товарами.

Lookup meta `_wdc_yandex_delivery_request_id` синхронизируется при успешном create, reconciliation pending, status/cancel update и удаляется при local remove. `yandex_offer_expires_at` сохраняется как audit/persistence field и не используется для retry/повторного confirm. Для API lifecycle `request_id` берётся только из `yandex_request_id`, `request_id`, затем `external_id`; courier `tracking_number`/`courier_order_id` не используются в `request/info` или `request/cancel`.

## Статус 0.108.0

Яндекс.Доставка теперь встроена в существующий Shipment Framework как carrier `yandex_delivery`. Новая архитектура регистрации не создавалась: используется тот же путь, что у современной DPD-модели — `CarrierShipmentAdapterRegistry` -> `ShipmentCreationService` -> carrier adapter -> carrier registration service -> `OrderShipmentRepository` -> общий metabox/buttons lifecycle.

Carrier-specific слой:

- `YandexShipmentAdapter` реализует существующий `CarrierShipmentAdapterInterface`;
- `YandexShipmentRegistrationService` является bridge над уже готовым HTTP flow `YandexDeliveryShipmentRegistrationService`;
- `YandexShipmentRepository` сохраняет данные через общий `OrderShipmentRepository`;
- `YandexShipmentButtonPolicy` управляет кнопками create/status/cancel/remove без проверок в metabox.

Создание отправления идёт так: общий `OrderShipmentDraftFactory` строит `ShipmentCreateRequest` для `yandex_delivery`, adapter переиспользует существующий `ShipmentAllocation` через `CdekShipmentAllocationAdapter`, Yandex payload builder строит offers/create payload, HTTP service выполняет `offers/create` -> earliest offer -> `offers/confirm` -> обязательный `request/info`. Каноническое состояние shipment — именно `request/info`, а не исходный payload.

После успешной регистрации в `_wdc_shipments[yandex_delivery]` сохраняются `request_id`, `courier_order_id`, `sharing_url`, статус Яндекса, `operator_request_id`, `selected_offer_id`, `delivery_policy`, snapshots destination/recipient/items/places, temporary-to-real barcode map and full request/info snapshot. Body `offers/create` не сохраняется намеренно.

Повторная регистрация запрещается существующим `OrderShipmentRepository::has_created_for_carrier()` через общий `ShipmentCreationService`. Кнопка статуса вызывает `request/info` и обновляет canonical state. Кнопка отмены вызывает `request/cancel`, затем mandatory `request/info`, и сохраняет both async cancel response and final canonical info. History доступна через Yandex registration service over `request/history`; отдельный новый UI не добавлялся.

## Статус 0.107.1

Этап 0.107.1 выравнивает HTTP/DTO слой с production-схемой, проверенной реальными PowerShell запросами. `offers/create` вызывается как `POST /api/b2b/platform/offers/create?send_unix=false`, чтобы API возвращал строковые UTC интервалы. `request/info` и `request/history` отправляются как GET query requests без JSON body: `request/info?request_id=...&slim=false` и `request/history?request_id=...`.

Offer DTO читает production shape `offer_details`: `delivery_interval.policy`, `delivery_interval.min/max`, `pickup_interval.min/max`, `pricing_total`, `pricing`, `features`, а также top-level `expires_at` и `station_id`. Повреждённые offers без `offer_id`, policy или delivery interval dates не попадают в публичную collection.

`RequestInfo` читает `request_id`, `courier_order_id`, `sharing_url` и `state` с верхнего уровня ответа, а `destination`, `recipient_info`, `items`, `places`, `available_actions` и `delivery_policy` из nested `request`. `RequestHistory` читает primary поле `state_history`. `request/cancel` сохраняет async response `status=CREATED`, `description=Заказ отменяется`, `reason=cancellation_started`; это не трактуется как финальный lifecycle status.

Защиты перед будущим persistence: `request_info()` отклоняет пустой или чужой `request_id`, отсутствие `request`, отсутствие `state.status`, mismatch количества temporary/real places и ненадёжную barcode map. Если `request/info` падает после успешного confirm, registration service перевыбрасывает `YandexDeliveryApiException` с `error_code=request_info_after_confirm_failed` и `confirmed_request_id`; следующий этап должен делать reconciliation через `request/info`, а не повторять confirm.

## Статус 0.107.0

Этап 0.107.0 добавляет чистый HTTP layer регистрации отправлений Яндекс.Доставки. Подключения к WordPress UI, checkout, order save, tracking, labels и persistence нет. Используется существующий `YandexDeliveryApiClient` и его WordPress transport abstraction `YandexDeliveryHttpClientInterface` / `WpYandexDeliveryHttpClient`; `curl` и второй HTTP abstraction не добавлялись.

Реализованные HTTP методы: `offers/create`, `offers/confirm`, `request/info`, `request/history`, `request/cancel`. `request/create` не реализован и не используется. `YandexDeliveryShipmentClient` возвращает DTO, а не raw arrays: offers, selected/confirmed request, canonical request info, history and shipment state. `YandexDeliveryApiException` сохраняет HTTP code, sanitized error body and decoded response.

`YandexDeliveryShipmentRegistrationService` выполняет production flow: payload builder -> `offers/create` -> earliest offer selector -> `offers/confirm` -> mandatory `request/info`. Канонический результат регистрации — `RequestInfo`; он содержит `request_id`, `courier_order_id`, `sharing_url`, status, destination, recipient, items, places and a temporary-to-real place barcode map. Retry не реализован: если confirm завершился transport/API exception, сервис не повторяет confirm и не продолжает request/info.

## Статус 0.106.2

Этап 0.106.2 закрывает validation-блокеры перед реальным HTTP-flow, не добавляя HTTP/API/UI/persistence. Registration allocation не может быть пустым: `ShipmentAllocation` должен содержать хотя бы один item суммарно, а каждое `ShipmentAllocationPlace` должно содержать хотя бы один `ShipmentAllocationItem`. `CdekShipmentAllocationAdapter` отклоняет пустые `cdek_item_rows` и ситуацию, когда одно из мест не имеет allocation rows.

Yandex destination теперь строгий: `destination.mode` принимает только `pickup` или `courier`. Для pickup обязателен непустой `platform_station_id`. Для courier обязательны структурированные `details.locality`, `details.street`, `details.house` и `details.full_address`; `country`, `region`, `room`, `postal_code`, `geoId` и `geo_id` остаются необязательными scalar-полями. Координаты не требуются и не добавляются искусственно.

Recipient validation обязательна до построения payload: итоговый `first_name` (`Фамилия Имя`) не может быть пустым, телефон должен нормализоваться в `+7XXXXXXXXXX`, email может быть пустым, но если задан — должен пройти `FILTER_VALIDATE_EMAIL`. Ready interval проверяется по timestamp: `ready_to` может быть равен `ready_from`, но не может быть раньше. Формат UTC остаётся `Y-m-d\TH:i:s.u\Z`.

## Статус 0.106.1

Этап 0.106.1 готовит foundation к реальному HTTP-flow без добавления HTTP. `items[]` приведены к подтверждённой production-структуре: цены и НДС передаются только внутри `billing_details`, а не на верхнем уровне товарной строки. `ShipmentAllocationItem` хранит две нейтральные цены: `unit_price_kopecks` и `assessed_unit_price_kopecks`, потому что для Яндекса обычная цена и оценочная стоимость могут различаться; текущий CDEK adapter заполняет их одинаковым значением из существующего `cost`, не меняя CDEK payload.

`CdekShipmentAllocationAdapter` больше не исправляет повреждённые данные молча. Строка с неизвестным `place_number`, пустым `item_key`, `amount <= 0`, невалидным весом или отрицательной стоимостью приводит к `InvalidArgumentException`. Yandex builder также вызывает `ShipmentAllocation::validate()` перед построением payload.

## Статус 0.106.0

Этот этап добавляет только чистое построение payload. HTTP, offers/create, offers/confirm, request/info, хранение meta, статусы, labels, UI и автоматическая регистрация не реализованы.

## Проверенный production flow

Для реальной регистрации используется `offers/create` → выбор самого раннего подходящего offer → `offers/confirm` → обязательный `request/info`. `request/create` не является основным путём. После confirm Яндекс заменяет временные barcodes своими, поэтому следующий этап обязан сохранить `request_id`, `courier_order_id`, `sharing_url`, статус, history, интервалы, normalised destination и итоговые barcodes мест и товарных строк.

Подтверждённые правила: pickup — `destination.type=platform_station`, `platform_id`, `last_mile_policy=self_pickup`; courier — `custom_location.details` и `last_mile_policy=time_interval`; `forbid_unboxing=true`; оплаченный заказ — `already_paid` и нулевая delivery cost; `nds=-1`. В кабинете отображается только `recipient_info.first_name`, поэтому туда передаётся `Фамилия Имя`, а `last_name` и `patronymic` пусты.

## Allocation

Фактическое распределение ранее существовало только в CDEK: `ShipmentCreateRequest::places` содержит характеристики `ShipmentPlace`, а CDEK editor хранит строки в `meta['cdek_item_rows']` с `item_key`, `place_number` и `amount`. `item_key` — identity исходной order item; SKU (`ware_key`) лишь дополнительный атрибут. Разделение quantity представлено несколькими строками с тем же `item_key` и разными `place_number`.

Нейтральный read-model `Shipments/Allocation` и `CdekShipmentAllocationAdapter` адаптируют эти уже существующие данные без перерасчёта packing. CDEK request builder и payload не менялись. Новый слой не содержит carrier-полей; он хранит place dimensions/weight и source item identity, quantity, name, SKU, unit price, assessed unit price и item weight.

## Yandex payload

`YandexDeliveryShipmentPayloadBuilder` принимает готовый allocation и рассчитанный интервал, форматирует его в UTC как `yyyy-MM-ddTHH:mm:ss.ffffffZ` и создаёт полный pure offers/create payload. Временный barcode равен `{operator_request_id}-{place_number}`: он детерминирован внутри запроса и одновременно используется в `places[].barcode` и `items[].place_barcode`.

Yandex item row:

- `name`
- `article`
- `count`
- `billing_details.inn`
- `billing_details.nds`
- `billing_details.unit_price`
- `billing_details.assessed_unit_price`
- `place_barcode`
- `refused_count`
- `fitting`

Строки одинаковой source item могут агрегироваться только в пределах одного barcode. Между местами они остаются отдельными строками. В item не передаются выдуманные physical dimensions; dimensions передаются только в `places[]`. Следующий этап добавит transport flow и pure selector: фильтр policy, затем `delivery_interval.min`, `max`, `pickup_interval.max`, `pricing_total`, `offer_id`.
