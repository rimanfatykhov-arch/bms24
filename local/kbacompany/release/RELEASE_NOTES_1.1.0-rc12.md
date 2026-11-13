# local_kbacompany 1.1.0-rc12 build2026101232

## Staffing plan acceptance hardening

This release completes the planned rc12 staffing-plan UX/safety slice without adding a new domain model.

### Approval impact
- Draft staffing plans now preview current factual assignments before approval.
- The preview shows current assigned FTE, FTE that can be carried forward, and FTE that will require placement.
- Conflicts list the employee, position, assignment FTE and reason.
- Conflicts do not silently delete or end assignments.
- If conflicts exist, the user must explicitly acknowledge the impact before approval from the BMS24 UI.

### Versioned staffing safety
- The preview mirrors the existing canonical carry-forward rule: same position and equal slot FTE.
- Removed positions and incompatible capacity are surfaced before approval.
- The user can jump directly to Staffing Placement from the impact block.
- Existing Plan / Occupied / Vacancy / Overstaffed information remains visible in the draft editor.

### Validation UX
- Expected staffing-form validation errors stay in the BMS24 staffing editor instead of surfacing as a Moodle system error page.

### Technical scope
- No database schema changes.
- No capability/ACL changes.
- No privacy-provider changes.
- Approved staffing history remains immutable.
