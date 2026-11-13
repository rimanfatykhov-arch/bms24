# RC29 build2026101779 — Coordination HTTP 500 diagnostic hotfix

Adds site-administrator-only `/local/kbacompany/battle_plan_week_coordination_diag.php` with isolated, read-only tests for overview, readiness, coverage and coordination services. Shows caught exception type, file and line to admins only; writes diagnostic reference to PHP error log. No DB structure changes. Does not claim that HTTP 500 itself has been resolved. Live Moodle: NOT RUN.
