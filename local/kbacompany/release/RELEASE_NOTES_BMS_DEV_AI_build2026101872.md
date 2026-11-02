# BMS24 build2026101872 — BMS Dev AI foundation

**Component:** `local_kbacompany` **release:** `1.1.0-rc29`.

- Added externally supplied local Git repository and developer policy (`AGENTS.md`).
- Added six least-privilege AI developer role descriptions in developer repository.
- Added machine-verifiable deny-by-default policy and isolated policy tests.
- Added deterministic cumulative ZIP builder (standard Python, no credentials).
- Added CI workflow source for PHP 8.1 syntax, regression static checks and
  deterministic ZIP verification. CI has not been connected to a remote.
- `version.php` and RC30 technical acceptance expected version updated to
  2026101872, with no-schema-change savepoint in `db/upgrade.php`.
- Domain logic, database schema, permissions, task/Battle Plan UI unchanged.

**Install:** use INSTALL ZIP, keep backups, run Moodle upgrade and cache purge.
**Acceptance:** RC30 is not promoted; real staging tests are outstanding.
**Separation:** `.github/`, developer agent policies and Git history are
shipped in the separate developer kit, **not** in the installable ZIP.
