# TEST REPORT — v0.31.2

Status: Prepared / requires runtime pilot in target Moodle.

Static release gate:
- PHP syntax: PASS (all plugin PHP files).
- XML well-formedness: PASS for db/install.xml.
- ZIP root: PASS (`kbacorporate/` only).
- ZIP integrity: PASS.
- Numeric build increased to 2026090608: PASS.
- lang/en and lang/ru: PASS.
- Database migration: none; upgrade savepoint only.
- Destructive migration: none.

Functional scenarios to confirm in pilot:
1. Select employee with GAPs → preview shows competencies/current/target/GAP/course/action.
2. Apply with no open plan → KBA draft and Moodle Learning Plan are created.
3. Re-run on same draft → idempotent; no duplicate plan/items.
4. New GAP on draft → item is added and Moodle competency membership is updated.
5. Resolved GAP with old plan item → old item is retained, not deleted.
6. Pending/active KBA plan → membership change blocked; sync-only remains possible.
7. User without development-plan capability cannot use builder.
