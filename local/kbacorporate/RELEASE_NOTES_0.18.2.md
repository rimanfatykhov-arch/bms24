# KBA Corporate Dashboard 0.18.2.1

Build: `2026090214`  

Hotfix: removed premature assignment of the new development-plan capability
from the XMLDB upgrade. Moodle registers it from `db/access.php` after the
upgrade function completes.
Public API: `1.15`

## Individual development plans

- Managers generate a plan from the employee's current company-scoped role profile.
- Only unassessed target competencies and positive confirmed gaps become plan actions.
- The first actionable company course linked to the competency is recommended when available.
- A 90-day target date is assigned by default.
- Employees can open their active plan from My Competencies.
- Actions move through Planned, In progress and Completed; completing every action completes the plan.
- A second active plan is not created for the same employee and company.

## Data ownership and security

Moodle Competencies remains the source of current levels and evidence. The new tables store only
the development workflow. Company and department visibility is enforced for managers. Privacy API,
member/company deletion and Health Center include the new tables.
