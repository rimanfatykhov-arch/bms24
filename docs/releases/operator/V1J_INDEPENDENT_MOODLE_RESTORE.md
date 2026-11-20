# R1/R2 — independent restore of the exact disposable Moodle snapshot

**Status: separate QA rehearsal, NOT production installation.** This acceptance branch forks the **unchanged #124 SHA** `30a720c18f231a70bdb08cd65e8cba8206d62f7b`. The original accepted #124 PR and its immutable install ZIP are not edited.

## Scope

The existing `tools/acceptance/pr90/rehearsal.py` already starts a real disposable Moodle 4.1.22 + PHP 8.1 on MariaDB or Percona, captures the full synthetic site (code, config.php, moodledata, SQL), installs the candidate plugin, and restores the snapshot back into the original test site.

This QA increment adds a **second, independent restore** immediately after the snapshot and before any plugin upgrade:

1. Validate that the full code tree (including `config.php`), complete `moodledata` and saved SQL file have not changed since capture.
2. Copy code/config and moodledata into distinct private filesystem directories under the isolated runner.
3. Create a second synthetic database `bmsci_independent_restore`, import the **same backup SQL bytes**, re-dump the *entire restored database* and compare exact output to the original SQL dump.
4. Re-hash the independent code/config and moodledata trees and verify they match the captured baseline.
5. Drop only the new synthetic database and remove the independent clone. Keep the accepted original snapshot and existing PR90 full restore/upgrade path unchanged.

The independent clone is **intentionally not booted** because its original `config.php` references the source site/DB: the test validates snapshot fidelity, not isolation of a live running clone. We do not claim the clone is a separate operational Moodle or that its integrations are disabled. No production/user DB, real moodledata or keys are read; only synthetic GitHub Actions test data is used.

The resulting report adds `steps.independent_full_site_restore=PASS` and a limited public summary with file counts and synthetic SQL digest, never the DB contents or `config.php`.

## Test paths

`tools/acceptance/pr90/independent_restore.py` implements the actual separate filesystem+DB restore in the disposable rehearsal.
`tools/acceptance/pr90/test_independent_restore.py` has fast fail-closed tests for changed SQL, config.php, fake successful restore, wrong snapshot root, pre-existing clone directory, wrong DB endpoint and failed SQL setup.

`.github/workflows/pr90-disposable.yml` invokes unit tests in Python normal and -O and the existing full Moodle rehearsal on **both** engines, using the new acceptance helper. The candidate package still contains `local/kbacompany` only; no product PHP, theme, SQL upgrade or Corporate/Core code has changed.

## Important limitation

This is a stronger **real disposable Moodle full-backup fidelity** check, **not** the currently missing production test of a continuously blocked web/API/cron writer, hosted account quota, SSH trust, approved maintenance window, real credentials or whole-site restart from the independently restored clone. It also does not perform SIGKILL during real Moodle DDL; V1h tests genuine SQL DDL crash only on synthetic tables.

Consequently, even if CI PASS, R1/R2 #124 remains on production HOLD until the authorized server operator executes Issue #140 with verified current baseline, real isolated site full restore, writer freeze, operator lock and DDL recovery. The accepted #124 ZIP SHA256 remains `07a0c7f71b4246e08d19683449dacd18da5b79914f7aac8849aa1ad60a69c69e` and is not rebuilt for production by this PR.

No change to `main`, production, existing server backup or original #124 accepted artifact.
