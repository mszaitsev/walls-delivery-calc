# План перехода к новой архитектуре

Archived/not-runtime as of 0.20.0: this plan documents the completed migration away from legacy `includes/*`. Current runtime is `src/` only and fresh-install oriented.

## Что сохраняем из текущей базы

- Функциональное поведение текущего расчета международной Почты России.
- Формулу тарифа: `ceil( api_price_rub / formula_divider + formula_add_rub )`, затем скидка от суммы товаров и `max( 1, ... )`.
- Fallback rate с нулевой стоимостью, если расчет невозможен и fallback включен.
- Настройки `wdc_settings` как legacy source of truth на первом этапе.
- Packaging tiers и расчет веса как существующее поведение.
- Справочник стран Почты России и country overrides.
- Сохранение meta в заказ через WooCommerce CRUD.
- WooCommerce logger source `walls-delivery-calc`.
- Classic shortcode checkout как единственный поддерживаемый checkout flow.

## Что переносим в новую архитектуру

- `WDC_Carrier_Interface` как основу carrier adapter contract.
- `WDC_Carrier_Registry` как идею registry/capability map.
- `WDC_Quote_Normalizer` как идею единого quote/rate DTO, но без hardcoded Russian Post defaults.
- `WDC_Weight_Calculator` в domain/calculation layer.
- `WDC_Cache` в infrastructure/cache.
- `WDC_Russian_Post_API` в adapter Почты России.
- `WDC_Russian_Post_Countries` в отдельные сервисы: API client, normalizer, repository/cache, override resolver.
- `WDC_Order_Meta` как compatibility writer/reader для старых заказов.

## Что нужно изолировать

- `WDC_Shipping_Method` от конкретного `WDC_Russian_Post_Carrier`.
- Meta schema заказа от конкретной Почты России.
- Admin UI от business logic country overrides.
- API diagnostics/logging от персональных и технически чувствительных данных.
- Legacy settings schema от новой carrier/service configuration.
- Fallback behavior от carrier adapter, чтобы rule engine мог принимать решение централизованно.

## Что переписываем позже

- Shipping method orchestration.
- Admin UI settings architecture.
- Order meta schema для multi-carrier/multi-shipment.
- Carrier registry/capability model.
- Quote/rate DTO.
- Статусы доставки и shipment lifecycle.
- ПВЗ/map flow.
- Rule engine.
- Calendars.
- ФИАС/ГАР address normalization.
- API clients с retry/backoff/redaction.

## Какие изменения лучше делать первыми

1. Добавить тестовый контур вокруг текущего legacy behavior: расчет веса, fallback, meta mapping, Russian Post quote normalization.
2. Ввести core platform/autoload/namespaces без изменения runtime behavior.
3. Объявить и проверить HPOS compatibility только после теста classic и HPOS order screens.
4. Выделить legacy Russian Post adapter за стабильный interface.
5. Ввести DTO/value objects параллельно старым arrays, с adapter layer для обратной совместимости.
6. Централизовать logging redaction.
7. Вынести admin inline JS/CSS в assets с enqueue.

## Какие изменения опасно делать без тестов

- Любые изменения `WDC_Russian_Post_Carrier::get_quote()`.
- Изменение формулы цены, округления или скидки.
- Изменение fallback rate id/label/cost.
- Изменение shipping rate meta keys `wdc_quote_summary`, `wdc_rate_summary`, `wdc_raw_meta`.
- Изменение order meta keys `_wdc_*`.
- Изменение country mapping и Russian Post country overrides.
- Изменение cache key для tariff API без migration/clear strategy.
- Изменение WooCommerce hook priority/signature.
- Добавление HPOS compatibility declaration без проверки admin metabox behavior.

## Рекомендуемый порядок перехода

### 1. Core platform

- Ввести Composer/PSR-4 или внутренний autoloader.
- Создать namespaces и service bootstrap.
- Сохранить старые классы как compatibility facade или загрузить их через legacy bridge.
- Добавить feature flags для новой архитектуры.
- Добавить HPOS compatibility declaration после проверки.

### 2. Domain model

- Описать value objects/DTO:
  - address/destination;
  - package/parcel;
  - carrier;
  - service;
  - rate;
  - quote;
  - pickup point;
  - shipment;
  - delivery status.
- Сделать adapter между текущими arrays и новой model.
- Зафиксировать order meta schema version.

### 3. Calendars

- Ввести два календаря как отдельные domain services:
  - календарь РФ/ТК;
  - календарь магазина.
- Не подключать их к расчету до появления тестов и правил.
- Сначала использовать для eligibility/available dates, затем для SLA/ship date.

### 4. FIAS/GAR

- Ввести address normalization boundary.
- Не смешивать ФИАС/ГАР с carrier-specific city IDs.
- Спроектировать кеш и manual override для адресных сопоставлений.

### 5. Rule engine

- Вынести условия доступности служб, стран, веса, календарей и ручных правил в отдельный слой.
- Начать с read-only evaluation поверх текущего результата.
- Затем перевести fallback/eligibility в rule engine.

### 6. Checkout rates

- Заменить direct Russian Post call в `WDC_Shipping_Method` на rate orchestrator.
- Сохранить classic shortcode checkout.
- Для Blocks checkout явно оставить unsupported guard.
- Добавлять новые carriers только через adapter interface.

### 7. Pickup map

- Спроектировать pickup point entity и selection persistence.
- Подключать map только после готовности checkout state persistence.
- Отдельно решить UX для ПВЗ разных ТК.

### 8. Admin recalculation

- Добавить admin action/service для перерасчета доставки заказа.
- Требуются nonce/capability/idempotency и audit trail.
- Не перезаписывать старую meta без snapshot/history.

### 9. Shipments

- Ввести shipment aggregate отдельно от order shipping rate.
- Хранить external shipment IDs/statuses/events.
- Добавить idempotency keys и retry-safe API calls.
- Продумать отмену/повторное создание отправления.

### 10. Carrier adapters

- Перенести Почту России в adapter v1.
- Затем добавлять СДЭК, DPD, Yandex.Доставка и ручные службы как независимые adapters.
- Для каждого adapter описать capabilities:
  - rates;
  - pickup points;
  - courier;
  - shipment creation;
  - labels/docs;
  - statuses;
  - calendars;
  - auth/secrets.

## Риски миграции

- Потеря совместимости с уже сохраненными `_wdc_*` meta в заказах.
- Расхождение итоговой цены доставки из-за округления или изменения источника веса.
- Нарушение fallback behavior и checkout conversion.
- Слом country mapping при переносе справочника Почты России.
- Лишние API-запросы при изменении cache key/TTL.
- HPOS admin metabox может вести себя иначе на разных версиях WooCommerce.
- Новые adapters потребуют хранения секретов, которых сейчас нет; нужна безопасная settings strategy.
- Rule engine может стать слишком широким, если внедрять его до domain model и тестов.

## Блокеры перед большой доработкой

- Нет тестов текущего расчета и meta persistence.
- Нет официальной HPOS compatibility declaration и проверки.
- Нет стабильной multi-carrier quote schema.
- Нет политики логирования/redaction.
- Нет схемы хранения секретов для будущих API carriers.
