# KBA Corporate Dashboard 0.23.3.1

Build: `2026090258`

This hotfix corrects client switching for multi-company administrators. The permanent “BMS implementation” shortcut no longer retains the company identifier from the current page when several companies are available. It opens the searchable client registry instead.

Each company implementation workspace also includes an explicit “Select another company” link. Managers with access to exactly one company continue to open that company directly.

Existing audits, programs, tariffs, curator assignments and Bitrix24 settings are preserved. Upgrade over v0.23.3 without uninstalling the plugin. No schema migration is required.
