# local_kbacompany 1.1.0-rc14 — Position 2.0

Build: `2026101234`

## Scope

Unified BMS24 Position card over existing canonical objects. No second CKP, function, metric, competency, process, authority or learning engine is introduced.

## Position card

The record now exposes a single navigation surface:

- Main
- Valuable final product (ЦКП)
- Functions
- Metrics
- Competencies
- Authorities
- Processes
- Learning
- Staffing
- History

The Main tab includes a compact BMS24 profile summary showing configured relations.

## Canonical projections

- ЦКП reuses `local_kbacompany_product` owned by the position.
- Functions reuse the canonical Function registry.
- Metrics reuse Position → Metric bindings and norms from Metrics 2.x.
- Competencies reuse the versioned Position Competency Profile / Role DNA.
- Authorities reuse Function Transfer authorization records; the transfer DTO now exposes source/target position UUIDs for safe position-scoped filtering.
- Processes are derived from the source processes of published position functions.
- Learning is driven by published competency requirements and links to the canonical BMS24 Learning contour; no position-course copy is created.
- Staffing and history remain the existing canonical projections.

## Compatibility

- No DB schema change.
- No ACL/capability change.
- No privacy storage change.
- Existing dedicated position competency and position operating-system pages remain available.
