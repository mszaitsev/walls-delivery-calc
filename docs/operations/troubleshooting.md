# Troubleshooting

Version: 0.131.7

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

## PEK Schema Recovery

If PEK diagnostics report `PEK location mapping lookup failed`, first verify the physical tables with the active WordPress prefix:

```sql
SHOW TABLES LIKE 'wp_wdc_pek_location_mappings';
SHOW TABLES LIKE 'wp_wdc_pek_terminals';
SHOW COLUMNS FROM wp_wdc_pek_location_mappings LIKE 'mapping_precision';
```

Version 0.131.7 expects the physical mapping precision column to be `mapping_precision`; the domain/API mapping key remains `precision`. Migration `0050` repairs missing PEK foundation tables, and migration `0051` backfills `mapping_precision` from any legacy physical `` `precision` `` column. Failed migrations are not marked applied and `wdc_db_version` is not advanced; after fixing the DB issue, run the normal plugin update/migration lifecycle again. Do not create PEK tables from diagnostics or repository read/write methods.
