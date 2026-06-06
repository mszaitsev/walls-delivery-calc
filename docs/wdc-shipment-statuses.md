# WDC Shipment Statuses

Version: 0.36.0.

## Universal Status Model

WDC uses carrier-neutral shipment statuses so future delivery services can share the same state model:

| Code | UI label |
| --- | --- |
| `created_in_carrier` | `создан в ТК` |
| `in_transit` | `в пути` |
| `ready_for_pickup` | `ожидает самовывоза из ПВЗ/постамата` |
| `handed_to_courier` | `передан курьеру` |
| `delivered` | `доставлен` |
| `returning_to_sender` | `возвращается отправителю` |
| `returned_to_sender` | `возвращен отправителю` |
| `cancelled` | `отменён` |
| `rejected` | `отказ` |
| `unknown` | `не определён` |

The implementation lives in `src/Domain/Status/DeliveryStatus.php`.

## Russian Post Tracking

Manual status refresh uses Russian Post Tracking API single access:

- endpoint: `https://tracking.russianpost.ru/rtm34`;
- WSDL: `https://tracking.russianpost.ru/rtm34?wsdl`;
- method: `getOperationHistory`;
- SOAP: 1.2;
- request fields: `Barcode`, `MessageType=0`, `Language=RUS`, `AuthorizationHeader.login`, `AuthorizationHeader.password`.

The client is `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php`. It uses `wp_remote_post` and does not require external Composer dependencies.

Tracking credentials are stored in the unified Russian Post domestic service settings:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

They are separate from Otpravka AccessToken/login/password and from the Tariff API token.

## Russian Post Mapping

`src/Shipments/RussianPost/RussianPostTrackingStatusMapper.php` contains the fixed mapping generated from the attached `status pocha.xlsx` table. Runtime does not read Excel.

Mapping key:

- `operation_type_id`;
- `operation_attr_id`.

If the pair exists, WDC saves the mapped universal status and the terminal flag from the table. If the pair is absent, WDC saves `unknown` / `не определён`.

The raw Russian Post status is always preserved:

- operation type id/name;
- operation attribute id/name;
- operation date;
- operation address;
- operation index.

## Manual Metabox Refresh

The WooCommerce order metabox `Отправления` uses the existing `Обновить статус` button. The button is enabled only when the shipment is created and has a barcode.

AJAX action:

```text
wdc_update_shipment_status
```

The status block shows:

- `Статус в плагине`;
- `Статус Почты России`;
- `Последняя операция`;
- `Проверено`;
- `Barcode`.

Automatic polling/synchronization is not part of version 0.36.0.
