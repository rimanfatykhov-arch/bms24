# KBA Corporate Dashboard v0.30.3 — Mapping Cleanup & Integrity

## Scope
- Added Mapping Integrity page in Framework Manager.
- Shows active criterion coverage with working competencies.
- Detects BMS-Dxx domain nodes used as terminal mappings.
- Detects duplicate mappings and invalid target levels.
- Blocks new BMS-Dxx terminal mappings in JSON imports and manual edits.
- Hides BMS-Dxx nodes from manual competency selection.
- Adds controlled cleanup action for existing BMS-Dxx mappings; no automatic destructive migration.

## Migration impact
No DB schema changes. Existing mappings are not automatically deleted. Cleanup is an explicit sesskey-protected action and marks affected tools as draft.
