# BMS24 1.1.0-rc23 / build2026101350 — Performance / Large Company

Baseline: RC22/build2026101349. Scope: performance stabilization only.

* Communication Center screen_context (messages/discussions/unread): replace 2 per-row DML reads with read-state JOIN and unread count in paginated SELECT. Unread formula and object ACL check retained. On 25 rows saves up to 50 round trips, but not verified as faster on live MySQL.
* No table, index, domain-data, permissions or business API changes. Upgrade savepoint only.
* Pending: EXPLAIN, realistic data benchmark, high-scale pagination and total counts, full multi-company security and functional regression.
* Live Moodle/production MySQL: NOT RUN. RC23 cannot be accepted based on PHP lint alone.
