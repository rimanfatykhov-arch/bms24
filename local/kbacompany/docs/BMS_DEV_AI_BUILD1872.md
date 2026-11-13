# BMS Dev AI — build2026101872

This is the first delivery of the governed AI software-development pipeline.

The developer kit (separate from Moodle INSTALL ZIP) includes:
`AGENTS.md`, `dev_ai/agents.json`, `tools/dev_ai_gate.py`,
`tools/build_install.py`, `tools/test_dev_ai_tools.py`, CI workflow,
architecture and release gates.

A local Git repository and an offline Git bundle are delivered to bootstrap
private source control. No remote GitHub repository has been created.

**Non-goals:** No running AI agents, no live connectors, no automated production
deployment, no production AI keys; business data and schema are unchanged.

After installing plugin, verify `version.php` and the administrator's upgrade
completion. Continue RC30 acceptance independently. Keep production access
restricted to human-approved and audited operations.
