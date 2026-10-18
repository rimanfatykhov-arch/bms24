# Test report — KBA Corporate Dashboard v0.28.2

## Static checks

- PHP syntax lint: all plugin PHP files passed.
- Plugin root remains `kbacorporate/`.
- Moodle requirement remains 4.0+.
- No DB schema change in v0.28.2.

## Import checks covered by implementation

- Reject malformed JSON.
- Reject unsupported schema/version.
- Reject missing stable codes.
- Reject target levels outside 0..9.
- Reject priority outside `critical|core|additional`.
- Detect missing tool, criterion or competency.
- Detect duplicate Moodle competency `idnumber` values.
- Detect create/update/unchanged state.
- Apply valid changes inside a delegated DB transaction.
- Preserve existing unique `(criterionid, competencyid)` constraint.
- Mark affected tools as draft via `save_criterion_competency()`.

## Server-side runtime test after installation

1. Open BMS tool library for a company.
2. Click “JSON: competencies ↔ tools”.
3. Export current mappings and verify downloaded JSON.
4. Upload a valid schema 1.0 file.
5. Verify preview statuses.
6. Apply import.
7. Open a changed tool and confirm mappings and draft badge.
8. Publish through Version history.
