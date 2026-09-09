# Локальное исследование GAR-дельты 08.09.2026

Дата исследования: 09.09.2026. Это диагностический инструмент и наблюдения над локальными файлами, не production updater. Сеть, SPAS, токены, WordPress, WP-CLI, БД и enrichment API не использовались.

## 1. Вывод

**Выбранный файловый путь практически подтверждён как пригодный для следующего локального этапа. Рекомендуется вариант B: однократный bootstrap source context из полной выгрузки, а не merge дельты непосредственно в WDC CSV.**

Установлено на реальном выпуске:

- Внутренние версии: full `2026.09.04 / v.289`, delta `2026.09.08 / v.290`.
- В delta 360 версий AS_ADDR_OBJ, 312 объектов, но лишь два потенциальных населённых пункта. Оба существовали в full и CSV; оба получили актуальную неактивную версию с операцией 30 «Удаление».
- В AS_ADM_HIERARCHY 59 629 строк, большая часть относится не к населённым пунктам. Нельзя считать их числом изменившихся WDC locations.
- У 92 объектов меняются параметры без собственной карточки AS_ADDR_OBJ в delta. Отбор только по карточкам принципиально неполон.
- Delta изменяет содержимое уже существующих record ID: закрывает версии/связи, меняет значения параметров. Append-only/INSERT IGNORE неверны.
- Даты UPDATEDATE в исследованных основных семействах: 03–04 сентября. Фильтр UPDATEDATE > дата выпуска baseline потеряет изменения.
- Реальный sandbox overlay по ID повторяем и не зависит от порядка строк. Он сохраняет baseline-записи, отсутствующие в delta. Это ещё не полный WDC projector всей RU.

Следующий шаг конкретен: построить локальный source-context bundle из имеющегося full, воспроизвести все 161 405 строк данного CSV, затем применить v.290 в отдельную копию bundle и получить source-only changeset с проверкой повторного запуска. Ответ ФНС или SPAS не является условием этого шага.

Метки: **Наблюдение** = измерено/прочитано в реальных файлах; **Код** = действующий проект; **Синтетика** = искусственный тест; **Предложение** = правило будущей реализации, не обещание формата ФНС.

## 2. Baseline и границы

Branch `develop`, HEAD `13f0b49c964df116ce97964588eb3abdd1eb4fc0` (`документ`). Рабочая копия в начале чистая. Предыдущий research baseline не навязывался. Git log также содержит merge PR #187 `fa960b16`. Ветка не переключалась; fetch/network не выполнялись. Версия остаётся `0.155.16`.

Прочитаны относящиеся к задаче разделы docs/README.md, development/development-workflow.md, development/coding-rules.md, subsystems/locations.md, предыдущий fias-auto-update-api-investigation.md. Проверены src/Export-GarPlaces.ps1, GarPlacesCsvImporter.php, LocationIncrementalUpdateService.php и ValueObjects/Location.php. PHP/JS/exporter/schema не менялись и не запускались.

Созданы только tools/diagnostics/Inspect-GarDelta.ps1, его локальный C# helper GarDeltaInspector.cs, synthetic smoke и этот отчёт; tree.txt дополнен точечно. Production-импорт, расписание и SQL не создавались.

## 3. Входы и provenance

| Файл | Размер, bytes | Проверенное происхождение |
|---|---:|---|
| D:\fias\delta\gar_delta_xml.zip | 22 450 132 | version.txt: `2026.09.08` + `v.290` |
| D:\fias\gar_xml_full.zip | 57 484 117 140 | version.txt: `2026.09.04` + `v.289` |
| D:\fias\out\gar_places.csv | 67 409 580 | Пользователь указал как результат full; внутри нет source release/даты оценки |

SHA-256 **дельты**: `0AAC22C132EB11036FFD7583F70849C9F576223AC88A0C93E1220F9C6AD3C0BE`.

Большой full не хешировался, не скачивался и не распаковывался. Исходные файлы не менялись. LastWriteTime дельты 09.09, full 04.09, CSV 08.09 являются файловыми датами, не source checkpoint. В manifest сохранён UTC mtime delta отдельно от release_date_declared.

Имена XML delta содержат `20260907`, full `20260903`. Это не повод переименовать выпуск: версия подтверждена внутренним version.txt. Сохранённый каталог из предыдущего исследования также описывал выпуски 20260904/20260908; новый сетевой запрос не выполнялся. Последовательные v.289/v.290 наблюдаются в этой паре, но не доказывают универсальный контракт непрерывности всех выпусков.

