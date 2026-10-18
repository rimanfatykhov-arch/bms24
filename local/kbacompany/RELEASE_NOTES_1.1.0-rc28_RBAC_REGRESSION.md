# RC28 — RBAC Regression Checkpoint — build2026101464

Based on RC28/build2026101463. No business logic or DB schema changes.
Added PHPUnit regression for view/edit/create separation, combined grants, immediate revocation, grant removal, and no implicit access to employees or documents.
Previous CEO permission templates remain manually assigned only. Cross-company tests from RC28 remain.
Static checks do not substitute for Moodle PHPUnit or live authorization acceptance: NOT RUN.
Before 1.1.0 FINAL test company roles, direct URLs, actions and API in staging.
