# BMS24 RC29 build2026101729 — Global compact App Shell navigation

- Moves menu collapse from Communication Center-only AMD to shared BMS page shell.
- Uses icon-only compact sidebar with top-mounted button for every BMS page.
- Retains labels via title and aria-label; localStorage remembers preference.
- Supports owned App Shell; KBA Theme only when the sidebar is a direct child of a supported grid/flex shell.
- Protects other/unknown theme layouts by leaving them unchanged.
- Removes previous communication-only collapse CSS and toggle; keeps async conversation search.
- No DB migration, permissions or communication write-path changes.
- Static QA only; live acceptance across KBA Theme/owned shell still required.
