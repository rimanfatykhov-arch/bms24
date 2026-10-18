# API Contract v0.34.1

No new external web-service function is published.

The internal read model returned by `economic_dashboard::build()` contains:

- filter state: company, category, periodicity, date range, audit and verification;
- selected indicator IDs;
- summary counters;
- `panels[]` with definition, values, latest actual, previous actual, plan, forecast, deltas, currencies and chart arrays;
- optional `auditcontext` with current/previous audit values and comparison rows.

The projection is designed for later reuse by management dashboards and ERP-facing analytics, but it is not declared as a stable public API in `v0.34.1`.
