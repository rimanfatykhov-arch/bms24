# KBA Corporate Dashboard 0.27.0

This release adds a management view of BMS implementation effectiveness.

- Monitor total tasks, completion rate, tasks in review, overdue tasks, average completion cycle, and checklist completion.
- Compare results by company and assigned curator.
- Display the latest confirmed BMS Index beside operational delivery indicators.
- Filter by company, curator, free-text search, and all-time/30/90/365-day periods.
- Export the filtered comparison to a semicolon CSV protected against spreadsheet-formula injection.
- Preserve scope: curators see assigned programs; authorised implementation administrators can analyse all programs.

Build: `2026090277`.

After installation, complete the Moodle database upgrade and purge all caches.

Recommended acceptance path: open “Implementation effectiveness” from the curator workspace, compare the all-time and 30-day views, filter one company, verify its task counts against the Kanban, and download the CSV.
