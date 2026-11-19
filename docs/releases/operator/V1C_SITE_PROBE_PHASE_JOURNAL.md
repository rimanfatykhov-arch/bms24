# BMS Release Operator V1c — local Moodle probe and durable failure model

**Status: draft, read-only server probe and synthetic phase journal. NOT a production deploy tool.**
V0 / V1a / V1b remain dependent draft PRs. Nothing in V1c grants release permission, changes services, executes Moodle upgrade, or provisions credentials.

## 1. Read-only local site probe

File: tools/release_operator/site_probe.php

This standalone PHP CLI probe does not include Moodle config.php or bootstrap Moodle sessions. It accepts a local Moodle source directory and storage-volume directory, and reads a private connection JSON from stdin (never from command-line flags, chat, Git, or the report). A hosting administrator must provision a **separate SELECT-only database account** restricted to loopback access. The probe sets a READ ONLY transaction before querying only plugin config versions, five report-table names and database server version/comment. The code directories are read to extract code version markers and deterministic SHA256 trees; the filesystem is not changed.

Template invocation **on an authorized server, not executed in this PR**:

    BMS_RELEASE_READONLY_PROBE=1 php tools/release_operator/site_probe.php \
       /PRIVATE/MOODLE/ROOT /PRIVATE/BACKUP/VOLUME \
       < /PRIVATE/VAULT/SELECT_ONLY_CONNECTION.json \
       > /PRIVATE/RECEIPT/server-probe-v1c.json

Never copy the private input JSON or resulting infrastructure paths into Git. Paths shown above are illustrative, not actual server paths. The input is an ordered JSON object with keys host, port, dbname, user, password, prefix; allowed DB hosts are 127.0.0.1, localhost and ::1 only. The probe does not print or store the credentials or customer data. It fails closed on missing inputs, unreadable files, unexpected symlinks or inaccessible database. The report explicitly says HOLD / origin_authenticated=false.

**Important limits**
- The probe returns a **partial, raw** schema bms24.release_operator.server_probe.v1c. This is not the authenticated V1b server_inventory.v1b document. Feeding it straight into V1b must fail.
- Tree hash algorithm bms24-relative-path-null-file-sha256-v1 is new. Its hashes are NOT comparable to previously reported runtime-tree SHA256 pins until a trusted baseline is recorded using the same algorithm. Never relabel either hash.
- Free inode capacity, actual account quota, CLI maintenance marker, external cron, writer quiescence and the operator lock are **NOT_MEASURED**. The provided storage path's disk_free_space result is not proof that all backup volumes have capacity.
- Both PDO SQL and static code hashing are read-only by design. However, no real production or isolated Moodle runtime proof has been performed and the running DB user grants are not under this PR's control.
- No authentication/signature or protection from forgery is implemented; V1d must add an attested trusted delivery path.

## 2. Durable phase journal and recovery model

File: tools/release_operator/phase_journal.py

This is a local-only, simulated state machine. It cannot execute any external commands, including maintenance, cron, backup, schema migration or restore. It writes a 0600 append-only JSONL file in a private 0700 directory. A local nonblocking flock serializes cooperative model processes; it is **not** the future server-wide release lock or a continuous writer-pause lease. Each record includes a monotonically increasing sequence, a SHA256 hash chain, a synthetic target and a simulation flag. Each append is fsynced, and the parent directory is fsynced. Corrupt, incomplete or modified records are rejected rather than repaired automatically.

Simulation phases, in enforced order:

1. INIT
2. OPERATOR_LOCK_ATTESTED (symbolic model event only)
3. WRITER_PAUSE_ATTESTED (symbolic model event only)
4. FRESH_BACKUP_ATTESTED (symbolic model event only)
5. ISOLATED_RESTORE_ATTESTED (symbolic model event only)
6. DDL_INTENT — persistent fence **before any future DDL execution**
7. UPGRADE_ATTESTED (symbolic)
8. SMOKE_ATTESTED (symbolic)
9. SIMULATED_COMPLETE (still HOLD)

Recovery guidance is conservative:
- Before DDL_INTENT: PRE_DDL_HOLD, independently verify no DDL before any code revert or writer resume.
- After DDL_INTENT and before upgrade verification: POST_DDL_MAINTENANCE_HOLD; no code-only downgrade.
- After verified upgrade: keep compatible 1601 code, feature OFF unless separately authorized; no automatic DB restore.
- At any phase: no automatic writer resume, no automatic restore, no deployment authorization.

This journal must **not** be attached to a real production command runner yet. It is not cryptographically authenticated and a malicious host writer could forge the unkeyed hash chain; it only detects inadvertent corruption and models crash-consistent local writes.

## 3. Tests

Existing V0 read-only PR workflow runs:

    python -m unittest discover -s tools/release_operator -p 'test_*.py' -v

New negative cases include skipping or repeating phases, changed release identity, local lock collision, damaged/truncated journal, forged event, and subprocess SIGKILL immediately after durable pre-DDL/DDL intent writes. Site probe testing is limited to actual PHP lint and fail-closed CLI invocations **without connecting to any database**.

Local test outcomes / CI statuses must be reported separately from live server tests. A SIGKILL test of this simulated journal does not prove production pause-lease safety or rollback behavior.

## 4. Next acceptance and implementation milestone V1d

1. Provision a non-admin, read-only database identity and protected transport from the Moodle host; independently audit the host implementation. No credentials or private paths in Git/chat.
2. Bind server inventory to an authenticated host identity, fresh nonce, exact package and target, and verify signed receipts through a trusted channel (prefer a short-lived mutually authenticated service or a pinned public-key signature).
3. Extend the operator to actually keep a **continuous** writer pause until a verified restore and upgrade outcome is known, with a single server-wide lock across all agents. Use an allowlisted, separately audited executor, isolated from untrusted PR code.
4. Prove snapshot/restore of DB + code + moodledata + configuration on a disposable Moodle 4.1.22/Percona clone; simulate SIGKILL before backup, between backup/restore, after DDL intent, during partial DDL and after upgrade. Fail closed on stale versions/low bytes/inodes.
5. Only then request owner approval to assign installation responsibility and a **separate decision for a specific SHA, package, target, window and feature toggle**. The existing prepare-release.yml intentional HOLD and R1/R2 publication HOLD are unchanged.

**No production/staging/dev server was contacted or changed by V1c.**
