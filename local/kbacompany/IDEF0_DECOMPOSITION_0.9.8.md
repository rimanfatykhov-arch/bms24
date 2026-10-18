# IDEF0 Decomposition — local_kbacompany 0.9.8

## Scope
IDEF0 decomposition is projected from the existing canonical Process hierarchy.

## Mapping
- L0 context function: `A-0`; its decomposition diagram is `A0`.
- Visible L1 child processes are rendered as `A1`, `A2`, ... in canonical PRC-code order.
- Visible L2 child processes are rendered as `A11`, `A12`, ... below their L1 parent.
- Process UUID remains canonical identity; PRC code remains stable code. IDEF0 notation is calculated presentation metadata only.

## Lifecycle and security
- Reader sees only the published Process hierarchy already visible under existing ACL semantics.
- Manager sees the existing working Process hierarchy.
- Child creation reuses the existing Process CRUD with the canonical parent preselected.
- Every hierarchy query applies Company UUID scope before fetch.

## Deliberately deferred
- IDEF0 interconnections/arrows between functions;
- ICOM inheritance or balancing between parent and children;
- automatic IDEF0 ↔ SIPOC synchronization;
- hierarchy deeper than the current canonical L2 boundary;
- drag/drop ordering or persisted IDEF0 node positions;
- AI generation.
