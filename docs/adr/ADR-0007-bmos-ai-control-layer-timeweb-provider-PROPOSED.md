# ADR-0007 — BMOS AI Control Layer + Timeweb Cloud AI Gateway provider adapter

- Status: **PROPOSED — architecture approved in principle by product owner 2026-11-13; implementation and provider processing terms still require separate approval**.
- Scope: future BMOS-A0/A1/A2/A3, RC33 communication/MAX and RC34 daily reports.
- Out of scope: current RC30 release, Moodle Core, KBA Theme, production config, API key provisioning, live model calls.
- Related: [BMOS Agent Platform issue #76](https://github.com/rimanfatykhov-arch/bms24/issues/76), [RC33/34 issue #75](https://github.com/rimanfatykhov-arch/bms24/issues/75), [agent concept](../18_BMOS_AI_AGENTS_FUTURE.md), [implementation plan](../architecture/BMOS_AI_CONTROL_LAYER.md).

## Context / decision

BMS24 models a persistent **functional post** with CKP (valuable final product), functions, competencies, metrics and a supervisor. The occupant may be a **human, AI agent, or explicitly supervised hybrid**. Work, reports, history and accountability must survive reassignment of the occupant. Existing human-oriented identifiers and Moodle roles must not be rewritten to represent a bot.

**Adopt a two-level architecture:**
1. **BMOS Agent Platform / AI Control Layer (our domain):** post occupancy, principal identity, permissions, company UUID, tool registry, human approval, execution orchestration, typed evidence, memory/RAG retrieval, event/outbox, costs/limits, safety, audit, and daily reporting.
2. **Timeweb Cloud AI Gateway (first external ModelProvider adapter):** model catalogue and inference API access, provider-side key/usage facilities, supported fallback/logs. It is **not** the source of BMS rights, agent identity, RAG or MCP execution.

A provider-neutral `ModelProviderInterface` + `ModelRouter` isolates BMOS from Timeweb API details. Alternate providers may be added by their own adapters and capability contracts. Do **not** implement a second monolithic Java/Kotlin AI Gateway now; borrow the operational controls (bounded budgets, timeouts, retries, observability, PII minimisation) when warranted.

## Confirmed provider surface (official documentation reviewed 2026-11-13)

- Docs: https://timeweb.cloud/docs/ai-gateway and https://timeweb.cloud/docs/ai-gateway/manage
- OpenAI-compatible base URL: `https://api.timeweb.ai/v1`; `chat.completions`, `models.list`. Responses API available only on compatible models. Embeddings supported for documented model(s); discover actual list dynamically.
- Dedicated AI Gateway API keys, independent from general account API tokens; key created per project with expiration and management capabilities.
- Key/model usage limits, activity and per-request log metadata; provider log retention stated as 90 days. Logs do not retain prompt/response texts according to provider docs (verify actual account policies/terms).
- Gateway provides direct LLM access, **not managed-agent RAG or MCP orchestration**. Model features and pricing can change; no assumption of complete OpenAI SDK feature parity.

These are **provider-documented claims, not verified BMOS integration tests**. Before first live test, verify current endpoints, model-specific features, pricing/limits, data processing location, retention and terms.

## Security and operational contract

- BMOS API v1 is read-only; **API v2 command surfaces are separately designed, reviewed and gated**. Model output can only propose commands; the canonical BMS service reauthorizes each call (company, principal, effective post assignment, object, capability, version/revision, rate, idempotency, audit).
- `human`, `ai_agent`, `service_integration` are distinct principals; never give agent or MAX bot full Moodle user's human session/site-admin role by default. A human sponsor is mandatory for active AI functional posts. Existing `task.assigneeid` remains a Moodle user identifier and `dutypositionuuid` stays position responsibility; add a separate attribution/assignment layer, not FK coercion.
- Secret material remains in an approved secure store, never in source, prompt, web, browser, backup manifest or generic logs. Support key rotation/revocation and emergency agent-disable.
- Authorize and minimise retrieved context **before** the model call. Untrusted MAX posts/documents/LLM outputs cannot change system instructions or confer privileges. Sensitive or high-risk actions (payment, permissions, external publication, destructive changes) always require authorized human approval in first releases.
- Global provider key limits are **not sufficient** for multi-tenant BMS: track company/agent/model budgets, request/token quotas, retries, estimated and reconciled ruble expense. No shared unrestricted cross-tenant credential.
- Dedicated test environment with provider-only allowlisted egress; **no live provider traffic from the isolated RC30 recovery clone**. Retry only idempotent inference operations, cap attempts, exponential backoff with jitter, typed 401/403/422/429/5xx/timeout/invalid model errors. Automatic fallback only for semantically safe tasks/models; strict schema or tool-call tasks fail closed unless fully compatible.
- Maintain metadata-only invocation telemetry (run_id, company UUID, post UUID, provider/model, token usage, latency, error/status, request correlation). Prompt/response payload storage requires explicit restricted policy and privacy review. Provider's 90-day log retention is not a substitute for BMOS audit.
- Semantic/exact caching is **deferred**; any future cache requires tenant and object authorization scope, model version, prompt revision, policy version, TTL and invalidation. No cross-company cache reuse.

## Position / reporting implications

- A canonical Position is independent of the occupant; effective-dated occupancy can be human, agent or hybrid with explicit boundaries. Every action has a true `principal_type/principal_id` and a human accountable sponsor where AI is involved.
- Agent can propose tasks/comments and submit its own daily report. First version: only an authorized **human manager** accepts/returns reports and authorizes consequential actions. Never silently impersonate a human report author.
- MAX bot is a `service_integration` principal, not automatically an AI functional-post occupant. MAX group messages are untrusted input, mapped to BMS feed via explicit sender/company/thread verification and deduplication.

## Alternatives considered

1. Direct LLM calls from each Moodle module — rejected: inconsistent tenant controls, secrets, budgets and audits.
2. Outsource all agent orchestration to Timeweb managed agents — rejected for canonical BMS post/ACL/accountability ownership; may be a future optional adapter after evaluation.
3. Build full independent gateway/proxy with semantic cache immediately — deferred: premature operational complexity.
4. Timeweb-specific API in every business service — rejected: provider lock-in.

## Staged delivery / exit criteria

1. **BMOS-A0 (design):** service inventory, principal+occupancy ADR, data model, APIs and model-provider interface, tenant/privacy and residency threat review, candidate budget/approval policies, test matrix.
2. **BMOS-A1 (isolated provider adapter):** fake provider tests, approved synthetic live key and budget-bound smoke, capability negotiation, key rotation, no production credentials.
3. **BMOS-A2 (API/ops):** bounded actions via canonical BMS services, outbox, cross-tenant/revocation/idempotency/concurrency tests, rate & cost accounting, audit.
4. **BMOS-A3 (supervised pilot):** one AI analyst on a post → authorised metrics read → **draft** daily report → human accept/return → audited result. Percona/MariaDB, browser/HTTP and negative ACL checks.
5. **BMOS-A4:** optional multi-agent coordination, advanced routing, caching and tenant billing after observed need and independent approvals.

**No release GO until** provider and data protection terms are approved, secrets isolation and costs limits are tested, external endpoints/feature support verified, negative ACL and high-risk approval gates PASS, and owner separately approves deployment. Documentation alone is NOT implementation.

## Sources
- Timeweb gateway: https://timeweb.cloud/docs/ai-gateway
- Timeweb connect/keys/examples: https://timeweb.cloud/docs/ai-gateway/manage
- Timeweb logs/retention: https://timeweb.cloud/docs/ai-gateway/logs
- OTUS architecture patterns (reference, not Timeweb feature claim): https://habr.com/ru/companies/otus/articles/1031276/
