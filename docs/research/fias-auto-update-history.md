# ФИАС: результаты исследования автоматического обновления

**Статус: исследование завершено, реализация отложена по решению пользователя.**

Действующий способ обновления — ручной запуск существующего one-click GAR CSV workflow из полной выгрузки. Рабочий экспортёр `src/Export-GarPlaces.ps1` сохранён без изменений.

Автоматическое расписание, production-обработчик XML-дельт и source-context bootstrap в рамках исследования не реализованы. Автоматизация отложена, а не признана невозможной. Предложения ниже являются историческими результатами, не начатой реализацией и не обязательной следующей задачей.

## 1. Границы и доказательность

Исследования проведены **09.09.2026**, версия плагина **0.155.16**. Они не меняли production, migrations, настройки, очереди или БД магазина. Авторизованных SPAS-запросов, задач GetChanges и прочитанных через SPAS карточек было **0**; токен не извлекался. Публичные документы, Swagger и каталог выпусков были прочитаны в первом исследовании. Файловая диагностика второго этапа выполнялась только локально, без сети, WordPress, WP-CLI и API enrichment.

Здесь различаются: сведения документации/OpenAPI, наблюдения реальных публичных ответов и локальных файлов, синтетические проверки, отложенные предложения. Свойства всей RU и произвольной цепочки выпусков не выводятся из одной выборки.

Состояние до исследований: merge PR #187, `fa960b162f6a49ad22937be1b2c987cd5a884b0e`. Исследования сохранены в коммитах `13f0b49c964df116ce97964588eb3abdd1eb4fc0` и `b9c678c1767ee375dc5e35564f7d846e4cf68328`. Cleanup убрал инструменты только из актуального дерева; история Git и локальные входные файлы не удалялись.

## 2. SPAS и публичный каталог

### Источники, прочитанные 09.09.2026

