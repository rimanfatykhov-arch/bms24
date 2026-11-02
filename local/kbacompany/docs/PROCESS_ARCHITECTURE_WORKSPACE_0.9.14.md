# Process Architecture Workspace — 0.9.14

## Purpose
Provide one owner-facing view of the complete visible Process hierarchy without creating a second process tree.

## Canonical sources
- `local_kbacompany_process`: hierarchy, status, owner, primary Product.
- `local_kbacompany_procmodel` / `procelem`: visible model and canonical I/O Product bindings.
- Semantic Core `Process -> supplies -> Process`: sibling interconnections.
- Existing IDEF0 boundary and IDEF0↔SIPOC rules are projected read-only into node diagnostics.

## Workspace states
- Ready: published Process, owner + Product, published model, consistent IDEF0↔SIPOC, balanced boundary when decomposition exists.
- Needs attention: partial/draft formalisation.
- Structural gap: boundary issue or projection inconsistency.
- Not modelled: no model and no owner/Product formalisation.

## Safety
The workspace is read-only. It does not persist notation, hierarchy, score or health. No schema, ACL, privacy, SIPOC/BPMN write or background job is added.
