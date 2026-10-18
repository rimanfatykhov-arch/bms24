# KBA Corporate Dashboard 0.21.1 — Test Report

Build: `2026090240`

## Local checks

- Source workbook extraction reconciles to 15 areas and 137 unique criteria.
- Demonstration answers and personal/company example values are not included in the template JSON.
- Install XML and additive upgrade fields are structurally checked.
- RU/EN key parity and static language references are checked.
- PHPUnit coverage is supplied for idempotent template installation and equal-area BMS Index calculation.
- ZIP root and compressed-data integrity are checked during packaging.

## Moodle acceptance required

1. Upgrade a copy of v0.21.0 to v0.21.1.
2. Open `BMS tool library` for a company and click `Install the Point A template`.
3. Confirm 15 areas and 137 criteria are created once.
4. Create a baseline audit and verify the score selector runs from 0 to 9.
5. Confirm the 0 and 9 endpoint descriptions match the workbook.
6. Score criteria in at least two areas and verify area averages and the normalised BMS Index.
7. Verify `Needs data` excludes an item from the score and blocks confirmation.
8. Verify `Not applicable` excludes an item and permits confirmation.
9. Confirm that existing ordinary 0–5 audits retain their previous calculation.
10. Verify company isolation with two companies.

PHP lint and runtime Moodle PHPUnit cannot be executed in the current build environment because PHP/Moodle runtime is unavailable.
