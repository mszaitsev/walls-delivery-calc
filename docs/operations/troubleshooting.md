# Troubleshooting

Version: 0.131.6

Start with:

```bash
php tests/shipments/run-shipment-regression-profile.php --list
php tests/shipments/run-shipment-regression-profile.php --group=framework
php tests/shipments/run-shipment-regression-profile.php
```

If a carrier shipment UI issue appears, check in this order:

1. adapter registration in `CarrierShipmentAdapterRegistry`;
2. persistence mapper registration in `ShipmentCreationService`;
3. document/modal registries if UI buttons or modal fields are missing;
4. AJAX controller nonce/capability/order/carrier validation;
5. generic JS payload key `document_actions` and carrier extension hooks.

Never expose carrier credentials or full raw API errors in admin messages.