CSV содержит **161 405** строк данных. Столько же текущих target-строк найдено при проходе AS_ADDR_OBJ full по predicate экспортёра. Это согласованность количества и двух конкретных примеров, не полное доказательство побайтового происхождения CSV. Число 184 442 из прежнего ручного обновления магазина не использовано как эталон: магазин и данный RU CSV не сравнивались, БД не читалась.

## 4. Инструмент и сохранённые результаты

`Inspect-GarDelta.ps1` требует PowerShell 7 и параметры DeltaArchive, OutDir, ReleaseDate; BaseArchive/BaseCsv необязательны; SampleLimit=10. Используется Add-Type с соседним GarDeltaInspector.cs, без установки пакетов. Архивы открываются read-only, XML читается XmlReader, DTD запрещён, XmlResolver=null. Все stream/reader/ZIP закрываются using/finally. Значения ID сохраняются строками, без double/JavaScript Number; leading zero параметров сохраняются.

Семейство определяется regex, отделяющим имя от `_YYYYMMDD_`, поэтому AS_ADDR_OBJ, AS_ADDR_OBJ_PARAMS и AS_ADDR_OBJ_TYPES не смешиваются. Нет распаковки XML в рабочую папку. При ошибке создаётся failure.json, исключение возвращается вызывающему процессу; успешный statistics.json не публикуется. Отсутствующее семейство имеет `present=false`; существующее с нулём строк не маскируется как отсутствующее.

OutDir должен быть вне репозитория и пустым. Скрипт не перезаписывает прежние результаты. UNC/URI input paths отвергаются. Нет HTTP, SQL или внешних процессов. Метаданные до 64 KiB читаются целиком только для малых version/manifest, не для больших XML.

Авторитетный финальный OutDir:

`C:\Users\mszai\AppData\Local\Temp\wdc-gar-delta-20260908-final`

Артефакты: archive-manifest.json, files.csv, reference-records.json, statistics.json, object-version-samples.jsonl, hierarchy-samples.jsonl, parameter-samples.jsonl, missing-context.json, base-manifest.json, baseline-context.json, classification.json, base-csv.json, replay-result.json. Полный список entries и размеры сохранены в files.csv; группировки семейств/регионов в manifest. JSONL содержит ограниченные группы примеров, не весь XML.

Ранние диагностические прогоны оставлены отдельно в `%TEMP%\wdc-gar-delta-20260908-inventory`, `-baseline`, `-verified`; они не заменяют финальные результаты. Synthetic fixtures находятся только в `%TEMP%\wdc-gar-delta-tests-*`; ZIP не добавлены в Git.

## 5. Состав ZIP

**Наблюдение:** 1 739 entries, 201 709 649 bytes суммарно без сжатия. 96 региональных папок, 18 семейств по 96 XML, 10 корневых справочников и version.txt. XSD/отдельный manifest не обнаружены. Семейства могут содержать пустые XML, наличие 96 файлов не означает 96 изменённых регионов.

| Семейство | XML files | Uncompressed bytes | Строк / уникальных OBJECTID |
|---|---:|---:|---|
| AS_ADDR_OBJ | 96 | 116 275 | 360 / 312, GUID тоже 312 |
| AS_ADM_HIERARCHY | 96 | 18 643 322 | 59 629 / 57 261 |
| AS_ADDR_OBJ_PARAMS | 96 | 485 228 | 2 650 / 404 |
| AS_PARAM_TYPES | 1 | 5 274 | 22 справочных строки |
| AS_OPERATION_TYPES | 1 | 8 763 | 34 |
| AS_OBJECT_LEVELS | 1 | 2 918 | 17 |
| AS_ADDR_OBJ_TYPES | 1 | 89 459 | 427 |
| AS_REESTR_OBJECTS | 96 | 30 753 697 | 175 374 строки; уникальность не агрегировалась |
| AS_CHANGE_HISTORY | 96 | 26 376 947 | 177 408 строк; уникальность не агрегировалась |
| AS_MUN_HIERARCHY | 96 | 15 881 019 | Только inventory, не источник ADM |
| AS_ADDR_OBJ_DIVISION | 96 | 4 800 | Только inventory |

