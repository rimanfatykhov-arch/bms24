# RC28 / build2026101468 — BMS Cloud catalog foundation

- Read-only catalog contract with four products and namespaced feature/limit codes.
- Separate plan and subscription concepts; Cloud owns pricing, purchased seats, and enforcement revisions.
- `tariff_catalog.php` visible only to verified BMS24 company administrators; linked from roles page.
- Existing `entitlement_service` and snapshot remain the runtime access authority. Legacy tenants unchanged.
- No invented seat limits, no prices, no invitations or signup, no new DB schema, no enforcement.
- QA: static PHP syntax and packaging; live Moodle and Cloud billing integration NOT RUN.
