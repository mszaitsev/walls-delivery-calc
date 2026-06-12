# WDC CDEK Insurance Audit

Version: 0.46.0.

## Scope

This audit covers CDEK insurance/declared value behavior for the current tariff-management stage. It does not implement CDEK order creation.

## Documentation Findings

The local CDEK API HTML documentation contains the control phrase `Удаление подписки по UUID` and the calculator section `Расчет стоимости доставки`.

Relevant methods:

- `POST /v2/calculator/tarifflist` - current WDC runtime quote calculation.
- `GET /v2/calculator/alltariffs` - available/current tariffs by contract, used by the new tariff sync.
- Order payload package item field `cost` is documented as the declared item value; the docs state that insurance is calculated from this value.
- Calculator responses include `delivery_sum`, `services[].sum`, `services[].total_sum` and overall `total_sum`.
- Order payloads support `services[]`; the documentation history notes that for delivery orders, if an insurance service is not passed, CDEK may add it automatically with a parameter that makes insurance free for the relevant process.

## Current Runtime State

WDC currently sends calculator requests with package weight and dimensions only:

- `type = 1`
- `currency = 1`
- `from_location`
- `to_location`
- `packages[].weight/length/width/height`

The current quote stage does not send package item lines or declared item `cost` to `/v2/calculator/tarifflist`.

## Interpretation

Based on the documentation, CDEK insurance is tied to declared value (`packages.items[].cost`) and/or order services, not to the tariff directory itself. The tariff list sync (`GET /v2/calculator/alltariffs`) does not contain insurance amounts.

For quotes, the safest future behavior is:

- keep `delivery_sum` as the base delivery amount;
- inspect calculator `services[]` and `total_sum` when declared values are added to requests;
- use `total_sum` only after confirming whether it includes delivery plus services for the active contract;
- never assume the tariff directory price includes insurance.

## Recommended Next Work

For `feature/cdek-order-creation`:

1. Pass item declared values (`cost`) in CDEK order packages when creating shipments.
2. Decide whether quote calculation should include declared values before checkout display.
3. If quote calculation includes declared value, compare request A with declared cost `0` and request B with declared cost `10000` using the same city, package weight and dimensions.
4. Record whether CDEK returns insurance in `services[]`, `delivery_sum`, or only `total_sum` for the real/test contract.
5. Store insurance details in hidden calculation data, not visible shipping item meta, unless a product requirement explicitly asks to show it.

## Out Of Scope

- CDEK order creation.
- Statuses.
- Webhooks.
- Print forms.
