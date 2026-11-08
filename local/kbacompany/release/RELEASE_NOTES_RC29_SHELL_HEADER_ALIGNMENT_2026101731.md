# BMS24 RC29 build2026101731 — Shared shell header alignment

- Expanded sidebar: collapse button and BMS24 brand appear on the same row, in that order.
- Compact sidebar: BMS24 brand hidden, toggle and navigation icons retained.
- Implemented in shared `bms_shell_navigation` for both owned BMS24 Shell and recognized KBA Theme sidebar.
- Avoids modifying unknown KBA shell grid structure.
- UI-only, no database schema changes or change to communication access/write paths.
- Live Moodle acceptance NOT RUN.
