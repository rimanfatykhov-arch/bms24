# TEST REPORT — v0.31.5 Employee GAP Calculation

## Release Gate

- Component: `local_kbacorporate`
- Build: `2026090615`
- Minimum Moodle: 4.0 (`2022041900`)
- ZIP root expected: `kbacorporate/`
- Database schema changes: none
- Destructive migration: none

## Static checks

- PHP syntax check: **204/204 PHP files passed** before packaging.
- `db/install.xml` unchanged from v0.31.4.
- `db/upgrade.php` contains savepoint `2026090615`.
- Russian and English strings added for every new UI element.
- New PHPUnit regression class: `tests/employee_gap_test.php`.

## Regression cases defined

1. Employee without assigned business roles returns explicit `noroles` state.
2. Confirmed Moodle competency grades are converted to current levels.
3. Unknown current level remains `null` and is not treated as level zero.
4. Known target/current values produce the correct numeric GAP.
5. Mandatory, supporting and critical counters are separated.
6. Highest role target and strongest priority continue to come from `target_profile`.
7. Status/priority/type filters do not mutate calculation data.
8. Opening the report performs no writes.

## Manual pilot procedure

1. Open `Employee GAP` for a company employee with an assigned business role.
2. Confirm assigned role names and target-profile competency count.
3. Verify that unassessed competencies show `Not assessed`, not current level zero.
4. Confirm one competency with a known current level below target and verify the numeric GAP.
5. Confirm one target-achieved competency.
6. Verify mandatory/supporting counts and priority labels.
7. Verify course recommendations only show actionable company courses.
8. Open competency audit and GAP Learning Plan preview from the report.

## Runtime status

Static validation is performed in the build environment. Moodle runtime and PHPUnit execution remain to be confirmed on the pilot server after installation.
