# Test report — 0.18.2.1

Build: `2026090214`

## Added automated coverage

- Generates a plan for a role requirement with no confirmed current level.
- Captures target level and priority.
- Reuses an existing active plan instead of creating a duplicate.
- Declares both new tables in Privacy metadata.

## Required on Moodle server

- Run PHP lint and PHPUnit.
- Verify upgrade creates both development-plan tables.
- Generate a plan for an employee with mixed achieved, gap and unassessed competencies.
- Verify achieved requirements are excluded and course recommendations respect company access.
- Verify employee self-view and department-manager isolation.
- Verify Privacy export/delete and company/member deletion.
