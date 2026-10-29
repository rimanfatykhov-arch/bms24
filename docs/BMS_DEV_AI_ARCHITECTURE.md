# BMS Dev AI — architecture baseline, build2026101872

## Purpose
A governed software-delivery department of AI roles that designs, implements,
reviews and packages changes to BMS24. The first milestone is **reproducible
source and quality gates**, not autonomous deployment or a running agent swarm.

## Components and boundaries

- **Git repository**: immutable review history, pull requests, branch protections.
  This delivery includes an *offline* Git bundle; no remote GitHub repository
  was created or given production credentials.
- **Plugin**: `local/kbacompany` (Moodle). Company ACL, task/business logic,
  MySQL and workflow services remain here.
- **AI developer orchestration (future)**: separate service with model gateway,
  sandboxed worktrees, task queue, budgets and approval ledger.
- **Knowledge base (future)**: indexed approved specifications, code,
  architecture decisions, source links and release reports. Do not put
  customers' production data into development retrieval datasets.
- **Dev AI policy**: machine-checkable `dev_ai/agents.json` and
  `tools/dev_ai_gate.py` prevent unauthorized agent capabilities.
- **GitHub Actions skeleton**: runs static checks; no credentials, writes,
  production deployment or agent runtime. Repository settings must enforce
  protected branches and human reviews separately.

## Standard task lifecycle
`Product goal -> analyst proposal -> architect review -> feature branch ->
implementation -> isolated tests -> independent QA/security -> PR -> human
approval -> staging -> live acceptance -> release.`

## Domain invariants
1. `companyuuid` scope and record-level permission checks before data reads.
2. BMS Theme owns presentation shell, not domain permissions/navigation data.
3. Workflows preserve existing tasks, weekly coordination, Battle Plan links.
4. RC30 readiness is a gate, not a numeric build or automatic CI green check.
5. Agent-generated dashboards/workflows use validated declarative specs,
   never arbitrary PHP or SQL executed as a Moodle request.
6. Third-party connectors and model endpoints follow least privilege,
   audited requests and controlled data redaction.

## Delivery boundaries of 1872
**Implemented:** policy registry, offline policy validator, CI workflow
source, local Git history and bundle, deterministic INSTALL ZIP builder and
reproducibility tests. Moodle business UI, DB schema and user roles unchanged.

**Not implemented:** hosted Git remote, credentials, CI run on GitHub,
agent execution, orchestration, production deploy, staging Moodle PHPUnit,
Behat, MySQL/PHP 8.1 runtime verification or RC30 acceptance.
