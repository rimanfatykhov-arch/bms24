# BMS24 Dev AI — source repository skeleton

**Release:** local_kbacompany `1.1.0-rc29`, build `2026101872`.

- `local/kbacompany` — installable Moodle plugin (full cumulative source).
- `AGENTS.md` — mandatory guidelines for AI coding agents.
- `dev_ai/agents.json` — least-privilege agent role registry; **no runtime**.
- `tools/dev_ai_gate.py` — validations of policy, release checkpoints,
  documentation and source safety.
- `tools/build_install.py` — deterministic Moodle install ZIP packager.
- `.github/workflows/bms24-ci.yml` — CI workflow (not deployed remotely).
- `docs/` — architecture and release gates.

Start with `python tools/dev_ai_gate.py` and `python tools/test_dev_ai_tools.py`.
Import this repository into your own private Git host after verifying source
and enabling protected branches/reviews. This deliverable is an offline Git
bundle and source archive, not a published repository.

A passing CI workflow is not a real Moodle integration or release acceptance.
