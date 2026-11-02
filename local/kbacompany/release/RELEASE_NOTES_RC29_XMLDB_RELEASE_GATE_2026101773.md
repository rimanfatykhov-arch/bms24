# BMS24 1.1.0-rc29 — build2026101773

## XMLDB release safety gate

- Added fail-closed validation of XMLDB table names (maximum 28 characters) both in `db/install.xml` and literal `new xmldb_table()` calls in `db/upgrade.php`.
- Added a regression test that rejects the exact invalid table name responsible for the 1769 migration failure.
- Wired both checks into canonical `cli/build_release.py` before packaging.
- No schema or functional changes; existing working data retained. New Moodle plugin version checkpoint added.
- Static QA only; live Moodle upgrade not tested.
