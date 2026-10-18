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
