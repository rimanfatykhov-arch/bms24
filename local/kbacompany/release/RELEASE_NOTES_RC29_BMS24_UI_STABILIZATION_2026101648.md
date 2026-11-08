# BMS24 RC29 — UI stabilization — build 2026101648

## Scope
Presentation-only stabilization of the plugin-owned BMS24 standalone shell.

## Changes
- Sidebar stays available while the application content scrolls on desktop.
- BMS24 top bar stays pinned at the top of the application viewport.
- Workspace page metadata is rendered as separated UI chips instead of concatenated inline text.
- Page-level actions are placed in a dedicated action group.
- User menu and navigation labels receive safer geometry constraints.
- Mobile owned-shell layout keeps the sidebar hidden and restores normal document flow.

## Boundaries
No DB schema, ACL/RBAC, privacy, routing, company-isolation or business-domain changes.
