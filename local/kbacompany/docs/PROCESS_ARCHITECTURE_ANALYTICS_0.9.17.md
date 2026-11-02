# 0.9.17 — Process Architecture Analytics

Read-only analytics over the existing canonical Process Architecture workspace.

## Scope
- New `analytics` view beside Architecture and Registry.
- Overall architecture maturity calculated as the average existing per-process architecture readiness score for the current filtered slice.
- Coverage for publication, owner, product, Process Model, IDEF0↔SIPOC consistency and decomposition-boundary balance.
- Breakdowns by process type and L0/L1/L2.
- Gap counters and a severity-sorted priority process list with drill-down links to Process, IDEF0 and Process Passport.
- Filters operate on the analytics slice; ancestor context nodes are excluded unless they directly match the filter.

## Invariants
- Analytics reuse the Company-scoped `architecture_workspace` projection; there is no analytics table or cache.
- Scores, coverage and issue counts are diagnostic projections, not canonical business facts.
- Manager remains draft-first; Reader remains published-only through the existing architecture visibility rules.
- No Process, Process Model, ICOM, SIPOC, BPMN or Semantic Core writes.
- No schema, ACL or privacy changes.