Также есть AS_HOUSES/AS_HOUSES_PARAMS, AS_APARTMENTS/AS_APARTMENTS_PARAMS, AS_ROOMS/AS_ROOMS_PARAMS, AS_STEADS/AS_STEADS_PARAMS, AS_CARPLACES/AS_CARPLACES_PARAMS, AS_NORMATIVE_DOCS. Они учтены по bytes, но содержимое не интерпретировалось. Остальные справочники: ADDHOUSE_TYPES, APARTMENT_TYPES, HOUSE_TYPES, ROOM_TYPES, NORMATIVE_DOCS_TYPES/KINDS.

У REESTR_OBJECTS LEVELID: 9=88 897, 10=45 229, 11=36 384, 12=435, 17=3 830, 7=133, 8=463, 4=1, 6=2. Это подтверждает смешанный состав, а не «175 тысяч населённых пунктов». ID реестра/истории не используется как ID версии AS_ADDR_OBJ. Их ограниченные raw samples сохранены в statistics.json.

## 6. AS_ADDR_OBJ: версии, операции, реальные примеры

Первый проход сохранил **все** версии без фильтра ISACTUAL/ISACTIVE. Дубли и конфликтующие payloads одного record ID в этом семействе не найдены. Для ADM/PARAMS результат такой же. В AS_ADDR_OBJ 360 строк при 312 объектах; группы нескольких версий сохранены отдельно.

LEVEL: 6=4, 7=149, 8=207 строк. Флаги ISACTUAL/ISACTIVE: `1/1=283`, `0/0=48`, `1/0=29`. Нет ни LEVEL5, ни активной текущей target-строки; 283 активных строки относятся к другим типам.

TYPENAME counts полностью в statistics.json. Наибольшие: ул.=143, тер.=97, пр-д=33, пер.=23; д.=2 и п.=2 являются четырьмя версиями двух targets. Есть р-н=2 на нецелевом уровне: название типа само по себе не делает объект населённым пунктом.

OPERTYPEID и названия **из локального AS_OPERATION_TYPES**, не из памяти:

| ID | Название | Строк AS_ADDR_OBJ |
|---|---|---:|
| 10 | Добавление | 280 |
| 20 | Редактирование | 33 |
| 30 | Удаление | 26 |
| 40 | Объединение | 4 |
| 42 | Прекращение существования вследствие объединения | 4 |
| 50 | Переподчинение | 12 |
| 70 | Восстановление прекратившего существование объекта | 1 |

Операция относится к версии. 280 строк с операцией «Добавление» не являются 280 NEW WDC locations.

### Наблюдаемые before/after

1. **Заготовка, п., OBJECTID 1024738**, GUID `256f4697-f0b4-424c-96be-6387fe88ccc2`. В full ID52937809 текущий `1/1`; delta обновляет его в `0/0`, NEXTID53467355, ENDDATE2026-09-03. Новая версия ID53467355: operation30, `1/0`, PREVID52937809. Историческая ID1265449 остаётся. Это обоснованный кандидат REMOVED по полному состоянию, а не по отсутствию в delta.
2. **Малые Пороги, д., OBJECTID755169**, GUID `a2f0099e-8c39-496a-baeb-67067cec965f`. Тот же переход: ID52885243 закрывается, ID53467499 становится текущим `1/0`, operation30. Объект найден в исходном CSV и full.
3. **Юнатов → Юннатов, пер., OBJECTID1130200**, LEVEL8. Full ID53146976 `1/1` имеет старое имя; delta закрывает его и добавляет ID53467507 с новым именем `1/1`. В baseline была ещё историческая строка с похожим новым именем, поэтому поиск по имени вместо object identity неверен. Это реальный пример rename, но не target-населённый пункт.
4. **Восточная, OBJECTID1129297**, LEVEL8: рядом с закрытой версией ID53151975 есть активная ID53467617, operation40. Нельзя удалять объект, увидев одну строку `ISACTIVE=0`.
5. **Киевское, OBJECTID772173**, GUID `0fa69a32-856a-4c08-b907-15505cf375b0`: собственной карточки в delta нет. Full содержит историческую ID936135 `снт / LEVEL6 / 0/0` и текущую ID936168 `тер. СНТ / LEVEL7 / 1/1`. Перестал быть target ещё в 2018 году, не в этом выпуске. Его нельзя считать третьим текущим WDC target по историческому LEVEL6.

Изменения типа среди версий и примеры операций представлены raw samples; конкретного нового действующего target или перехода В target в этом выпуске не найдено. Федеральные города и нетипичные уровни поддержаны predicate, но фактических их изменений здесь нет.

