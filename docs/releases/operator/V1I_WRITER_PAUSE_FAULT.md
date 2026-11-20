# V1i — controlled writer pause failure experiment (disposable CI only)

This is **NOT a Moodle or production executor**. It extends draft #141 (real disposable SQL backup/restore and SIGKILL after DDL). Both stages must remain explicitly limited to isolated CI. Do not merge these experiments into the production release procedure without separate acceptance.

## Actual experiment

The runner executes real Linux processes rather than mocks. The controller launches two synthetic file writers ('synthetic_web' and 'synthetic_cron') under a temporary private GitHub-hosted runner directory, waits until both are actively appending synthetic heartbeat lines, then sends SIGSTOP to each worker.

The controller confirms each worker is in kernel process state T/t, fsyncs a private receipt containing the PIDs and sizes at stop, and kills **itself** with SIGKILL. A separate test harness verifies the durable marker survived, both worker processes are still stopped after controller death and their heartbeat files do not advance over a real measured delay. Finally the harness explicitly resumes and terminates its own synthetic writers.

No database, SSH connection, Moodle web server, cron, queue, user data, hosting storage or production process is accessed. The entire workload is a model of how a fail-closed pause could behave, not proof of a production enforcement mechanism. The result always includes `status=HOLD`, `deployable=false`, `real_moodle_writer_pause_verified=false`, `server_wide_lock_verified=false` and `production_backup_restore_verified=false`.

## Gate and CI

The test refuses to run outside Ubuntu/Linux GitHub Actions unless the exact environment is present: CI=true, GITHUB_ACTIONS=true, BMS_WRITER_PAUSE_TEST=1, and RUNNER_TEMP underneath /home/runner/work/_temp. The dedicated workflow `.github/workflows/bms-operator-writer-pause-fault.yml` is PR-only and permissions contents:read. It runs targeted unit tests in normal and -O Python, then exercises actual SIGSTOP/SIGKILL.

No artifact upload, no cloud keys, no SSH credentials, no deploy command. Synthetic heartbeat receipts are local to the disposable runner.

## Remaining operational proof in Issue #140

A production-compatible design must block **all** write paths (Moodle web/API, scheduled cron, PHP workers, queues, CLI, integrations and direct DB writers) for the whole critical interval and must actually stop in-flight writes, not merely pause two known PIDs. It must use an independently supervised lock/lease with persistent recovery and demonstrate real backup + independently restored DB, code, config, moodledata and DDL fault handling on an isolated copy of Moodle 4.1.22 with Percona 8.0. Quotas and account identity must be verified at the hosting provider.

The accepted immutable R1/R2 release #124 remains a separate production HOLD; E2/UX-1 #135 remains separately awaiting dev review. No production deployment or merge is performed by this phase.
