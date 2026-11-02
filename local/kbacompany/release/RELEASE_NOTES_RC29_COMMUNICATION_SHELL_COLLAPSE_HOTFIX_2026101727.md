# RC29 build2026101727 — Sidebar collapse layout hotfix

- Fix live-reported catastrophic shell layout collapse after pressing the Communication Center menu toggle.
- Remove the unsupported assumed KBA shell two-column grid override (`grid-template-columns: 0 minmax(0, 1fr)`). That override squeezed KBA theme chrome into a zero-width column, with text wrapping character by character.
- Scope collapsed state to the actual shell node instead of globally toggling body. Hide only its sidebar, preserving the KBA theme's native layout model and other page components.
- Keep a one-column rule for the plugin-owned BMS24 CSS grid, whose markup is known. Preserve restore button and aria-expanded updates.
- Add release-blocking source checks for this regression. No DDL, no Moodle core or KBA theme edits, no message/business logic changes.
- Static QA only; live KBA Theme/Boost acceptance pending.