- [Портал ФИАС](https://fias.nalog.ru/Frontend).
- [SPASDesc v2.0.docx](https://fias.nalog.ru/docs/SPASDesc%20v2.0.docx), прежде всего разделы 14–16, 23–24.
- [Описание службы получения обновлений.docx](https://fias.nalog.ru/docs/Описание%20службы%20получения%20обновлений.docx).
- [Swagger UI](https://fias-public-service.nalog.ru/api/spas/v2.0/swagger/index.html).
- [Фактическая OpenAPI YAML](https://fias-public-service.nalog.ru/api/spas/v2.0/swagger/swagger.yaml): OpenAPI 3.0.1, info.version 2.0. URL взят из UI, не подобран перебором; Network capture не был доступен.
- [Условия использования API](https://fias.nalog.ru/docs/Условия%20использования%20API-сервисов%20ФИАС.pdf).
- [GetLastDownloadFileInfo](https://fias.nalog.ru/WebServices/Public/GetLastDownloadFileInfo) и [GetAllDownloadFileInfo](https://fias.nalog.ru/WebServices/Public/GetAllDownloadFileInfo), публичные HTTPS endpoints.

Прочитаны локальные оригиналы `D:\Downloads\SPASDesc v2.0.docx` и `D:\Downloads\Описание службы получения обновлений.docx`. SHA-256 сохранённой OpenAPI: `566FC93A5834C7945BAEB167154AAB7E9618D10636CDD6F61DA38982443E4CE9`. Это фиксация исследованного источника, не утверждение о неизменности удалённой спецификации.

### Контракт исследованной OpenAPI

Префикс paths: `/api/spas/v2.0/`. Security scheme `master-token`: `type=apiKey`, `in=header`, `name=master-token`; не Bearer и не query token. Значений токена/секретов в материалах нет.

| GET endpoint | Параметры OpenAPI | Ответ 200 по schema |
|---|---|---|
| GetChanges | startDate/endDate: date-time; changeMask/regionCode: int32 | IdResult: success:boolean, id:int64 |
| GetSearchTaskStatus | taskId:int64 | IFetchChangesTaskStatus: completed:boolean, blockCount:int32 |
| GetSearchResultBlock | taskId:int64, blockIndex:int32 | FetchChangesTaskResultBlock: block, nullable массив UUID |

DOCX использует `start_date`, `end_date`, `change_mask`, `region_code`, `task_id`, `block_index`, ответ `task_id` и `block_count`. Это расхождение **snake_case DOCX / camelCase OpenAPI**, не проверенное авторизованным прогоном. В DOCX незавершённая задача имеет completed=false/block_count=-1, пример первого блока использует индекс0. Размер блоков, TTL, повтор создания, истечение, пустой результат и устойчивость результатов не подтверждены фактическим SPAS-тестом.

У GetChanges в исследованной OpenAPI **нет level/type-фильтра**. GUID-блок не содержит уровней: фильтр только по уже известным WDC GUID потерял бы новые населённые пункты. Подтверждённого batch lookup произвольного набора GUID не найдено. Карточки GetAddressItemById/GetAddressItemByGuid описаны как `addresses[]`; DOCX местами пишет singular `address` и ошибочное `GetAddresItemByGuid`. В hierarchy схема AddressPart не объявляет текущие name/type_name/type_short_name из подклассов DOCX, что мешает доказать точный mapper без реальных карточек. Нельзя разбирать красивую строку адреса вместо stable identity и canonical fields.

Предложенная, **не испытанная** комбинация `changeMask=105` означает `1 | 8 | 32 | 64`: основные сведения, ОКАТО/ОКТМО, иерархия, КЛАДР. Флаг4 почтовых индексов намеренно не включался. Пример33 в DOCX согласуется с объединением флагов, но полнота105 для WDC source fields не доказана.

Публичные условия указывали100 запросов/минуту и10 000/сутки. Квота конкретного токена, её фактическая применимость, суточный объём GUID и стоимость детализации всей RU **не измерялись**. Не подтверждены полнота потомков при parent change, полнота descendants, трактовка inactive/404, timezone/границы интервалов, задержки публикации и чтение карточки именно на endDate. Change-log GUID за период плюс карточка на момент чтения не доказаны как исторический snapshot.

Старые automatic GAR/SPAS runtime-заготовки существовали до исследований: обнаружены несовпадения URL/методов с OpenAPI и отсутствие готового wiring авторизации/limiter. На момент исследования они не запускались и не исправлялись; впоследствии заготовки удалены. Их историческое наличие не означает, что исследование реализовало автообновление.

### Наблюдавшиеся публичные ответы

Оба каталожных HTTPS GET вернули200: последний выпуск20260908, каталог63 записи, среди них20260904. Последний объект содержал Date=08.09.2026, ExpDate=2026-09-08T00:00:00, GarXMLFullURL и GarXMLDeltaURL с путями выпуска2026.09.08. GarXMLDeltaURL не равен FiasDeltaXmlUrl. Date/ExpDate не приравнивались к SPAS cutoff или дате загрузки CSV.

Поля каталога подтверждают доступность выпусков, но документ службы не задаёт все правила XML-merge, непрерывность/кумулятивность дельт и обработку пропусков. Статический Actual ZIP не доказывает наличие всей пропущенной цепочки.

## 3. Локальная файловая диагностика

| Исторический входной путь | Размер, bytes | Внутренняя версия / происхождение |
|---|---:|---|
| D:\fias\gar_xml_full.zip | 57 484 117 140 | version.txt: 2026.09.04 / v.289 |
| D:\fias\delta\gar_delta_xml.zip | 22 450 132 | version.txt: 2026.09.08 / v.290 |
| D:\fias\out\gar_places.csv | 67 409 580 | Пользователь указал как результат full; внутри нет release manifest |

SHA-256 delta: `0AAC22C132EB11036FFD7583F70849C9F576223AC88A0C93E1220F9C6AD3C0BE`.

Full не хешировался целиком и не распаковывался. XML filenames delta имеют дату20260907, full20260903; версии выпусков определены отдельно по version.txt. Файловые даты скачивания/изменения и дата запуска экспортёра не являются source checkpoint.

Delta:1 739 ZIP entries, 201 709 649 bytes без сжатия, 96 региональных папок. Найдены справочники операций, уровней, типов объектов и параметров; XSD/отдельный manifest не найдены. Дома, помещения, участки, муниципальная иерархия учтены в inventory, не использованы вместо address-object ADM context.

| Семейство | Строки | Уникальные OBJECTID |
|---|---:|---:|
| AS_ADDR_OBJ | 360 версий | 312, также312 GUID |
| AS_ADM_HIERARCHY | 59 629 | 57 261 |
| AS_ADDR_OBJ_PARAMS | 2 650 | 404 |

У92 объектов имеются PARAM-строки без собственной AS_ADDR_OBJ карточки в delta. 56 965 объектов связей также не имеют собственной карточки; общая hierarchy включает не только населённые пункты. Из16 548 непосредственных parent ID16 439 отсутствуют в карточках delta. Это **отсутствие в дельте**, не отсутствие родителя в полном GAR.

Исходный CSV содержит **161 405 строк RU-проекции**. Столько же текущих target-строк насчитано в full по predicate экспортёра. Это **не число всех стран в БД магазина**, не сравнение с production и не полная построчная сверка CSV. Прежние результаты ручного обновления4/180/18360 и184442 не использовались как эталон этой дельты.

## 4. Наблюдения о версиях и правилах обработки

- `ID` версии, `OBJECTID` объекта, `OBJECTGUID` и `CHANGEID` — разные идентификаторы; ID разных XML-семейств имеют отдельные пространства. Значения сохранялись точно, без float.
- Новый payload того же record ID может закрыть версию или изменить PARAM VALUE. Поэтому append-only/INSERT IGNORE недостаточны. В исследованном overlay delta заменяла baseline payload по ID; конфликт разных payload одного ID внутри одного входа отклонялся.
- Отсутствующая запись в delta сохраняет baseline, а не означает удаление. Неактивная историческая строка рядом с действующей версией не означает прекращения объекта.
- Актуальная карточка выбирается по явному состоянию версий после объединения, не через MAX(ID). В AS_ADDR_OBJ были283 строки1/1,48 строк0/0 и29 строк1/0; операция относится к версии, не к числу NEW WDC locations.
- UPDATEDATE основных семейств был03–04.09.2026 при выпуске08.09.2026. Фильтр «UPDATEDATE позже даты baseline04.09» потерял бы изменения. Нужна идентичность выпуска, не только timestamp записи.
- Необходимы карточки объектов, **AS_ADM_HIERARCHY**, параметры и справочники. AS_MUN_HIERARCHY не является взаимозаменяемым источником. Старые и новые parent paths важны для определения затронутых потомков.
- PARAM сначала объединяются по версиям, затем оцениваются. Отсутствие нового параметра не разрешает молча очистить существующий. TYPEID6=ОКАТО,7=ОКТМО,10=КЛАДР с признаком актуальности;11 не эквивалентен10.
- `EvaluationDate` будущего projector следует задавать явно. Существующий экспортёр использовал DateTime.Today для STARTDATE/ENDDATE; исследовательский overlay сохранял все версии и не подменял выбор ReleaseDate. Полная temporal-policy остаётся предметом parity-проверки.
- В справочниках обнаружено ISACTIVE="true", в других XML используются1/0. Будущий reader должен корректно различать/понимать оба представления. Экспортёр в исследованном случае оставался на fallback TYPEID6/7/10, совпадающих со справочником; его не меняли.
- Target predicate не ограничен LEVEL5/6: учитываются типы населённых пунктов и федеральные города. Lower/trim/концевые точки применяются для predicate, не для произвольного переписывания stored type.

### Совместимость с действующей проекцией

Exporter использует current AS_ADDR_OBJ (ISACTUAL1 и ISACTIVE1), уровни PlaceLevels5/6 либо допустимые типы городов/сёл/деревень/посёлков/хуторов и других населённых пунктов. Region выбирается по LEVEL1; city — self для города, иначе ближайший LEVEL5; district — подходящий предок до city/target, без внутригородского района после города. Region code берётся из ADM либо KLADR. Собственное place переносится CSV mapper также в settlement; display_name/searchable_text производные.

Существующие postal_code,latitude,longitude,russianpost_courier_calc_postal_code являются enrichment-owned и не должны перезаписываться source patch. NEW-only enrichment сохраняет порядок postcode→coordinates→Russian Post courier postcode. RU changeset не затрагивает AM/BY/KZ/KG. Aliases удалены; возможная будущая интеграция должна оставаться locations-only и не возвращать их.

## 5. Реальные примеры

| Объект | Identity | Наблюдение |
|---|---|---|
| Заготовка, п. | OBJECTID1024738; GUID256f4697-f0b4-424c-96be-6387fe88ccc2 | Full ID52937809 был1/1; delta закрыла его0/0 и добавила current ID53467355,1/0, operation30 «Удаление». ADM того же ID53655054 сталinactive. Найден в full и CSV: обоснованный кандидат REMOVED |
| Малые Пороги, д. | OBJECTID755169; GUIDa2f0099e-8c39-496a-baeb-67067cec965f | ID52885243 закрыт; current ID53467499,1/0, operation30; ADM ID44032689 деактивирован. Full/CSV подтверждают прежний target: кандидат REMOVED |
| Юнатов → Юннатов, пер. | OBJECTID1130200, LEVEL8 | ID53146976 закрыт, новая активная ID53467507 имеет новое имя. Реальный rename, но не населённый пункт |
| Киевское | OBJECTID772173; GUID0fa69a32-856a-4c08-b907-15505cf375b0 | Собственной карточки в delta нет; меняются ADM и PARAM. Исторический LEVEL6/снт сменился текущим LEVEL7/тер. СНТ ещё в2018 году, поэтому это не третий current WDC target |

У Киевского закрыта ADM ID80406017 с parent772151 и PATH749230.771808.772151.772173; новая ID254990642 имеет parent771808, PATH749230.771808.772173. Меняются ОКАТО41218000008→41218000000, ОКТМО41518000232→41518000 и КЛАДР47007008000000151→47007000000119000. Это реальный пример изменений связей/параметров без собственной delta-карточки, не доказательство изменения всех потомков.

У Заготовки PARAM ID1723657066 сохраняет ID, но меняет КЛАДР5900000400300→5900000400399 и CHANGEIDEND, при ENDDATE2079-06-06. У ADM inactive также может оставаться ENDDATE2079. Поэтому один срок или суффикс КЛАДР не заменяет анализ полного состояния объекта.

В delta найдены лишь два потенциальных target OBJECTID, оба с подтверждённым прекращением. Нового действующего target в этой выборке нет. Совпадений changed GUID с region/city/district GUID исходного CSV было0, но CSV не хранит все промежуточные пути: универсальная полнота parent-impact этим не доказана.

## 6. Replay, измерения и ограничения

Проведён небольшой overlay baseline+delta по record IDs, не применение в магазин. Для AS_ADDR_OBJ выбранных delta-объектов86 baseline-версий +360 delta-строк дали398 version IDs; ADM для трёх объектов4+4→5; нужные PARAM24+8→27. Повтор и обратный порядок входных строк давали одинаковое состояние. Отсутствующие в delta baseline-версии сохранялись. Проверены synthetic конфликт ID, несколько версий, inactive рядом с active, missing parent/family, кириллица/ё, точные большие ID, повреждённый XML и запрет DTD.

**Полный RU projector, полная построчная сверка CSV и source-context bootstrap не выполнены.** Число161403 после двух предполагаемых удалений было проверяемой гипотезой, **не фактически полученным результатом полной проекции**. Сохранность настоящего production enrichment не тестировалась на БД: БД не подключалась.

Измерение финального ограниченного локального прогона:16.9567s, peak working set422944768bytes, около403.4MiB. Это не benchmark полного bootstrap, cold-disk или hosting; компиляция C# не входила в elapsed, повторные чтения могли использовать OS cache. SampleLimit ограничивал примеры, не общий объём агрегируемой delta.

Full AS_ADDR_OBJ прочитан потоково:3 453 173 версии /1 047 062 310 bytes XML; current/context predicate дал1 602 546 строк. Сохранена только необходимая диагностике часть. Для ADM/PARAM выбраны регионы47/59 и три объекта, а не вся иерархия RU. Размер будущего **persistent source context с индексами не измерен**; RSS процесса не является такой оценкой.

Вывод исследования: текущего CSV недостаточно для общего автономного XML-merge. Он не содержит record versions, полные ADM paths и неизменившиеся параметры нецелевых объектов, которые позднее могут стать targets. Данные отсутствующих родителей брались из full; отсутствие в delta не компенсировалось догадками.

## 7. Отложенное направление

При отдельном будущем решении вернуться к задаче предлагалось:

1. Bootstrap компактного адресного source context из full: AS_ADDR_OBJ identities/версии/флаги/даты, ADM relations/paths, PARAM6/7/10 и справочники; не зеркало домов/помещений и не aliases.
2. Полная сверка исходной проекции с CSV по OBJECTID/GUID и source columns с явной EvaluationDate; затем replay delta, повтор, перестановка входа, проверка parent closure и promotion non-target→target.
3. Только после parity — отдельное формирование delta changeset и адаптер к существующему candidate pipeline. Не использовать full-snapshot отсутствие строки как REMOVED. Unknown/conflict/missing parent должны оставаться unresolved.
4. Согласовать source checkpoint с ручным full update, backup/restore и переносом prepared snapshot. Восстановление старой таблицы не должно оставлять checkpoint новой базы. Atomic RENAME и сохранение checkpoint не являются одной SQL-транзакцией по умолчанию; нужен recovery-протокол.
5. Учесть параллельных admin/runtime writers и сохранение enrichment. Named lock между запросами не решает весь многошаговый lifecycle; долгого ожидания источника и короткого apply нельзя считать одной операцией блокировки.
6. Scheduler/ActionScheduler, расписание, retry/idempotency и эксплуатация — отдельный этап после доказательства data pipeline, не часть выполненного исследования.

Это сохранённое предложение, а не текущий план работ. Сейчас продолжает действовать ручной full-GAR CSV workflow.

## 8. История и восстановление материалов

Все ссылки ниже **исторические, закреплены за commit SHA**, а не указывают на удалённые файлы текущей ветки. Инструменты при необходимости доступны в Git history; действующих команд их запуска здесь нет.

| Коммит | Исторический исходный путь / ссылка |
|---|---|
| 13f0b49c964df116ce97964588eb3abdd1eb4fc0 | [docs/research/fias-auto-update-api-investigation.md](https://github.com/mszaitsev/walls-delivery-calc/blob/13f0b49c964df116ce97964588eb3abdd1eb4fc0/docs/research/fias-auto-update-api-investigation.md) |
| b9c678c1767ee375dc5e35564f7d846e4cf68328 | [docs/research/gar-delta-file-investigation.md](https://github.com/mszaitsev/walls-delivery-calc/blob/b9c678c1767ee375dc5e35564f7d846e4cf68328/docs/research/gar-delta-file-investigation.md) |
| b9c678c1767ee375dc5e35564f7d846e4cf68328 | [tools/diagnostics/Inspect-GarDelta.ps1](https://github.com/mszaitsev/walls-delivery-calc/blob/b9c678c1767ee375dc5e35564f7d846e4cf68328/tools/diagnostics/Inspect-GarDelta.ps1) |
| b9c678c1767ee375dc5e35564f7d846e4cf68328 | [tools/diagnostics/GarDeltaInspector.cs](https://github.com/mszaitsev/walls-delivery-calc/blob/b9c678c1767ee375dc5e35564f7d846e4cf68328/tools/diagnostics/GarDeltaInspector.cs) |
| b9c678c1767ee375dc5e35564f7d846e4cf68328 | [tests/locations/run-gar-delta-diagnostics-smoke.ps1](https://github.com/mszaitsev/walls-delivery-calc/blob/b9c678c1767ee375dc5e35564f7d846e4cf68328/tests/locations/run-gar-delta-diagnostics-smoke.ps1) |

Исторические локальные артефакты первого этапа сохранялись в `C:\Users\mszai\AppData\Local\Temp\wdc-fias-api-research` (OpenAPI, извлечённые тексты DOCX, публичные JSON, PDF условий), второго — в `C:\Users\mszai\AppData\Local\Temp\wdc-gar-delta-20260908-final` (manifest, statistics, samples, baseline context и replay-result). TEMP не является гарантированным архивом; наличие файлов при будущем возобновлении надо проверять. Этот cleanup не удалял TEMP, Downloads, D:\fias, ZIP/CSV или DOCX и не проверял их повторным прогоном.
