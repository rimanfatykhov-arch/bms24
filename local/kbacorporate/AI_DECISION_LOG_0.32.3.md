# AI DECISION LOG — v0.32.3

## ADR-0323-01 — KBA IPR complements, not replaces, Moodle Learning Plan

Decision: standard Moodle Learning Plan remains the canonical learning-plan/competency membership layer. KBA stores management extensions: business objective, mentor, dates, practice, evidence and workflow.

Reason: avoid parallel competency-plan semantics while retaining corporate implementation context not provided by Moodle core.

## ADR-0323-02 — Course completion is not competency confirmation

Decision: a completed course may complete a simple learning action, but never changes the confirmed competency level. If practice/evidence requirements are configured, course completion only moves the action into work and asks for evidence.

Reason: participation and competency demonstration are different facts.

## ADR-0323-03 — Explicit evidence review and separation of duties

Decision: evidence enters `pendingreview`; it must be accepted or returned explicitly. The evidence submitter cannot review their own evidence.

Reason: protect auditability and prevent self-confirmation of a corporate development result.

## ADR-0323-04 — Action completion and competency grading remain separate

Decision: accepted practical evidence completes the KBA development action, not the Moodle competency. Competency change occurs only through the existing confirmed assessment workflow.

Reason: preserve one authoritative source of truth for competency level.

## ADR-0323-05 — Additive schema migration

Decision: add fields and a new history table; do not rewrite or delete existing plans. Backfill only deterministic values (`reviewdate`, role-required state where resolvable).

Reason: safe in-place upgrade and preservation of user data.

## ADR-0323-06 — Immutable operational history

Decision: append plan/action events to `local_kbacorp_devlog`; deleted actions keep historical references by numeric item ID.

Reason: support troubleshooting, governance and later analytics without rewriting history.

## ADR-0323-07 — Mentor eligibility follows company membership

Decision: mentors must be active Moodle users and current members of the company. Removed members lose mentor eligibility and reminders.

Reason: prevent stale assignments from granting ongoing access.

## ADR-0323-08 — Reminder visibility follows role scope

Decision: plan mentor and authorised managers receive plan-wide reminders; an action-only mentor receives only actions assigned to them.

Reason: apply least-privilege disclosure to employee development data.

## ADR-0323-09 — Evidence storage scope for 0.32.3

Decision: support URL and text comment only. Do not add a file area in the same release as the primary IPR schema/workflow migration.

Reason: keep the first migration bounded; file lifecycle, antivirus, retention and privacy rules require a dedicated design.

## Rejected alternatives

- Treat all course completions as competency achievement — rejected as methodologically incorrect.
- Allow manual completion of evidence-governed actions — rejected because it bypasses review.
- Let the evidence author approve their own result — rejected due to conflict of interest.
- Reconstruct historical events from timestamps — rejected because inferred history would not be trustworthy.
- Store a second full copy of Moodle Learning Plan data — rejected to avoid divergence.
