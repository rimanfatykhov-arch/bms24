# Release Notes — BMS v0.5.5 build 2026091503

## Management Exceptions

v0.5.5 adds a schema-neutral management attention queue above the canonical indicator analytics and Task layers.

### What changed

- Added the **Exceptions** tab to the Indicator workspace.
- Added `indicator_management_exception_service` as a read-model only service.
- One current indicator binding produces at most one exception row; multiple attention reasons are aggregated in that row.
- Explicit attention reasons include:
  - critical indicator without factual data;
  - critical indicator without an effective norm;
  - critical indicator without an explicit condition policy;
  - Non-Existence / Danger / Emergency / Power Change;
  - current fact outside the effective norm;
  - worsening trend;
  - direction-aware adverse reversal or extreme;
  - attention condition not yet confirmed by a manager;
  - confirmed attention condition without a canonical Task;
  - overdue canonical Task sourced from the condition decision.
- Added filters by attention level and reason group.
- Task titles/links are shown only through existing Task ACL. A hidden task is not treated as missing.

### Architecture

No exception table was added. The read model consumes only existing canonical sources:

`stat/statval -> statbind -> statnorm/statpolicy -> trend/events/condition -> stateevt -> canonical Task`

The release does not write to `statval`, does not create an Incident/Exception entity and does not duplicate Task or Indicator.

### Threshold policy

Management Exceptions introduces no hidden percentage or age threshold. Numeric interpretation remains exclusively in the explicit versioned condition policy. Exception severity is an explicit semantic mapping of already-known states and execution facts.

### Compatibility

- Moodle production target: 4.1.22.
- PHP compatibility target: 7.3.
- Schema-neutral upgrade savepoint only.
