# TEST_REPORT — v0.31.2.3

## Environment
Static release gate in build container. Moodle runtime smoke-test requires installation on pilot `edu.kzn.mba`.

## Checks
- PHP syntax: all plugin PHP files — PASS.
- version.php numeric build = 2026090611 — PASS.
- release string = 0.31.2.3 — PASS.
- lang/en and lang/ru present — PASS.
- db/upgrade.php savepoint 2026090611 present — PASS.
- install.xml unchanged from v0.31.2.2 — PASS.
- ZIP root contains one top-level directory `kbacorporate/` — PASS.
- ZIP integrity — PASS.
- no destructive migration on upgrade — PASS.
- bulk update code validates row ownership, ignores BMS-Dxx rows and target range 0..9 — PASS (static review).
- bulk update preserves competency membership/source and updates only target/priority/required/timemodified — PASS (static review).

## Runtime test required
1. Open role `Собственник` → «Массовая настройка профиля роли».
2. Confirm 16 working competencies and current summary.
3. Change supporting competencies to target 3 / priority core / mandatory No.
4. Save and confirm summary e.g. 12 mandatory / 4 supporting.
5. Re-open role profile and verify competency membership remains 16.
6. Open role matrix and confirm GAP uses the updated targets.
