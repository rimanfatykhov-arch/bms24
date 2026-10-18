# TEST REPORT — v0.31.0

Status before pilot: **Prepared / static release-gate checks passed; Moodle runtime pilot required.**

## Static checks
- PHP syntax: PASS.
- ZIP root: PASS (`kbacorporate/` only).
- ZIP integrity: PASS.
- Numeric build increased: PASS.
- `lang/en` and `lang/ru`: PASS.
- Database migration: not applicable; no schema changes.
- Destructive migration: none.

## Pilot regression checklist
1. Open Business Roles and confirm the new matrix button is visible.
2. Open matrix with a company context.
3. Filter by one role; requirements must match the role profile.
4. Select an employee assigned to business roles.
5. Verify current level / target / GAP against My Competencies.
6. Verify mapped company courses are shown for competencies.
7. Verify an existing learning plan is linked when present.
8. For an employee with GAP and no active plan, create a plan and verify it opens normally.
9. Confirm no role, competency, course or learning-plan records are modified merely by viewing the matrix.
