# RC29 build2026110890 — Issue44 schema parity repair candidate

Source-only candidate, NOT approved for deployment. Based on approved ADR-0002 and PR47.
Adds a reachable XMLDB repair for missing task.starttime, task.duetime and deviation.executionstatus. Existing values and compatible definitions are preserved; incompatible definitions stop before DDL. Savepoint occurs only after validation. No DML or role changes.

Candidate build2026110890 is greater than observed stage41 build2026110802. Production BMS1878/Corporate2613 was observed read-only; its DB is Percona8.0.46-37. The stopped old-test database is unobserved, so global version uniqueness is not established. Do not install this older feature tree over stage41: it omits later functionality and contains Corporate2610 while stage41 already has2613. Port the repair into the final reconciled feature tree before deployment; never downgrade Corporate.

Version.php, strict acceptance identity, final savepoint, source/workflow gates and CI artifact naming move together. RC30 remains HOLD. Detailed checks and inventory: docs/reconcile/ISSUE44_SCHEMA_REPAIR.md in repository source.

Rollback requires matched code and database backup/restore; do not edit Moodle version records or restore old code alone. No server deployment performed.
