# Исследование ФИАС SPAS для автоматического обновления населённых пунктов

Дата исследования: 09.09.2026. Только исследование и проектное предложение, не описание реализованного автообновления.

## 1. Решение и границы доказательства

**Рекомендация: для выбора источника пока не хватает перечисленных ниже подтверждений. Не включать SPAS auto-apply.** SPAS имеет необходимые building blocks, но наличие трёх Changes endpoints не доказывает полноту delta для нашей проекции GAR. Приоритет следующей проверки: контракт карточки и parent changes у ФНС, затем ограниченный авторизованный read-only прогон с отдельным разрешением. Файловые GAR-дельты выглядят перспективнее по стоимости массовой обработки и близости к XML-экспортёру, но требуют исходного XML-контекста и подтверждения последовательности выпусков; нынешнего компактного CSV для этого недостаточно.

Главные блокеры:

- [OpenAPI] У GetChanges нет level/type-фильтра. Результат содержит GUID без уровня; неизвестные GUID нельзя отбрасывать, иначе теряются NEW населённые пункты.
- [Документировано / OpenAPI] DOCX и действующая спецификация расходятся в query names и envelope создания задачи. Нельзя молча выбрать snake_case или считать описание фактическим ответом.
- [Не подтверждено] Нет гарантии, что изменения родителей возвращают всех затронутых потомков, что выдача descendants полна и что карточки читаются на дату endDate.
- [OpenAPI] В hierarchy описан базовый AddressPart, без актуальных name/type_name/type_short_name из DOCX. Это существенный пробел для точного mapper, а не косметика display_name.
- [Документировано] Публичный лимит 10 000 запросов в день может оказаться недостаточным для всей RU с детализацией каждого GUID; измерений G за сутки нет.
- [Код] Старый GarSyncManager не является готовым клиентом delta API, а текущий candidate pipeline рассчитан на полный snapshot.

Обозначения доказательности во всём отчёте:

| Метка | Значение |
|---|---|
| Документировано | Текст приложенных DOCX или официальных условий; не live execution guarantee |
| OpenAPI | Проверено по опубликованной спецификации и Swagger UI 09.09.2026 |
| Наблюдение | Реальный ограниченный публичный HTTP/UI результат этого исследования |
| Код | Непосредственно прочитанный код на указанном HEAD |
| Предложение | Будущий дизайн; не реализован и не включён |
| Не подтверждено | Требует ответа ФНС, контракта или отдельной проверки |

## 2. Baseline и выполненные действия

