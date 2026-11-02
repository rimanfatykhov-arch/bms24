# RC29 build2026101506 — metric identity hardening

- Legacy `indicators.php` POST `metricprofile` could submit a modified `code` to `product_service::update_statistic` and alter the canonical metric code. Now the service rejects any submitted code different from the existing persisted Stable Code and never writes to the `code` column during update.
- Existing codes, UUIDs, facts, plans, norms, bindings remain unchanged. Create still automatically assigns a code.
- Company-scoped query and permission checks remain in the canonical service.
- No database schema changes.
- Regression tests: static assertions and full PHP lint. Running actual Moodle/PHPUnit/Behat and cross-company role matrix requires a configured Moodle installation; NOT RUN.
- Other metric routes remain to be audited; this is not final security acceptance.
