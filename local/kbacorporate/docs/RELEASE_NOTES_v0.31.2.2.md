# KBA Corporate Dashboard v0.31.2.2
## Selective Role Profile Migration

### Purpose
Replace BMS-Dxx grouping requirements in a business-role profile with a deliberately selected subset of concrete BMS-Cxx-xx competencies instead of expanding an entire domain automatically.

### Changes
- Role profile integrity page now renders each direct concrete child as a selectable candidate.
- Nothing is selected by default.
- Target level, priority and mandatory flag can be configured per selected competency.
- Existing concrete requirements are shown and preserved without overwrite.
- Source domain removal is explicit per domain and is allowed only when at least one concrete direct child remains in the role after the operation.
- GAP and Moodle Learning Plan logic from v0.31.2.1 remains unchanged: BMS-Dxx nodes are excluded until replaced.
- Legacy all-domain expansion remains available only as a backward-compatible internal action and is no longer exposed in the current UI.

### Data / migration impact
No database schema changes. No automatic data migration on plugin upgrade. Changes occur only after an authorised user explicitly submits the selective migration form.
