# KBA Corporate Dashboard 0.18.0

Release: **Company Competency Map**  
Build: `2026090209`  
Public API: `1.15`

## Added

- Company competency map at `competency_map.php`.
- Employee × target competency rows based on active business-role profiles.
- Confirmed current level, target level, gap, priority and assessment status.
- Company, visible department, business role, framework, status and text filters.
- Links to the employee competency profile and audit creation.

## Security

The map requires `local/kbacorporate:viewcompetencies`. Employee scope is resolved by
`access::get_visible_employee_ids()`: company managers see their company; department managers
see only employees from departments they manage. Different companies are never merged.

## Upgrade

There are no schema changes. Moodle records savepoint `2026090209`. Install the complete archive
over the existing plugin and complete Site administration → Notifications.
