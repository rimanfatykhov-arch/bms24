# BMS24 — build2026101874 / BMS Dev AI: GitHub + Timeweb infrastructure foundation

- Package layout remains `kbacompany/`, Moodle version `2026101874`, release
  `1.1.0-rc29` (RC30 awaiting real server acceptance).
- Full cumulative PHP Moodle plugin; **exclude 58 development-only Python
  scripts** from installation archive, retain them in source Git repo.
- GitHub workflow: tests and version-specific deterministic ZIP build,
  SHA-256 manifest verification, candidate upload for pushes to main only.
- Read-only Timeweb staging host inventory and manual deployment checklist;
  no remote server access, deployment or credentials created.
- Safety gate for ZIP contents (`tools/verify_install.py`) and offline tests.
- `db/upgrade.php`: version savepoint 2026101874 only. No schema or data changes.
- PHP 8.1 and Moodle staging PHPUnit have not been run in this environment;
  GitHub CI has not yet been executed on the linked remote repository.
