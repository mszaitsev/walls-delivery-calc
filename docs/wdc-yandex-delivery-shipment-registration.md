# Регистрация отправлений Яндекс.Доставки

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
