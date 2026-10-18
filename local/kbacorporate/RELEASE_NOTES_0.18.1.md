# KBA Corporate Dashboard 0.18.1

Build: `2026090212`  
Public API: `1.15`

## Added

- Assessment coverage: assessed target requirements / all target requirements.
- Target readiness: target requirements achieved / all target requirements.
- Pagination at 50 matrix rows per page.
- CSV export that preserves the active company, department, role, framework, status and search filters.

## Security

Export requires both `local/kbacorporate:viewcompetencies` and
`local/kbacorporate:exportreport`. The same company and department visibility resolver is used for
the screen and export. User-controlled CSV cells are protected from spreadsheet formula injection.

There are no database schema changes.
