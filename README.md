# BMS24 Dev AI — source repository skeleton

**Release:** local_kbacompany `1.1.0-rc29`, build `2026101875`.

- `local/kbacompany` — installable Moodle plugin (full cumulative source).
- `AGENTS.md` — mandatory guidelines for AI coding agents.
- `dev_ai/agents.json` — least-privilege agent role registry; **no runtime**.
- `tools/dev_ai_cycle.py` — approved task -> isolated Git worktree -> scope inspection -> independent QA/security evidence (no model runtime).
- `tools/dev_ai_gate.py` — validations of policy, release checkpoints,
  documentation and source safety.
- `tools/build_install.py` — deterministic Moodle install ZIP packager.
- `.github/workflows/bms24-ci.yml` — CI workflow (not deployed remotely).
- `docs/` — architecture and release gates.

Start with `python tools/dev_ai_gate.py` and `python tools/test_dev_ai_tools.py` and `python tools/test_dev_ai_cycle.py`.
Import this repository into your own private Git host after verifying source
and enabling protected branches/reviews. This deliverable is an offline Git
bundle and source archive, not a published repository.

A passing CI workflow is not a real Moodle integration or release acceptance.

- `tools/verify_install.py` — verify packaged artifact and per-file manifest.
- `infra/timeweb/staging-preflight.sh` — non-destructive staging host inspection.
- `docs/INFRA_TIMEWEB_GITHUB_STAGING.md` — Timeweb VDS + GitHub rollout plan.

**Important:** the production INSTALL zip is a PHP/Moodle distribution, not a
container for Python development checks. GitHub CI publishes only *candidates*;
a passing workflow does not constitute server acceptance or authorize deployment.
