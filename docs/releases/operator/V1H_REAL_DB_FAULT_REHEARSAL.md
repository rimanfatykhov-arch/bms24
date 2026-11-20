# V1h — Disposable real MySQL crash rehearsal

Status: **isolated CI test; NOT a Moodle production migration**. This phase is a stacked draft PR on V1g #139. It is intentionally unable to connect to production.

## What actually runs

New workflow: .github/workflows/bms-operator-disposable-db-fault.yml

On GitHub-hosted Ubuntu 22.04 it launches pinned Percona 8.0 and MariaDB 10.11 service containers. Using the isolated root password literally set to `isolated-ci-only`, it creates its own randomly named ephemeral databases under a strictly validated `bmsci_fault_` prefix.

The test performs real InnoDB SQL:
1. Create and fill synthetic legacy and history tables; calculate deterministic row fingerprints.
2. Run real `mysqldump --single-transaction` into a runner-private 0600 file with fsync, then restore it into a distinct synthetic database.
3. Verify independent restored database row hashes.
4. Spawn a Python child that fsyncs a PRE_DDL marker and terminates itself with SIGKILL; verify no DDL took effect and original rows survived.
5. Spawn another child that fsyncs DDL_INTENT **before** actually issuing CREATE TABLE new_report; terminate with SIGKILL immediately after MySQL confirms the DDL.
6. Verify actual partial schema on the source while original rows and independently restored snapshot remain intact.
7. Remove only the random disposable databases, dump and markers from the isolated runner (no production connections).

No SQL dump, credentials, config.php, business rows or private data are uploaded to GitHub artifacts. The logged report contains only synthetic checksums, state, and engine version. Full DB password is CI-only synthetic test input.

The script refuses to run unless all of GITHUB_ACTIONS=true, CI=true, BMS_FAULT_REHEARSAL=1, DBHOST=127.0.0.1, DBPORT=3306, DBUSER=root, DBPASS=isolated-ci-only and a /home/runner/work/_temp private directory are present. It rejects non-random or unsafe DB names. No SSH/deploy/production tooling is invoked.

## What this does NOT prove

This test uses actual SQL and real process SIGKILL on isolated databases. It does **not** test installing Moodle, pausing real Moodle web/API/cron writers, exact R1/R2 five-table schema, backing up code/config/moodledata, provider quota, full recovery, or a production release window. `status=HOLD, deployable=false` remains true even when these two engine tests pass.

The existing #124 CI already rehearses full synthetic Moodle install/restore. This new phase adds **partial-DDL process-death evidence** on real SQL engines, not a substitute for the missing end-to-end live-site migration adapter.

## Run instructions

The dedicated GitHub Actions workflow is CI-only and read-only with respect to the repository and all real sites. Its only writes are isolated synthetic CI databases and runner scratch. Test logs must be reviewed for actual success.

The authorized production operator must still perform Issue #140 on a real isolated Moodle clone with continuous writer pause, fresh full consistent backup, independent complete restore, phase-aware recovery and a current baseline/host quota receipt. No automatic R0 code-only downgrade after any DDL.

**No changes to main/production, no SSH keys and no publication performed by this PR.**