UPDATEDATE основных семейств: min2026-09-03/max2026-09-04. AS_ADDR_OBJ STARTDATE от2014-01-10 до2026-09-04, ENDDATE от2026-09-03 до2079-06-06. Это диапазоны полей, не границы публикации. ID, OBJECTID, OBJECTGUID и CHANGEID сохраняются раздельно. Максимальный ID нигде не выбирается как «актуальный».

## 7. Административная иерархия

Фактические поля: ID, OBJECTID, PARENTOBJID, CHANGEID, REGIONCODE, AREACODE, CITYCODE, PLACECODE, PLANCODE, STREETCODE, PREVID, NEXTID, UPDATEDATE, STARTDATE, ENDDATE, ISACTIVE, PATH. Строк active=52 919, inactive=6 710. Нет ISACTUAL, GUID или уровня в самой связи.

В PATH и parent references всего 82 616 различных ID, включая собственные ID в PATH; 82 312 отсутствуют в AS_ADDR_OBJ delta. Более точная отдельная метрика непосредственных родителей: 16 548 ID, из них 16 439 без карточки в delta. **Это missing-from-delta, не missing-from-GAR**, и множество включает дома/иные сущности общей hierarchy. 56 965 OBJECTID связей не имеют собственной карточки delta.

Реальные примеры:

- Заготовка: relation ID53655054, parent1026332 (Гремячинск), PATH `1446971.1026332.1024738`. В full active1, в delta **тот же ID** active0. ENDDATE при этом остаётся2079-06-06. Одного теста дат для выбора актуальной связи недостаточно.
- Малые Пороги: relation ID44032689, parent751333 (Всеволожский), PATH `749230.751333.755169`; тот же ID переводится active1→0.
- Киевское: прежняя связь ID80406017 `749230.771808.772151.772173`, parent772151 закрывается. Новая ID254990642: `749230.771808.772173`, parent771808, active1. Есть ещё более старая связь ID80406082 через772182. Это наблюдаемое переподчинение без изменения собственной карточки.

Родители 1446971/1026332, 749230/751333, 771808/772151 не появились автоматически в delta. Они извлечены из full, со своими GUID, именами, типами и уровнями. Старый ancestor772182 потребовал дополнительного **группового** прохода AS_ADDR_OBJ двух выбранных регионов; после него missing_sample_ancestor_cards пуст. Не выполнялся повторный проход архива на каждый ID.

109 изменённых карточек используются как непосредственные родители в delta hierarchy. Это преимущественно контекст улиц/территорий и не доказывает 109 изменений родителей WDC-населённых пунктов. В имеющемся CSV совпадений changed GUID с сохранёнными region/city/district GUID **0**. Промежуточные пути CSV не хранит: такой тест не доказывает универсальную полноту обнаружения всех descendants.

## 8. Параметры и выбор версии

Поля PARAM: ID, OBJECTID, CHANGEID, CHANGEIDEND, TYPEID, VALUE, UPDATEDATE, STARTDATE, ENDDATE. PREVID/NEXTID/ISACTIVE в наблюдаемых PARAM не присутствуют. Ключ версии PARAM не равен OBJECTID; несколько параметров одного объекта различаются TYPEID и версиями.

Локальный справочник подтверждает: TYPEID6=ОКАТО, 7=ОКТМО, 10=КЛАДР с признаком актуальности, 11=КЛАДР без признака; 5=почтовый индекс. В delta PARAM наблюдаются 6:343, 7:320, 10:336 строк. Почтовых TYPEID5 строк в этом семействе **0**, но семейство и справочник присутствуют. Другие TYPEID отражены в statistics.json; их не выдаём за нужные WDC fields.

**Особенно важный реальный пример:** PARAM ID1723657066 Заготовки имел VALUE5900000400300, CHANGEIDEND0. Delta содержит тот же ID, но VALUE5900000400399, CHANGEIDEND789996310; ENDDATE по-прежнему2079-06-06. Для Малых Порогов ID1723600581 аналогично меняет КЛАДР на4700500051899. Значит, неизменность record ID не означает неизменность payload. Но суффикс99 сам по себе не выбран правилом удаления WDC: решение опирается на карточку объекта и связи.

Киевское, при отсутствии собственной AS_ADDR_OBJ delta, меняет:

- ОКАТО: ID654090947, VALUE41218000008 закрыт03.09; новый ID1765649654 VALUE41218000000.
- ОКТМО: ID1557832013, VALUE41518000232 закрыт; новый ID1765649655 VALUE41518000.
- КЛАДР: ID11746650, VALUE47007008000000151 закрыт; новый ID1765649656 VALUE47007000000119000.

