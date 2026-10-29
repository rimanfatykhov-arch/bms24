# RC29 build 2026101728 — Communication Center compact sidebar

## What changed
- Communication Center shell now uses a compact collapsed sidebar, inspired by Bitrix24 but adapted to BMS24.
- Left menu no longer disappears completely; it collapses to an icon rail.
- Collapse toggle moved from floating bottom-left position into the top of the sidebar.
- Sidebar state persists in localStorage.
- Dynamic thread search from build 1726 remains enabled.

## UX result
- Expanded mode: full menu with labels.
- Collapsed mode: compact icon-only sidebar, more space for the communication workspace.
- Safer behavior in KBA Theme and owned BMS24 shell.

## Technical scope
- Updated `amd/src/communication_live_ui.js` and build artifact.
- Added compact sidebar CSS for `.bms24-app` and `.kba-bms`.
- No DB changes.
