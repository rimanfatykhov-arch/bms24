# TEST REPORT — v0.31.3

Status before pilot: prepared / static validation passed; Moodle runtime pilot required.

## Release gate
- ZIP root: `kbacorporate/` — PASS
- Numeric build increased to `2026090613` — PASS
- Release metadata `0.31.3` — PASS
- lang/ru and lang/en — PASS
- DB schema change — NONE
- Destructive migration — NONE
- PHP syntax check — PASS
- ZIP integrity — PASS
- SHA-256 — generated

## Runtime pilot cases
1. Open Role → `Покрытие компетенций курсами`.
2. Verify role counts and current coverage.
3. Verify only active visible company courses appear in selectors.
4. Link one company course to one uncovered working competency.
5. Refresh and verify coverage increases by one and the relation is shown.
6. Repeat the same relation and verify idempotent `already exists` behavior.
7. Open Role ↔ Competency ↔ Learning Plan ↔ Courses matrix and verify the course appears for that competency.
8. Open GAP Learning Plan Preview for an employee with that GAP and verify the course is recommended when the learner has an actionable enrolment/licence path.

No relation removal is included in this release.
