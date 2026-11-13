# BMS24 Contextual Quick Navigation Standard — RC29

Build baseline: `2026101699`.

## Purpose

The left BMS24 sidebar answers **which major contour am I in?** The contextual quick menu answers **which neighbouring workspace/page can I open next without losing context?**

The quick menu is presentation-only. It does not grant access, change lifecycle state, infer roles, or create a second route model. Every destination remains owned by its canonical controller.

## Navigation families

- **Workspace:** Workspace, Calendar, Tasks, Recurring tasks, Battle Plan, Communication Center.
- **Management:** Overview; Planning (Weekly Coordination, Management Week, Battle Plan, Management Cycles); Indicators (Information Center, Metrics, Exceptions); Decisions; Workspaces.
- **Architecture / strategic model:** Architecture Center, Owner, Philosophy, Market, Business Model, Strategy, Financial Model, Administrative Scale.
- **Architecture / operating structure:** Organisation, Positions, Processes, Functions, Business Roles, Flows, Products.
- **People:** Overview, Employees, Positions, Coverage, Culture, Function Transfers, Learning, 360°.
- **Governance:** Quality Center, Documents, Policies, Deviations.

## Rendering rule

1. A domain page may render its own contextual nav when it needs a more precise active item or preserved context.
2. Domain navs add the marker class `bms-context-quicknav`.
3. `bms_page_shell_service` injects a route-appropriate contextual nav only when that marker is absent. This prevents duplicates.
4. Major custom-shell landing pages (Workspace and Architecture Center) prepend their nav explicitly.
5. Only a validated `week` value may be propagated by Management navigation; arbitrary request parameters, action, sesskey or record UUIDs are never copied.
6. Detail/edit pages may inherit their contour nav when it helps the user return to sibling workspaces. Utility/settings pages are intentionally excluded.

## UX rules

- Active item uses `aria-current=page`.
- Keyboard focus is visible.
- Menus wrap; no mandatory horizontal scrolling.
- On small screens items grow to readable touch targets.
- The contextual nav must not replace the global sidebar or breadcrumbs.

## Security / data ownership

Navigation is not authorization. Existing target controllers and services remain authoritative for ACL/RBAC, company isolation, validation, writes and lifecycle transitions. No new database table or business entity is introduced by this standard.
