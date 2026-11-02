# BMS24 date contract — build2026101876

## Machine values vs display

- Machine/business dates (DB lexical comparisons, ISO date controls, Calendar
  `day_key`, URLs and filters) **must be ASCII Gregorian** `YYYY-MM-DD`.
- `datetime-local` inputs **must be** `YYYY-MM-DDTHH:MM`, regardless of UI locale.
- Keep `userdate()` for **display labels only**, e.g. localized dates and time.
- Convert a Unix timestamp to the *current Moodle user's timezone* before
  generating the day or time; don't use `gmdate()` blindly.
- Use `business_date_service::day($timestamp)` for day keys and defaults.
- Use `business_date_service::datetime_local($timestamp)` for HTML datetime.
- Use `business_date_service::yesterday($timestamp)` for the prior civil day,
  not `time()-DAYSECS` (DST transition can make this off by one day).
- Business date comparison remains based on validated ISO values. The service
  must never modify stored historical data while converting dates.

## Regression and deployment

Run `python tools/test_business_dates_1876.py` in source checkout/CI. It executes
14 isolated PHP cases, scans source code and enforces 7 critical callers.
`tests/business_date_service1876_test.php` must also be run as part of real
Moodle PHPUnit on the staging server. The isolated script is not a substitute
for Moodle + database integration or manual user acceptance.

The existing build1875 emergency fix is kept intact. Staging/production
rollout requires backed-up database and files and explicit human approval.
