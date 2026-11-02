# IDEF0 ICOM CRUD — local_kbacompany 0.9.7

## Scope
Draft-only CRUD for IDEF0 ICOM inside the existing canonical Process card.

## Canonical mapping
- Input -> existing `procelem.elementtype=input`.
- Control -> existing `procelem.elementtype=control`.
- Output -> existing `procelem.elementtype=output`.
- Mechanism -> existing `procelem.elementtype=resource`.
- Primary process product remains `process.productuuid`; it is not duplicated as an IDEF0 record.

## Lifecycle and security
- Reader: published visible revision only, no mutation controls.
- Manager: existing visible draft semantics. If only a published model exists, a new draft revision must be created first.
- All reads/writes remain Company UUID scoped through the existing process-model service.
- Existing request receipt, sesskey, optimistic change-version and model immutability guards are reused.

## Deliberately deferred
- IDEF0 decomposition;
- inter-process IDEF0 arrows;
- automatic IDEF0 ↔ SIPOC synchronization;
- links to policies, positions, systems or other new Controls/Mechanisms engines;
- drag/drop IDEF0 layout;
- AI generation.
