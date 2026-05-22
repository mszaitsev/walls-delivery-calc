# WDC DaData Suggestions 0.14.12

DaData используется только для визуальных подсказок адреса в checkout. Постфактум-нормализация адреса через DaData удалена из runtime pipeline.

Цепочка нормализации checkout остается:

`local city context -> FIAS placeholder -> manual fallback`

## Токены И Лимиты

В разделе `Подсказки адреса DaData` используется список токенов в option `dadata_suggestions_tokens`.

Каждая строка токена хранит `id`, encrypted token, masked token, `daily_limit`, `enabled`, `label`, `created_at`, `updated_at`.

Plaintext-токен не сохраняется, не показывается в админке и не локализуется во frontend config. Пустое поле токена при сохранении сохраняет уже зашифрованное значение без изменений.

Timeout и количество подсказок остаются общими настройками для всего раздела DaData.

## Учет Запросов

Счетчик `usage_today` увеличивается ровно один раз на каждую фактическую HTTP-попытку `wp_remote_post` к DaData:

- `stage=address`
- `stage=resolve`
- `stage=city`, если используется
- retry при переключении на следующий токен

Если токен уже исчерпан и запрос через него не отправлялся, счетчик не увеличивается.

Если запрос был отправлен и получил timeout, HTTP error, `400`, `403`, `429` или другой ответ, счетчик увеличивается, потому что HTTP-попытка фактически была сделана.

Ключ счетчика:

`wdc_dadata_suggestions_usage_{token_id}_{Ymd}`

Если DaData возвращает quota/limit error, токен помечается отдельным дневным флагом как исчерпанный, но `usage_today` не подменяется значением лимита. Это нужно, чтобы число в админке отражало реальные HTTP-попытки.

В таблице токенов также показывается последний request audit:

- stage
- время попытки
- HTTP status
- error code

Ключ audit:

`wdc_dadata_suggestions_last_request_{token_id}_{Ymd}`

Возможные причины расхождения с кабинетом DaData:

- кабинет DaData обновляет статистику с задержкой;
- тот же токен используется где-то еще;
- `resolve` после выбора дома считается отдельным запросом;
- retry на другой токен добавляет отдельную HTTP-попытку;
- city-stage requests тоже считаются, если включены в сценарии.

## Выбор Токена

`DaDataTokenPool` выбирает первый включенный токен, у которого есть остаток на сегодня и который не помечен исчерпанным.

`DaDataSuggestionClient` отправляет запрос с выбранным токеном:

`Authorization: Token <token>`

Если DaData возвращает ошибку лимита или квоты, текущий токен помечается исчерпанным на сегодня, и клиент повторяет запрос со следующим доступным токеном. Количество попыток ограничено числом активных токенов.

Если токенов нет, AJAX возвращает `no_available_dadata_token`.

Если все токены исчерпаны, AJAX возвращает `dadata_daily_limit_exhausted`.

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

## Поиск Улицы И Дома

Frontend больше не переводит picker в отдельный restricted mode после выбора улицы.

Поиск адреса всегда идет через `stage=address` по полной строке, которую видит пользователь в modal search input. `house_after_street` остается на backend на будущее, но frontend не вызывает его автоматически.

Если выбрана улица:

- `address_1` получает `street_with_type + " "`;
- status становится `street_selected`;
- street FIAS/KLADR hidden fields сохраняются;
- search input получает полный `unrestrictedValue` или `value` выбранной улицы с завершающей `, `;
- модалка остается открытой;
- hint показывает `Уточните номер дома`;
- следующий поиск снова идет через `stage=address`.

Если пользователь стирает строку и вводит другую улицу, старый `street_fias_id` не влияет на поиск.

## DaData Request

Server-side proxy использует DaData Suggest API:

`https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`

`stage=address` отправляет:

- `query`
- `count`
- `locations: [{ country_iso_code: "RU" }]`
- `from_bound: street`
- `to_bound: house`

`locations_boost` не отправляется. Приоритет по городу не используется, потому что регион и город уже входят в полную query string.

`stage=resolve` уточняет финальный выбранный адрес с `count: 1`.

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

- search value записывается в `address_1`;
- status становится `manual`;
- выбранный город и область не меняются;
- address-specific DaData ids очищаются;
- checkout не блокируется.

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
