# Test report — 0.18.1

Build: `2026090212`

## Automated coverage added

- Assessment coverage and target readiness calculations.
- Distinction between an unassessed requirement and level 0.
- CSV formula-injection protection.

## Required on Moodle server

- Run PHP lint and PHPUnit.
- Verify paging with more than 50 target requirements.
- Compare screen and CSV rows under each filter.
- Verify export denial without `local/kbacorporate:exportreport`.
- Verify company and department-manager isolation.
