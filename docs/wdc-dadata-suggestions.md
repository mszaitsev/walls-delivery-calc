# WDC DaData Suggestions 0.14.11

DaData используется только для визуальных подсказок адреса в checkout. Постфактум-нормализация адреса через DaData удалена из runtime pipeline.

Цепочка нормализации checkout остается:

`local city context -> FIAS placeholder -> manual fallback`

## Токены

В разделе `Подсказки адреса DaData` больше нет одиночного API-ключа. Используется список токенов в option `dadata_suggestions_tokens`.

Каждая строка токена хранит:

- `id`
- encrypted token
- masked token
- `daily_limit`
- `enabled`
- `label`
- `created_at`
- `updated_at`

Plaintext-токен не сохраняется, не показывается в админке и не локализуется во frontend config. После сохранения видна только masked-версия. Пустое поле токена при сохранении сохраняет уже зашифрованное значение без изменений.

Общие настройки раздела:

- включить подсказки DaData
- timeout подсказок DaData
- количество подсказок DaData

Timeout и количество подсказок общие для всех токенов.

## Суточные лимиты

Для каждого токена задается суточный лимит запросов. Использование считается по ключу:

`wdc_dadata_suggestions_usage_{token_id}_{Ymd}`

Дата берется из WordPress date/time API, если оно доступно. Счетчик хранится до конца текущего дня с небольшим буфером.

Запрос засчитывается только когда HTTP request фактически отправлен в DaData. Если токен уже достиг лимита, он пропускается и запрос через него не выполняется.

## Выбор Токена

`DaDataTokenPool` выбирает первый включенный токен, у которого есть остаток на сегодня. `DaDataSuggestionClient` отправляет запрос с выбранным токеном:

`Authorization: Token <token>`

Если DaData возвращает ошибку лимита или квоты, текущий токен считается исчерпанным на сегодня, и клиент повторяет запрос со следующим доступным токеном. Количество попыток ограничено числом активных токенов.

Если токенов нет, AJAX возвращает:

`no_available_dadata_token`

Если все токены исчерпаны:

`dadata_daily_limit_exhausted`

В обоих случаях checkout не ломается, а modal address picker предлагает ручной ввод.

## Address Picker UX

Покупатель фокусируется или кликает активное WooCommerce поле `address_1`. WDC открывает собственную модалку выбора адреса. Поиск выполняется только внутри поля поиска модалки.

Inline-autocomplete прямо внутри WooCommerce поля не используется.

При открытии модалки search input заполняется из видимых checkout fields:

`region/state, city, address_1`

Примеры:

- `Новосибирская область, Новосибирск, Некрасова`
- `Новосибирская область, Новосибирск, `

Hidden `wdc_platform_location_display_name`, selected notice и прошлые DaData suggestions не участвуют в opening query.

## DaData Request

Server-side proxy использует DaData Suggest API:

`https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`

`stage=address` отправляет полную строку поиска:

- `query`
- `count`
- `locations: [{ country_iso_code: "RU" }]`
- `from_bound: street`
- `to_bound: house`

`locations_boost` не отправляется. Приоритет по городу не используется, потому что регион и город уже входят в полную query string.

`stage=house_after_street` может ограничивать поиск выбранной `street_fias_id`, потому что пользователь явно выбрал улицу.

`stage=resolve` уточняет финальный выбранный адрес с `count: 1`.

## Street To House

Если выбрана улица:

- `address_1` получает `street_with_type + " "`
- status становится `street_selected`
- street FIAS/KLADR hidden fields сохраняются
- модалка остается открытой
- следующий поиск идет через `stage=house_after_street`

Кнопка `Изменить улицу` или полная очистка search input сбрасывает выбранную улицу и возвращает поиск в `stage=address`.

## Финальный Адрес

Финальным считаются house, flat и FIAS levels `8`, `9`, `75`.

После выбора WDC делает `resolve` и применяет результат:

- если регион/город совпадают с локальным выбором по FIAS id, city/region не меняются, а `address_1` получает только улицу и дом;
- если регион/город отличаются, но сопоставлены локально, city/region обновляются из DaData, а `address_1` получает только улицу и дом;
- если локального сопоставления нет, city/region обновляются из DaData, а `address_1` получает полный адрес без страны.

DaData `postal_code` считается более точным и всегда перезаписывает checkout postcode, если присутствует в resolved address.

## Manual Fallback

Если подсказки не вернули результат, токены недоступны или лимиты исчерпаны, модалка показывает:

`Подсказки адреса временно недоступны. Введите адрес вручную.`

Покупатель может нажать `Использовать введенный адрес`. В этом режиме:

- search value записывается в `address_1`
- status становится `manual`
- выбранный город и область не меняются
- address-specific DaData ids очищаются
- checkout не блокируется

## Hidden Fields And Order Meta

Resolved address selection заполняет `{billing|shipping}_dadata_*` hidden fields:

- status
- unrestricted value
- region/city/settlement/street/house/flat data
- FIAS/KLADR ids
- FIAS level

WDC-compatible location hidden fields обновляются, если они есть:

- `wdc_platform_location_fias_id`
- `wdc_platform_location_gar_id`
- `wdc_platform_location_display_name`
- `wdc_platform_location_region_name`
- `wdc_platform_location_postcode`

Order persistence сохраняет DaData hidden fields и совместимые WDC meta.
