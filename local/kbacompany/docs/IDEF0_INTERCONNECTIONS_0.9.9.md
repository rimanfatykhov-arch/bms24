# IDEF0 Interconnections — local_kbacompany 0.9.9

## Canonical source
- Function nodes: existing canonical Process hierarchy.
- Directed interconnection: existing Semantic Core relation `process -> supplies -> process`.
- Display label: source Process primary product when available.
- Identity: relation UUID remains Semantic Core identity; Process UUID remains function identity.

## Scope and guards
- Only direct sibling functions of the same visible decomposition may be connected.
- Self-links are rejected.
- Duplicate active relations are rejected by Semantic Core.
- Reader sees only interconnections whose endpoints are in the reader-visible published hierarchy.
- Manager operates on the current working hierarchy.
- Every interconnection query is Company UUID scoped before fetch.
- Create/remove actions use sesskey, request receipts and parent Process revision stale guards.
- Remove archives only the relation; endpoints and process-model content are unchanged.

## Deliberately deferred
- ICOM balancing/inheritance across decomposition levels.
- Explicit binding of one relation to a specific non-primary Output/Input ICOM element.
- Branch/join semantics or execution behavior.
- Automatic SIPOC/BPMN writes.
- Cross-parent/deep-hierarchy interconnections.
