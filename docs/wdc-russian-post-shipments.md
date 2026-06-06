# WDC Russian Post Shipments

Version: 0.37.1.

Russian Post shipment creation stores two identifiers:

- barcode / ШПИ: primary tracking number, shown to managers and used by Tracking API;
- `backlog_order_id`: hidden technical Otpravka backlog id, parsed from create-response `result-id` or manual backlog search `id`.

Documents, labels, batches and F103 are not generated or downloaded by WDC. Managers create the batch and download documents manually in the Russian Post account.

Cancellation:

- API: `DELETE /1.0/backlog`;
- body: JSON array of internal ids, for example `[2285075494]`;
- identifier: `backlog_order_id`;
- available only when the latest Russian Post operation is `28 / Присвоение идентификатора`;
- successful cancellation clears shipment state so the order can be prepared again.

Manual ШПИ attachment:

- API: `GET /1.0/backlog/search?query={barcode}`;
- identifier entered by manager: barcode / ШПИ;
- returned `id` is saved as `backlog_order_id`;
- state source is `manual_tracking_attach`;
- WDC attempts the first Tracking API status refresh after saving.

Manual tracking attachment in 0.37.1:

- first lookup: `GET /1.0/backlog/search?query={barcode}`;
- fallback lookup when backlog search is empty: `GET /1.0/shipment/search?query={barcode}`;
- state source is `manual_tracking_attach`;
- state lookup source is `backlog_search` or `shipment_search`;
- returned `id`, when present, is saved as `backlog_order_id`;
- if shipment search returns barcode but no `id`, WDC still saves tracking and runs Tracking API by barcode;
- cancellation remains disabled when `backlog_order_id` is absent.
