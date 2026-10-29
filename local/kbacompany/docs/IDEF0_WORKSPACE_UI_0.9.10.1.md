# IDEF0 Workspace UI — 0.9.10.1

Presentation-only UI correction over exact 0.9.10 build2026100602.

## Scope
- Recompose the Process / IDEF0 tab into one BMS workspace.
- Keep all canonical Process, Process Model, ICOM, decomposition, interconnection and balancing services unchanged.
- No schema, ACL, privacy, controller, mutation-route or business-rule changes.

## UX changes
- Compact workspace status bar: IDEF0 notation, model revision/status and short ICOM balance state.
- Draft/new-revision or Publish is promoted to the workspace action area instead of a separate management panel.
- Local anchor navigation: context diagram / decomposition / interconnections.
- Context diagram becomes the primary first section; ICOM columns show compact item counts.
- Decomposition and interconnections are separate peer sections instead of one nested technical panel.
- Child/interconnection creation stays next to the section it affects.
- Interconnection balance status is visually separated from route metadata; actions get their own footer row.
- Long architecture/source explanations move into a collapsed Help block.
- Process tabs receive a compact BMS workspace treatment; responsive behavior is preserved.

## Invariants
- Manager/Reader visibility rules unchanged.
- Published model remains immutable.
- Existing draft creation, publish, ICOM CRUD, child Process CRUD, Semantic Core relations and balancing remediation routes are reused unchanged.
- KBA Theme remains presentation/App Shell only; domain UI composition stays in local_kbacompany.
