# BMS Release Operator V1b — GitHub provenance and server inventory contracts

Status: read-only gates implemented and tested with synthetic fixtures. **NOT a deployment executor.** This dependent branch contains V0 + V1a + V1b; PR #129 and PR #130 have not been merged into main.

## 1. Verify authoritative GitHub CI metadata

The V1b provenance gate queries the GitHub REST API over HTTPS and checks the current protected main SHA, PR head/base/test-merge SHA, three exact workflow paths and run attempts, and every job in the required job matrix. It rejects incomplete result pages, failed/skipped/in-progress jobs, unknown extra jobs, unrelated PR runs, wrong workflow paths, expired/mismatched artifacts, changed base or changed HEAD.

Pinned R1/R2 example:
- PR #124 head 30a720c18f231a70bdb08cd65e8cba8206d62f7b, checkout a04a818cdedb0699abe408918876ad60cb32d20a, base fbf36ed350732b46a232a56a216b3d78c733da34.
- Required tiers + Merge gate run 44237304436, FULL run 44237304478, package + dual-engine rehearsal run 44237304437; all attempts 1.
- Exact GitHub Actions artifact ID 14398405898, name release-install-44237304437-a1.
- IMPORTANT: the GitHub artifact container digest (outer ZIP) is NOT the installable plugin ZIP checksum. The actual inner candidate-install.zip and manifest.json still require V1a byte-for-byte verification against the pinned SHA256 values.

Run from a **trusted, previously reviewed** environment that can reach GitHub API, never as an untrusted PR job with a token:

    python tools/release_operator/provenance_gate.py --request docs/releases/operator/r1r2-request.sample.json --plan docs/releases/operator/r1r2-ci-plan.sample.json --report provenance-result.json

If the repository is private, a narrowly scoped read-only GitHub API token can be passed through the BMS_RELEASE_GITHUB_READ_TOKEN environment variable in a trusted, protected execution environment. Do not expose any GitHub token or deployment secret to PR code, repository files, test fixtures, workflow_dispatch input, logs or chats. This PR's CI only runs mocked tests without a token. Token provisioning has NOT occurred.

A successful live API audit returns status HOLD and github_api_metadata_verified=true. It does not prove that the downloaded ZIP bytes match the manifest, nor provide an authenticated server inventory, nor authorize deployment.

## 2. Validate a future authenticated server inventory

The inventory gate validates an externally supplied, structured server state snapshot against the trusted baseline policy:
- R0 BMS and Corporate plugin versions and runtime tree SHA256 pins, Moodle 4.1.22/PHP 8.1/Percona.
- Five R1/R2 daily report tables still absent from the source R0 database.
- Current service states, single-operator lock absence, UTC timestamp freshness (within 5 minutes).
- Free space, free inode reserve and an extra 512 MiB safety margin on top of the estimated required bytes.

The baseline policy is a **sample** copied from the last operator report and is not a live recheck. It deliberately requires 4731174912 + 536870912 = 5268045824 free bytes. The last test free-space figure 4767711232 bytes would FAIL this more conservative margin. It is an estimate, not a verified release-storage budget and must be recalculated by the real operator for the correct volume, quotas and storage growth.

Usage when a genuine server-generated snapshot exists:

    python tools/release_operator/inventory_gate.py --request docs/releases/operator/r1r2-request.sample.json --baseline-policy docs/releases/operator/r1r2-baseline-policy.sample.json --inventory /trusted/path/server-inventory.json --report inventory-result.json

There is **no server inventory collection or signature verification implemented yet**. A JSON file can be forged, so even a fully matching snapshot returns status HOLD, server_origin_authenticated=false, deployable=false. No real production server inventory was gathered in this PR.

## 3. Security constraints

- No SSH/SFTP/deployment credentials, no server access, no database writes, no site maintenance changes, no backup/restore, no actual migration.
- No modifications to existing CI/prepare-release.yml. It retains its intentional HOLD. PR jobs use zero secrets.
- Check the exact SHA and CI package; do not mix R1/R2 #124 with E2 #123, UX-1 #127, or adaptive navigation #128.
- The read-only gate cannot certify business readiness or the owner approval. A green provenance check NEVER authorizes production.
- The server-side phase-aware operator, continuous writer pause lease and verified pre-DDL backup/isolated restore still need implementation and fault testing.

## 4. Tests and next engineering slice

    python -m unittest discover -s tools/release_operator -p 'test_*.py' -v

Unit tests use synthetic GitHub API objects and synthetic inventory only. They include failures for PR checkout/head/base drift, wrong workflow/run attempt, missing/failed/skipped CI jobs, incorrect artifact association, fake approval, stale server inventory, unexpected runtime tree, insufficient capacity margin and active operator lock.

Next step V1c: trusted, remotely attestable read-only server inventory collector and an isolated fault-tested state machine for operator lock, writer-pause and recoverability. Do NOT enable a privileged executor until these are reviewed and live-tested in a disposable environment. Switching the release operator away from Codex requires an explicit owner decision.
