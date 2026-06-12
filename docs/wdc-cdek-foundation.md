# WDC CDEK Carrier Foundation

Version: 0.46.0.

0.46.0 update: CDEK tariff management now builds on the foundation API client. `CdekApiClient::allTariffs()` calls `GET /v2/calculator/alltariffs`, the admin `Тарифы` tab stores synced tariffs in a dedicated table, and runtime tariff labels/types can be managed without changing OAuth or credential handling.

0.45.0 update: CDEK pickup points are implemented in `docs/wdc-cdek-pickup-points.md` through `GET /v2/deliverypoints`. Pickup rates now require selecting a pickup point, and the selected CDEK point is written to the WooCommerce shipping address. CDEK order creation, statuses, webhooks and print forms are still intentionally out of scope.

0.44.0 update: CDEK tariff runtime is now implemented in `docs/wdc-cdek-tariff-calculation.md`. The foundation OAuth/settings layer remains the base for runtime calls, while pickup points, pickup selection, orders/shipments, statuses, print forms and webhooks are still intentionally out of scope.

This document describes the foundation for CDEK API v2 integration. Checkout tariff calculation was added later in 0.44.0; pickup maps, shipment creation, statuses, print forms, autosync and webhooks remain unimplemented.

## Scope

Implemented:

- Delivery service metadata key: `cdek`.
- Future delivery types: `pickup` and `courier`.
- Admin credentials tab labeled `Данные для входа`.
- Environment switch: `test` / `production`.
- Separate encrypted credentials for test and production environments.
- OAuth client for `POST /v2/oauth/token`.
- Token cache with expiry and a 60 second safety margin.
- Admin connection check that only obtains an OAuth token.
- Smoke test coverage with fake HTTP client and no real CDEK requests.
- Runtime tariff calculation foundation consumers: `CdekApiClient`, `CdekLocationResolver`, and `CdekCarrier`.
- Pickup point runtime through `GET /v2/deliverypoints`, documented in `docs/wdc-cdek-pickup-points.md`.
- Tariff directory sync through `GET /v2/calculator/alltariffs`, with editable tariff presentation in the CDEK delivery service admin tab.

Not implemented:

- `POST /v2/calculator/tariff` by fixed tariff code.
- Orders/shipments.
- Shipment statuses.
- Print forms.
- Webhooks.

## API

CDEK API v2 base URLs:

- Test: `https://api.edu.cdek.ru`
- Production: `https://api.cdek.ru`

OAuth endpoint:

- `POST /v2/oauth/token`

The request body currently uses the standard CDEK v2 OAuth form format:

```text
grant_type=client_credentials
client_id={account}
client_secret={secure_password}
```

The API client keeps this body construction isolated in `CdekOAuthTokenService` so it can be adjusted if CDEK changes the documented format.

## Settings

Core settings keys:

- `cdek_environment`
- `cdek_test_account`
- `cdek_test_secure_password_encrypted`
- `cdek_production_account`
- `cdek_production_secure_password_encrypted`
- `cdek_last_connection_check`
- `cdek_last_connection_status`
- `cdek_last_connection_message`

The active environment selects both base URL and credentials:

- `test` uses `https://api.edu.cdek.ru`, `cdek_test_account` and `cdek_test_secure_password_encrypted`.
- `production` uses `https://api.cdek.ru`, `cdek_production_account` and `cdek_production_secure_password_encrypted`.

The admin password fields are intentionally empty after save; submitting them empty keeps the saved secrets. Switching the active environment does not copy, clear or mutate credentials for the other environment. CDEK service enablement is controlled only by the common delivery service tab `Основное`, not by the credentials tab.

For development compatibility only, test credentials can fall back to old `cdek_account` / `cdek_secure_password_encrypted` values when the new test keys are empty. New saves write only the environment-specific keys.

Diagnostics messages are redacted for account, secrets, and token-like fragments.

## Token Cache

The token cache key includes environment and account hash, so test and production tokens are not mixed even when the account value is the same. Cached payloads contain the access token and calculated expiration timestamp. `clearTokenCache()` removes the cached token and is used before connection checks and after settings saves.

## Connection Check

The admin action "Проверить подключение" is protected by the existing delivery services capability and nonce. It loads saved CDEK settings, requests an OAuth token for the active environment only, stores timestamp/status/message, and never stores the token in diagnostics.

Success message:

```text
Подключение к СДЭК успешно проверено.
```

Error message format:

```text
Не удалось подключиться к СДЭК: {короткое сообщение}
```

Diagnostics include the active environment, for example:

```text
Среда: Тестовая
Среда: Рабочая
```

## Async API Note

CDEK uses asynchronous processing for part of API v2. A response such as `ACCEPTED` means the request has been accepted for processing, not that the entity was definitely created. This foundation only documents that behavior; order processing and polling are intentionally deferred.

## Roadmap

Future stages should be separate branches:

- `feature/cdek-tariff-calculation`: checkout tariff calculation through `/v2/calculator/tarifflist` or `/v2/calculator/tariff`.
- Done in `feature/cdek-pickup-points`: pickup points through `GET /v2/deliverypoints`.
- Next: `feature/cdek-order-creation`.
- Orders: `POST /v2/orders`, `GET /v2/orders`, `GET /v2/orders/{uuid}`, `PATCH /v2/orders`, `DELETE /v2/orders/{uuid}`, `POST /v2/orders/{uuid}/refusal`.
- Print forms: `/v2/print/orders` and `/v2/print/barcodes`.
- Webhooks: `POST /v2/webhooks`, `GET /v2/webhooks`, `GET /v2/webhooks/{uuid}`, `DELETE /v2/webhooks/{uuid}`.
