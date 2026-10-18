# TEST REPORT — v0.31.4 Course Coverage Integrity

**Status before production pilot:** static validation passed; Moodle runtime pilot required.

## Release gate

- ZIP root `kbacorporate/` — PASS
- Component `local_kbacorporate` — PASS
- Numeric build increased to `2026090614` — PASS
- Release metadata `0.31.4` — PASS
- `lang/ru` and `lang/en` — PASS
- Database schema change — NONE
- Destructive migration — NONE
- PHP syntax check — PASS (`201/201` PHP files)
- XML install schema validation — PASS
- ZIP integrity — PASS
- SHA-256 — generated
- Upgrade savepoint `2026090614` — present

## Static regression scope

1. Existing course coverage page still adds Moodle core course-to-competency relations idempotently.
2. Role and company boundaries remain enforced.
3. Domain nodes `BMS-Dxx` remain excluded from working role requirements.
4. Hidden courses remain excluded from active company coverage.
5. Integrity page does not write data.
6. Integrity findings link back to the relevant role/course pages.
7. Existing GAP and Moodle Learning Plan logic is not modified.

## Moodle runtime pilot cases

1. Open a business role with working competencies and choose **Course coverage integrity**.
2. Verify total and mandatory coverage match the coverage editor.
3. Verify mandatory competencies without courses are listed separately.
4. Verify supporting competencies without courses are listed separately.
5. Verify an active company course with no competency relation appears under **Courses without competencies**.
6. Add one course-to-competency mapping through the existing coverage page; refresh integrity and verify the relevant count decreases.
7. Verify hidden courses and inactive company-course assignments are shown as unavailable catalogue mappings and do not count as company coverage.
8. Verify no records are changed by refreshing or reopening the integrity page.
9. Verify the role status becomes **Ready** only when all role competencies are covered and no integrity anomalies remain.

## Known limitations

- Duplicate core Moodle relations are reported but not automatically repaired.
- Out-of-scope relations are reported but not automatically removed because the Moodle relation is global to the course.
- A course with competencies unrelated to the selected role is not classified as “without competencies”; this check is intentionally course-global.
