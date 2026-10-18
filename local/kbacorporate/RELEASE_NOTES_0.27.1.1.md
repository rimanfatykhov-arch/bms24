# KBA Corporate Dashboard 0.27.1.1

This hotfix restores reliable tool selection on the “Create implementation audit” page.

- Lists active tools owned by the current company and active global templates.
- Never exposes a tool owned by another company.
- Automatically prepares the bundled Point A template when the company has no available audit tool.
- Displays a readable “Choose a BMS tool” prompt instead of a collapsed empty field.
- Rejects unavailable, inactive and cross-company tool identifiers during server-side form validation.

Build: `2026090279`.

After installation, complete the Moodle upgrade and purge all caches. Open the audit creation page for a company that has no previous tool configuration and verify that “Point A” appears in the selector.
