# BMS24 build2026101876 — invariant machine dates after Battle Plan incident

Date: 2026-11-02. Release remains `1.1.0-rc29`, pending separate RC30 acceptance.

## Why this change

After the user confirmed the live `battleplan_date` hotfix in build1875, a static audit
found further `userdate($timestamp, '%Y-%m-%d')` / HTML datetime usages. Moodle's
`userdate()` produces localized display text and is not a safe source of ISO 8601
machine values, even when a fixed-looking format is specified.

## Changes

- New `classes/local/business_date_service.php`: date, yesterday and HTML
  `datetime-local` helpers using `core_date::get_user_timezone_object()` and
  `DateTimeImmutable`, never locale-specific text rendering.
- Replaced 35 vulnerable call sites across canonical Task Center date buckets,
  management cycle defaults, Battle Plan metric fact dates, staffing forms,
  deviation date comparisons, BMS Calendar and Google Calendar projections,
  meeting datetime fields and communication message day separators.
- `yesterday()` subtracts a **civil calendar day** rather than `DAYSECS`,
  avoiding misclassification around daylight saving time.
- Preserved all user-facing localized date labels, company ACL, stored facts,
  business logic, data schema and existing `battle_plan_service::user_today()`.
- Build guard tests scan all PHP sources for accidental reintroduction of
  locale-dependent machine dates. New local PHP CLI regression scenarios and
  Moodle PHPUnit test class added; PHPUnit tests are *not* claimed executed.
- GitHub CI now runs the business-date guard and current candidate packaging.
- Version-only upgrade savepoint `2026101876`; no DB migration.

## Installation & acceptance

1. Back up Moodle database, `moodledata` and `local/kbacompany`.
2. Install the **full** `INSTALL_local_kbacompany_1.1.0-rc29_build2026101876.zip` archive.
   Apply Moodle plugin upgrade at `/admin/index.php`, purge caches and PHP OPcache.
3. Verify BMS Workspace, Task Center overdue/today/week buckets, Battle Plan
   metric fact date, calendar day grouping and event editor, deviation register,
   management cycle new form, staffing plan and communication labels.
4. Test Monday 2026-11-02 and appropriate user timezone near UTC midnight.
5. Confirm date-related values are ISO `YYYY-MM-DD` and HTML `YYYY-MM-DDTHH:MM`
   while human-readable labels remain localized.
6. Roll back only from verified database+files backup if required.

## Limits

No server access/deployment, real Moodle runtime, PHP 8.1 runtime, production
MySQL, Behat or Moodle PHPUnit was available during local construction.
The user's successful live verification applies to **build1875** only.