Всего параметры без собственной карточки меняют92 объекта; ни один не является текущим target данного baseline. Три historical-or-current target ID в PARAM intersection включают историческое Киевское; **current target intersection ровно два**. Это наблюдение выпуска, не разрешение игнорировать parameter-only изменения в следующем.

**Код:** Export-GarPlaces.ps1 выбирает PARAM по STARTDATE/ENDDATE относительно DateTime.Today, при конкуренции по самому позднему STARTDATE. Почтовый индекс намеренно не выводится. В этом исследовании DateTime.Today не используется и temporal selection не выполняется: ReleaseDate сохраняется как provenance, все версии остаются доступны. Поэтому report не подменяет XML-merge фильтром «действует на08.09».

**Предложение:** для будущего projector передавать явный EvaluationDate, сначала overlay версий, затем выбор параметров по согласованной parity-policy. При нескольких разных значениях с одинаковым приоритетом фиксировать conflict, а не выбирать по порядку файла. Не объявлять CHANGEIDEND=0 единственным универсальным условием, не проверив parity. Описанный record overlay не доказывает ещё все правила temporal projection.

Дополнительное наблюдение: справочники используют ISACTIVE="true". Exporter проверяет строку "1" и в этом архиве остаётся на fallback IDs6/7/10, которые совпадают со справочником. Exporter не изменялся. Будущий reader справочника должен явно понимать true/false и1/0, не выдавая отсутствие parsing за отсутствие типа.

## 9. Target predicate и source ownership

**Код / воспроизведено в diagnostic predicate:** PlaceLevels default5,6 OR тип населённого пункта, включая г/город, с/село, д/деревня, п/поселок/посёлок, рп/рабочий поселок/рабочий посёлок, пгт, кп, дп, х/хутор, аул, ст-ца/станица, сл/слобода, м/местечко, нп/населенный пункт/населённый пункт. Federal city LEVEL1 с г/город уже включён этим OR. Predicate нормализует lower/trim/концевые точки, **stored type не переписывается**.

Для current projection нужны ISACTUAL1 && ISACTIVE1; для анализа выхода из target учитываются прежние версии. Synthetic `х. / LEVEL2 / Хёлки` проверяет нетипичный уровень, кириллицу и ё; это не реальная строка архива.

Действующий экспортёр использует только ADM, не MUN. Region = первый LEVEL1 в PATH, self для LEVEL1; city=self для города, иначе nearest LEVEL5 (self если LEVEL5); district=последний district-like ancestor до city/target, исключая внутригородской район после города. Region code: hierarchy, затем первые2 цифры KLADR региона/места. CSV mapper переносит place в settlement; display/search fields производные, не первичные XML fields.

GAR-owned source patch: region_name/code/type; district_name/type/fias_id/kladr_id/gar_object_id/level; city_name/type/fias_id/kladr_id; settlement_name/type; place_name/type/level; kladr_id,okato,oktmo,active. Identity отдельно: country_code,RU; fias_id/gar_object_id/gar_id не мутируются обычным changed patch.

**Не source patch:** postal_code,latitude,longitude,russianpost_courier_calc_postal_code. Existing значения сохраняются независимо от PARAM; NEW-only enrichment остаётся postcode→coordinates→RP courier. Diagnostic не запускает enrichment. display_name/searchable_text пересчитываются будущим существующим formatter после source patch. Aliases не возвращаются.

Категории пересекаются:

| Категория | Наблюдение |
|---|---|
| A, собственные потенциальные targets | 2 OBJECTID, 4 версии, current active target в delta0 |
| B, changed карточки, используемые как прямые родители | 109; совпадений с сохранёнными WDC CSV ancestor GUID0 |
| C, изменения связей | 57 261 OBJECTID; current baseline targets2; есть отдельный реальный контекст772173 |
| D, нужные параметры6/7/10 | 383 OBJECTID; current baseline targets2; 92 объекта любых PARAM без карточки |
| E | Остальные карточки/общая hierarchy/нецелевые сущности; не вычисляется вычитанием пересекающихся A–D |

## 10. Достаточность baseline и sandbox replay

Полный проход AS_ADDR_OBJ: **3 453 173 версии**, 96 XML, 1 047 062 310 uncompressed bytes. Сохранены только ID, на которые ссылается delta: после old-ancestor closure23 459 объектов /46 805 версий. Не сохранялось зеркало всей полной выгрузки.

