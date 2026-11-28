# BMS Release Operator V0 — independent of the developer's computer

**Current state: read-only preflight, NOT a deployment system.**

## Goal

Create a durable cloud/server release process for BMS24 that does not depend on a particular Windows laptop, VS Code, Codex payment, or AI session. Claude develops features. A release operator verifies immutable artifacts and, after the owner's approval, performs controlled installation. V0 does not appoint an alternate installer or grant server permissions.

## Implemented in V0

- tools/release_operator/preflight.py — fail-closed inspection of a structured release request.
- tools/release_operator/test_preflight.py — seven positive and negative unit tests; synthetic data only.
- docs/releases/operator/r1r2-request.sample.json — R1/R2 sample with independently recorded SHA references from PR #124.
- .github/workflows/bms-release-operator-preflight.yml — read-only tests and downloadable HOLD report.

A valid request always returns status HOLD, deployable=false, evidence_verified=false and actions_executed=[]. An invalid request returns INVALID. Input SHA values are structural declarations, not proof that the package or server matches. NO server connectivity, remote commands, backup, restore, migrations, GitHub write action or owner-approval verification exist in V0.

Run in a trusted local clone:

    python -m unittest discover -s tools/release_operator -p 'test_*.py' -v
    python tools/release_operator/preflight.py --request docs/releases/operator/r1r2-request.sample.json --report release-operator-v0-report.json

Do not remove the intentional HOLD from .github/workflows/prepare-release.yml. The new preflight workflow is informational and its green check is NEVER authorization to deploy.

## Proposed V1: separately trusted executor (not yet developed)

The control plane lives in GitHub protected main and a permanent cloud service, not on the user's computer. Deployment credentials must be restricted to a trusted runner/gateway, never made available to PR code, model chat, repository files or the normal CI environment. A scoped GitHub identity or controlled hosting credential is configured by an authorized administrator.

Trust boundary:

1. The development PR supplies code but NO authority to deploy.
2. A trusted process verifies current main, selected PR head, CI checkout tree, ZIP SHA256, manifest SHA256, production runtime baseline, BMS/Corporate/Core versions and full mandatory CI results. Any mismatch means HOLD.
3. Owner approves the exact package + target + execution window; enabling dailyreports_enabled needs its own explicit consent.
4. One server-side lock prevents simultaneous operators. Inventory confirms sufficient disk bytes/inodes/quota and fresh backup capacity.
5. Close web/API/background/cron writers with continuous pause lease; take a consistent backup of full DB, code, Moodle configuration and moodledata. Restore and verify on an isolated, externally disconnected clone before any production DDL.
6. Install only the verified plugin artifact; run Moodle CLI upgrade; inspect exactly the expected five additive tables; preserve existing data and Corporate/Core. Perform smoke and issue a machine-readable result receipt.
7. Before DDL: safe code revert only when schema truly unchanged. After DDL begins: NO automatic code-only downgrade from 1601 to R0. Keep a compatible 1601 code/feature OFF or hold maintenance; separately approved full restore only after explicit assessment of new writes and data loss.
8. Simulate SIGKILL, interrupted backup, interrupted DDL, post-upgrade failure and recovery on disposable environments before granting the executor any production permission.

## Current candidate and restrictions

- PR #124 R1/R2 head 30a720c18f231a70bdb08cd65e8cba8206d62f7b.
- Production baseline R0, numeric plugin version 2026111100 (as last recorded by operator); target 2026111601.
- Sample ZIP 07a0c7f71b4246e08d19683449dacd18da5b79914f7aac8849aa1ad60a69c69e and manifest 152cbf3857d1d4ad4433f9a5efe6075a490a90c4e66745de3f11920cbe204780 must be independently rechecked.
- E2 #123, UX-1 #127 and adaptive navigation #128 are NOT part of this candidate.
- R1/R2 production is HOLD until V1 execution/recovery, verified fresh backup and isolated restore, and separate owner approval. No staging or production changes are made by this PR.

## Next concrete slice

Create a trusted V1 read-only inventory adapter and a privileged server operator design with allowlisted commands; independently test its failure/recovery state machine on a disposable Moodle clone. Set up provider/network identity only through the owner-controlled hosting/GitHub configuration. Leave existing CI workflows and approved security rules intact.

References: docs/05_RELEASE_QA_STANDARD.md; PR #124 (acceptance), PR #125 (Corporate source fix), PR #126 (operator role documentation).
