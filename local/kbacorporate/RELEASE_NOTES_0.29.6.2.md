# KBA Corporate Dashboard v0.29.6.2
## BMS Workspace Navigation Refactor

### Classification
PATCH / UX navigation refactor. No database schema changes.

### Changed
- Rebuilt the common BMS workspace menu in `lib.php`.
- The BMS links are now grouped by the work sequence:
  1. Client
  2. Diagnostics
  3. BMS Tools
  4. Framework
  5. Implementation
  6. Analytics
- Added direct access to JSON competency ↔ tool import/export from the BMS Tools section.
- The active section and active link are highlighted consistently.
- The same navigation is rendered on implementation, audits, tools, framework, curator, board and analytics pages.
- Added responsive 3/2/1-column layouts.

### Compatibility
- Moodle 4.0+
- In-place upgrade from build `2026090305`
- No destructive migrations