1 602 546 строк full удовлетворяют текущему active/context predicate (levels1..8/targets/district-like); 161 405 current target rows совпадают по количеству с CSV. Это подсчёт строк, не отдельная глобальная проверка уникальности full.

Выборка для baseline hierarchy/PARAM детерминирована: сначала delta targets, затем затронутые historical targets; получилось3 ID:1024738,755169,772173, регионы47/59. Прочитано5 959 335 hierarchy rows, 1 605 520 847 uncompressed bytes, сохранено4 связи. PARAM scan1 651 208 строк, сохранено56 нужных параметров выбранных объектов/предков. Дополнительный old-ancestor scan197 190 object rows. Другие41ГБ hierarchy целиком не сканировались.

### Результат record overlay

Алгоритм эксперимента: namespace семейства + ID версии; одинаковый ID в delta заменяет baseline payload; конфликт внутри одного входа отклоняется. Неизвестные ID добавляются. Отсутствующие в delta версии сохраняются. Нет max-ID/last-file-wins выбора current.

| Семейство / sandbox scope | Before rows | Delta rows | After version IDs | Повтор/обратный порядок |
|---|---:|---:|---:|---|
| AS_ADDR_OBJ для312 delta OBJECTID | 86 | 360 | 398 | одинаковое состояние |
| ADM для3 выбранных OBJECTID | 4 | 4 | 5 | одинаковое состояние |
| PARAM6/7/10 для3 выбранных OBJECTID | 24 | 8 | 27 | одинаковое состояние |

Before/after raw examples с exact IDs находятся в replay-result.json; минимум rename, closed/current, смена parent и PARAM воспроизведены из реального full. В повторе ничего не добавляется; отсутствие baseline PARAM в delta не удаляет его. Synthetic конфликт одного ID с разным payload вызывает exception. Missing parents перечисляются отдельно, не подменяются NULL-патчем.

**Ограничение:** это sandbox source-version overlay, не полный CSV projector, не доказательство всех parent-impact closure и не готовое применение RU. Enrichment отсутствует в XML replay по природе входа; список исключённых WDC полей явный, но настоящий магазин/snapshot и его сохранность экспериментально не проверялись. Synthetic ownership assertion не выдается за test live enrichment.

### Что достаточно / чего недостаёт

| Компонент | Delta | BaseCsv | Full/source context |
|---|---|---|---|
| Изменённые record versions | Есть | Нет ID версии/CHANGEID | Нужны прежние payloads и registry identities |
| Неизменившиеся родители | Обычно нет | Только выбранные region/district/city labels и часть IDs | Нужны карточки и полный ADM path; реальные примеры перечислены выше |
| Старый/новый parent path | Иногда обе строки, не вся история | Нет PATH | Old chain + reverse ancestor→targets |
| Parameter-only изменения | Есть92 объекта | Есть только текущие значения target | Нужна TYPEID/version context, включая нецелевые кандидаты |
| Выход/вход в target | Карточки версии, если пришли | Только текущий target, без истории | Нужен old classification, пример Киевского доказывает различие |
| Existing enrichment | Не authoritative | В CSV postal пуст, coords отсутствуют | Только будущий live/candidate persistence; не overwrite |
| NEW target с неизменным parent | Здесь не встретился | Родителя может не быть как отдельной строки | Нужен bootstrap; synthetic missing-parent не заменяет реальный пример |

Вариант A может обработать два конкретных удаления этого выпуска при чтении явных флагов. Но он не даёт общего решения: некуда сохранить версионные закрытия, промежуточные paths, параметры нецелевых объектов. **Выбран B.** Это не утверждение, что нужно зеркало домов/помещений GAR.

## 11. Минимальная рекомендуемая source-модель

**Предложение, ничего из перечисленного не создано в WDC schema:**

