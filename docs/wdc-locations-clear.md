# WDC Locations Clear

В админке `Калькулятор доставок -> Населенные пункты` есть кнопка `Очистить базу населенных пунктов`.

Перед очисткой браузер показывает подтверждение: `Удалить все населенные пункты и алиасы из локальной базы WDC?`

Действие удаляет только локальную базу населенных пунктов WDC:

- `{$wpdb->prefix}wdc_location_aliases`;
- `{$wpdb->prefix}wdc_locations`.

Сначала очищаются алиасы, затем населенные пункты. Если таблицы отсутствуют, очистка не должна падать с fatal error. Репозиторий сначала считает строки, затем пробует `TRUNCATE`; если `TRUNCATE` недоступен, используется fallback `DELETE`.

Действие не удаляет:

- ПВЗ;
- правила доставки;
- календарь;
- настройки;
- DaData tokens;
- shipping/order meta;
- данные WooCommerce.

Использовать кнопку нужно перед загрузкой новой полной базы населенных пунктов, чтобы демо-данные и старые алиасы не смешивались с реальным справочником.

# 0.15.x GAR Import Update

Full clear now removes local locations, aliases, regions, and `wdc_location_carrier_codes`. Carrier mappings are cleared during a full GAR reimport because location IDs and GAR mappings may be replaced together.
