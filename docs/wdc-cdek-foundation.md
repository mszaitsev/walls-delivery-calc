# WDC CDEK Carrier Foundation

Version: 0.43.0.

This stage adds the foundation for CDEK API v2 integration without connecting CDEK to checkout rates, pickup maps, shipment creation, statuses, print forms, autosync, or webhooks.

## Scope

Implemented:

- Delivery service metadata key: `cdek`.
- Future delivery types: `pickup` and `courier`.
- Admin settings section/tab for CDEK.
- Environment switch: `test` / `production`.
- Encrypted storage for Secure password / client_secret.
- OAuth client for `POST /v2/oauth/token`.
- Token cache with expiry and a 60 second safety margin.
- Admin connection check that only obtains an OAuth token.
- Smoke test coverage with fake HTTP client and no real CDEK requests.

Not implemented:

- Checkout rates.
- `POST /v2/calculator/tarifflist` and `POST /v2/calculator/tariff`.
- Pickup points and pickup map from `GET /v2/deliverypoints`.
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

- `cdek_enabled`
- `cdek_environment`
- `cdek_account`
- `cdek_secure_password_encrypted`
- `cdek_last_connection_check`
- `cdek_last_connection_status`
- `cdek_last_connection_message`

`cdek_secure_password_encrypted` stores the encrypted Secure password. The admin password field is intentionally empty after save; submitting it empty keeps the saved secret. The diagnostics message is redacted for account, secret, and token-like fragments.

## Token Cache

The token cache key includes environment and account hash, so test and production tokens are not mixed. Cached payloads contain the access token and calculated expiration timestamp. `clearTokenCache()` removes the cached token and is used before connection checks and after settings saves.

## Connection Check

The admin action "Проверить подключение" is protected by the existing delivery services capability and nonce. It loads saved CDEK settings, requests an OAuth token, stores timestamp/status/message, and never stores the token in diagnostics.

Success message:

```text
Подключение к СДЭК успешно проверено.
```

Error message format:

```text
Не удалось подключиться к СДЭК: {короткое сообщение}
```

## Async API Note

CDEK uses asynchronous processing for part of API v2. A response such as `ACCEPTED` means the request has been accepted for processing, not that the entity was definitely created. This foundation only documents that behavior; order processing and polling are intentionally deferred.

## Roadmap

Future stages should be separate branches:

- `feature/cdek-tariff-calculation`: checkout tariff calculation through `/v2/calculator/tarifflist` or `/v2/calculator/tariff`.
- Pickup points: `GET /v2/deliverypoints`.
- Orders: `POST /v2/orders`, `GET /v2/orders`, `GET /v2/orders/{uuid}`, `PATCH /v2/orders`, `DELETE /v2/orders/{uuid}`, `POST /v2/orders/{uuid}/refusal`.
- Print forms: `/v2/print/orders` and `/v2/print/barcodes`.
- Webhooks: `POST /v2/webhooks`, `GET /v2/webhooks`, `GET /v2/webhooks/{uuid}`, `DELETE /v2/webhooks/{uuid}`.