1. Release ledger: внутренняя версия, заявленная дата, SHA256 архива, предыдущая принятая версия, статус overlay/project/validate/apply, версия projector и явная EvaluationDate.
2. Address-object context: OBJECTID↔OBJECTGUID; record ID, CHANGEID, PREVID/NEXTID, NAME,TYPENAME,LEVEL,ISACTUAL,ISACTIVE,OPERTYPEID,STARTDATE,ENDDATE,UPDATEDATE. Хранить current и необходимые закрываемые версии. Для надёжного первого local parity-прохода проще сохранить версии **AS_ADDR_OBJ**, а не все сущности GAR.
3. ADM context для address-object IDs: relation ID,OBJECTID,PARENTOBJID,PATH,REGIONCODE, version/change/date/active fields и необходимые прочие source codes. Индекс object→relations и ancestor→target descendants. Не хранить hierarchy домов, если ID не относится к address objects и не нужен как ancestor.
4. PARAM context для этих же объектов: ID,OBJECTID,TYPEID,VALUE,CHANGEID,CHANGEIDEND,STARTDATE,ENDDATE,UPDATEDATE. Нужные source types6/7/10, плюс локальные справочники типов/уровней/операций. Не заменять10 на11 без смены согласованного storage contract.
5. Projection mapping: source identity→WDC target identity, old/new ancestor closure, last source projection hash. Это не aliases и не поисковый индекс.

Причина сохранения не только текущих161405 targets: прежний non-target может стать target, а его неизменившиеся параметры не обязаны повториться в delta. Есть компромисс хранения лишь target closure с lazy extraction из сохранённого baseline, но тогда новые promotions становятся unresolved без этого архива. Для автономного обновления рекомендован адресный контекст, без houses/rooms/plots.

Измеренная исходная верхняя заготовка AS_ADDR_OBJ:3.453млн строк /1.047ГБ XML, current/context1.603млн строк. Размер будущего persistent bundle с индексами **ещё не измерен**; нельзя переносить process RSS на размер БД. Однократный bootstrap должен потоково отфильтровать ADM/PARAM по address-object IDs и измерить resulting rows/bytes. Не предлагается заранее сохранять все41.05ГБ hierarchy или71.54ГБ change history full.

Bootstrap: один проход объектов, затем один потоковый проход ADM по нужным IDs, затем PARAM6/7/10; сохранять компактные записи, не whole XML. Построить reverse paths и explicit gaps. Этот процесс локальный, не WordPress migration.

## 12. Проверенные ограничения merge и будущий changeset

**Наблюдение поддерживает следующие правила; production реализация требует parity-теста следующего раздела:**

1. Использовать release identity/hash, а не UPDATEDATE watermark. Overlay v.290 применяется после v.289 целиком для выбранных семейств. Архив уже обработанной версии с другим hash = conflict/manual review; тот же hash = idempotent retry. Правило пропущенного выпуска пока fail-closed, а не применение «самого свежего Actual».
2. Разные families имеют независимые ID namespaces. Внутри release одинаковый ID/разный payload = conflict; между releases новый payload того же ID обязан обновить source record. Не принимать случайный порядок XML за приоритет.
3. AS_ADDR_OBJ current определяется явными флагами после overlay, не MAX(ID). Несколько разных current версий одного OBJECTID/GUID = unresolved. Одна историческая inactive рядом с active не удаляет объект.
4. ADM current по active после overlay; старые пути сохранить для вычисления affected descendants. ENDDATE2079 не перекрывает active0. Не использовать MUN вместо ADM.
5. PARAM отсутствие в delta = preserve. Закрывающая строка может иметь тот же ID и изменённое VALUE; сначала source overlay, потом temporal projection. При отсутствии нового текущего значения не молча обнулять WDC без полной оценки baseline+delta.
6. Affected set = changed objects + relation OBJECTID + needed-param OBJECTID + targets по **старым и новым** parent closures. Перепроецировать этот set через тот же region/district/city/place алгоритм, не считать все отсутствующие в delta removed.
7. NEW: раньше отсутствовал в target projection, теперь подтверждённый current target с полным context. CHANGED: тот же stable identity, изменились только GAR-owned поля. REMOVED: ранее target и после полного overlay подтверждённо inactive/вышел из target, либо другое явно проверенное прекращение. Unknown card/parent/conflict = unresolved, не delete.
8. Для двух current targets здесь есть согласованные inactive current card + deactivated ADM + baseline/CSV identity. Поэтому они **обоснованные кандидаты REMOVED**. Предполагаемый RU projector итог161403 вместо161405 нужно проверить, а не выдавать как уже полученный CSV.
9. Existing enrichment сохраняется; NEW-only enrichment выполняется позднее existing candidate pipeline, не исследовательским reader. AM/BY/KZ/KG вне GAR changeset. Apply в будущем locations-only atomic, без aliases; caches только после успешного apply.

