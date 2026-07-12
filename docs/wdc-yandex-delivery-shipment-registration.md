# Регистрация отправлений Яндекс.Доставки

## Статус 0.108.11

Local remove теперь защищён не только кнопками, но и backend-ом. `YandexShipmentRegistrationService::remove_local()` перед удалением получает сохранённый shipment и вызывает `YandexShipmentButtonPolicy::resolve()`. Если policy возвращает `remove=false`, repository не удаляется, lookup meta остаётся, а AJAX получает русскую ошибку `Текущее отправление Яндекс нельзя удалить из заказа.` Это блокирует ручной AJAX remove для активного `CREATED` и для `cancellation_started`; `reconciliation_required` и terminal статусы (`CANCELLED`, `DELIVERED`, `RETURNED`, `RETURNED_TO_SENDER`, `REJECTED`) удаляются локально по той же policy.

Polling после accepted create теперь различает pending-ответы Яндекса и транспортные ошибки. Pending JSON (`pending=true`) по-прежнему обновляет status message и идёт на следующую попытку без toast-spam. HTTP/network/JSON ошибка внутри bounded generic/Yandex polling больше не возвращается как успешный `null`: ошибка пробрасывается в polling helper, считается попыткой и планирует следующий tick до canonical status, terminal/error response или exhaustion. После 14 pending/transport-error попыток запускается тот же backend mark-exhausted flow. DPD `mode=dpd` сохраняет прежнее поведение остановки после ошибки, CDEK polling не менялся.

## Статус 0.108.10

Pending reconciliation теперь управляется серверной button policy. Любая сохранённая запись Яндекс со статусом `reconciliation_required` и `request_id` сразу после перезагрузки страницы показывает `Обновить статус` и `Удалить из заказа`, а `Создать`, manual attach и cancel остаются скрытыми. Это сделано намеренно: реальное отправление уже могло быть создано в Яндексе, но менеджер должен иметь выход из локальной pending-связи и затем при необходимости прикрепить `request_id` вручную. `cancellation_started` отделён от reconciliation и по умолчанию не разрешает local remove, чтобы не потерять связь с активным запросом отмены.

После 14 polling-попыток JS больше не оставляет exhausted только в DOM. Общий AJAX `wdc_mark_shipment_poll_exhausted` вызывает Yandex adapter hook и сохраняет `yandex_reconciliation_poll_exhausted=true`, `yandex_reconciliation_attempts=14`, `yandex_reconciliation_poll_exhausted_at`, `status=reconciliation_required` и `status_title=Статус пока не получен. Повторите обновление позднее.` Request ID, `_wdc_yandex_delivery_request_id`, diagnostics, temporary place data и selected offer audit не очищаются. Если позже `request/info` всё ещё неполный, exhausted-флаги сохраняются и remove остаётся доступным; если приходит canonical status (`CREATED` или другой валидный статус), exhausted-флаги очищаются, reconciliation завершается и кнопки пересчитываются обычной policy.

Polling attempts после обычной перезагрузки не восстанавливаются и не стартуют автоматически. Metabox просто показывает persisted pending state с update/remove. При local remove используется предупреждение `Удаление уберёт запись только из заказа WooCommerce и не отменит отправление в Яндекс.Доставке. Продолжить?`; операция не вызывает `request/cancel`, `request/info`, `offers/create` или `offers/confirm`. Runtime polling подавляет повторяющиеся pending-toast, сохраняет timeout через backend и останавливает/инвалидирует timer перед local remove, чтобы поздний status response не восстановил удалённую запись визуально.

## Статус 0.108.9

Реальный тест подтвердил eventual consistency после `offers/confirm`: отправление уже создано в кабинете Яндекс.Доставки, но немедленный `request/info` может временно не содержать `state.status`. Такой ответ теперь не считается провалом create. Framework сохраняет pending shipment с `status=reconciliation_required`, подтверждённым `request_id`, lookup meta `_wdc_yandex_delivery_request_id`, safe diagnostics и selected offer audit, а AJAX create возвращает success/accepted с сообщением `Отправление создано в Яндекс.Доставке. Ожидается получение статуса.`

