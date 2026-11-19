# BMS Release Operator V1a — exact artifact gate

Status: local offline verifier implemented. No SSH, no server inventory, no backup, no deployment.

## What changes

V0 validates only request fields; that is insufficient if an artifact is replaced. V1a compares the actual CI ZIP and manifest bytes against separately pinned request digests, plus validates the exact plugin archive contents.

Controls: SHA256 of ZIP and manifest; plugin component and version; PR head SHA; CI merge checkout SHA and tree SHA format; CI base SHA, run ID and attempt; all manifest and ZIP entry names, order, content hashes and sizes; safe path and canonical kbacompany/ prefix; no directory traversal, foreign plugin, symlinks, executable file modes, encrypted entries or secret filename; bounded decompression; match embedded version.php.

On success output remains status=HOLD, deployable=false, archive_bytes_verified=true, ci_status_authenticated=false, server_baseline_verified=false, actions_executed=[]. This tool performs no server action.

## R1/R2 example invocation

The exact original CI artifact must be downloaded independently from the accepted run, not rebuilt or renamed. The operator supplies candidate-install.zip and manifest.json locally. Use the sample request from the previous slice:

python tools/release_operator/artifact_gate.py --request docs/releases/operator/r1r2-request.sample.json --zip candidate-install.zip --manifest manifest.json --base-sha fbf36ed350732b46a232a56a216b3d78c733da34 --run-id 44237304437 --run-attempt 1 --report artifact-gate-result.json

Sample exact identity (must be revalidated live): PR #124 HEAD 30a720c18f231a70bdb08cd65e8cba8206d62f7b; CI checkout a04a818cdedb0699abe408918876ad60cb32d20a; ZIP 07a0c7f71b4246e08d19683449dacd18da5b79914f7aac8849aa1ad60a69c69e; manifest 152cbf3857d1d4ad4433f9a5efe6075a490a90c4e66745de3f11920cbe204780; build 2026111601.

Important: the user-supplied expected base SHA and CI run ID are NOT independently authenticated by this CLI; a malicious actor controlling the request and artifact could invent a self-consistent package. A trusted external process must query GitHub and verify the provenance and mandatory checks. Passing an offline fixture is not proof of real CI acceptance.

## Tests

python -m unittest discover -s tools/release_operator -p 'test_*.py' -v

The existing read-only V0 workflow uses this discovery command. The tests here create disposable synthetic ZIP archives and intentionally corrupt signatures, hashes, entry list, traversal paths, permissions, version, CI identity and approval. They do not touch Moodle or production.

## V1b required before an actual install

- Authenticated GitHub API provenance for the accepted workflow run, PR base/head, full required CI and exact downloaded original package.
- Authenticated read-only server inventory including actual installed BMS and Corporate/Core versions and lock state; reject drift.
- A separate tightly privileged executor and server-side single-operator lock. Never execute untrusted PR code with deployment secrets.
- Continuous writer pause, fresh verified full backup and isolated restore, exact Moodle CLI upgrade, durable DDL intent, tested phase-aware recovery.
- Per-release separate owner approval binding immutable package SHA, target and execution window; separate permission for dailyreports_enabled.
- Explicit recovery handling: after DDL begins, no code-only downgrade from 1601 to R0; restore only with separate approval after assessing data loss.

Existing .github/workflows/prepare-release.yml stays on intentional HOLD, and PR #124 stays NOT PUBLISHED. This change does not alter production, staging, Core, Corporate, feature toggles or release-operator roles.
