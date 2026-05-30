# WDC DaData Suggestions 0.26.0

## Pickup Address Search 0.26.0

The Russian Post pickup map uses the existing DaData suggestion stack for address-to-coordinate lookup. `PickupAddressSearchService` depends on `AddressSuggestionClientInterface`; in production that resolves to `DaDataSuggestionClient`, backed by the shared `DaDataTokenPool`.

Non-cached address searches therefore use the same daily token pool as checkout address suggestions:

- `DaDataTokenPool::next_available_token()` selects an enabled token with remaining daily quota;
- `DaDataSuggestionClient` increments usage after every actual HTTP attempt;
- quota/limit responses mark the current token exhausted and rotate to the next one;
- when all tokens are exhausted, the endpoint returns `address_search_available=false`.

The pickup endpoint caches successful address results for 24 hours under a key derived from normalized `query`, `location_id`, and `country_code`. A cache hit returns the resolved address and nearby points without making a DaData request or increasing usage counters.

If the query is exactly six digits, pickup search bypasses DaData entirely. It first searches local Russian Post pickup points by postcode, then falls back to a local location row with the same postal code and saved coordinates. This postcode fallback is intentionally independent from DaData so it works even after the daily suggestion limits are exhausted.

When the checkout has a selected local settlement, its `location_id` / country context is sent to the endpoint. The DaData request adds the corresponding FIAS/KLADR location filter and `restrict_value` where possible, so a query like "Ленина 15" is resolved inside the current city instead of across all Russia.

## Coordinate Batch Limits And Reset 0.25.14

The admin coordinate batch now treats `dadata_daily_limit_exhausted` from the shared DaData suggestion client as a terminal stop, not as `skipped_no_dadata_success`. When all configured DaData tokens are exhausted for the current day, the job stops immediately, keeps already processed progress, and writes `phase/status=finished`, `stopped_reason=daily_limit_exhausted`, `tokens_exhausted=true`, and the readable message "Суточные лимиты DaData исчерпаны. Повторите запуск позже." A later `step` poll does not resume the batch; the operator must start it again manually after limits are available.

The locations settings DaData block includes "Обнулить задачу координат". This AJAX action requires the same nonce and capability checks as the other DaData actions, refuses to reset a running coordinate job, and deletes only the coordinate batch progress option. It does not remove saved `latitude` / `longitude` values and does not touch the postcode/index DaData job. After reset, the next coordinate start scans the missing-coordinate set from the beginning, while rows with already valid coordinates remain excluded by the repository query.

## Coordinate Query Diagnostics 0.23.12

The location coordinate fill query is now built only from `postal_code` and `display_name`. Rows with an empty `display_name` are skipped before the DaData request. The job status includes reason-specific skip counters: `skipped_empty_query`, `skipped_no_dadata_success`, `skipped_no_coordinates`, and `skipped_invalid_coordinates`; `last_skip_reason` and `last_dadata_message` make the latest skipped row readable in the admin JSON output.

## Location Coordinate Fill 0.23.11

The locations admin page also uses the configured DaData suggestion client for a controlled maintenance batch: "Получить координаты через DaData" on `?page=wdc-platform-locations`. It processes only RU locations with missing coordinates (`latitude`/`longitude` NULL or `0.0000000`), starts from city rows (`г` / `г.` place types), and then continues with the remaining settlements.

Each batch uses small AJAX `start`/`step` requests and persists valid DaData `geo_lat` / `geo_lon` values through `LocationRepository::update_coordinates()`. Existing valid coordinates are not overwritten; empty DaData responses are counted as skipped, and single-row errors are counted without stopping the whole job.

DaData используется только для визуальных подсказок адреса в checkout. Постфактум-нормализация адреса через DaData удалена из runtime pipeline.

## Токены И Лимиты

В разделе `Подсказки адреса DaData` используется список токенов в option `dadata_suggestions_tokens`.

Каждый токен хранит `id`, encrypted token, masked token, `daily_limit`, `enabled`, `label`, `created_at`, `updated_at`.

Plaintext-токен не сохраняется, не показывается в админке и не локализуется во frontend config. Пустое поле токена при сохранении оставляет уже зашифрованное значение без изменений.

Timeout и количество подсказок остаются общими настройками для всего раздела DaData.

## Учет Использований

`usage_today` теперь считает две вещи:

- каждую фактическую HTTP-попытку `wp_remote_post` к DaData;
- выбор строки подсказки пользователем.

HTTP-попытка считается один раз для:

