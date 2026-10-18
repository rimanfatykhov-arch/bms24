# KBA Corporate Dashboard 0.17.3

Release: **Automated Role-based Audit**  
Build: `2026090200`  
Minimum Moodle: 4.0  
Public API: 1.15 (unchanged)

## Outcome

An authorised manager selects an employee and creates an audit. The plugin uses that employee's active business-role profile in the selected company to define the competencies, targets and priorities. It then collects evidence already held by Moodle.

## Evidence rules

- A current Moodle user-competency grade is authoritative and is marked confirmed.
- Grade-bearing core competency evidence and company-course competency results create preliminary proposals.
- Evidence without a grade remains visible but does not invent a level.
- Absence of evidence creates **Needs assessment**, distinct from a genuine level 0.
- Preliminary proposals change the current Moodle level only after explicit confirmation.
- The bulk-confirm action still writes each grade through Moodle's Competencies API.

Learning Plans remain optional learning sources. They do not define business-role requirements or the audit scope.

## Upgrade

Install the complete ZIP over the existing plugin. Do not uninstall the current plugin. Moodle runs savepoint `2026090200`, adds audit snapshot fields and creates `local_kbacorp_audit_ev`.

After upgrade, purge caches and run the acceptance steps in `TESTING.md`.
