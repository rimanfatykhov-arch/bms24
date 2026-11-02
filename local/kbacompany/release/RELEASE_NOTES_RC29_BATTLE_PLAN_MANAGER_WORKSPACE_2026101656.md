# BMS24 1.1.0-rc29 build 2026101656

## Battle Plan manager workspace

- Adds an employee selector to the Battle Plan list, built only from plans already visible to the current actor.
- Makes subordinate-plan context explicit on the plan card: employee, position and week.
- Adds quick access to the selected employee's Battle Plans and to edit the current plan when canonical ACL permits it.
- Reuses existing `selected_can_edit`, `may_edit_department_plan()` and company-wide Battle Plan permissions.
- No schema, capability, privacy or company-isolation changes.