После accepted create модалка закрывается, блок `Отправления` обновляется, и общий JS запускает bounded registration polling через существующий status AJAX endpoint. Для Яндекса polling настроен на 5 секунд × 14 попыток. Каждый tick вызывает только `request/info`; `offers/create`, `offers/confirm` и `request/create` не повторяются. Как только `request/info` возвращает canonical response с непустым `state.status`, reconciliation flags очищаются, сохраняются canonical request/info snapshot, destination/recipient/items/places/barcodes, `courier_order_id`, `sharing_url`, а статус отображается без дублирования: `Статус Яндекс.Доставки: CREATED`.

Если все 14 попыток остаются pending, UI показывает `Статус отправления пока не получен. Повторите обновление статуса позднее.`, оставляет доступной кнопку `Обновить статус` и локально показывает `Удалить из заказа`. Перед local remove Яндекс показывает предупреждение: `Удаление уберёт запись только из заказа WooCommerce и не отменит отправление в Яндекс.Доставке. Продолжить?` Удаление остаётся только локальным и не вызывает `request/cancel`.

Selected offer сохраняется как audit до reconciliation и после status update: `yandex_selected_offer_id`, `yandex_offer_expires_at`, `yandex_offer_pricing`, `yandex_offer_pricing_total`, `yandex_offer_pricing_total_kopecks`, `yandex_offer_delivery_interval`, `yandex_offer_pickup_interval`, `yandex_selected_offer_snapshot`. `source.interval_utc` в payload остаётся заявленной готовностью заказа; `pickup_interval` выбранного offer — фактическое окно сдачи на исходную станцию, а `delivery_interval` — окно доставки получателю.

## Статус 0.108.8

Перед первым реальным тестом регистрации Яндекс.Доставки общий shipment modal больше не подставляет рассчитанные weight/dimensions в редактируемые поля грузоместа ни для одной службы доставки. `OrderShipmentsMetabox` очищает только initial UI values `weight_g`, `length_cm`, `width_cm`, `height_cm`, но сохраняет все draft places, place numbers, товары и `shipment_items[]` в распределении. При submit `places[]` из формы являются единственным источником фактических размеров: пустые значения не заменяются calculated defaults, проходят в strict validation как invalid и возвращаются менеджеру русскими сообщениями `Укажите вес/длину/ширину/высоту грузоместа.`

Расчётный вес теперь показывается только compact-подсказкой `⚖️{weight}`: для одного исходного грузоместа рядом с полем веса, для нескольких мест — как общий hint над списком. Эта подсказка не является `value`, не попадает в `FormData`, не разблокирует preview/create и не распределяется автоматически по нескольким коробкам. Расчётные dimensions не показываются как подсказки: менеджер вводит фактические внешние габариты упаковки вручную.

Ручное прикрепление Яндекс использует существующий generic manual attach UI. Если локального Yandex shipment ещё нет, button policy показывает `Ввести номер Яндекс вручную`; поле подписано `Request ID Яндекс`, placeholder равен `***-udp`, а input value при открытии пустой. Введённое поле интерпретируется как Yandex `request_id`. Adapter не вызывает `offers/create`, `offers/confirm` или `request/create`: выполняется один `request/info?request_id=...&slim=false`, затем проверяется совпадение `request.info.operator_request_id` с номером WooCommerce заказа. При mismatch или отсутствии operator id shipment не сохраняется.

Успешный manual attach сохраняет canonical request/info state через `YandexShipmentPersistenceMapper` и `YandexShipmentRepository`: `request_id`/`yandex_request_id`, `courier_order_id`, `sharing_url`, `yandex_status`, `operator_request_id`, delivery policy, destination/recipient/items/places snapshots, request/info snapshot, generic local status, `created_by_context=admin_manual_attach` and lookup meta `_wdc_yandex_delivery_request_id`. После attach повторный create/manual attach блокируются существующей button policy; status/cancel/remove определяются по сохранённому API status.

## Статус 0.108.6

Перед первым реальным созданием отправления добавлен обязательный UI-gate: Яндекс использует carrier-neutral capability `requires_successful_preview`, поэтому кнопка `Создать отправление` остаётся disabled, пока preview не загрузился успешно и не вернул ошибок. Это реализовано в общей shipment modal без Yandex-specific JS-проверки.