[Код] Branch `develop`; HEAD и `origin/develop` после `git fetch origin`: `fa960b162f6a49ad22937be1b2c987cd5a884b0e` (merge PR #187). Исходный `git status --short` пуст. Версия plugin header/WDC_VERSION: `0.155.16`. Develop не продвинулся относительно заданного baseline. Checkout/reset/pull/изменение ветки не выполнялись.

[Данные пользователя] Исходная полная GAR-выгрузка датирована 04.09.2026. Ручной update: было 184618, NEW 4, removed 180, changed 18360, стало 184442. Проверка арифметики: 184618 + 4 - 180 = 184442. Это не число GUID в GetChanges и не измерение суточной нагрузки API. Дата загрузки CSV не определяет состояние источника.

Не выполнялись: production PHP/JS changes, migrations, WordPress bootstrap, SQL к live, import/apply/restore, изменение настроек, GarSyncManager, DaData/Russian Post, создание очереди, commit/push/PR, bump версии. Пользователь после уточнения явно выбрал **исследование без авторизованных запросов**. Поэтому токен не извлекался, не передавался; SPAS GetChanges не вызывался ни разу.

Прочитаны релевантные разделы обязательных docs: docs/README.md, architecture/plugin-architecture.md, architecture/dependency-injection.md, development/development-workflow.md, development/coding-rules.md, development/testing-and-regression.md, subsystems/locations.md, operations/project-status.md. Исторические carrier-разделы не служат источником выводов о ФИАС.

Основной code inventory (пути от корня репозитория):

| Файлы | Проверенный предмет |
|---|---|
| src/Locations/Gar/GarSyncManager.php, GarChangesClient.php | Hook, settings gate, старые URL, options, detection persistence |
| src/Locations/Fias/FiasHttpClient.php, FiasCredentials.php, FiasRateLimiter.php | HTTP, отсутствие wiring авторизации в GAR-клиенте, storage токена, локальные лимиты |
| src/Locations/Import/LocationIncrementalUpdateService.php | Full-source diff, source whitelist, seed/changes/derived/enrichment, validation, RENAME/recovery/cache |
| src/Locations/Import/LocationIncrementalCandidateEnricher.php | NEW-only patch API, порядок и pause semantics |
| src/Locations/Services/LocationMaintenanceJobGuard.php, src/Locations/Storage/LocationWriteLock.php | Logical job, active phases, named lock, owner bypass |
| src/Locations/Storage/LocationRepository.php, src/Locations/ValueObjects/Location.php | Identity, сохранённые поля hierarchy, save/update_coordinates |
| src/Checkout/Locations/LocationCoordinateEnricher.php | Runtime координаты пишутся в live вне административного lock |
| src/Infrastructure/Queue/ActionScheduler.php, src/Core/Plugin.php | Single/recurring/unschedule API, composition и boot_modules |
| src/Export-GarPlaces.ps1 | Полный алгоритм target selection, ADM hierarchy, PARAMS и формат CSV |
| src/Locations/Import/GarPlacesCsvImporter.php | map_csv_row, stage_row_to_location, trim/default и snapshot import |
| src/Locations/Services/LocationDisplayNameFormatter.php | Derived presentation, display rules, не нормализатор source types |
| src/Locations/Services/LocationDatabaseBackupService.php | Locations-only snapshot; нет source checkpoint metadata |
| src/Locations/Import/LocationsSnapshotExporter.php, LocationsSnapshotImporter.php | JSONL tables/options/meta, отсутствие source provenance |
| src/Locations/Services/LocationCountryIndexService.php, src/Checkout/Cache/DeliveryQuoteCacheManager.php | Инвалидация после apply/restore |
| src/Admin/SettingsAdminPage.php, src/Locations/Admin/LocationsAdminPage.php | Token/timeout/limits UI, ручной polling owner |

Связанные tests найдены, ключевые assertions просмотрены без исполнения: tests/fias/run-fias-smoke.php, tests/locations/run-locations-incremental-update-smoke.php, run-locations-incremental-update-ui-smoke.js, run-location-database-backup-smoke.php, run-gar-import-smoke.php, run-dadata-postcode-fill-smoke.php, run-russianpost-courier-postcode-fill-smoke.php; tests/fixtures/gar_places_sample.csv; tests/checkout/run-runtime-stabilization-smoke.php. Synthetic fixture GUID не использованы как реальные FIAS IDs. Исследование не запускает integration runtime.

## 3. Источники и воспроизводимость

| ID | Источник и locator | Получение |
|---|---|---|
| D1 | [SPASDesc v2.0](https://fias.nalog.ru/docs/SPASDesc%20v2.0.docx), §§2–8, 14–16, 23–24 | Локальный `D:\Downloads\SPASDesc v2.0.docx`, прочитан OOXML paragraphs/tables; 144321 bytes |
| D2 | [Описание службы получения обновлений](https://fias.nalog.ru/docs/Описание%20службы%20получения%20обновлений.docx) | Локальный `D:\Downloads\Описание службы получения обновлений.docx`, 19828 bytes |
| O | [OpenAPI YAML](https://fias-public-service.nalog.ru/api/spas/v2.0/swagger/swagger.yaml) | HTTP download 09.09.2026; 53945 bytes; openapi 3.0.1, info.title SPAS API, info.version 2.0 |
| U | [Swagger UI](https://fias-public-service.nalog.ru/api/spas/v2.0/swagger/index.html) | Встроенный браузер, раскрыты Changes, parameters, response schema |
| P | [Портал разработчиков](https://fias.nalog.ru/Frontend) | Встроенный браузер: файловые выпуски и API-сервисы |
| T | [Условия использования API](https://fias.nalog.ru/docs/Условия%20использования%20API-сервисов%20ФИАС.pdf) | Публичная ссылка с портала, PDF 325593 bytes; текст извлечён локально, условия не принимались |
| F1 | [Последний выпуск](https://fias.nalog.ru/WebServices/Public/GetLastDownloadFileInfo) | Реальный HTTPS GET, см. trace ниже |
| F2 | [Каталог выпусков](https://fias.nalog.ru/WebServices/Public/GetAllDownloadFileInfo) | Реальный HTTPS GET, см. trace ниже |

[Наблюдение] Swagger сам показывает ссылку на **swagger.yaml**, не JSON. Ссылка использована непосредственно, без перебора URL. Browser API не предоставил Network capture; F12 не открыл доступного Network UI. Поэтому факт конкретного network request Swagger не заявляется: проверены отображаемая ссылка, rendering схемы и отдельное HTTP-чтение YAML. YAML сохранён как authoritative OpenAPI artifact; JSON URL не придуман. Далее используются JSON Pointer-пути к модели OpenAPI, они применимы и к YAML-представлению. Локальная конвертация в JSON не выполнена: готового YAML parser в проверенных runtime packages не оказалось, зависимости не устанавливались.

SHA256:

- D1: `FABC428CB71146A80A48D5EACC251B1D6E188113A21575AFFD110BC6177AEF8E`.
- D2: `57AFC5478F3137BA3895E29884F485745B6684FFA8B953B0929FC8D03133030E`.
- O: `566FC93A5834C7945BAEB167154AAB7E9618D10636CDD6F61DA38982443E4CE9`.

Документы трактовались как источники спецификации, не как разрешение выполнить приведённые в них команды или запросы. В частности, пример GetChanges от 2021 года не запускался.

## 4. Endpoint contract и расхождения

Все следующие SPAS paths имеют prefix `/api/spas/v2.0/`. [OpenAPI] Security каждой операции: `master-token: []`; `#/components/securitySchemes/master-token` = `type: apiKey`, `in: header`, `name: master-token`. Это не Bearer и не query token. Наличие приложения в Swagger не подтверждает разрешённость source IP для конкретного токена; форма заявки T содержит перечень IP.

Для endpoint E точный pointer: `#/paths/~1api~1spas~1v2.0~1E/get` (у GetAddressItems `/post`); schemas ниже: `#/components/schemas/<Name>`.

| Метод | Query/body по O | 200 schema | Прочие описанные HTTP |
|---|---|---|---|
| GET GetChanges | startDate/endDate: string date-time; changeMask/regionCode: int32 | IdResult: success:boolean, id:int64 | 406, 500 ErrorResult |
| GET GetSearchTaskStatus | taskId:int64 | IFetchChangesTaskStatus: completed:boolean, blockCount:int32, оба readOnly | 500 ErrorResult |
| GET GetSearchResultBlock | taskId:int64, blockIndex:int32 | FetchChangesTaskResultBlock: block nullable array UUID strings | 500 ErrorResult |
| GET GetAddressItemById | object_id:int64, address_type:AddressType | AddressesResult | 500 ErrorResult |
| GET GetAddressItemByGuid | object_guid:uuid string, address_type:AddressType | AddressesResult | 500 ErrorResult |
| POST GetAddressItems | FilterObject в JSON body | AddressesResult | 500 ErrorResult |
| GET GetDetails | object_id:int64 | AddressDetailsResult: address_details | 404, 500 ErrorResult |
| GET GetFiasObjectTypes | Нет parameters | FiasTypesResult: types[] FiasType | 500 ErrorResult |
| GET IsDescendant | ancestor/descendant:int64, address_type | CheckResult | 500 ErrorResult |
| GET HasDescendants | parent:int64, up_to_level:int32, address_type | CheckResult | 500 ErrorResult |

[OpenAPI] У перечисленных query parameters отсутствуют `required:true`, default и bounds. Это не обещание безопасного поведения при пропуске обязательных для нашего запроса дат. У основных schemas нет required property list. ErrorResult имеет nullable string `description`, `additionalProperties:false`; 401/403/429 не описаны как responses этих операций, но клиент должен безопасно обрабатывать их. На практике они не наблюдались. int64 сохранять без потери точности (не JS Number для произвольного ID).

| Предмет | DOCX | OpenAPI | Реальный SPAS test / WDC |
|---|---|---|---|
| Changes query | start_date, end_date, change_mask, region_code (§14) | startDate, endDate, changeMask, regionCode | Не выполнялся. Недопустимо послать обе формы и надеяться на precedence |
| Создание задачи | task_id:number | success:boolean + id:int64 | Не выполнялось; WDC допускает task_id/taskId/id и даже генерирует hash при отсутствии, что не годится для надёжного remote ID |
| Status query/response | task_id; completed/block_count (§15) | taskId; completed/blockCount | WDC URL GetTaskStatus отсутствует в O; camelCase taskId сам по себе согласован с O |
| Block query | task_id/block_index (§16) | taskId/blockIndex | WDC GetResultBlock + block неверны относительно O |
| GUID lookup spelling | GetAddresItemByGuid (§7) | GetAddressItemByGuid | Не выполнялся |
| Lookup envelope | В таблицах §§6–7 address; в примерах addresses[] | AddressesResult.addresses nullable array | Не выбирать singular/direct response без evidence |
| GetAddressItems levels | FilterObject §23.5 address_level; §24 примеры address_levels | Обе properties присутствуют: enum single и nullable array enum | Precedence при совместном указании не определён |
| ResultBlock summary | Получение GUID (§16) | Summary ошибочно говорит о типах ФИАС, но response ref ведёт на block UUID[] | Это не GetFiasObjectTypes |
| Hierarchy names/types | Region/AddressObject subclasses (§23.7) содержат name, type_name, type_short_name | AddressPart их не объявляет; additionalProperties:false; нет oneOf/discriminator с этими subclasses | Критический пробел, требуется реальная карточка/исправленная схема |

[Код] GarChangesClient::request_changes() вызывает `.../api/spas/v2.0/GetAllDownloadFileInfo`, которого нет в O. Публичный файловый каталог находится на другом host/path F2. FiasHttpClient добавляет Accept, но не читает FiasCredentials и не подключает limiter; GarChangesClient не передаёт headers. FiasCredentials сохраняет encrypted/masked values, но не имеет public plaintext getter. Наличие сохранённого токена не делает старый GAR path авторизованным. Эти файлы в исследовании не изменены.

### Task lifecycle

[Документировано D1 §§14–16] Отсутствующий start_date означает первую запись реестра; end_date отсутствует = настоящее время; region_code отсутствует/0 = вся RU; change_mask отсутствует = основные данные. Пример status до готовности: completed=false/block_count=-1; первый result block в примере имеет индекс 0.

[OpenAPI] Нет обещаний о размере блока, максимальном blockIndex, TTL задачи, repeat-request idempotency, expired/not-found task, фиксированности результатов после completed или точной обработке completed=true/blockCount=0. Значение 0 в Swagger Example Value автоматически сгенерировано из schema и не является наблюдением пустой задачи. Conversion statuses сюда не переносить.

[Предложение] При подтверждённом wire contract валидировать shape/types, poll не чаще 10 секунд, извлекать все индексы 0..blockCount-1, сохранять dedup GUID manifest и количество принятых блоков. Для blockCount=0 разрешать пустую дельту только после подтверждения семантики ФНС. При timeout создания не повторять GetChanges автоматически: состояние create_unknown, без fictitious task ID и без продвижения checkpoint. 401/403 остановить; 429 pause/backoff с Retry-After при наличии; 5xx/transport retry только безопасных status/block/card reads, в bounded budget.

## 5. Маска и стоимость отбора

[Документировано D1 §14] Флаги 1 основные данные, 2 налоговые органы, 4 индексы, 8 ОКАТО/ОКТМО, 16 кадастр, 32 иерархия, 64 КЛАДР. Пример 33 показывает комбинирование 1+32. Битовая OR-интерпретация согласуется с таблицей степеней двойки и примером, но O описывает просто integer mask, без enum/Flags contract.

[Предложение] `1 | 8 | 32 | 64 = 105` является разумной минимальной гипотезой. 4 не добавлять: postal_code existing rows принадлежит enrichment. [Не подтверждено] Что basic=1 включает создание/rename/type/deactivation/reactivation полностью; нет ли новых флагов; включает ли 32 всех затронутых потомков, а 8/64 изменения сведений родителей. Поэтому 105 пока не доказанная достаточная маска.

[OpenAPI] В GetChanges ровно четыре query parameters. Нет address_levels, up_to_level, include_descendants, object type filter. GetSearchResultBlock возвращает лишь GUID: определить level по его битам или наличию в нашей таблице невозможно. Потенциально приходят адресообразующие объекты, улицы, дома, помещения и другие уровни; распределение не измерено.

[OpenAPI] GetAddressItems выбирает потомков по path, не принимает arbitrary list GUID. FilterObject имеет path, address_level, address_levels, name_part, address_type, include_descendants, include_hist. Нет paging cursor/limit/continuation или гарантии полноты ответа. Он не доказанный batch lookup для произвольного changes block. Дополнительные DataPump endpoints действительно присутствуют (GetLastId/GetLastChangeId/GetDataPumpBlockById/GetDataPumpBlockByChangeId), но требуют недокументированный `description`, startId/length и возвращают binary без record schema. Их нельзя объявить готовым batch-решением; PumpRecords не вызывался.

### Оценка, не измерение

[Документировано T, раздел общих условий] 100 requests/minute, 10 000/day; ФНС оставляет за собой изменение объёмов. Эти значения совпадают с WDC defaults, но локальные настройки могут отличаться и не увеличивают официальную квоту. Условия scope/reset timezone и конкретная квота токена не проверены.

Пусть G = все уникальные GUID изменений, B = число блоков, P = polls, H = дополнительные ancestor/details/descendants requests. Тогда минимум `R = 1 + P + B + G + H`; при чтении обеих иерархий G может удвоиться. Учесть остальные потребители того же токена и повторы. Нижняя граница времени по rate limit: R/100 минут; при последовательных запросах добавляется latency, не меньше R * среднего времени ответа. Это формула, не benchmark ФНС.

Синтетические сценарии: 5000 карточек при одной на GUID уже требуют больше 50 минут и половины дневной квоты; 20000 не помещаются в 10000/day даже без H. Полный контроль 184442 карточек требует минимум 19 квотных дней, если ни один другой запрос не расходует квоту. Число 18360 changed ручного GAR сравнения НЕ подставляется вместо G. Без G/B/latency и shared-token traffic нельзя признать ежедневную RU обработку выполнимой.

## 6. Карточки, иерархия и удаление

[OpenAPI] AddressItem: object_id:int64, object_guid:uuid, object_level_id:AddressLevel, operation_type_id:nullable int32, region_code:int32, is_active:boolean, path:nullable string, address_details ref, successor_ref:ObjectRef, hierarchy:nullable AddressPart[], address_type, full_name, federal_district, hierarchy_place. AddressLevel enum: 0,1,2,3,4,5,6,7,8,9,10,11,12,14,17. ObjectRef содержит object_id/object_guid. Это IDs объектов, не локальный WDC id.

[OpenAPI] AddressPart содержит IDs/level, full_name/full_name_short, kladr_code, readOnly object_type, hierarchy_place и hist_names(name,short_type). Текущие раздельные name/type fields не объявлены. AddressObject в O используется StructuredAddress и имеет name/type_name; он не указан subclass hierarchy. Использовать hist_names как current name либо разбирать full_name по пробелам нельзя без доказательства.

[OpenAPI] AddressDetails содержит nullable строки postal_code, okato, oktmo, kladr_code и налоговые/кадастровые параметры. Required completeness не задана. FiasType описывает типы адресных объектов, не справочник operation_type_id. В D1 примерах встречаются operation_type_id 1/10/20, но это не нормативная таблица операций. Ни перечисление операций, ни семантика successor полностью не установлены.

[Не подтверждено] Возвращаются ли неактивные карточки по GUID; достаточно ли include_hist (он есть только у FilterObject); различие deleted/missing/null/404; дата состояния карточки. GetDetails документирует HTTP404, GUID lookup в O лишь 200/500. Отсутствие 404 в O не гарантирует, что его не бывает. Ни один из этих результатов не разрешает автоматически удалить location.

| Событие | Предлагаемая классификация и необходимое доказательство |
|---|---|
| Неизвестный GUID, полный действующий target | NEW, после проверки RU, target predicate, GUID/OBJECTID consistency |
| Известный GUID, полные source fields изменены | CHANGED; enrichment/created_at сохраняются |
| Rename/type/параметры | Patch только source whitelist с field-presence mask, rebuild derived |
| Inactive/аннулирование | REMOVED только по подтверждённой семантике is_active/операции и complete current view; не по пустому ответу |
| Reactivation | NEW или восстановление конкретной identity по подтверждённой модели; старые enrichment нельзя приписывать другому GUID |
| Выход из target set | Explicit removal decision с доказательством нового level/type, не absence-as-removed |
| Successor/объединение | Обработать старый и successor GUID; не менять identity старой строки UPDATE-ом и не переносить её enrichment автоматически |
| Переподчинение | Пересчитать ADM ancestor projection, old/new dependency closure |
| 404/null/timeout/неполная карточка | Unresolved, сохранить live, заблокировать unsafe apply/checkpoint либо bounded retry |

### Parent changes

[Код] WDC хранит собственные fias_id/gar_object_id, district_fias_id/district_gar_object_id и city_fias_id, но не полный ADM path/все ancestor IDs. region_fias_id есть в CSV и region import, однако не является полем canonical Location; региональная таблица не заменяет полный dependency graph. Поиск только по city/district не покрывает всех промежуточных родителей и изменение старой связи после переноса.

[Не подтверждено] changeMask=32 может возвращать непосредственно изменившиеся объекты, а не closure. Нельзя считать один успешный пример доказательством fan-out. GetAddressItems(include_descendants) потенциально полезен, но нет подтверждённых paging/лимита/полноты и нет old hierarchy view.

[Предложение] Минимальный дополнительный source context: generation-bound ADM ancestor-ID sequence для каждого target, mapping ancestor -> target GUID и проверенный current source hash. Нужны старые и новые связи; новую closure дополнять подтверждённым перечислением descendants, чтобы не потерять неизвестные targets. Это техническое состояние синхронизации, не alias index. При неполной closure parent-change блокирует promotion checkpoint. Альтернатива при отсутствии guarantees: периодическая полная reconciliation из GAR snapshot; она даёт eventual convergence, не гарантию ежедневной полноты.

## 7. Parity с Export-GarPlaces.ps1

[Код] Экспортёр читает AS_ADDR_OBJ, AS_ADM_HIERARCHY, AS_ADDR_OBJ_PARAMS, AS_PARAM_TYPES. **AS_MUN_HIERARCHY не читается; муниципального fallback нет.** Следовательно address_type=1 выбран по коду, не по частоте примеров DOCX. type=2 допустим только как диагностическое сравнение, не как источник stored geography.

Точный target contract экспортёра:

1. AS_ADDR_OBJ: ISACTUAL=1 и ISACTIVE=1.
2. Target = LEVEL из PlaceLevels (default 5,6) OR IsPlaceType(TYPENAME) OR federal city LEVEL1 с city-like type.
3. IsPlaceType после trim/lower/trailing-dot removal принимает г/город, с/село, д/деревня, п/поселок/посёлок, рп/рабочий поселок/рабочий посёлок, пгт, кп, дп, х/хутор, аул, ст-ца/станица, сл/слобода, м/местечко, нп/населенный пункт/населённый пункт. Нормализация используется для predicate, не переписывает сохранённый TYPENAME.
4. Для контекста сохраняются все уровни 1..8, target и district-like objects. Активный AS_ADM_HIERARCHY target задаёт REGIONCODE/PATH.
5. Region = первый ancestor LEVEL1 (self для LEVEL1). City = self при г/город; иначе nearest LEVEL5; если self LEVEL5, self. Это не произвольный поиск любого city-like ancestor на любом уровне.
6. District = последний district-like ancestor между region и target, а если city отдельный ancestor, до city. Внутригородской район после города исключается. IsDistrictType ищет район/р-н.
7. Region code: hierarchy REGIONCODE, затем первые два знака region KLADR, затем place KLADR. Path разбивается по '.', '/', ','.
8. PARAMS: текущие STARTDATE/ENDDATE относительно **DateTime.Today машины экспорта**, при конкуренции самый поздний STARTDATE. TYPEID из AS_PARAM_TYPES, fallback KLADR10, postal5, OKATO6, OKTMO7. Postal намеренно не выгружается. Поэтому дата архива сама по себе не доказывает дату оценки параметров скриптом.

[Код] CSV mapper копирует place_name/type также в settlement_name/type, а не берёт отдельное одноимённое поле StructuredAddress. display_name/searchable_text derived rebuild использует LocationDisplayNameFormatter и текущие display rules. Existing source types не переводятся глобально в полные названия. SQL diff city_type/settlement_type нормализует lower/пробелы/точку; place_type и region_type не получают такую же семантическую нормализацию. API mapper обязан избежать массовой смены г/г./город, обл/область без реального source change.

### Mapping matrix

Обозначения: A = выбранный AddressItem из `addresses[]`, H = A.hierarchy с доказанным ADM ordering; T = элемент H с object_guid=A.object_guid; R/C/D = region/city/district по алгоритму выше. [Предложение] Столбцы с † требуют полей DOCX subclasses, не подтверждённых O и не проверенных live. Пока они не разрешены, mapper parity не доказана.

Общая missing policy: absent != null != empty. Неполный ответ не очищает существующие fields. Для mandatory source facts NEW откладывается; для CHANGED unresolved сохраняется прежнее значение и не считается завершённой обработкой. Только доказанное отсутствие optional ancestor допускает явное очищение согласованной группы колонок.

| API path / locator | Колонка WDC | Преобразование | Missing/NULL policy |
|---|---|---|---|
| R.name † | region_name | Trim, без изменения регистра имени | Mandatory unresolved |
| A.region_code, R.kladr_code, T.kladr_code | region_code | Две цифры RU, fallback как скрипт | Не 0/пустое автоматически; unresolved |
| R.type_short_name/type_name † | region_type | Выбрать вариант, соответствующий XML TYPENAME, по parity fixture | Не заменять обл на область по удобству |
| D.name † | district_name | Trim | При доказанном no district -> empty; иначе preserve/unresolved |
| D.type_short_name/type_name † | district_type | XML-compatible type | Как district_name |
| D.object_guid | district_fias_id | UUID normalize, без fuzzy | Empty только при доказанном no district |
| D.kladr_code | district_kladr_id | Строка, leading zeros сохранить | Не удалять по отсутствующему field |
| D.object_id | district_gar_object_id | int64 | NULL при доказанном no district |
| D.object_level_id | district_level | Integer | NULL при доказанном no district |
| C.name † | city_name | Self или nearest LEVEL5 по exporter | Группа empty только при подтверждённом no city |
| C.type_short_name/type_name † | city_type | XML-compatible type | Не делать новый storage format |
| C.object_guid | city_fias_id | UUID | Как city_name |
| C.kladr_code | city_kladr_id | Строка | Preserve/unresolved при partial |
| T.name † | settlement_name | Копия place_name, как CSV mapper | Mandatory unresolved |
| T.type_short_name/type_name † | settlement_type | Копия place_type | Mandatory unresolved |
| T.name † | place_name | Собственное имя, не full_name | Mandatory unresolved |
| T.type_short_name/type_name † | place_type | Точный raw-compatible TYPENAME | Нужен parity test, не разбор full_name |
| A.object_level_id / T.object_level_id | place_level | Число и consistency check | Unresolved, не default0 при partial |
| A.address_details.kladr_code / T.kladr_code | kladr_id | Согласовать с текущим PARAMS value | Конфликт/partial -> unresolved |
| A.address_details.okato | okato | Строка | Явное отсутствие по полному details contract; иначе preserve |
| A.address_details.oktmo | oktmo | Строка | Аналогично okato |
| A.is_active + подтверждённый current/actual contract | active | Boolean только при полном знании | Отсутствие != false; inactive требует removal decision |

Identity отдельно: A.object_guid -> fias_id; A.object_id -> gar_object_id и производный gar_id; country_code=RU только после проверки географии. Эти колонки не входят в changed patch. Postal/coordinates/courier postcode existing rows не обновлять из API. Для NEW reuse текущий enrichment порядок; валидный переданный NEW postcode может пропустить postcode lookup по existing contract, но это отдельное enrichment решение, не source ownership.

[Не подтверждено] API current state не имеет ISACTUAL поля как XML. is_active не доказывает автоматически эквивалентность ISACTUAL && ISACTIVE. Current name/type без † не выводится надёжно из O: GetFiasObjectTypes даёт справочник, но не доказывает привязку specific item к type ID. Нельзя использовать прежний type existing row для распознавания реально изменившегося типа без дополнительной проверки.

### Ограниченная parity выборка

Авторизованных карточек и production DB snapshot в исследовании нет. Поэтому фактическое сравнение GUID/object_id/полей пяти категорий **не выполнено**, а не заменено сравниванием строк имён.

| Категория для следующего разрешённого прогона | Сейчас установлено |
|---|---|
| Обычный город | Synthetic fixture Новосибирск существует, но её GUID вымышленные; не API evidence |
| Село/деревня | Fixture Гусиный Брод; доказывает только mapper shape |
| Федеральный город | Exporter явно включает LEVEL1 city-like; live Москва не читалась |
| Населённый пункт с городом-предком | Fixture Ветошниково/Уфа; требуется реальный ADM path |
| Нетипичный уровень | В скрипте комментарий: Нижний Новгород LEVEL2/TYPENAME г. в выгрузке 18.05.2026; это не проверка текущего выпуска или API |

Acceptance будущего mapper: взять реальные GUID из разрешённой выгрузки 04.09, читать карточки без fuzzy search, сравнить все source columns и ancestor identities; отдельно объяснить source-time drift. Эти проверки выявляют ошибки, но пять карточек не доказывают универсальную полноту hierarchy.

## 8. Диагностический trace

Авторизованный SPAS run отменён по уточнению пользователя. SPAS запросов 0; задач 0; task_id отсутствует; block_count неизвестен; блоков 0; GUID получено 0; карточек изучено live 0. Ни mask105, ни casing, ни 401/403/429 фактически не тестировались. Доступная Swagger Example Value не записана как реальный ответ.

Реальные публичные запросы 09.09.2026, без headers/cookies/token dumps:

| Метод/URL | Параметры | HTTP | Время | Shape / объём |
|---|---|---|---|---|
| GET F1 | Нет | 200 | 524 ms | object DownloadFileInfo, 551 bytes |
| GET F2 | Нет | 200 | 209 ms | array 63 DownloadFileInfo, 32113 bytes |
| GET O | Нет | Успешная загрузка | Не измерялось отдельно | YAML OpenAPI, 53945 bytes |
| GET T | Нет | Успешная загрузка | Не измерялось отдельно | PDF, 325593 bytes |

Публичные страницы U/P открывались во встроенном браузере. Дополнительное HTTP-чтение HTML U не дало отдельного config URL; authoritative URL уже был виден в UI. Web-fetch PDF сначала завершился timeout, локальное HTTPS-чтение затем удалось. Это не ошибка SPAS API.

Реальный F1 excerpt (не синтетический):

```json
{
  "VersionId": 20260908,
  "TextVersion": "БД ФИАС от 08.09.2026",
  "GarXMLFullURL": "https://fias-file.nalog.ru/downloads/2026.09.08/gar_xml.zip",
  "GarXMLDeltaURL": "https://fias-file.nalog.ru/downloads/2026.09.08/gar_delta_xml.zip",
  "ExpDate": "2026-09-08T00:00:00",
  "Date": "08.09.2026"
}
```

Если пользователь отдельно разрешит следующий SPAS test: одна задача, регион54, две фиксированные даты одного дня, например 2026-09-07T00:00..2026-09-08T00:00, но timezone и принятие query casing сначала подтвердить. Это проект параметров, **не выполненный запрос**. Лимиты: <=30 запросов, <=10 polls с интервалом >=10s, <=5 blocks, <=10 distinct address objects; официальный лимит дополнительно соблюдается. При большем ответе сохранить sampled/incomplete, не считать задачу доказательством полноты. Никакого автоматического повторного create после timeout.

## 9. Даты и checkpoint

[Документировано D1] Пример datetime без offset и секунд. [OpenAPI] format=date-time, но нет описания source timezone, включённости границ, округления, точности события, срока доступности истории, publication lag или snapshot isolation. Поддержка Z/offset при actual model binding не проверена. Нельзя назвать DateTime без зоны UTC, Москвой или site timezone.

[Наблюдение F2] Выпуск 20260904 реально существует: Date=04.09.2026, ExpDate=2026-09-04T00:00:00. Это подтверждает идентификатор выпуска, но не равенство его cutoff SPAS midnight. [Код] Дополнительно exporter оценивает PARAMS относительно даты исполнения, а CSV не содержит source manifest/hash/cutoff.

[Предложение] Начальное состояние хранить как `source=file, release=20260904, checkpoint_spas=unknown` плюс проверенное происхождение файла, а не выдуманный SPAS timestamp. Для SPAS bootstrap нужен ответ ФНС о связи выпуска и журнала либо повторная reconciliation от известного anchor с proof coverage. Если этого нет, безопаснее подготовить новый verified file baseline, чем включить автообновление от даты загрузки CSV.

GetChanges может фиксировать GUID за интервал, а lookup возвращать более новое состояние. [Не подтверждено] Карточка на endDate не гарантирована; lookup не имеет as_of параметра. Поэтому допустимая модель SPAS после подтверждения контракта: идемпотентная сходимость к текущему источнику, checkpoint означает обработанное окно change-log, **не snapshot на endDate**. Изменение карточки позже окна должно повторно перечитываться при следующем событии; parent closure и задержки публикации остаются отдельными обязательствами.

[Предложение] Catch-up последовательными закрытыми окнами с upper cutoff, выбранным по договорённому publication lag; фиксировать start/end/region/mask, remote ID, все blocks и dedup GUID/hash. Перекрывать окна и дедуплицировать по GUID+source version/hash, а не считать перекрытие гарантией от произвольной задержки. При multi-region окнах watermark RU = минимум полностью обработанных регионов. Пропущенные дни не перескакивать; после TTL переискать то же фиксированное окно как новый controlled job, не забыв неопределённость предыдущего create.

Не продвигать checkpoint при неполных блоках, unresolved target/parent objects, failed validation, неприменённом candidate. Подтверждённая пустая дельта может продвинуть log watermark без RENAME только при complete coverage и отсутствии изменения generation во время поиска. Повторное выполнение применять по stable identity; частично выполненный workflow не вызывает enrichment заново для ранее обработанных NEW без необходимости.

## 10. Файловая альтернатива

[Документировано D2] GetAllDownloadFileInfo возвращает каталог, GetLastDownloadFileInfo последний объект. VersionId в прямых выгрузках имеет вид yyyyMMdd; ExpDate = дата экспорта; Date = дата выгрузки. GarXMLDeltaURL и FiasDeltaXmlUrl разные поля, заменять одно другим нельзя.

[Наблюдение] HTTPS variants обоих методов работают. Каталог содержит 63 записи, самая ранняя полученная 03.02.2026. Это наблюдаемое окно доступности, не обещание retention. Среди ближайших выпусков: 01.09 (20260901), 04.09 (20260904), 08.09 (20260908); после 04.09 в полученном каталоге есть 08.09, 09.09 выпуска нет. Нельзя трактовать отсутствие выпусков 05–07.09 как потерю цепочки без release schedule.

[Наблюдение P] Портал показывает delta08.09 ≈21 MB, delta04.09 ≈27 MB, full ≈53 GB. Это подписи UI, не измеренные скачанные размеры. Архивы не скачивались. Адрес Actual/gar_delta_xml.zip может сменить содержимое и не представляет всю пропущенную цепочку; фиксировать per-release URLs из каталога.

[Не подтверждено D2] Документ не задаёт точную последовательность применения XML records, predecessor release ID, кумулятивность delta, порядок PARAMS/HIERARCHY, правила закрытия/удаления версий. Доступность URL не доказывает совместимость его delta с baseline. Нужны официальные XSD/правила выгрузки и отдельный проверенный fixture.

[Предложение] Для file path поддерживать компактное зеркало source context: версии AS_ADDR_OBJ для target и нужного ancestor graph, AS_ADM_HIERARCHY, AS_ADDR_OBJ_PARAMS и AS_PARAM_TYPES. Дельта меняет это зеркало, после чего повторяется тот же projector, что full exporter. Нельзя запустить нынешний Export-GarPlaces.ps1 на одном delta ZIP: в нём отсутствует неизменённый контекст. При пропавшем необходимом выпуске остановить checkpoint и запросить полный verified baseline, а не перейти к Actual. Source context должен позволять находить новых targets/родителей, а не ограничиваться существующими WDC GUID.

| Вариант | Достоинства | Главные ограничения | Решение сейчас |
|---|---|---|---|
| A SPAS Changes + cards | Нет полного XML mirror для простых leaf changes, online current view | G-wide lookup quota, недоказанная hierarchy closure, temporal drift и mapper schema gap | Условный кандидат; auto-apply не разрешён |
| B GAR XML deltas | Версионированные releases, источник ближе к exporter; один архив вместо G calls | Нужны local source graph и rules применения; новые сведения только при публикации выпуска | Приоритет исследования массового path, ещё не подтверждён production design |
| C Hybrid | Возможна периодическая сверка или адресный repair | Два source watermark, конфликт времени/представления, двойная сложность | Только после доказанной необходимости; не default |

Ежедневный cron для B означает ежедневную проверку появления выпуска, а не гарантию ежедневной публикации ФНС. Требование ежедневного оперативного current view и требование parity с release snapshot различаются; перед выбором нужен согласованный SLA.

## 11. Предложение интеграции без реализации

[Код] Старый GarSyncManager регистрирует `wdc_gar_daily_check` с time()+1h и recurring86400, даже если requests disabled; check_for_changes gate=`gar_sync_enabled` defaultfalse. Он пишет last_check/pending/status options и wdc_gar_changes, трактует непустой payload как pending. Это не applied source checkpoint. FIAS smoke подтверждает disabled/no-HTTP path, не wire contract. Локальный FiasRateLimiter использует transient counters с продлением TTL при increment; не global atomic quota allocator и не доказанная семантика суток ФНС.

[Код] Сейчас ручной one-click update продвигается browser step requests. Candidate seed bounded1000, source/derived100, API stage одна NEW row; phase state/source checks нельзя просто заменить HTTP вызовом. Full-snapshot removed = отсутствие в staging. **Передавать delta GUID как staging full snapshot запрещено.**

[Предложение] Минимальные владельцы:

- Source adapter: verified SPAS protocol или file release reader, auth/redaction/budget и immutable source evidence.
- Delta changeset builder: explicit NEW/CHANGED/REMOVED/UNRESOLVED, presence mask, old/new ancestor dependencies, proof каждой removal. Никакого missing-in-delta deletion.
- Existing candidate workflow: отдельный input mode для validated changeset; clone live, apply bounded explicit patches, derived, NEW-only enrichment, validation, locations-only RENAME. Не менять repository singleton table name.
- Queue coordinator через существующий ActionScheduler: job_id/stage cursor/idempotency, source generation, delivery-cache recovery. Admin UI показывает status/resume/cancel и не является worker.

Будущая последовательность: source discovery -> frozen changeset -> fresh live generation reservation -> candidate seed -> explicit changes -> derived -> NEW postcode/coordinates/RP -> validate -> atomic locations swap -> durable checkpoint association -> country/delivery cache recovery -> cleanup. Все эти изменения требуют следующей implementation-задачи.

### Планирование

[Предложение] ON/OFF, HH:MM и site timezone, next check, last applied source state, last attempt/error, check-now, pause/resume/status. ON не включает неизвестный checkpoint. Перепланирование при HH:MM/timezone: отменить только принадлежащий feature hook/group, вычислять next wall-clock occurrence с timezone/DST, предпочтительно schedule_single next-day, не постоянные86400. Старый wdc_gar_daily_check отключить/unschedule контролируемо при внедрении, не оставить два worker path. Проверить уже queued actions и no-op старого handler.

System cron должен вызывать поддерживаемый WP/Action Scheduler runner; закрытый браузер не мешает работе. Настроить health detection отсутствия runner, ограниченные шаги и повторную доставку с тем же job/cursor. Duplicate queue deliveries не создают второй remote task или candidate; claim/checkpoint под lock. SPAS HTTP outside short DB lock, separate credential interface без логирования секретов, официальный combined-token limiter с durable counters и bounded backoff. При Woo runtime OFF preparation sync может работать, как текущий boot_modules, но только при собственной настройке ON; checkout/order hooks не нужны.

## 12. Exclusivity и работающий checkout

[Код] LocationWriteLock = GET_LOCK('wdc_locations_write_lock',0), finally RELEASE_LOCK. LocationMaintenanceJobGuard держит logical busy между запросами, waiting_dadata_limit остаётся active. Import/admin/DPD writers проходят этот boundary. Lock cooperative, не общий запрет всех SQL. LocationCoordinateEnricher вызывает LocationRepository::update_coordinates напрямую; repository обновляет live и updated_at без этого lock.

[Предложение] Разделить remote collection и candidate critical lifecycle:

1. Пока ожидается remote task, держать reservation только source-sync job. Не блокировать всё администрирование на часы. Если full update/restore меняет dataset generation, collected evidence пометить stale, пересчитать against new baseline или остановить; нельзя apply к предположительно старому baseline.
2. Под GET_LOCK проверить generation и отсутствие конфликтующего maintenance job, зарезервировать candidate owner, затем seed. С этого момента блокировать canonical admin writers до apply/cancel; пауза на enrichment лимите сохраняет ownership либо требует явного abandon/rebase.
3. Для работающего checkout не блокировать чтение/quotes и не требовать вечного отключения runtime enrichment. Минимальная дополнительная политика: runtime persistence координат берёт короткий writer lock, проверяет stable GUID+generation, пишет dirty-identity journal и live update. HTTP enrichment выполняется вне lock. Journal должен переживать crash; записать dirty intent до mutation под тем же lock либо в одной доказанно поддерживаемой DML transaction.
4. Перед RENAME закрыть journal high-water mark под тем же lock, bounded-reconcile live enrichment-owned fields для изменённых identities в candidate; на короткой финальной секции новые writes ждут/откладываются. Не переносить старые значения по reused local id, проверять GUID и source fingerprint. Concurrent result старого поколения можно вернуть текущему запросу, но persist только после повторной identity проверки.
5. Если финальный dirty tail превышает короткий бюджет, release, продолжить bounded drain и повторить barrier; не держать сетевые вызовы или массовый scan под lock. На период финального swap persistence может enqueue retry, не ломая checkout. Removed identity не перепривязывать к successor автоматически.

Это изменение writer policy, а не существующая возможность LocationMaintenanceJobGuard. Нужен явный узкий runtime-enrichment путь под сериализацией, не произвольный owner bypass. Другие canonical runtime writers, если найдутся при implementation inventory, обязаны участвовать. Периодическое чтение всей live enrichment без writer barrier само по себе race не закрывает. Без journal/barrier или другого доказанного merge protocol seed может потерять координаты, записанные после копирования строки.

## 13. Apply recovery и provenance

[Код] Текущий RENAME меняет только locations, а options/job/cache updates идут отдельно. Recovery распознаёт previous present/candidate absent/live present; это полезная основа, но не SQL transaction с source checkpoint. Backup сохраняет только locations и timestamp имени; JSONL сохраняет tables regions/locations/delivery_codes и display rules, meta.version/created_at, **не** source release/checkpoint.

[Предложение] Ввести dataset generation и durable apply intent: job_id, old/new generation, candidate identity/checksum, source manifest, source checkpoint proposal, table names, state. До swap записать intent и убедиться в durable success. После swap зафиксировать applied-source association; до завершения recovery новые apply/restore и cleanup previous запрещены. На crash восстановить состояние по exact intent и существованию ожидаемых tables/generation, не по одному пустому option. При неоднозначности fail closed, не повторять rename и не продвигать watermark.

Source checkpoint логически продвигается только после доказанного swap (или verified no-op delta); cache failure не откатывает successful data swap. Сохранять applied state + cache_pending, выполнять idempotent country stale/delivery cache clear и fail-safe version bump; finished только после recovery. Удалять previous/temp только после durable association/checkpoint, чтобы не уничтожить evidence.

Backup, full update и перенос snapshot должны согласованно переносить **source provenance + generation**, не секреты и не transient task ID:

- Full GAR update требует source release manifest/hash и режима exporter; после apply rebase source checkpoint, не брать upload time. Legacy CSV без manifest -> source unknown, auto-update paused до reconciliation.
- Backup create фиксирует provenance того же поколения под lock; restore возвращает соответствующий checkpoint либо явно invalidates его. Нельзя оставить новый checkpoint у восстановленной старой таблицы. Отдельный metadata manifest должен участвовать в publish/recovery; timestamp backup не source date.
- Prepared snapshot export/import включает проверенный source manifest и mapper version вместе с данными. Перенос без metadata сбрасывает source sync validity; не переносить token/remote task lease/host queue state. Не импортировать checkpoint раньше successful dataset replacement.
- Ancestor context, если добавлен, version-bound и тоже восстанавливается/перестраивается до включения sync. Alias tables не возвращаются.

Это необходимое расширение будущего scope: его нельзя объявить решённым нынешним locations-only backup автоматически.

## 14. Минимальные этапы следующей реализации

1. Получить ответы ФНС по wire casing, mapper subclass, hierarchy closure, temporal bounds, task TTL, batch/quotas и XML delta ordering. Зафиксировать версию/хэш спецификации и decision A/B; до этого auto-apply OFF.
2. Отдельный разрешённый diagnostics harness вне production runtime: безопасная авторизация, bounded trace, пять реальных parity категорий, неактивный object и parent-change fixtures. Без apply. Не расширять бюджет втихую.
3. Спроектировать source manifest/generation/checkpoint/ancestor context и recovery с full-update/backup/snapshot; согласовать migration отдельно.
4. Реализовать source adapter + delta changeset tests, исключив absence-as-removed и enrichment ownership changes.
5. Подключить bounded candidate input и runtime dirty-write reconciliation; failure/race tests для seed, repeated steps, RENAME-before-option, restored checkpoint, foreign preservation.
6. Добавить Action Scheduler coordinator, schedule/timezone migration, UI status, OFF default. Проверить closed-tab execution, duplicate jobs, token-limit pause, no checkpoint on partial evidence.
7. Shadow runs/dry-run diffs на production-like snapshot; full exporter parity и staged rollout с backup. Только затем разрешить автоматический apply.

## 15. Вопросы ФНС и критерии решения

1. Какой wire contract production принимает для Changes: camelCase из O или snake_case D1? Что происходит с неизвестным query parameter? Не может ли ошибочное имя даты молча открыть весь реестр?
2. Task id envelope success/id или task_id; blockCount или block_count? Размер блока, zero-based range, zero results, TTL, repeat-create idempotency, expired/error response?
3. Точная semantics flag1 и flag32, подтверждённый OR и актуальная таблица flags. Возвращаются ли descendants при изменении parent name/type/params/hierarchy?
4. Есть ли поддержанный фильтр Changes по object levels/types или batch arbitrary GUID lookup? Назначение и публичный contract DataPump без догадок description/binary schema?
5. Какие runtime properties Region/AddressObject hierarchy существуют и почему они отсутствуют в O? Где normative mapping type names к AS_ADDR_OBJ.TYPENAME и ISACTUAL?
6. Как получить inactive/annulled/restored/successor objects; authoritative operation-type dictionary; значение null/404/empty при lookup?
7. Гарантии GetAddressItems/include_descendants/include_hist: объём, pagination, полнота, inactive filtering, old/new hierarchy и ordering?
8. Timezone, offsets, inclusivity/precision start/end, событие датирования, publication delay bound, history retention, result immutability и as-of карточки?
9. Связь VersionId/ExpDate/Date с SPAS cutoff. Можно ли считать delta release зависимым от предыдущего опубликованного release; что делать при gap и есть ли predecessor ID/checksum?
10. Состав/применение GAR XML delta для объектов, hierarchy, PARAMS и справочников; закрытие старых версий и переносы; есть ли безопасный compact initial context?
11. Точная quota данного master-token, reset timezone/rolling windows, общий бюджет endpoints, 429/Retry-After, разрешённость фонового RU replication для указанного продукта?

**Критерий выбора SPAS:** доказаны wire contract, полная projection/parent closure и sustainable quota с запасом; temporal model явно принята как convergence либо доказан as-of. **Критерий выбора files:** доказана release chain и projector parity на достаточном source context, допустима задержка до публикации выпуска. Сейчас ни один полный набор условий не закрыт. Токен сам по себе не аргумент в пользу A.

## 16. Артефакты и итоговое состояние

Создан только этот research document в репозитории. Canonical architecture/status docs, production, migrations, tests, tree.txt и версия не менялись. Поиск established research directory его не обнаружил; использован явно запрошенный путь docs/research/fias-auto-update-api-investigation.md. tree.txt намеренно не обновлялся в research-only scope.

Временная папка вне Git: `C:\Users\mszai\AppData\Local\Temp\wdc-fias-api-research`:

- swagger.yaml: исходная публичная OpenAPI, без токенов;
- SPASDesc v2.0.txt и Описание службы получения обновлений.txt: извлечённый текст локальных DOCX;
- GetLastDownloadFileInfo.json и GetAllDownloadFileInfo.json: реальные публичные ответы;
- api-terms.pdf: официальные условия с лимитами.

Не созданы HAR, screenshots авторизации, raw header dumps, token files, API task или diagnostic execution script. Исходные DOCX не изменялись. JSON-производная OpenAPI не создана; отсутствие parser не заменялось самодельным YAML разбором или установкой пакетов.

Проверка research artifact: relative Markdown links отсутствуют; официальные URLs приведены явно, локальные code paths проверены по inventory. Production tests не запускались: исследование не меняет runtime и не должно запускать старый GarSyncManager. Изменения ограничены новым Markdown; `git diff --check` прошёл. Дополнительно новый untracked файл проверен через `git diff --no-index --check -- NUL`: whitespace errors отсутствуют, есть только предупреждение Git о будущем LF -> CRLF.

Итоговый `git status --short`:

```text
?? docs/research/
```
