# BMOS Agent Platform — planned, not current product scope

Concept author: Фатыхов Риман Хузяханович, CEO of ООО «Бизнес Академия».

BMOS is a proposed digital management software category, not a certified or globally agreed software class. Proposed future function: management of human roles and AI agents as **digital functional posts** with accountable human owner, allowed tools/data/actions, guardrails, performance metrics, versioning, execution audit, and independent authorization.

Ideas: approved messaging analysis (MAX, WhatsApp, WeChat via permitted provider APIs), ERP analytical dashboards, coordinated marketer agents. Provider access is scoped by law/terms/credentials and company consent; no indiscriminate chat collection or tenant leakage.

Rules for future BMS releases:
- Do NOT place provider credentials in agent prompts or repository.
- Do NOT grant human RBAC to a bot by merely registering agent.
- Agents submit proposals and/or narrowly authorized commands via canonical services, not direct SQL writes.
- Human owners control irreversible actions (payments, public publication, private permissions, high-risk actions).
- Agent-to-agent communication, lifecycle, telemetry and model/provider abstraction belong to a separately approved architecture and release stream.

Not part of `1.1.0-rc29`, including build2026101642. Do not implement implicitly while polishing UI.


## 2026-11-13 — architecture baseline for human/AI posts, Timeweb and SIPOC

Owner approved planning a single canonical functional post occupied by `human`, `ai_agent` or a supervised `hybrid` team. Position/CKP/function/metric definitions survive the replacement of an executor; do not coerce agent IDs into historical Moodle user foreign keys or silently transfer Position Coverage.

**Core interaction contract:** a process output produced by post A is a validated, versioned, access-scoped `OutputArtifact`; using the approved SIPOC/BPMN definition the artifact is handed off to the authorized consumer post B through an auditable `ProcessHandoff`. No raw shared agent memory, unrestricted chat or unauthorised cross-company access. Next operations advance only after validation/acceptance; human manager approves high-risk consequences.

**Architecture:** BMOS Agent Platform owns principals, posts/occupancy, company authorization, tools, workflow/handoffs, output validation, daily reports, human approvals, audit, limits and agent memory. Provider-neutral `ModelRouter/ModelProviderInterface` uses **Timeweb Cloud AI Gateway as first optional ModelProvider**, not as the orchestration/identity authority. Add adapters for other providers when needed.

References:
- [ADR-0007 Timeweb provider boundary](adr/ADR-0007-bmos-ai-control-layer-timeweb-provider-PROPOSED.md)
- [BMOS AI Control Layer and SIPOC handoff](architecture/BMOS_AI_CONTROL_LAYER.md)
- GitHub [#76](https://github.com/rimanfatykhov-arch/bms24/issues/76) and [#75](https://github.com/rimanfatykhov-arch/bms24/issues/75)
- Official Timeweb docs https://timeweb.cloud/docs/ai-gateway
- Architecture comparison https://habr.com/ru/companies/otus/articles/1031276/

Status remains **PLANNED**, not a feature implemented by RC30. No provider credentials, API activation, production deployment or Core/theme changes were authorized by this document.
