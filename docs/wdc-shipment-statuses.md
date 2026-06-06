# WDC Shipment Statuses

Version: 0.36.2.

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

Version 0.36.1 corrects the first mapping import for Russian Post pickup/courier operations:

- `8:2` and related pickup operations `8:9`, `8:10`, `8:14`, `8:27`, `8:28`, `8:33`, `8:35`, `8:42`, `8:43`, `8:56`, `8:57`, `8:58`, `8:59` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `12:1..12:31` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `42:1..42:30` map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`;
- `8:15` and `8:18` map to `handed_to_courier` / `передан курьеру`;
- unknown operation/attribute pairs remain `unknown` / `не определён`.

Version 0.36.2 adds the no-attribute fallback: if an exact `operation_type_id:operation_attr_id` key is absent, the mapper tries `operation_type_id:-`. Empty, absent, `0`, and `-` attributes therefore share the same no-attribute mapping. This maps Russian Post `28:0`/`28:''`/`28:-` to `created_in_carrier` / `создан в ТК`, and `46:0`/`46:''`/`46:-` to `cancelled` / `отменён`. Unknown no-attribute operations still map to `unknown` / `не определён`.

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

After a successful shipment create, the preparation modal closes, WDC shows a local success toast for 10 seconds, and the first status refresh starts automatically through the same `wdc_update_shipment_status` action. If that automatic refresh fails, creation remains successful and the metabox shows `Отправление создано, но статус пока не обновлен: ...`.

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

The metabox shows Russian labels for both the creation/status summary and the universal delivery status. Otpravka `result-id` is not shown and is not stored in shipment state because status refresh uses barcode/ШПИ.

Automatic polling/synchronization is not part of version 0.36.2.
