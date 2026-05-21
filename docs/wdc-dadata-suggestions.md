# WDC DaData Suggestions 0.14.4

## Задача

DaData в этой версии используется не как постфактум-нормализатор уже введенной строки, а как пошаговый источник визуальных подсказок в классическом WooCommerce checkout.

Покупатель вводит город и адрес, видит варианты, выбирает их из popup и постепенно получает заполненные поля города, индекса, улицы, дома и квартиры. Ручной ввод остается рабочим fallback.

## Server Proxy

Браузер вызывает только WordPress AJAX action:

`wdc_platform_dadata_address_suggest`

API-ключ DaData хранится на сервере через `EncryptionService` в настройке `dadata_api_key_encrypted`. Во frontend config передаются только служебные значения `ajax_url`, `nonce`, `min_chars`, `debug`, `enabled`, `strings`, `stages` и `actions`; ключ или токен в HTML/JS не локализуются.

Серверный клиент обращается к:

`https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`

Headers:

- `Authorization: Token <api_key>`
- `Content-Type: application/json`
- `Accept: application/json`

Secret key и `X-Secret` не используются.

## Stages

`stage=city` ищет город или населенный пункт:

- `locations: [{ country_iso_code: "RU" }]`
- `from_bound: city`
- `to_bound: settlement`

`stage=address` ищет улицу или дом:

- `locations: [{ country_iso_code: "RU" }]`
- `locations_boost` с `city_kladr_id` или `settlement_kladr_id`, если выбран город
- `from_bound: street`
- `to_bound: house`

Город именно бустит выдачу, а не ограничивает ее. Если покупатель выбирает адрес в другом городе, выбранный адрес становится источником истины.

`stage=house_after_street` включается после выбора улицы:

- `locations: [{ fias_id: street_fias_id }]`
- `from_bound: house`
- `to_bound: house`
- `restrict_value: true`
- `count: 20`

`stage=resolve` уточняет выбранный дом/квартиру по `unrestrictedValue` с `count: 1`.

## Frontend Flow

City suggestions подключаются к `shipping_city`, иначе к `billing_city`. При выборе города frontend заполняет city, postcode, state/region и hidden fields DaData, затем запускает `update_checkout`.

Address suggestions подключаются к `shipping_address_1`, иначе к `billing_address_1`. При выборе улицы:

- `address_1` получает `street_with_type`
- сохраняются `street_fias_id` и `street_kladr_id`
- статус становится `street_selected`
- фокус остается в `address_1`
- следующий ввод дома идет через `house_after_street`

При выборе дома или квартиры выполняется `resolve`, после чего обновляются city, address_1, address_2, postcode и hidden fields. Статус становится `resolved`, затем запускается `update_checkout`.

## Hidden Fields

Для billing и shipping добавляются hidden fields вида `{prefix}_dadata_*`:

- `status`
- `unrestricted_value`
- region/city/settlement FIAS/KLADR данные
- street/house FIAS/KLADR данные
- `block`, `flat`, `fias_id`, `kladr_id`, `fias_level`

Статусы: `empty`, `city_selected`, `street_selected`, `house_selected`, `resolved`, `manual`, `invalid`.

## Fallback

Если DaData выключена, ключ не задан, API недоступен или подсказки не подходят, checkout не блокируется. Покупатель может продолжить ручной ввод. Для ручного сценария сохраняется `manual`, совместимые WDC meta получают `normalized=false`, `normalization_source=manual|fallback`, `address_fallback_used=true`.

Город остается обязательным: если city пустой, checkout validation должна просить ввести населенный пункт.

## Order Meta

На `woocommerce_checkout_create_order` сохраняются:

- `_billing_dadata_*`
- `_shipping_dadata_*`

Также заполняются совместимые WDC meta:

- `_wdc_platform_fias_id`
- `_wdc_platform_gar_id`
- `_wdc_platform_resolved_postcode`
- `_wdc_platform_normalized`
- `_wdc_platform_normalization_source`
- `_wdc_platform_fallback_address`
- `_wdc_platform_address_fallback_used`

## Local City Picker

Если DaData suggestions включены и API-ключ сохранен, локальный city picker не подключается, чтобы на поле города не было двух конкурирующих popup. Если DaData suggestions выключены, локальный city picker работает как раньше.

## Debug

При включенном checkout debug frontend пишет в console:

- `address suggestions script loaded`
- `config enabled / disabled`
- `city field found` или `city field not found`
- `address field found` или `address field not found`
- `address field selector used`
- `address input event`
- `stage`
- `query`
- context: `city_kladr_id`, `street_fias_id`
- `ajax request start`
- `ajax success items count`
- `ajax fail`
- `suggestion popup opened`
- `selected level`
- `suggestion selected`
- `status`
- `resolve request start`
- `resolve request success`

Телефон, email и API-ключ не логируются.

## Troubleshooting

Если подсказки адреса не появляются:

1. Проверьте настройки: новая доставка включена, `Включить подсказки DaData` включено, API-ключ DaData сохранен.
2. Откройте Console под администратором с включенным checkout debug panel.
3. Должны быть логи `address suggestions script loaded`, `address field found`, `address field selector used`.
4. При вводе в `billing_address_1` или `shipping_address_1` должен появиться `address input event`, затем `ajax request start`.
5. В Network должен быть запрос `admin-ajax.php?action=wdc_platform_dadata_address_suggest`.
6. Если есть `address field not found`, проверьте реальные имена checkout inputs: `billing_address_1`, `shipping_address_1`, `billing_city`, `shipping_city`.
7. Если `config disabled`, значит DaData suggestions выключены или API-ключ не считается сохраненным сервером.

## Проверка

1. Включить новую доставку.
2. Включить подсказки DaData.
3. Сохранить API-ключ DaData.
4. Включить checkout debug panel.
5. На checkout ввести город и выбрать подсказку.
6. Ввести улицу, выбрать улицу.
7. Добавить дом и выбрать дом.
8. Проверить hidden fields, console debug и order meta после оформления.
