# KBA Corporate Dashboard v0.29.6.3
## BMS Workspace Menu Optimization

Type: PATCH / UX navigation optimization.

### Changes
- Removed the standalone **BMS tools** navigation card.
- Consolidated `Framework Manager`, the BMS tool library and JSON competency/tool exchange into one **BMS methodology** card.
- Reduced the implementation workspace from six cards to five process-oriented cards.
- Balanced the desktop grid: Client / Diagnostics / Methodology on the first row; Implementation / Analytics on the second row.
- Preserved all existing URLs, permissions and active-state highlighting.
- No database schema changes and no destructive migrations.

### Upgrade path
Supported in-place upgrade from build `2026090306` to `2026090307`.
