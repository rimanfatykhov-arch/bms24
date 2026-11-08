# local_kbacompany 0.9.10 — IDEF0 ICOM Balancing

Build 2026100602. ALPHA.

Adds explicit ICOM balancing over the existing 0.9.9 sibling `Process -> supplies -> Process` interconnections. A handoff is balanced when the source Process primary Product is referenced by an Input element of the target Process visible model. Input elements may now use the existing `procelem.productuuid` field for a canonical Product binding; no schema change is introduced.

The IDEF0 decomposition view shows balanced / unbalanced / source-product-missing state for each interconnection and a bounded summary. Managers can open a target draft with the source Product preselected in the existing ICOM Input form; no automatic propagation or hidden mutation occurs. If the target has no draft, the UI routes to the target IDEF0 editor so the user can explicitly create a draft first.

This release does not add parent-child boundary tunneling, automatic ICOM inheritance, SIPOC/BPMN writes, execution semantics, cross-parent arrows, or a second IDEF0 storage model.
