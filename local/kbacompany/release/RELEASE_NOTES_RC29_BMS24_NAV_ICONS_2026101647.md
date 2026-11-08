# BMS24 RC29 — build 2026101647

## Change
Restores route icons in the plugin-owned BMS24 standalone sidebar after the switch to Moodle `embedded` page layout.

## Root cause
The semantic BMS navigation icons were still rendered as inline SVG, but their historical sizing rules were scoped to the legacy `.kba-bms-sidebar`. The new `.bms24-app` shell therefore did not give the nested `.bms-nav-svg` wrapper an explicit size.

## Fix
- Added owned-shell scoped dimensions for `.bms24-navicon` and `.bms-nav-svg`.
- Preserved `currentColor`, hover and active-state behavior.
- No route, capability, company isolation, DB schema or business logic changes.

## Live check
Verify icons beside visible sidebar items in desktop and mobile BMS24 owned mode.
