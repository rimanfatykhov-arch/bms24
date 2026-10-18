# local_kbacompany 1.1.0-rc15 — Employee 2.0

Build: `2026101235`

## Scope

The BMS24 Employee card is now a single management workspace over existing canonical objects. The release does not introduce duplicate employee, task, Battle Plan, metric, competency, learning, development or document storage.

## Employee card

The record exposes one navigation surface:

- Overview
- Positions and roles
- Metrics
- Battle Plan
- Tasks
- Competencies
- Learning
- Development
- Documents
- History
- Integration (for managers)

The Overview includes compact counters for active assignments, position metrics, open tasks, competency gaps, active learning and pending document acknowledgements.

## Canonical projections

- Positions and assignment history reuse `local_kbacompany_posassign` and canonical Position records.
- Business roles remain a provider-neutral projection through `business_role_service`.
- Metrics reuse Position → Metric bindings and norms; no employee KPI copy is created.
- Battle Plans reuse `local_kbacompany_bplan`.
- Tasks reuse canonical BMS tasks assigned to the employee.
- Competencies reuse employee competency evidence versus the published position profile.
- Learning reads the live Learning contour and is explicitly not competency evidence.
- Development reuses employee development plans and actions.
- Documents show revision-specific acknowledgement assignments from the controlled-document contour.

## Access boundary

Sensitive personal management tabs require one of:

- the employee viewing their own card;
- people-management permission;
- organization-management permission;
- site administrator.

Other company members retain the existing basic directory/position visibility without gaining access to personal tasks, Battle Plans, competency evidence, learning, development or document acknowledgement data.

## Compatibility

- No DB schema change.
- No capability change.
- No privacy storage change.
- Old `tab=organization` employee links redirect to the new Positions and roles tab.
- Existing dedicated Employee Competencies, Learning, Development Plan, Tasks, Battle Plan and Documents pages remain canonical drill-downs.
