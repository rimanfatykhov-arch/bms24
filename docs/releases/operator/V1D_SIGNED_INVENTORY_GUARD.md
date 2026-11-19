# BMS Release Operator V1d — signed inventory and durable reservation

**Status: REVIEW-ONLY. Production remains HOLD.**
Depends on PR #132 -> #131 -> #130 -> #129. This PR neither installs Moodle nor grants deployment privileges.

## 1. Signing a read-only site report

- V1c site_probe.php returns a partial, raw, unsigned report. It does not measure cron, operator lock, inodes, production quota or CLI maintenance. Its tree hash format differs from older runtime-tree checksums.
- V1d sign_probe.php signs exact raw report bytes using PHP OpenSSL RSA/SHA-256. The signing key is read from an existing private file, must be mode 0600 and must be provisioned by a trusted administrator outside webroot, Git, PR CI and ChatGPT. No real signing key is provisioned in this PR.
- A protected controller must independently generate an unpredictable 32-byte challenge nonce and bind it to exact release ID + target. The verifier accepts only independently pinned public-key SHA256, nonce, release ID and target.
- verify_signed_probe.py verifies detached signature, public-key fingerprint, raw report hash and freshness. Unknown fields, altered bytes or signature, stale payload and mismatched nonce/target fail closed.
- A valid signature proves use of a signing key, not the physical identity of the production host or correctness of observations. Result is HOLD, deployable=false, server_origin_authenticated=false.

Signature framing is identical in PHP and Python: ASCII domain BMS24-READONLY-INVENTORY-V1D, NUL, release_id NUL, target NUL, nonce NUL, decimal payload size NUL, lowercase SHA256 of exact raw JSON NUL.

## 2. Durable reservation model

- operator_guard.py creates a 0600 reservation file inside a 0700 directory through atomic replacement, fsync of file and directory, and local flock to serialize cooperating processes. State persists after SIGKILL: losing flock does not permit automatic takeover.
- Ordered symbolic states: RESERVED -> WRITER_PAUSE_ASSERTED -> BACKUP_RESTORE_ASSERTED -> DDL_INTENT -> UPGRADE_ASSERTED -> SMOKE_ASSERTED -> MODEL_COMPLETE.
- These are symbolic assertions, NOT actual writer pause, backup, restore, DDL, cron control or a server-wide operator lock. Code has no SSH/SQL/network/Moodle commands, unlock, reset or automatic recovery.
- After DDL_INTENT: HOLD and no code-only downgrade. At every stage the model forbids automatic writer resume and database restoration. Even MODEL_COMPLETE is not permission to deploy.
- An unkeyed reservation hash detects accidental corruption, not tampering by a malicious writer with access to its host directory.

## 3. Tests

The existing read-only workflow discovers tests in tools/release_operator. New tests:
- Generate ephemeral RSA keys in a disposable directory, use real PHP signer and OpenSSL/Python verifier; tampering, stale data, wrong nonce/target/key and fake approvals must fail closed.
- Inject SIGKILL after a durable reservation and after DDL_INTENT, test lock contention and forbidden takeover, tampered/truncated state and impossible transitions.
- Do not access live Moodle, server, database, backups, or production credentials. They cannot establish production readiness.

## 4. Required before a production-capable operator

1. A trusted host identity, private signing key, protected public-key pin and one-time nonce registry must be provisioned by an authorized administrator. Never send credentials in Git or ChatGPT.
2. Demonstrate real host inventory collection and authenticated delivery. The V1c raw partial probe is not equivalent to the V1b inventory schema.
3. Develop a separately audited server-wide release lock, continuous writer-pause lease, independent process supervisor, and phase-aware recovery. The simulation mutex is insufficient.
4. On isolated Moodle 4.1.22/Percona, rehearse fresh full DB/code/moodledata/config backup, independent restoration, migration and crash/recovery before and after DDL, including SIGKILL. Verify no unattended data loss.
5. Independently verify exact immutable ZIP, manifest, GitHub provenance, complete CI and live baseline. Never mix R1/R2 #124 with E2/UX-1 product slices.
6. Obtain separate owner authorization for exact release SHA, ZIP, target and execution window. Enabling dailyreports_enabled is another explicit decision.

The existing prepare-release.yml intentionally retains HOLD and PR #124 remains NOT PUBLISHED. No main/production/staging/dev site was modified in V1d.
