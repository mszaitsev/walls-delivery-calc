# Регистрация отправлений Яндекс.Доставки

## Статус 0.106.0

Этот этап добавляет только чистое построение payload. HTTP, offers/create, offers/confirm, request/info, хранение meta, статусы, labels, UI и автоматическая регистрация не реализованы.

## Проверенный production flow

Для реальной регистрации используется `offers/create` → выбор самого раннего подходящего offer → `offers/confirm` → обязательный `request/info`. `request/create` не является основным путём. После confirm Яндекс заменяет временные barcodes своими, поэтому следующий этап обязан сохранить `request_id`, `courier_order_id`, `sharing_url`, статус, history, интервалы, normalised destination и итоговые barcodes мест и товарных строк.

Подтверждённые правила: pickup — `destination.type=platform_station`, `platform_id`, `last_mile_policy=self_pickup`; courier — `custom_location.details` и `last_mile_policy=time_interval`; `forbid_unboxing=true`; оплаченный заказ — `already_paid` и нулевая delivery cost; `nds=-1`. В кабинете отображается только `recipient_info.first_name`, поэтому туда передаётся `Фамилия Имя`, а `last_name` и `patronymic` пусты.

## Allocation

Фактическое распределение ранее существовало только в CDEK: `ShipmentCreateRequest::places` содержит характеристики `ShipmentPlace`, а CDEK editor хранит строки в `meta['cdek_item_rows']` с `item_key`, `place_number` и `amount`. `item_key` — identity исходной order item; SKU (`ware_key`) лишь дополнительный атрибут. Разделение quantity представлено несколькими строками с тем же `item_key` и разными `place_number`.

Нейтральный read-model `Shipments/Allocation` и `CdekShipmentAllocationAdapter` адаптируют эти уже существующие данные без перерасчёта packing. CDEK request builder и payload не менялись. Новый слой не содержит carrier-полей; он хранит place dimensions/weight и source item identity, quantity, name, SKU, price и weight.

## Yandex payload

`YandexDeliveryShipmentPayloadBuilder` принимает готовый allocation и рассчитанный интервал, форматирует его в UTC и создаёт полный pure offers/create payload. Временный barcode равен `{operator_request_id}-{place_number}`: он детерминирован внутри запроса и одновременно используется в `places[].barcode` и `items[].place_barcode`.

Строки одинаковой source item могут агрегироваться только в пределах одного barcode. Между местами они остаются отдельными строками. В item не передаются выдуманные physical dimensions; dimensions передаются только в `places[]`. Следующий этап добавит transport flow и pure selector: фильтр policy, затем `delivery_interval.min`, `max`, `pickup_interval.max`, `pricing_total`, `offer_id`.
