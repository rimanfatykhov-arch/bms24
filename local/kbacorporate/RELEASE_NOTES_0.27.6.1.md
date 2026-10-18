# KBA Corporate Dashboard 0.27.6.1

This hotfix makes the shared role-aware workspace menu compact and readable on the Corporate dashboard and other plugin pages.

- Desktop uses three groups: My work, Company and BMS implementation.
- BMS implementation links wrap within the available width instead of creating a horizontal scrollbar.
- Switch company is part of the Company group and is hidden on the Corporate dashboard because that page already provides a company selector.
- Medium screens move BMS implementation to a full-width second row.
- Phone screens stack all groups and wrap their actions.

Build: `2026090285`.

After installation, complete the Moodle upgrade and purge all caches. Open the Corporate dashboard at desktop, tablet and phone widths and verify that no page-level or menu-level horizontal scrollbar appears.
