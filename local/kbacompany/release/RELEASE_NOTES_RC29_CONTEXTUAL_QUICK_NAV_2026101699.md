# BMS24 1.1.0-rc29 — build2026101699

## Contextual Quick Navigation Standard

This release implements a consistent quick-access navigation layer across BMS24 so users can move between related screens without returning to the left sidebar or losing their current contour.

### What changed

- Added `bms_workspace_ux_service::contextual_nav()` as a presentation-only route-to-navigation adapter.
- Added Workspace quick navigation.
- Extended Architecture quick navigation with Architecture Center.
- Added operating-architecture quick navigation for Organisation / Positions / Processes / Functions / Roles / Flows / Products.
- Extended People quick navigation with Position Coverage.
- Extended Governance quick navigation with Quality Center.
- Extended Management > Indicators with Information Center.
- Battle Plan now automatically receives the Management > Planning quick menu when a page did not already render a more precise domain nav.
- `bms_page_shell_service` injects contextual navigation only when the page has not already rendered a `bms-context-quicknav`, preventing duplicates.
- Workspace and Architecture Center custom-shell landing pages prepend their appropriate quick menu explicitly.
- Added responsive quick-navigation CSS and accessibility labels.

### Product rule

Global sidebar = major BMS24 contour. Contextual quick menu = sibling screens inside the current work context. Breadcrumbs continue to show hierarchy/history.

### Safety

- Presentation/navigation only.
- No DDL.
- No new business entity, status or lifecycle.
- No direct write actions.
- Destination controllers continue to enforce ACL/RBAC and company isolation.
- Only validated week context is carried in Management links; arbitrary request parameters are not propagated.

### Live acceptance

NOT RUN. Verify active-state highlighting and no duplicate menu on Workspace, Tasks, Battle Plan, Management Cycles, Architecture, Processes, Employees, Documents and mobile widths.