- `stage=address`;
- `stage=resolve`;
- `stage=city`, если используется;
- retry при переключении на следующий токен.

Если токен уже исчерпан и запрос через него не отправлялся, счетчик не увеличивается.

Если запрос был отправлен и получил timeout, HTTP error, `400`, `403`, `429` или другой ответ, счетчик увеличивается, потому что HTTP-попытка фактически была сделана.

Выбор строки подсказки считается отдельным использованием через AJAX action:

`wdc_platform_dadata_suggestion_selected`

Frontend вызывает этот endpoint fire-and-forget при клике по DaData item. Запрос не блокирует UX и не мешает применению адреса, если endpoint не ответил.

Endpoint принимает `usage_type`:

- `suggestion_click` - клик по любой строке подсказки, значение по умолчанию для обратной совместимости;
- `final_selection` - финальный выбор адреса, после которого модалка закрывается и данные переносятся в checkout fields.

Итоговый учет:

- HTTP request to DaData: `+1`;
- click on suggestion row: `+1`;
- final address selection that closes modal and fills checkout fields: `+1`;
- manual fallback: `+0`.

Для финального выбора дома/квартиры обычно получается: HTTP-поиск `+1`, клик по строке `+1`, resolve HTTP request `+1`, финальное применение адреса `+1`.

Для selection usage используется последний DaData token id, сохраненный после последнего HTTP request в сессии WooCommerce. Если token id не найден, endpoint возвращает success с `counted=false` и checkout не ломается.

Ручной fallback `Использовать введенный адрес` не считается выбором DaData-подсказки и не добавляет selection usage.

Ключ счетчика:

`wdc_dadata_suggestions_usage_{token_id}_{Ymd}`

Если DaData возвращает quota/limit error, токен помечается отдельным дневным флагом как исчерпанный, но `usage_today` не подменяется значением лимита. Число в админке отражает реальные HTTP-попытки и выборы подсказок.

В таблице токенов показывается последний request audit:

- stage;
- время попытки;
- HTTP status или `selection`;
- error code.

Ключ audit:

`wdc_dadata_suggestions_last_request_{token_id}_{Ymd}`

Возможные причины расхождения с кабинетом DaData:

- кабинет DaData обновляет статистику с задержкой;
- тот же токен используется где-то еще;
- `resolve` после выбора дома считается отдельным запросом;
- клик по строке подсказки считается отдельным selection usage;
- retry на другой токен добавляет отдельную HTTP-попытку;
- city-stage requests тоже считаются, если включены в сценарии.

## Выбор Токена

`DaDataTokenPool` выбирает первый включенный токен, у которого есть остаток на сегодня и который не помечен исчерпанным.

`DaDataSuggestionClient` отправляет запрос с выбранным токеном:

`Authorization: Token <token>`

Если DaData возвращает ошибку лимита или квоты, текущий токен помечается исчерпанным на сегодня, и клиент повторяет запрос со следующим доступным токеном. Количество попыток ограничено числом активных токенов.

Если токенов нет, AJAX возвращает `no_available_dadata_token`.

Если все токены исчерпаны, AJAX возвращает `dadata_daily_limit_exhausted`.

## Address Picker UX

Покупатель фокусируется или кликает активное WooCommerce поле `address_1`. WDC открывает собственную модалку выбора адреса. Поиск выполняется только внутри поля поиска модалки.

При открытии модалки search input заполняется из выбранного локального населенного пункта, если он есть:

`selected_location.display_name, address_1`

Локальный контекст используется только когда hidden `wdc_platform_location_fias_id` и `wdc_platform_location_display_name` заполнены после выбора в city picker или успешного auto-resolve.

Если локальный населенный пункт не выбран однозначно, используется fallback из видимых checkout fields:

`region/state, city, address_1`

## Поиск Улицы И Дома

Frontend не переводит picker в отдельный restricted mode после выбора улицы.

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

## Финальный Адрес

Финальным считаются house, flat и FIAS levels `8`, `9`, `75`.

После выбора WDC делает `resolve` и применяет результат. DaData `postal_code` считается более точным и всегда перезаписывает checkout postcode, если присутствует в resolved address.

## Manual Fallback

Если подсказки не вернули результат, токены недоступны или лимиты исчерпаны, модалка показывает ручной fallback.

При `Использовать введенный адрес`:

- search value записывается в `address_1`;
- status становится `manual`;
- выбранный город и область не меняются;
- address-specific DaData ids очищаются;
- selection usage не добавляется;
- checkout не блокируется.
