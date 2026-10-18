# Test Report — KBA Corporate Dashboard 0.17.2

Date: 2026-09-01  
Build: `2026090103`  
Scope: Competency Audit

## Local release gate

- XML parsing and install schema version: PASS (`2026090103`, 14 tables).
- Install/upgrade table parity for `local_kbacorp_audit` and `local_kbacorp_audit_item`: PASS (24 fields checked).
- English/Russian language key parity: PASS (929 keys each, no duplicates).
- Version/release/savepoint consistency: PASS (`0.17.2`, build/savepoint `2026090103`).
- Conflict markers and whitespace errors: PASS.
- Structural delimiter scan for all PHP files: PASS (101 files).
- Literal plugin language references: PASS (726 references resolved).
- Full ZIP root/integrity: PASS (`kbacorporate/`, 188 entries, no unsafe paths).

## Added automated coverage

- Preliminary assessment does not create/update `competency_usercomp`.
- Confirmed assessment grades through Moodle core and completes the audit.
- Rejected assessment does not grade the competency.
- Company deletion removes audit items before audit/company parents.
- Privacy metadata declares both new personal-data tables.

## Runtime tests required on Moodle 4.0

The current build environment has no PHP executable, Moodle database or browser runtime. The following remain required on the target server:

1. `php -l` for every PHP file.
2. Moodle PHPUnit suite including `competency_audits_test.php`.
3. Upgrade from production build `2026090102` to `2026090103`.
4. MySQL/MariaDB creation of both new XMLDB tables and indexes.
5. Corporate-manager company isolation and rejection of department-only/full-company boundary violations.
6. Confirmation with and without `moodle/competency:competencygrade`.
7. Pending, confirmed and rejected audit rendering in **My competencies**.
8. Privacy export/delete and employee/company lifecycle deletion.

No runtime PASS is claimed by this report.
