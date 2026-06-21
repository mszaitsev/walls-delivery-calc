# DPD Documents 0.69.0

DPD document download is available from the WooCommerce order `Отправления` block through the `Скачать документы` action. The action is intentionally narrow: it is shown only when the order has a DPD shipment, the shipment has `dpd_order_number`, and `dpd_event_code` is strictly `1401` (`OrderCreate`). Statuses `1001`, `1101`, `1201`, `1301`, `1501`, delivered, cancelled, returned and unknown states do not show the action.

## DPD Web Services

Invoice PDF uses the existing DPD `order2` web service:

- test WSDL: `https://wstest.dpd.ru/services/order2?wsdl`
- production WSDL: `https://ws.dpd.ru/services/order2?wsdl`
- SOAP method: `getInvoiceFile`
- wrapper: `request`
- order field: `orderNum`

The request sends auth and `orderNum` only. `parcelCount` and `cargoValue` are deliberately omitted so DPD takes those values from the order stored in its system.

Label PDF uses section 7, `Веб-служба "Печать Наклейки"`:

- test WSDL: `https://wstest.dpd.ru/services/label-print?wsdl`
- production WSDL: `https://ws.dpd.ru/services/label-print?wsdl`
- SOAP method: `createLabelFile`
- request wrapper: `getLabelFile`
- order field: `order.orderNum`
- parcel count field: `order.parcelsNumber`

The label request sends auth, `fileFormat=PDF`, `pageSize=A6`, `order.orderNum`, and `order.parcelsNumber`. A6 is the DPD quarter-A4 sticker layout from the label-print documentation.

## Download Flow

The admin download endpoint validates nonce, admin capability, `order_id`, `carrier=dpd`, the DPD shipment, `dpd_order_number`, and strict EventCode `1401`. It then requests both PDFs from DPD and creates one temporary ZIP:

- `dpd-documents-order-{order_id}-{dpd_order_number}.zip`
- `dpd-invoice-{dpd_order_number}.pdf`
- `dpd-label-a6-{dpd_order_number}.pdf`

The ZIP is streamed with `application/zip`. The individual files are validated as PDFs before being added. DPD responses may arrive as raw PDF bytes, base64 PDF strings, byte arrays, or nested SOAP response fields; empty files, non-PDF payloads, SOAP faults, transport failures and business errors stop the download.

Documents are not saved to order meta and no shipment status fields, including `tracking_checked_at`, are changed by the download flow. Temporary files are removed after streaming or after any partial failure.

## Pickup Date Error

DPD may return the business error `У заказа дата забора ранее текущей даты` while creating the label. This is surfaced to the administrator as:

`Наклейка DPD недоступна: у заказа дата забора ранее текущей даты.`

When this happens no partial ZIP is downloaded, no document is stored, and the shipment status is not changed.