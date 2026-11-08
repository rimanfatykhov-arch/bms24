# RC29 build 2026101732 — Sidebar header construction

## Goal
Make the BMS24 sidebar header follow the requested construction: menu icon first, then BMS24 title on the same level, similar to Bitrix24.

## Changes
- Shared shell navigation toggle kept in the top row of the sidebar.
- Header row now renders as: `☰  BMS24`.
- Secondary company sublabel hidden in the header for a cleaner Bitrix-like look.
- In compact mode the BMS24 title disappears and only the menu button remains.
- Applies to both owned `bms24-app` shell and KBA Theme `kba-bms` shell.

## Scope
Visual/UI only. No DB changes. No permissions changes.
