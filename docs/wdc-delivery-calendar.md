# WDC Delivery Calendar

## Scope

This stage adds delivery calendars and planned delivery date calculation only. It does not add checkout orchestration, carriers, rule engine, pickup maps, external APIs, shipment export, runtime shipping logic, REST API, AJAX frontend, admin recalculation, or legacy settings migration.

## Calendars

The platform supports two calendar types:

- `carrier_ru`: Russian carrier calendar. Saturday and Sunday are generated as non-working days.
- `shop`: shop processing calendar. Saturday is generated as working, Sunday is generated as non-working.

Calendar type constants live in `WallsShop\WDC\Calendar\CalendarTypes`.

## Database

Migration `database/migrations/0001_create_calendar_days_table.php` creates `wdc_calendar_days` through `dbDelta()` with the current WordPress database charset/collation.

Columns:

- `id`
- `calendar_type`
- `calendar_date`
- `is_working`
- `reason`
- `created_at`
- `updated_at`

Indexes:

- unique key on `calendar_type, calendar_date`
- index on `calendar_date`

Applied migrations are tracked in `wdc_applied_migrations`; the current DB version is tracked in `wdc_db_version`.

## Timezone And Cutoff

All date calculation uses `Asia/Novosibirsk`.

The order cutoff is 19:00 local time. If an order date is after or equal to 19:00, the effective order date becomes the next calendar day. Example: `2026-05-20 20:00 Asia/Novosibirsk` becomes `2026-05-21`.

## Generation Logic

Initial generation creates year 2026 for both calendars if those years do not already exist. The daily Action Scheduler task checks on December 1 whether the next year exists. If it is missing and `auto_generate_next_year` is enabled, the year is generated and an admin attention notice is registered.

Generated reasons are:

- `weekday`
- `weekend`

The repository also accepts manual reasons such as `manual`, `holiday`, and `generated` for admin edits and future imports.

## Delivery Date Calculation

`DeliveryDateCalculator` uses:

- `CalendarService`
- `TimezoneService`
- Domain `DateRange`
- Domain `PlannedDeliveryDate`

Algorithm:

1. Normalize the order date with the 19:00 Novosibirsk cutoff.
2. Add shop processing days using the `shop` working calendar.
3. Treat the resulting date as the carrier handoff date.
4. For carrier `calendar_days`, add the carrier days as calendar days.
5. For carrier `working_days`, add carrier working days using `carrier_ru`; the handoff day itself is not counted.
6. Calculate min and max separately when the `DateRange` is a range.
7. Return a `PlannedDeliveryDate` with a human readable Russian comment.

## Admin Editing

The admin page is available under WooCommerce as `WDC Calendars`.

It supports:

- selecting calendar type;
- selecting year;
- generating a default year;
- editing a month grid;
- toggling working/non-working state;
- editing day reason;
- saving the whole year through POST.

The save flow uses nonce and `manage_options` capability checks. UI assets live in:

- `assets/admin/calendar-admin.css`
- `assets/admin/calendar-admin.js`

## Action Scheduler

`CalendarScheduler` registers the `wdc_calendar_generate_next_year` hook and schedules a daily recurring task through the existing Action Scheduler wrapper. On December 1, missing next-year calendars are generated and marked as requiring admin attention.

## Future Integration Points

Future stages can consume this layer from:

- checkout flow;
- carrier implementations;
- rule engine;
- admin recalculation tools.