`ajax_create()` теперь защищён тем же JSON contract, что и preview: validation errors возвращают JSON с понятным русским сообщением, unexpected throwable логируется и возвращает controlled JSON error, HTML critical-error page не должен попасть в response. JSON shape успешного preview/create и HTTP layer Яндекса не менялись.

Все пользовательские fallback-сообщения shipment modal/runtime приведены к русскому языку. Технические validation strings из neutral allocation остаются внутри validators/tests, но на AJAX boundary переводятся в публичные сообщения вроде `Укажите вес грузоместа.` или `Каждое грузоместо должно содержать хотя бы один товар.` API status codes Яндекса не переводятся.

## Статус 0.108.5

Модалка регистрации Яндекс.Доставки теперь открывается через существующий общий shipment modal с заполненным carrier-specific draft. `draft_array()` отдаёт ровно один service variant, соответствующий сохранённому типу доставки заказа: `Яндекс до ПВЗ` для pickup или `Яндекс до двери` для courier. Переключение между сценариями в модалке пока не вводится.

У Яндекса нет заранее выбранного tariff object: registration flow остаётся `payload preview -> offers/create -> earliest offer -> offers/confirm -> request/info`. Поэтому общий tariff select и сообщение об отсутствии включённых тарифов скрыты, а модалка показывает пояснение об автоматическом выборе самого раннего оффера. Поле `postoffice_code` Почты России для Яндекса не отображается и не требуется.

Yandex-specific presentation в общей модалке:

- read-only исходная `yandex_source_platform_station_id` из настроек;
- для pickup — snapshot конечного ПВЗ (`pickup_point_code`, `yandex_pickup_platform_station_id`, адрес/ID);
- для courier — структурированные адресные поля `yandex_postal_code`, `yandex_region`, `yandex_locality`, `yandex_street`, `yandex_house`, `yandex_room` и `courier_original_address`;
- `yandex_ready_from` / `yandex_ready_to` как сохранённые hidden ISO/offset values;
- все initial places из draft с пустыми editable factual weight/dimensions и сохранённым распределением товаров.

Preview остаётся dry-run/local: он строит `ShipmentCreateRequest`, allocation и Yandex offers/create payload без HTTP-вызовов. AJAX preview теперь возвращает controlled JSON errors для отсутствующей исходной станции, отсутствующего pickup destination, невалидных places/allocation и unexpected throwable; HTML critical-error body не показывается администратору. JS использует controlled parser для preview response и показывает понятное сообщение вместо `Unexpected token`.

## Статус 0.108.4

Этот patch закрывает последние metabox/runtime замечания перед реальным тестом регистрации. Общий shipment metabox теперь использует capability payload carrier adapter как основной источник видимости кнопок: `has_shipment`, `can_create`, `can_attach_manual`, `can_update_status`, `can_cancel`, `can_remove_from_order`. Legacy fallback остался только для путей, где конкретный capability key отсутствует.

Для Яндекса это важно в промежуточных состояниях: `reconciliation_required` и `cancellation_started` считаются существующим shipment, поэтому повторное создание скрыто, доступно только обновление статуса, а cancel/remove/manual attach недоступны. Терминальный `CANCELLED` скрывает cancel и разрешает remove по существующей adapter policy. Отдельные Яндекс-кнопки, разметка или carrier-specific UI не добавлялись.

`ShipmentModalRequestMapper` теперь округляет дробные габариты грузомест вверх до целого сантиметра (`19.9` -> `20`, `19,1` -> `20`), чтобы carrier payload не занижал размеры. Вес остаётся строгим целым значением в граммах; decimal/invalid weight не исправляется и блокируется существующей validation. `CdekShipmentAllocationAdapter` в этом этапе не менялся.

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

Защиты перед persistence: `request_info()` отклоняет пустой или чужой `request_id`, отсутствие `request`, отсутствие `state.status`, mismatch количества temporary/real places и ненадёжную barcode map. Если `request/info` ещё не готов после успешного confirm, lower-level client по-прежнему отдаёт deterministic `YandexDeliveryApiException`, но framework stage 0.108.9 переводит temporary `request_info_status_missing` / `request_info_request_missing` в accepted reconciliation и продолжает через последующие `request/info`, не повторяя confirm.

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