Каталог выпусков описывает URL/версию, не все правила XML merge. Универсальные гарантии полноты произвольной цепочки и конфликтов одной проверенной парой не доказаны; продолжение остаётся локальным parity/replay тестом, не обращением в поддержку.

## 13. Следующий конкретный локальный этап

1. Из имеющегося full v.289 построить compact source bundle для AS_ADDR_OBJ + filtered ADM + PARAM6/7/10 и справочников. Не использовать рабочую БД и не менять exporter.
2. В отдельном диагностическом projector воспроизвести текущие exporter target/type/ancestor rules. EvaluationDate задать явно, например2026-09-08 для сравнения с предоставленным CSV; это тестовая выбранная дата, не доказанная дата прежнего запуска. Сравнить весь CSV по OBJECTID/GUID и source columns; несовпадения разобрать до delta.
3. Применить v.290 к копии source bundle, повторить и изменить порядок входных файлов. Проверить same logical state, source-версии и affected closure. Получить полный source-only changeset; отдельно проверить двух кандидатов REMOVED и отсутствие ложного изменения postal_code.
4. Провести синтетические promotion non-target→target с сохранённым параметром, parent rename/reparent с отсутствующей карточкой ребёнка, PARAM-only target change, конфликт current versions и отсутствующий release. В этом реальном выпуске не все эти сценарии встретились.
5. Только после parity проектировать production delta changeset adapter к candidate pipeline, source ledger/recovery и фоновые шаги. Не переиспользовать full-snapshot absence-as-removed SQL для delta.

Это достаточный конкретный план реализации следующего **локального тестового инструмента**; исследование SPAS не возобновляется.

## 14. Измерения, проверки и воспроизведение

Финальный реальный прогон `-final`: elapsed16.9567s внутри inspector, baseline phase11.1254s; peak working set422944768bytes (около403.4MiB), managed allocation snapshot255262920bytes. Эти значения измерены на данной машине, не прогноз hosting/WP. Компиляция C# до создания inspector не входит в elapsed; CSV обработан внутри общего времени. Повторные прогоны могли воспользоваться OS cache; это не cold-disk benchmark.

Delta-only первый прогон:2.90s, peak350908416bytes. Нельзя экстраполировать его на произвольно большой выпуск. XmlReader потоковый, но выбранные core delta records агрегируются в памяти для анализа всех версий; нет гарантии постоянного RSS для огромной delta. SampleLimit ограничивает примеры и baseline cohort, а не размер самой delta. Полная RU иерархия baseline и все ZIP entries на CRC не проверялись; разобранные XML прочитаны до конца.

Проверки: PowerShell parse; Add-Type compile; synthetic ZIP; несколько версий/active рядом с inactive; точные ID9007199254740993/4; `Хёлки` и типх.; LEVEL2 target; missing parent; missing family сpresent=false; malformed XML и DTD fail-closed; idempotent Merge/conflict; baseline overlay в трёх families с сохранением отсутствующих rows. Synthetic archive builder и входы не используют сеть. Реальные before/after и отсутствие DB writes отделены от synthetic assertions.

Команда повторного запуска, **OutDir должен быть новым или пустым**:

```powershell
pwsh -NoLogo -NoProfile -NonInteractive -File ".\tools\diagnostics\Inspect-GarDelta.ps1" `
  -DeltaArchive "D:\fias\delta\gar_delta_xml.zip" `
  -BaseArchive "D:\fias\gar_xml_full.zip" `
  -BaseCsv "D:\fias\out\gar_places.csv" `
  -OutDir "$env:TEMP\wdc-gar-delta-recheck" `
  -ReleaseDate "2026-09-08" `
  -SampleLimit 10

pwsh -NoLogo -NoProfile -NonInteractive -File ".\tests\locations\run-gar-delta-diagnostics-smoke.ps1"
```

На машине исследователя использован реальный pwsh.exe из `C:\Users\mszai\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\powershell\pwsh.exe`, не WindowsApps alias.

Production/regression PHP не запускались: production не менялся, задача запрещает bootstrap WordPress/БД. Исходный Export-GarPlaces.ps1 неизменён, версия0.155.16 неизменна. Commit/push/PR не выполнялись.

Финальный synthetic smoke прошёл; `git diff --check` прошёл (только предупреждение Git о будущем LF→CRLF в tree.txt). Diff production путей пуст. Итоговый Git status:

```text
 M tree.txt
?? docs/research/gar-delta-file-investigation.md
?? tests/locations/run-gar-delta-diagnostics-smoke.ps1
?? tools/
```
