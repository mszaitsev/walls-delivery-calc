# DPD Shipment Buttons

DPD shipment actions are driven by `DpdShipmentButtonPolicy` and persisted shipment data, not by transient AJAX-only state.

## 0.67.2 policy notes

- No local DPD shipment: show `Создать отправление DPD` and `Внести номер DPD вручную`.
- Pending registration without a DPD number: show `Обновить статус` and `Удалить из заказа`.
- Shipment with a DPD number: always show `Обновить статус`.
- EventCode `1001`, `1101`, `1201`, `1401`, `1501`: show `Отменить отправление в DPD` and hide local remove.
- EventCode `1301` and other non-cancellable states: hide cancel and show `Удалить из заказа`.

The policy reads both DPD-specific `dpd_event_code` and generic `carrier_operation_code`, so reload after a saved `1401 / OrderCreate` keeps the cancel button visible without requiring a fresh AJAX status update. DPD document/label actions are intentionally absent.
