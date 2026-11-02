# Position Competency Profile — 0.7.1

## Purpose
A Position Competency Profile defines the target competency requirements for a canonical BMS Position.

## Architecture
- Position remains canonical in `local_kbacompany_position`.
- Profile identity/revision uses the existing versioned Role DNA root (`local_kbacompany_roledna`).
- Requirement rows are stored in `local_kbacompany_rolednacomp`.
- Competency identity remains Moodle Core Competency ID and only company-authorised frameworks are selectable.
- Employee assessment is intentionally not changed by profile editing or publishing.

## Lifecycle
1. First edit creates a Role DNA draft for the Position.
2. Draft requirements can be added, updated and removed.
3. Publishing requires at least one competency requirement.
4. Published revisions are immutable.
5. Editing after publication creates the next draft revision and copies requirements from the published revision.

## Future integration
The contract is provider-neutral. Odoo may later consume published target requirements through an adapter without becoming the canonical source of BMS competency definitions.
